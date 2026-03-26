<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

require_login();
require_roles(['ADMIN', 'SUPER_ADMIN']);

$selection_label = 'Select the tenant you want to access';
$available_tenants = [];

if (is_super_admin()) {
  $selection_label = 'Select the tenant context you want to access';
  $available_tenants = fetch_all(q(
    "SELECT tenant_id, tenant_name, COALESCE(display_name, tenant_name) AS display_name, subdomain, is_active, 0 AS is_primary_owner
     FROM tenants
     WHERE is_active=1
     ORDER BY COALESCE(display_name, tenant_name) ASC"
  ));
} else {
  $available_tenants = user_owned_tenants($_SESSION['user_id'] ?? 0, true);
  if (empty($available_tenants)) {
    http_response_code(403);
    echo "No active tenants are assigned to this admin account.";
    exit;
  }

  if (count($available_tenants) === 1) {
    set_current_active_tenant_id($available_tenants[0]['tenant_id']);
    header("Location: " . APP_BASE . "/staff/dashboard.php");
    exit;
  }
}

$error = '';
$current_active_tenant_id = current_active_tenant_id();
$settings = get_system_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tenant_id = intval($_POST['tenant_id'] ?? 0);
  try {
    set_current_active_tenant_id($tenant_id);
    if (!empty($_SESSION['pending_login_audit'])) {
      log_activity_for_tenant(
        $tenant_id,
        'USER_LOGIN',
        'Staff user logged in: ' . ($_SESSION['full_name'] ?? 'User') . ' (' . ($_SESSION['role'] ?? 'UNKNOWN') . ').'
      );
      unset($_SESSION['pending_login_audit']);
    }
    $return_url = $_SESSION['return_url'] ?? (APP_BASE . "/staff/dashboard.php");
    unset($_SESSION['return_url']);
    header("Location: " . $return_url);
    exit;
  } catch (RuntimeException $e) {
    $error = $e->getMessage();
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Select Tenant</title>
  <link rel="stylesheet" href="<?php echo APP_BASE; ?>/assets/css/theme.css">
  <style>
    body {
      min-height: 100vh;
      background:
        radial-gradient(circle at top, rgba(14, 165, 233, 0.16), transparent 30%),
        linear-gradient(180deg, #020617 0%, #081121 42%, #0f172a 100%);
      color: #e5eefb;
    }

    .topbar {
      background: linear-gradient(135deg, #081121, #0f1b35) !important;
      border-bottom: 1px solid rgba(148, 163, 184, 0.14);
      box-shadow: 0 18px 40px rgba(2, 6, 23, 0.35);
    }

    .topbar .small,
    .topbar a.btn.btn-outline {
      color: #d8e4f5 !important;
      border-color: rgba(148, 163, 184, 0.24) !important;
    }

    .tenant-shell {
      display: flex;
      justify-content: center;
      align-items: flex-start;
      min-height: calc(100vh - 58px);
      padding: 40px 22px;
    }

    .tenant-card {
      max-width: 1040px;
      width: 100%;
      border-radius: 30px;
      border: 1px solid rgba(148, 163, 184, 0.16);
      background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.14), transparent 28%),
        linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97));
      box-shadow: 0 28px 80px rgba(2, 6, 23, 0.4);
      padding: 30px;
    }

    .tenant-kicker {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(125, 211, 252, 0.24);
      background: rgba(14, 165, 233, 0.12);
      color: #d8f4ff;
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .tenant-card h2 {
      margin: 14px 0 0;
      font-size: clamp(34px, 4vw, 48px);
      line-height: 1;
      letter-spacing: -0.04em;
      color: #f8fbff;
    }

    .tenant-card .small {
      color: #8ea3bf;
    }

    .tenant-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-top: 24px;
    }

    .tenant-tile {
      width: 100%;
      text-align: left;
      padding: 20px;
      border-radius: 22px;
      display: block;
      border: 1px solid rgba(148, 163, 184, 0.14);
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(8, 15, 30, 0.96));
      color: #e5eefb;
      box-shadow: 0 16px 40px rgba(2, 6, 23, 0.24);
      transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
    }

    .tenant-tile:hover {
      transform: translateY(-2px);
      border-color: rgba(56, 189, 248, 0.28);
      box-shadow: 0 20px 44px rgba(2, 6, 23, 0.34);
    }

    .tenant-tile.is-active {
      border-color: #38bdf8;
      background:
        radial-gradient(circle at top right, rgba(56, 189, 248, 0.14), transparent 30%),
        linear-gradient(180deg, rgba(14, 22, 42, 0.96), rgba(8, 15, 30, 0.98));
    }

    .tenant-display {
      font-size: 18px;
      font-weight: 800;
      color: #f8fbff;
    }

    .tenant-name {
      margin-top: 8px;
      color: #9fb0c9;
      font-size: 13px;
    }

    .tenant-subdomain {
      margin-top: 6px;
      color: #7f93b0;
      font-size: 12px;
    }

    .tenant-owner {
      margin-top: 12px;
      color: #7dd3fc;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    @media (max-width: 760px) {
      .tenant-shell {
        padding: 24px 16px;
      }

      .tenant-card {
        padding: 22px;
        border-radius: 22px;
      }
    }
  </style>
</head>
<body>
<div class="topbar" style="background-color:<?= htmlspecialchars($settings['primary_color'] ?? '#2c3ec5') ?> !important;">
  <div class="brand">
    <img src="<?php echo htmlspecialchars($settings['logo_path'] ?? APP_BASE . '/assets/img/logo.png'); ?>" alt="Logo"/>
    <div>
      <div style="font-weight:800;line-height:1"><?= htmlspecialchars($settings['system_name'] ?? 'CredenceLend') ?></div>
      <div class="small" style="color:#fde8ec"><?= is_super_admin() ? 'Super Admin Tenant Context' : 'Admin Tenant Selection' ?></div>
    </div>
  </div>
  <div>
    <span class="small" style="color:#fde8ec"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?> (<?= htmlspecialchars($_SESSION['role'] ?? '') ?>)</span>
    &nbsp; <a class="btn btn-outline" style="color:white;border-color:#ffd0d8" href="<?php echo APP_BASE; ?>/staff/logout.php">Logout</a>
  </div>
</div>

<div class="tenant-shell">
  <div class="tenant-card">
    <span class="tenant-kicker"><?= is_super_admin() ? 'Tenant Context' : 'Tenant Access' ?></span>
    <h2><?= htmlspecialchars($selection_label) ?></h2>
    <div class="small"><?= is_super_admin() ? 'All active tenants are available to super admin.' : 'Only tenants owned by your admin account are shown here.' ?></div>

    <?php if ($error): ?><div class="alert err" style="margin-top:14px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="tenant-grid">
      <?php foreach ($available_tenants as $tenant): ?>
        <?php $tenant_id = intval($tenant['tenant_id']); ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="tenant_id" value="<?= $tenant_id ?>">
          <button
            type="submit"
            class="tenant-tile<?= $current_active_tenant_id === $tenant_id ? ' is-active' : '' ?>"
          >
            <div class="tenant-display"><?= htmlspecialchars($tenant['display_name']) ?></div>
            <div class="tenant-name"><?= htmlspecialchars($tenant['tenant_name']) ?></div>
            <?php if (!empty($tenant['subdomain'])): ?>
              <div class="tenant-subdomain"><?= htmlspecialchars($tenant['subdomain']) ?></div>
            <?php endif; ?>
            <?php if (!empty($tenant['is_primary_owner'])): ?>
              <div class="tenant-owner">Primary owner</div>
            <?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</body>
</html>
