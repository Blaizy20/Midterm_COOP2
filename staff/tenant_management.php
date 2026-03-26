<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

require_login();
require_permission('manage_tenants');

$title = "Tenant Management";
$active = "tenants";
$err = '';
$ok = '';
$view_tenant_id = 0;

$tenant_status_labels = tenant_status_options();
$tenant_plan_labels = tenant_plan_options();

$admin_candidates = fetch_all(q(
  "SELECT user_id, username, full_name, email
   FROM users
   WHERE role='ADMIN' AND is_active=1
   ORDER BY full_name ASC"
));

$find_primary_owner = function ($tenant_id) {
  return fetch_one(q(
    "SELECT u.user_id, u.username, u.full_name, u.email
     FROM tenant_admins ta
     JOIN users u ON u.user_id = ta.user_id
     WHERE ta.tenant_id=?
     ORDER BY ta.is_primary_owner DESC, ta.id ASC
     LIMIT 1",
    "i",
    [$tenant_id]
  ));
};

$status_actions = [
  'approve' => [
    'from' => ['PENDING', 'REJECTED'],
    'to' => 'ACTIVE',
    'log' => 'TENANT_APPROVED',
    'message' => 'approved',
  ],
  'activate' => [
    'from' => ['INACTIVE', 'SUSPENDED'],
    'to' => 'ACTIVE',
    'log' => 'TENANT_ACTIVATED',
    'message' => 'activated',
  ],
  'deactivate' => [
    'from' => ['ACTIVE'],
    'to' => 'INACTIVE',
    'log' => 'TENANT_DEACTIVATED',
    'message' => 'deactivated',
  ],
  'suspend' => [
    'from' => ['ACTIVE'],
    'to' => 'SUSPENDED',
    'log' => 'TENANT_SUSPENDED',
    'message' => 'suspended',
  ],
  'reject' => [
    'from' => ['PENDING'],
    'to' => 'REJECTED',
    'log' => 'TENANT_REJECTED',
    'message' => 'rejected',
  ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tenant_status'])) {
  $tenant_id = intval($_POST['tenant_id'] ?? 0);
  $action = trim($_POST['action'] ?? '');
  $view_tenant_id = $tenant_id;

  if ($tenant_id <= 0 || !isset($status_actions[$action])) {
    $err = "Invalid tenant or action.";
  } else {
    $tenant = fetch_one(q(
      "SELECT tenant_id, COALESCE(display_name, tenant_name) AS display_name, subdomain, tenant_status, plan_code
       FROM tenants
       WHERE tenant_id=?
       LIMIT 1",
      "i",
      [$tenant_id]
    ));

    if (!$tenant) {
      $err = "Tenant not found.";
    } else {
      $current_status = normalize_tenant_status($tenant['tenant_status'] ?? '');
      $transition = $status_actions[$action];

      if (!in_array($current_status, $transition['from'], true)) {
        $err = "This tenant cannot be {$transition['message']} from its current status.";
      } else {
        $next_status = $transition['to'];
        q(
          "UPDATE tenants
           SET tenant_status=?, is_active=?, updated_at=CURRENT_TIMESTAMP
           WHERE tenant_id=?",
          "sii",
          [$next_status, tenant_status_is_active($next_status), $tenant_id]
        );

        $description = sprintf(
          'Tenant workflow updated for %s (%s): %s -> %s. Plan: %s.',
          $tenant['display_name'],
          $tenant['subdomain'],
          $tenant_status_labels[$current_status] ?? $current_status,
          $tenant_status_labels[$next_status] ?? $next_status,
          $tenant_plan_labels[normalize_tenant_plan($tenant['plan_code'] ?? '')] ?? normalize_tenant_plan($tenant['plan_code'] ?? '')
        );
        log_tenant_activity($tenant_id, $transition['log'], $description);
        $ok = "Tenant {$transition['message']} successfully.";
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tenant_owners'])) {
  $tenant_id = intval($_POST['tenant_id'] ?? 0);
  $owner_user_id = intval($_POST['owner_user_id'] ?? 0);
  $view_tenant_id = $tenant_id;

  if ($tenant_id <= 0) {
    $err = "Invalid tenant.";
  } else {
    $conn = db();
    try {
      $conn->begin_transaction();

      $tenant = fetch_one(q(
        "SELECT tenant_id, COALESCE(display_name, tenant_name) AS display_name, subdomain
         FROM tenants
         WHERE tenant_id=?
         LIMIT 1",
        "i",
        [$tenant_id]
      ));
      if (!$tenant) {
        throw new RuntimeException('Tenant not found.');
      }

      $previous_owner = $find_primary_owner($tenant_id);

      if ($owner_user_id > 0) {
        $valid_owner = fetch_one(q(
          "SELECT user_id, full_name FROM users WHERE role='ADMIN' AND is_active=1 AND user_id=? LIMIT 1",
          "i",
          [$owner_user_id]
        ));
        if (!$valid_owner) {
          throw new RuntimeException('Only active ADMIN users can own tenants.');
        }
      }

      q("DELETE FROM tenant_admins WHERE tenant_id=?", "i", [$tenant_id]);
      if ($owner_user_id > 0) {
        q(
          "INSERT INTO tenant_admins (tenant_id, user_id, is_primary_owner) VALUES (?, ?, ?)",
          "iii",
          [$tenant_id, $owner_user_id, 1]
        );
      }

      $conn->commit();

      $updated_owner = $find_primary_owner($tenant_id);
      $previous_owner_name = $previous_owner['full_name'] ?? 'No owner assigned';
      $updated_owner_name = $updated_owner['full_name'] ?? 'No owner assigned';
      $description = sprintf(
        'Tenant owner updated for %s (%s). Previous owner: %s. New owner: %s.',
        $tenant['display_name'],
        $tenant['subdomain'],
        $previous_owner_name,
        $updated_owner_name
      );
      log_tenant_activity($tenant_id, 'TENANT_UPDATED', $description);
      $ok = "Tenant owners updated successfully.";
    } catch (RuntimeException $e) {
      try { $conn->rollback(); } catch (Exception $rollback_exception) {}
      $err = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
      try { $conn->rollback(); } catch (Exception $rollback_exception) {}
      $err = "Failed to update tenant owners: " . $e->getMessage();
    }
  }
}

$tenants = fetch_all(q(
  "SELECT
      t.tenant_id,
      t.tenant_name,
      COALESCE(t.display_name, t.tenant_name) AS display_name,
      t.subdomain,
      t.tenant_status,
      t.plan_code,
      t.is_active,
      t.created_at,
      MAX(CASE WHEN ta.is_primary_owner = 1 THEN u.full_name END) AS primary_owner
   FROM tenants t
   LEFT JOIN tenant_admins ta ON ta.tenant_id = t.tenant_id
   LEFT JOIN users u ON u.user_id = ta.user_id
   GROUP BY
      t.tenant_id,
      t.tenant_name,
      t.display_name,
      t.subdomain,
      t.tenant_status,
      t.plan_code,
      t.is_active,
      t.created_at
   ORDER BY
      FIELD(t.tenant_status, 'PENDING', 'ACTIVE', 'INACTIVE', 'SUSPENDED', 'REJECTED'),
      COALESCE(t.display_name, t.tenant_name) ASC"
));

$selected_tenant = null;
$selected_owner_id = 0;
$selected_owner = null;
$tenant_stats = null;

if (!$view_tenant_id && isset($_GET['view']) && intval($_GET['view']) > 0) {
  $view_tenant_id = intval($_GET['view']);
}

if ($view_tenant_id > 0) {
  $tenant_id = $view_tenant_id;
  $selected_tenant = fetch_one(q(
    "SELECT
        tenant_id,
        tenant_name,
        COALESCE(display_name, tenant_name) AS display_name,
        subdomain,
        tenant_status,
        plan_code,
        is_active,
        created_at
     FROM tenants
     WHERE tenant_id=?",
    "i",
    [$tenant_id]
  ));

  if ($selected_tenant) {
    $selected_owner = $find_primary_owner($tenant_id);
    $selected_owner_id = intval($selected_owner['user_id'] ?? 0);

    $tenant_stats = fetch_one(q(
      "SELECT
        (SELECT COUNT(*) FROM users WHERE tenant_id=?) AS total_users,
        (SELECT COUNT(*) FROM users WHERE tenant_id=? AND is_active=1) AS active_users,
        (SELECT COUNT(*) FROM customers WHERE tenant_id=? AND is_active=1) AS total_customers,
        (SELECT COUNT(*) FROM loans WHERE tenant_id=?) AS total_loans,
        (SELECT COUNT(*) FROM loans WHERE tenant_id=? AND status='ACTIVE') AS active_loans,
        (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE tenant_id=?) AS total_payments",
      "iiiiii",
      [$tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id]
    ));
  }
}

include __DIR__ . '/_layout_top.php';
?>
<style>
  body { background: radial-gradient(circle at top, rgba(14, 165, 233, 0.12), transparent 30%), linear-gradient(180deg, #020617 0%, #081121 42%, #0f172a 100%); color: #e5eefb; }
  .topbar { background: linear-gradient(135deg, #081121, #0f1b35) !important; border-bottom: 1px solid rgba(148, 163, 184, 0.14); box-shadow: 0 18px 40px rgba(2, 6, 23, 0.35); }
  .topbar .small, .topbar a.btn.btn-outline { color: #d8e4f5 !important; border-color: rgba(148, 163, 184, 0.24) !important; }
  .layout, .main { background: transparent; }
  .sidebar { background: rgba(4, 10, 24, 0.84); border-right: 1px solid rgba(148, 163, 184, 0.12); backdrop-filter: blur(16px); }
  .sidebar h3 { color: #7f93b0; }
  .sidebar a { color: #d7e3f4; }
  .sidebar a.active, .sidebar a:hover { background: linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(59, 130, 246, 0.2)); color: #f8fbff; }
  .tenant-shell { display: grid; gap: 20px; color: #e5eefb; }
  .tenant-hero, .tenant-card { border-radius: 26px; border: 1px solid rgba(148, 163, 184, 0.16); background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%), linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97)); box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34); padding: 24px; }
  .tenant-hero .small, .tenant-card .small { color: #8ea3bf; }
  .tenant-card h3, .tenant-card h4, .tenant-hero strong { color: #f8fbff; }
  .tenant-table-wrap { overflow: auto; margin-top: 14px; border-radius: 20px; border: 1px solid rgba(148, 163, 184, 0.12); }
  .tenant-card .table { margin: 0; width: 100%; color: #e5eefb; background: transparent; }
  .tenant-card .table th { background: rgba(15, 23, 42, 0.96); color: #93a8c6; text-transform: uppercase; letter-spacing: 0.08em; font-size: 11px; border-bottom: 1px solid rgba(148, 163, 184, 0.16); }
  .tenant-card .table td, .tenant-card .table th { border-color: rgba(148, 163, 184, 0.1); padding: 14px 16px; vertical-align: top; }
  .tenant-card .table tbody tr:nth-child(odd) { background: rgba(15, 23, 42, 0.48); }
  .tenant-divider { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid rgba(148, 163, 184, 0.14); }
  .tenant-owner-list { display: grid; gap: 10px; max-height: 260px; overflow: auto; }
  .tenant-owner-card { display: flex; gap: 10px; align-items: flex-start; border: 1px solid rgba(148, 163, 184, 0.14); border-radius: 12px; padding: 10px; cursor: pointer; background: rgba(15, 23, 42, 0.56); }
  .tenant-card .btn.btn-outline { border-color: rgba(148, 163, 184, 0.2); color: #d8e4f5; background: rgba(15, 23, 42, 0.55); }
  .tenant-stats div { margin-bottom: 8px; }
  .tenant-note { margin-top: 10px; color: #8ea3bf !important; }
  .tenant-modal-backdrop { position: fixed; inset: 0; background: rgba(2, 6, 23, 0.56); z-index: 1190; }
  .tenant-modal-wrap { position: fixed; inset: 24px; display: flex; align-items: center; justify-content: center; z-index: 1200; }
  .tenant-modal-card { width: min(900px, 100%); max-height: calc(100vh - 48px); overflow: auto; border-radius: 28px; border: 1px solid rgba(148, 163, 184, 0.16); background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%), linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(8, 15, 30, 0.99)); box-shadow: 0 32px 90px rgba(2, 6, 23, 0.52); padding: 24px; color: #e5eefb; }
  .tenant-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
  .tenant-modal-title { margin: 0; font-size: clamp(28px, 4vw, 38px); line-height: 1; letter-spacing: -0.04em; color: #f8fbff; }
  .tenant-modal-kicker { font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #8ea3bf; }
  .tenant-modal-close { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 999px; border: 1px solid rgba(148, 163, 184, 0.18); background: rgba(15, 23, 42, 0.68); color: #e5eefb; text-decoration: none; }
  .tenant-modal-close:hover { text-decoration: none; background: rgba(30, 41, 59, 0.9); }
  .tenant-modal-grid { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(280px, .95fr); gap: 18px; }
  .tenant-modal-pane { border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.46); padding: 18px; }
  .tenant-modal-pane h4 { margin: 0 0 14px 0; color: #f8fbff; }
  .tenant-metric-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
  .tenant-metric { border-radius: 16px; border: 1px solid rgba(148, 163, 184, 0.12); background: rgba(15, 23, 42, 0.58); padding: 14px; }
  .tenant-metric .small { display: block; margin-bottom: 6px; }
  .tenant-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
  body.tenant-modal-open { overflow: hidden; }
  @media (max-width: 900px) { .tenant-modal-grid { grid-template-columns: 1fr; } }
  @media (max-width: 760px) {
    .tenant-hero, .tenant-card { padding: 18px; border-radius: 18px; }
    .tenant-modal-wrap { inset: 12px; }
    .tenant-modal-card { max-height: calc(100vh - 24px); padding: 18px; border-radius: 22px; }
    .tenant-modal-header { margin-bottom: 14px; }
  }
</style>

<div class="tenant-shell">
<div class="tenant-hero"><div class="small">Tenant Workflow Control</div><div style="font-size:20px;font-weight:800"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></div></div>

<?php if ($err): ?><div style="background:#fee;color:#c33;padding:12px;border-radius:4px;margin-bottom:14px;"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div style="background:#efe;color:#2f7d32;padding:12px;border-radius:4px;margin-bottom:14px;"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row">
  <div class="col" style="flex:1">
    <div class="tenant-card">
      <h3 style="margin-top:0">All Tenants</h3>
      <div class="tenant-table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Tenant</th>
            <th>Plan</th>
            <th>Primary Owner</th>
            <th>Status</th>
            <th>Created</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tenants as $tenant_row): ?>
            <?php $status_code = normalize_tenant_status($tenant_row['tenant_status'] ?? ''); ?>
            <?php $plan_code = normalize_tenant_plan($tenant_row['plan_code'] ?? ''); ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($tenant_row['display_name']) ?></strong><br>
                <span class="small"><?= htmlspecialchars($tenant_row['subdomain']) ?></span>
              </td>
              <td><?= htmlspecialchars($tenant_plan_labels[$plan_code] ?? $plan_code) ?></td>
              <td><?= htmlspecialchars($tenant_row['primary_owner'] ?: 'No owner assigned') ?></td>
              <td><span class="badge <?= tenant_status_badge_class($status_code) ?>"><?= htmlspecialchars($tenant_status_labels[$status_code] ?? $status_code) ?></span></td>
              <td><?= htmlspecialchars($tenant_row['created_at']) ?></td>
              <td><a class="btn btn-outline" href="?view=<?= intval($tenant_row['tenant_id']) ?>">View Tenant Profile</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($tenants)): ?>
            <tr><td colspan="6" class="small">No tenants found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
</div>

<?php if ($selected_tenant): ?>
  <?php $selected_status = normalize_tenant_status($selected_tenant['tenant_status'] ?? ''); ?>
  <?php $selected_plan = normalize_tenant_plan($selected_tenant['plan_code'] ?? ''); ?>
  <div class="tenant-modal-backdrop" data-tenant-modal-close></div>
  <div class="tenant-modal-wrap" id="tenantProfileModal" role="dialog" aria-modal="true" aria-labelledby="tenantProfileModalTitle">
    <div class="tenant-modal-card">
      <div class="tenant-modal-header">
        <div>
          <div class="tenant-modal-kicker">Tenant Profile</div>
          <h2 class="tenant-modal-title" id="tenantProfileModalTitle"><?= htmlspecialchars($selected_tenant['display_name']) ?></h2>
          <div class="small"><?= htmlspecialchars($selected_tenant['tenant_name']) ?> | <?= htmlspecialchars($selected_tenant['subdomain']) ?></div>
        </div>
        <a class="tenant-modal-close" href="<?php echo APP_BASE; ?>/staff/tenant_management.php" aria-label="Close tenant profile">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>

      <div class="tenant-modal-grid">
        <div class="tenant-modal-pane">
          <div class="tenant-divider">
            <strong>Status</strong><br>
            <span class="badge <?= tenant_status_badge_class($selected_status) ?>"><?= htmlspecialchars($tenant_status_labels[$selected_status] ?? $selected_status) ?></span>
          </div>

          <div class="tenant-divider">
            <strong>Plan</strong><br>
            <?= htmlspecialchars($tenant_plan_labels[$selected_plan] ?? $selected_plan) ?>
          </div>

          <div class="tenant-divider">
            <strong>Primary Owner</strong><br>
            <?php if ($selected_owner): ?>
              <?= htmlspecialchars($selected_owner['full_name']) ?><br>
              <span class="small"><?= htmlspecialchars($selected_owner['username']) ?></span><br>
              <?php if (!empty($selected_owner['email'])): ?><span class="small"><?= htmlspecialchars($selected_owner['email']) ?></span><?php endif; ?>
            <?php else: ?>
              <span class="small">No owner assigned.</span>
            <?php endif; ?>
          </div>

          <div class="tenant-divider" style="margin-bottom:16px">
            <strong>Created</strong><br>
            <?= htmlspecialchars($selected_tenant['created_at']) ?>
          </div>

          <?php if ($tenant_stats): ?>
            <h4>Tenant Statistics</h4>
            <div class="tenant-metric-grid">
              <div class="tenant-metric"><span class="small">Total Users</span><strong><?= intval($tenant_stats['total_users'] ?? 0) ?></strong></div>
              <div class="tenant-metric"><span class="small">Active Users</span><strong><?= intval($tenant_stats['active_users'] ?? 0) ?></strong></div>
              <div class="tenant-metric"><span class="small">Customers</span><strong><?= intval($tenant_stats['total_customers'] ?? 0) ?></strong></div>
              <div class="tenant-metric"><span class="small">Loans</span><strong><?= intval($tenant_stats['total_loans'] ?? 0) ?></strong></div>
              <div class="tenant-metric"><span class="small">Active Loans</span><strong><?= intval($tenant_stats['active_loans'] ?? 0) ?></strong></div>
              <div class="tenant-metric"><span class="small">Payments</span><strong>PHP <?= number_format((float)($tenant_stats['total_payments'] ?? 0), 2) ?></strong></div>
            </div>
          <?php endif; ?>
        </div>

        <div class="tenant-modal-pane">
          <form method="post">
            <input type="hidden" name="tenant_id" value="<?= intval($selected_tenant['tenant_id']) ?>">
            <h4>Assigned Owner Admin</h4>
            <div class="tenant-owner-list">
              <label class="tenant-owner-card">
                <input type="radio" name="owner_user_id" value="" <?= $selected_owner_id <= 0 ? 'checked' : '' ?>>
                <span>
                  <strong>No owner assigned</strong><br>
                  <span class="small">Remove any owner from this tenant.</span>
                </span>
              </label>
              <?php foreach ($admin_candidates as $admin): ?>
                <label class="tenant-owner-card">
                  <input type="radio" name="owner_user_id" value="<?= intval($admin['user_id']) ?>" <?= intval($admin['user_id']) === $selected_owner_id ? 'checked' : '' ?>>
                  <span>
                    <strong><?= htmlspecialchars($admin['full_name']) ?></strong><br>
                    <span class="small"><?= htmlspecialchars($admin['username']) ?></span><br>
                    <?php if (!empty($admin['email'])): ?><span class="small"><?= htmlspecialchars($admin['email']) ?></span><?php endif; ?>
                  </span>
                </label>
              <?php endforeach; ?>
              <?php if (empty($admin_candidates)): ?>
                <div class="small">No active ADMIN users are available.</div>
              <?php endif; ?>
            </div>

            <div class="tenant-actions">
              <button class="btn btn-primary" type="submit" name="update_tenant_owners" value="1">Save Owners</button>
            </div>
          </form>

          <form method="post" class="tenant-actions">
            <input type="hidden" name="tenant_id" value="<?= intval($selected_tenant['tenant_id']) ?>">
            <input type="hidden" name="update_tenant_status" value="1">

            <?php if ($selected_status === 'PENDING'): ?>
              <button class="btn btn-primary" type="submit" name="action" value="approve" onclick="return confirm('Approve this tenant?')">Approve</button>
              <button class="btn btn-outline" type="submit" name="action" value="reject" onclick="return confirm('Reject this tenant?')">Reject</button>
            <?php elseif ($selected_status === 'ACTIVE'): ?>
              <button class="btn btn-outline" type="submit" name="action" value="deactivate" onclick="return confirm('Deactivate this tenant?')">Deactivate</button>
              <button class="btn btn-outline" type="submit" name="action" value="suspend" onclick="return confirm('Suspend this tenant?')">Suspend</button>
            <?php elseif (in_array($selected_status, ['INACTIVE', 'SUSPENDED'], true)): ?>
              <button class="btn btn-primary" type="submit" name="action" value="activate" onclick="return confirm('Activate this tenant?')">Activate</button>
            <?php elseif ($selected_status === 'REJECTED'): ?>
              <button class="btn btn-primary" type="submit" name="action" value="approve" onclick="return confirm('Approve this rejected tenant and activate it?')">Approve</button>
            <?php endif; ?>
          </form>

          <div class="small" style="margin-top:10px;color:#8ea3bf">
            Workflow states are enforced as `PENDING`, `ACTIVE`, `INACTIVE`, `SUSPENDED`, and `REJECTED`.
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    document.body.classList.add('tenant-modal-open');
    document.querySelectorAll('[data-tenant-modal-close]').forEach(function (item) {
      item.addEventListener('click', function () {
        window.location.href = '<?php echo APP_BASE; ?>/staff/tenant_management.php';
      });
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        window.location.href = '<?php echo APP_BASE; ?>/staff/tenant_management.php';
      }
    });
  </script>
<?php endif; ?>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
