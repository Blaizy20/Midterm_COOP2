<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('manage_staff');

$title = "Staff Settings";
$active = "settings";

$err = '';
$ok = '';
$staff_scope_sql = is_system_admin()
  ? "SELECT user_id, full_name, role, is_active, tenant_id FROM users WHERE role IN ('SUPER_ADMIN','ADMIN','MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER') AND user_id=?"
  : "SELECT user_id, full_name, role, is_active, tenant_id FROM users WHERE role IN ('MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER') AND user_id=? AND tenant_id=?";

// Handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
  $user_id = intval($_POST['user_id'] ?? 0);
  $new_role = $_POST['role'] ?? '';
  
  $allowed_roles = is_system_admin()
    ? ['ADMIN', 'MANAGER', 'CREDIT_INVESTIGATOR', 'LOAN_OFFICER', 'CASHIER']
    : ['MANAGER', 'CREDIT_INVESTIGATOR', 'LOAN_OFFICER', 'CASHIER'];
  
  if ($user_id <= 0) {
    $err = "Invalid user.";
  } else if (!in_array($new_role, $allowed_roles, true)) {
    $err = "Invalid role.";
  } else if ($user_id === $_SESSION['user_id']) {
    $err = "Cannot change your own role.";
  } else {
    $staff = fetch_one(q(
      $staff_scope_sql,
      is_system_admin() ? "i" : "ii",
      is_system_admin() ? [$user_id] : [$user_id, require_current_tenant_id()]
    ));
    if (!$staff) {
      $err = "Staff not found.";
    } elseif ($staff['role'] === 'ADMIN' && $new_role !== 'ADMIN') {
      $err = "Owner admins must remain ADMIN accounts.";
    } elseif ($staff['role'] !== 'ADMIN' && $new_role === 'ADMIN') {
      $err = "Use the owner admin creation flow instead of changing a tenant staff role to ADMIN.";
    } else {
      $old_role = $staff['role'];
      q("UPDATE users SET role=? WHERE user_id=?", "si", [$new_role, $user_id]);
      log_activity('Staff Role Changed', 'Staff ' . htmlspecialchars($staff['full_name']) . ' role changed from ' . htmlspecialchars($old_role) . ' to ' . htmlspecialchars($new_role), null, null, null);
      $ok = "Staff role updated successfully.";
    }
  }
}

// Handle activate/deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
  $user_id = intval($_POST['user_id'] ?? 0);
  
  if ($user_id <= 0) {
    $err = "Invalid user.";
  } else if ($user_id === $_SESSION['user_id']) {
    $err = "Cannot deactivate your own account.";
  } else {
    $staff = fetch_one(q(
      $staff_scope_sql,
      is_system_admin() ? "i" : "ii",
      is_system_admin() ? [$user_id] : [$user_id, require_current_tenant_id()]
    ));
    if (!$staff) {
      $err = "Staff not found.";
    } else {
      $new_status = $staff['is_active'] ? 0 : 1;
      $status_text = $new_status ? 'Activated' : 'Deactivated';
      q("UPDATE users SET is_active=? WHERE user_id=?", "ii", [$new_status, $user_id]);
      log_activity('Staff ' . $status_text, 'Staff ' . htmlspecialchars($staff['full_name']) . ' ' . strtolower($status_text), null, null, null);
      $ok = "Staff " . strtolower($status_text) . " successfully.";
    }
  }
}

// Fetch all staff
$tenant_id = $_SESSION['tenant_id'] ?? current_tenant_id();
$staff = is_system_admin()
  ? fetch_all(q("SELECT user_id, username, full_name, role, email, is_active FROM users WHERE role IN ('SUPER_ADMIN','ADMIN','MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER') ORDER BY role DESC, full_name"))
  : fetch_all(q("SELECT user_id, username, full_name, role, email, is_active FROM users WHERE tenant_id=? AND role IN ('MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER') ORDER BY role DESC, full_name", "i", [$tenant_id]));

include __DIR__ . '/_layout_top.php';
?>
<div class="card">
  <h2 style="margin:0 0 10px 0">Staff Settings & Permissions</h2>
  <div class="small">Manage staff roles, permissions, and account status.</div>

  <?php if ($err): ?><div class="alert red" style="margin-top:12px"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert green" style="margin-top:12px"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

  <!-- Staff Management Table -->
  <div style="margin-top:20px;border-bottom:1px solid #ddd;padding-bottom:12px">
    <h3 style="margin:0 0 10px 0">Staff Members</h3>
  </div>

  <div style="overflow:auto;margin-top:15px">
    <table class="table">
      <thead>
        <tr>
          <th>Username</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($staff)): ?>
          <?php foreach ($staff as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['username']) ?></td>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td><?= htmlspecialchars($s['email'] ?? '-') ?></td>
              <td>
                <span class="badge blue"><?= htmlspecialchars($s['role']) ?></span>
              </td>
              <td>
                <span class="badge <?= $s['is_active'] ? 'green' : 'gray' ?>">
                  <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <?php if ($s['user_id'] !== $_SESSION['user_id']): ?>
                    <a class="btn btn-primary" href="#" onclick="return editRole(<?= intval($s['user_id']) ?>, '<?= htmlspecialchars($s['full_name']) ?>', '<?= htmlspecialchars($s['role']) ?>');" style="font-size:12px;padding:6px 10px">Change Role</a>
                    <form style="display:inline" method="post" onsubmit="return confirm('<?= $s['is_active'] ? 'Deactivate' : 'Activate' ?> this staff account?');">
                      <input type="hidden" name="user_id" value="<?= intval($s['user_id']) ?>">
                      <button class="btn btn-primary" type="submit" name="toggle_active" value="1" style="font-size:12px;padding:6px 10px">
                        <?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <div class="small" style="color:#999">(Current User)</div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6" class="small" style="text-align:center;padding:20px">No staff members found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Role Description -->
  <div style="margin-top:30px;border-bottom:1px solid #ddd;padding-bottom:12px">
    <h3 style="margin:0 0 10px 0">Staff Roles & Permissions</h3>
  </div>

  <div style="margin-top:15px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:15px">
    <div style="border:1px solid #ddd;border-radius:4px;padding:15px">
        <h4 style="margin:0 0 10px 0;color:#2c3ec5">ADMIN</h4>
      <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">
        <li>Owner admin access only for assigned tenants</li>
        <li>Create and manage tenant staff</li>
        <li>Access tenant loans, payments, reports, and settings</li>
        <li>Switch among owned tenants only</li>
        <li>Cannot create tenants</li>
      </ul>
    </div>

    <div style="border:1px solid #ddd;border-radius:4px;padding:15px">
      <h4 style="margin:0 0 10px 0;color:#2c3ec5">MANAGER</h4>
      <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">
        <li>Approve/deny loan applications</li>
        <li>View system settings</li>
        <li>Monitor staff activity</li>
        <li>View reports</li>
        <li>Process money release</li>
      </ul>
    </div>

    <div style="border:1px solid #ddd;border-radius:4px;padding:15px">
      <h4 style="margin:0 0 10px 0;color:#2c3ec5">CREDIT INVESTIGATOR</h4>
      <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">
        <li>Review loan requirements</li>
        <li>Validate customer info</li>
        <li>Submit CI review</li>
        <li>View assigned loans</li>
        <li>Download requirements</li>
      </ul>
    </div>

    <div style="border:1px solid #ddd;border-radius:4px;padding:15px">
      <h4 style="margin:0 0 10px 0;color:#2c3ec5">LOAN OFFICER</h4>
      <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">
        <li>Manage active loans</li>
        <li>Record payments</li>
        <li>View loan history</li>
        <li>Generate receipts</li>
        <li>Contact customers</li>
      </ul>
    </div>

    <div style="border:1px solid #ddd;border-radius:4px;padding:15px">
      <h4 style="margin:0 0 10px 0;color:#2c3ec5">CASHIER</h4>
      <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">
        <li>Record & process payments</li>
        <li>View payment history</li>
        <li>Generate receipts</li>
        <li>View customer accounts</li>
        <li>Reconcile payments</li>
      </ul>
    </div>
  </div>
</div>

<!-- Edit Role Modal -->
<div id="editRoleModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="max-width:400px;width:90%">
    <h3 style="margin-top:0">Change Staff Role</h3>
    <div id="roleStaffName" style="margin-bottom:15px;font-weight:500"></div>
    <form method="post">
      <input type="hidden" id="edit_user_id" name="user_id">
      <div>
        <label class="label">Select New Role</label>
        <select class="input" id="edit_role" name="role" required>
          <option value="">-- Select Role --</option>
          <?php if (is_system_admin()): ?>
            <option value="ADMIN">Admin</option>
          <?php endif; ?>
          <option value="MANAGER">Manager</option>
          <option value="CREDIT_INVESTIGATOR">Credit Investigator</option>
          <option value="LOAN_OFFICER">Loan Officer</option>
          <option value="CASHIER">Cashier</option>
        </select>
      </div>
      <div style="margin-top:15px;display:flex;gap:10px">
        <button class="btn btn-primary" type="submit" name="update_role" value="1">Update Role</button>
        <button class="btn btn-outline" type="button" onclick="closeEditRole()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function editRole(userId, fullName, currentRole) {
  document.getElementById('edit_user_id').value = userId;
  document.getElementById('roleStaffName').textContent = 'Staff: ' + fullName;
  document.getElementById('edit_role').value = currentRole;
  document.getElementById('editRoleModal').style.display = 'flex';
  return false;
}

function closeEditRole() {
  document.getElementById('editRoleModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('editRoleModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditRole();
});
</script>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
