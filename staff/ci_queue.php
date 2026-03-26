<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_permission('review_applications');

$rows = fetch_all(q("SELECT l.loan_id, l.reference_no, l.submitted_at, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name
  FROM loans l JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
  WHERE " . tenant_condition('l.tenant_id') . " AND l.status='PENDING' ORDER BY l.submitted_at ASC", tenant_types(), tenant_params()));

 $title="CI Queue"; $active="ci";
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
  .ci-shell { display: grid; gap: 20px; color: #e5eefb; }
  .ci-card { border-radius: 26px; border: 1px solid rgba(148, 163, 184, 0.16); background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%), linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97)); box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34); padding: 24px; }
  .ci-kicker { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; border: 1px solid rgba(125, 211, 252, 0.24); background: rgba(14, 165, 233, 0.12); color: #d8f4ff; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; }
  .ci-card h2 { margin: 12px 0 0; font-size: clamp(30px, 4vw, 44px); line-height: 1; letter-spacing: -0.04em; color: #f8fbff; }
  .ci-card .small { color: #8ea3bf; }
  .ci-table-wrap { margin-top: 16px; overflow-x: auto; border-radius: 20px; border: 1px solid rgba(148, 163, 184, 0.12); }
  .ci-card .table { margin: 0; width: 100%; color: #e5eefb; background: transparent; }
  .ci-card .table th { background: rgba(15, 23, 42, 0.96); color: #93a8c6; text-transform: uppercase; letter-spacing: 0.08em; font-size: 11px; border-bottom: 1px solid rgba(148, 163, 184, 0.16); }
  .ci-card .table td, .ci-card .table th { border-color: rgba(148, 163, 184, 0.1); padding: 14px 16px; vertical-align: middle; }
  .ci-card .table tbody tr:nth-child(odd) { background: rgba(15, 23, 42, 0.48); }
  .ci-card .btn.btn-outline { border-color: rgba(148, 163, 184, 0.2); color: #d8e4f5; background: rgba(15, 23, 42, 0.55); }
  @media (max-width: 760px) { .ci-card { padding: 18px; border-radius: 18px; } }
</style>

<div class="ci-shell">
<div class="ci-card">
  <span class="ci-kicker">Credit Investigation</span>
  <h2>CI Review Queue</h2>
  <div class="small">Review client requirements and mark as CI Reviewed.</div>
  <div class="ci-table-wrap">
  <table class="table">
    <thead><tr><th>Reference</th><th>Customer</th><th>Submitted</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['reference_no']) ?></td>
          <td><?= htmlspecialchars($r['customer_name']) ?> <span class="small">(<?= htmlspecialchars($r['customer_no']) ?>)</span></td>
          <td><?= htmlspecialchars($r['submitted_at']) ?></td>
          <td><a class="btn btn-outline" href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($r['loan_id']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($rows)): ?><tr><td colspan="4" class="small">No pending applications.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
</div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
