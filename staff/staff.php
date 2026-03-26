<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_permission('view_staff');

$filter_role = $_GET['role'] ?? '';
$search = $_GET['search'] ?? '';

// Build SQL query.
$sql = "SELECT user_id, username, full_name, role, contact_no, email, created_at FROM users WHERE role <> 'CUSTOMER' AND is_active=1";
if (!is_system_admin()) {
  $sql .= " AND tenant_id=?";
}

$types = '';
$params = [];

// Add filter by role
if ($filter_role) {
  $sql .= " AND role = ?";
  $types .= 's';
  $params[] = $filter_role;
}

// Add search filter
if ($search) {
  $sql .= " AND (full_name LIKE ? OR username LIKE ? OR contact_no LIKE ? OR email LIKE ?)";
  $types .= 'ssss';
  $search_term = '%' . $search . '%';
  $params[] = $search_term;
  $params[] = $search_term;
  $params[] = $search_term;
  $params[] = $search_term;
}

// Add tenant_id parameter if needed
if (!is_system_admin()) {
  array_unshift($params, current_tenant_id());
  $types = 'i' . $types;
}

$sql .= " ORDER BY full_name ASC";

$rows = fetch_all(q($sql, $types, $params));
$title="Staff Management"; $active="staff";
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
  .staff-shell { display: grid; gap: 20px; color: #e5eefb; }
  .staff-card { border-radius: 26px; border: 1px solid rgba(148, 163, 184, 0.16); background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%), linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97)); box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34); padding: 24px; }
  .staff-kicker { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; border: 1px solid rgba(125, 211, 252, 0.24); background: rgba(14, 165, 233, 0.12); color: #d8f4ff; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; }
  .staff-card h2 { margin: 12px 0 0; font-size: clamp(30px, 4vw, 44px); line-height: 1; letter-spacing: -0.04em; color: #f8fbff; }
  .staff-filters { display: grid; grid-template-columns: minmax(180px, 220px) minmax(0, 1fr); gap: 14px; margin: 18px 0 14px; align-items: end; }
  .staff-field .label { margin-bottom: 6px; color: #93a8c6; }
  .staff-card .input { background: rgba(15, 23, 42, 0.86); color: #f8fbff; border: 1px solid rgba(148, 163, 184, 0.18); }
  .staff-card .input::placeholder { color: #6f86a6; }
  .staff-card .btn.btn-outline { border-color: rgba(148, 163, 184, 0.2); color: #d8e4f5; background: rgba(15, 23, 42, 0.55); }
  .staff-search-row { display: flex; gap: 8px; }
  .staff-table-wrap { overflow: auto; margin-top: 14px; border-radius: 20px; border: 1px solid rgba(148, 163, 184, 0.12); }
  .staff-card .table { margin: 0; width: 100%; color: #e5eefb; background: transparent; }
  .staff-card .table th { background: rgba(15, 23, 42, 0.96); color: #93a8c6; text-transform: uppercase; letter-spacing: 0.08em; font-size: 11px; border-bottom: 1px solid rgba(148, 163, 184, 0.16); }
  .staff-card .table td, .staff-card .table th { border-color: rgba(148, 163, 184, 0.1); padding: 14px 16px; vertical-align: middle; }
  .staff-card .table tbody tr:nth-child(odd) { background: rgba(15, 23, 42, 0.48); }
  .staff-card .small { color: #8ea3bf; }
  @media (max-width: 860px) { .staff-filters { grid-template-columns: 1fr; } .staff-search-row { flex-wrap: wrap; } }
  @media (max-width: 760px) { .staff-card { padding: 18px; border-radius: 18px; } }
</style>

<div class="staff-shell">
<div class="staff-card">
  <span class="staff-kicker">Staff Directory</span>
  <h2>Staff Members</h2>
  
  <div class="staff-filters">
    <div class="staff-field">
      <label class="label" style="margin-bottom:6px">Filter by Role</label>
      <select class="input" onchange="location.href='?role='+this.value+'&search='+document.getElementById('search_box').value" style="min-width:150px">
        <option value="">All Roles</option>
        <?php if (is_system_admin()): ?>
          <option value="SUPER_ADMIN" <?= $filter_role==='SUPER_ADMIN'?'selected':'' ?>>Super Admin</option>
          <option value="ADMIN" <?= $filter_role==='ADMIN'?'selected':'' ?>>Admin</option>
        <?php endif; ?>
        <option value="MANAGER" <?= $filter_role==='MANAGER'?'selected':'' ?>>Manager</option>
        <option value="CREDIT_INVESTIGATOR" <?= $filter_role==='CREDIT_INVESTIGATOR'?'selected':'' ?>>Credit Investigator</option>
        <option value="LOAN_OFFICER" <?= $filter_role==='LOAN_OFFICER'?'selected':'' ?>>Loan Officer</option>
        <option value="CASHIER" <?= $filter_role==='CASHIER'?'selected':'' ?>>Cashier</option>
      </select>
    </div>
    
    <div class="staff-field">
      <label class="label" style="margin-bottom:6px">Search (Name, Username, Contact, Email)</label>
      <div class="staff-search-row">
        <input class="input" id="search_box" type="text" placeholder="Search..." value="<?= htmlspecialchars($search) ?>" style="flex:1">
        <button class="btn btn-primary" onclick="location.href='?role='+document.querySelector('select').value+'&search='+document.getElementById('search_box').value">Search</button>
        <a class="btn btn-outline" href="?" style="text-decoration:none">Clear</a>
      </div>
    </div>
  </div>

  <div class="staff-table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Username</th>
          <th>Name</th>
          <th>Role</th>
          <th>Contact</th>
          <th>Email</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['username']) ?></td>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td><span class="badge green"><?= htmlspecialchars(str_replace('_',' ', $r['role'])) ?></span></td>
            <td><?= htmlspecialchars($r['contact_no'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($rows)): ?><tr><td colspan="6" class="small">No staff found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
