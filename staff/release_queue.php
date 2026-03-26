<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('manage_vouchers');

$search = trim($_GET['search'] ?? '');
$where = tenant_condition('l.tenant_id');
$types = tenant_types();
$params = tenant_params();

if ($search) {
  $where .= " AND (l.reference_no LIKE ? OR c.customer_no LIKE ? OR CONCAT(c.first_name,' ',c.last_name) LIKE ?)";
  $search_param = '%' . $search . '%';
  $types = 'sss';
  $params = [$search_param, $search_param, $search_param];
}

$loans = fetch_all(q(
  "SELECT l.loan_id, l.reference_no, l.principal_amount, l.interest_rate, l.payment_term, 
          l.total_payable, l.activated_at, l.status,
          c.customer_id, c.customer_no, c.first_name, c.last_name, c.street, c.barangay, c.city, c.province, c.contact_no,
          u.full_name AS officer_name
   FROM loans l
   JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id=l.tenant_id
   LEFT JOIN users u ON u.user_id = l.loan_officer_id AND u.tenant_id=l.tenant_id
   WHERE $where
   ORDER BY l.activated_at DESC",
  $types, $params
));

$title = "Release Queue";
$active = "release_queue";
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
  .queue-shell { display: grid; gap: 20px; color: #e5eefb; }
  .queue-card { border-radius: 26px; border: 1px solid rgba(148, 163, 184, 0.16); background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%), linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97)); box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34); padding: 24px; }
  .queue-header { display: flex; justify-content: space-between; align-items: end; flex-wrap: wrap; gap: 14px; margin-bottom: 16px; }
  .queue-kicker { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; border: 1px solid rgba(125, 211, 252, 0.24); background: rgba(14, 165, 233, 0.12); color: #d8f4ff; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; }
  .queue-header h2 { margin: 10px 0 0; font-size: clamp(30px, 4vw, 44px); line-height: 1; letter-spacing: -0.04em; color: #f8fbff; }
  .queue-filter { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .queue-card .input { background: rgba(15, 23, 42, 0.86); color: #f8fbff; border: 1px solid rgba(148, 163, 184, 0.18); }
  .queue-card .input::placeholder { color: #6f86a6; }
  .queue-card .btn.btn-outline { border-color: rgba(148, 163, 184, 0.2); color: #d8e4f5; background: rgba(15, 23, 42, 0.55); }
  .queue-table-wrap { margin-top: 14px; overflow-x: auto; border-radius: 20px; border: 1px solid rgba(148, 163, 184, 0.12); }
  .queue-card .table { margin: 0; width: 100%; color: #e5eefb; background: transparent; }
  .queue-card .table th { background: rgba(15, 23, 42, 0.96); color: #93a8c6; text-transform: uppercase; letter-spacing: 0.08em; font-size: 11px; border-bottom: 1px solid rgba(148, 163, 184, 0.16); }
  .queue-card .table td, .queue-card .table th { border-color: rgba(148, 163, 184, 0.1); padding: 14px 16px; vertical-align: middle; }
  .queue-card .table tbody tr:nth-child(odd) { background: rgba(15, 23, 42, 0.48); }
  .queue-card .small { color: #8ea3bf; }
  @media (max-width: 760px) { .queue-card { padding: 18px; border-radius: 18px; } .queue-filter { width: 100%; } .queue-filter .input { min-width: 0 !important; width: 100%; } }
</style>

<div class="queue-shell">
<div class="queue-card">
  <div class="queue-header">
    <div>
      <span class="queue-kicker">Voucher Queue</span>
      <h2>Money Release Queue</h2>
    </div>
    <form method="get" class="queue-filter">
      <input class="input" type="text" name="search" placeholder="Search by Reference, Customer No, or Name" value="<?= htmlspecialchars($search) ?>" style="min-width:280px">
      <button class="btn btn-primary" type="submit" style="white-space:nowrap">Search</button>
      <?php if($search): ?>
        <a class="btn btn-outline" href="<?php echo APP_BASE; ?>/staff/release_queue.php">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="queue-table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Reference No</th>
          <th>Customer</th>
          <th>Principal</th>
          <th>Interest Rate</th>
          <th>Total Payable</th>
          <th>Loan Officer</th>
          <th>Approved Date</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($loans as $loan): ?>
          <tr>
            <td><strong><?= htmlspecialchars($loan['reference_no']) ?></strong></td>
            <td>
              <div><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></div>
              <div class="small"><?= htmlspecialchars($loan['customer_no']) ?></div>
            </td>
            <td>₱<?= number_format($loan['principal_amount'], 2) ?></td>
            <td><?= htmlspecialchars($loan['interest_rate'] ?? '—') ?>%</td>
            <td>₱<?= number_format($loan['total_payable'] ?? 0, 2) ?></td>
            <td><?= htmlspecialchars($loan['officer_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($loan['activated_at'] ?? '—') ?></td>
            <td><span class="badge <?= status_badge_class($loan['status']) ?>"><?= htmlspecialchars($loan['status']) ?></span></td>
            <td style="display:flex;gap:8px">
              <?php $loan_id = isset($loan['loan_id']) ? intval($loan['loan_id']) : 0; ?>
              <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/release_voucher.php?id=<?= $loan_id ?>" style="padding:6px 10px;font-size:12px">View Voucher</a>
              <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/release_voucher.php?id=<?= $loan_id ?>&edit=1" style="padding:6px 10px;font-size:12px">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($loans)): ?>
          <tr><td colspan="9" class="small">No approved loans ready for release.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
