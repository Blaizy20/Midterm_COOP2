<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_history');

$title = "Activity History";
$active = "history";

// Get filter and category parameters
$filter_action = $_GET['action'] ?? '';
$filter_role = $_GET['role'] ?? '';
$filter_from = $_GET['from'] ?? '';
$filter_to = $_GET['to'] ?? '';
$category = $_GET['category'] ?? 'all';

// Define action categories - Maps to ACTUAL logged action values in database
$action_categories = [
  'loan_payment' => [
    'Loan Approved', 'Loan Denied', 'CI Review',
    'Payment Recorded', 'Payment Updated',
    'Interest Rate Updated', 'Payment Term Updated',
    'Loan Officer Assigned',
    'Money Release Voucher Created', 'Money Release Voucher Updated',
    // Also support uppercase variants from demo data
    'LOAN_CREATED', 'LOAN_APPROVED', 'LOAN_DENIED',
    'PAYMENT_RECORDED', 'PAYMENT_UPDATED', 'PAYMENT_DELETED',
    'INTEREST_RATE_UPDATED', 'PAYMENT_TERM_UPDATED',
    'CI_REVIEW', 'LOAN_OFFICER_ASSIGNED',
    'MONEY_RELEASE_VOUCHER_CREATED', 'MONEY_RELEASE_VOUCHER_UPDATED'
  ],
  'customer' => [
    'Customer Registered', 'Customer Updated', 'Customer Activated', 'Customer Deactivated', 'Customer Permanently Deleted',
    // Also support uppercase variants
    'CUSTOMER_REGISTERED', 'CUSTOMER_UPDATED', 'CUSTOMER_DELETED'
  ],
  'staff' => [
    'STAFF_CREATED', 'STAFF_UPDATED', 'STAFF_DELETED',
    'Staff Role Changed',
    'USER_CREATED', 'USER_UPDATED', 'USER_DELETED'
  ],
  'authentication' => [
    'USER_LOGIN', 'USER_LOGOUT', 'LOGIN', 'LOGOUT'
  ],
  'tenant' => [
    'TENANT_CREATED', 'TENANT_UPDATED', 'TENANT_APPROVED', 'TENANT_ACTIVATED', 'TENANT_DEACTIVATED', 'TENANT_REJECTED', 'TENANT_SUSPENDED'
  ]
];

// Helper function to get logs by category using prepared statements
function get_logs_by_category($category, $action_categories, $filter_action, $filter_role, $filter_from, $filter_to) {
  $where = [];
  $types = '';
  $params = [];

  $sql = "SELECT al.*, u.full_name AS user_name FROM activity_logs al 
          LEFT JOIN users u ON u.user_id = al.user_id";

  // CRITICAL: Add tenant filter for non-SUPER_ADMIN users
  // Include logs with matching tenant_id OR logs where tenant_id is NULL/0 (backward compatibility)
  if (!is_system_admin()) {
    $tenant_id = current_tenant_id();
    $where[] = "(al.tenant_id = ? OR al.tenant_id IS NULL OR al.tenant_id = 0)";
    $types .= "i";
    $params[] = $tenant_id;
  }

  // Add category filter - CRITICAL: Use category array for filtering
  if ($category !== 'all' && isset($action_categories[$category])) {
    $actions = $action_categories[$category];
    if (!empty($actions)) {
      $placeholders = implode(',', array_fill(0, count($actions), '?'));
      $where[] = "al.action IN ($placeholders)";
      // Add type for each action parameter
      $types .= str_repeat('s', count($actions));
      // Add action parameters to the params array
      $params = array_merge($params, $actions);
    }
  }

  // Add custom action filter (if specified via form)
  if ($filter_action !== '') {
    $where[] = "al.action = ?";
    $types .= "s";
    $params[] = $filter_action;
  }

  // Add role filter
  if ($filter_role !== '') {
    $where[] = "al.user_role = ?";
    $types .= "s";
    $params[] = $filter_role;
  }

  // Add date range filters
  if ($filter_from !== '') {
    $where[] = "DATE(al.created_at) >= ?";
    $types .= "s";
    $params[] = $filter_from;
  }
  if ($filter_to !== '') {
    $where[] = "DATE(al.created_at) <= ?";
    $types .= "s";
    $params[] = $filter_to;
  }

  if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY al.created_at DESC LIMIT 500";

  return fetch_all(q($sql, $types, $params));
}

// Get logs based on category
$logs = get_logs_by_category($category, $action_categories, $filter_action, $filter_role, $filter_from, $filter_to);

include __DIR__ . '/_layout_top.php';
?>

<style>
  body {
    background:
      radial-gradient(circle at top, rgba(14, 165, 233, 0.12), transparent 30%),
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

  .layout,
  .main {
    background: transparent;
  }

  .sidebar {
    background: rgba(4, 10, 24, 0.84);
    border-right: 1px solid rgba(148, 163, 184, 0.12);
    backdrop-filter: blur(16px);
  }

  .sidebar h3 { color: #7f93b0; }
  .sidebar a { color: #d7e3f4; }
  .sidebar a.active,
  .sidebar a:hover {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(59, 130, 246, 0.2));
    color: #f8fbff;
  }

  .history-card {
    border-radius: 26px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background:
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%),
      linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97));
    box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34);
    padding: 24px;
  }

  .category-tabs {
    display: flex;
    gap: 10px;
    margin-top: 15px;
    margin-bottom: 15px;
    flex-wrap: wrap;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
  }

  .category-tab {
    padding: 10px 15px;
    background: none;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: #8ea3bf;
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
  }

  .category-tab:hover {
    color: #7dd3fc;
  }

  .category-tab.active {
    color: #7dd3fc;
    border-bottom-color: #38bdf8;
  }

  .history-card .small {
    color: #8ea3bf;
  }

  .history-card .label {
    color: #93a8c6;
  }

  .history-card .input {
    background: rgba(15, 23, 42, 0.86);
    color: #f8fbff;
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .history-card .input::placeholder {
    color: #6f86a6;
  }

  .history-card .btn.btn-ghost,
  .history-card .btn.btn-outline {
    border-color: rgba(148, 163, 184, 0.2);
    color: #d8e4f5;
    background: rgba(15, 23, 42, 0.55);
  }

  .history-filter-form {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    align-items: end;
  }

  .history-filter-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }

  .history-category-label {
    margin-top: 20px;
    padding: 12px 14px;
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: 14px;
    font-weight: bold;
    color: #7dd3fc;
  }

  .history-table-wrap {
    overflow: auto;
    margin-top: 14px;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.12);
  }

  .history-card .table {
    color: #e5eefb;
    background: transparent;
  }

  .history-card .table th {
    background: rgba(15, 23, 42, 0.96);
    color: #93a8c6;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 11px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }

  .history-card .table td,
  .history-card .table th {
    border-color: rgba(148, 163, 184, 0.1);
    padding: 14px 16px;
    vertical-align: top;
  }

  .history-card .table tbody tr:nth-child(odd) {
    background: rgba(15, 23, 42, 0.48);
  }

  .history-card .table a {
    color: #7dd3fc;
  }

  @media (max-width: 760px) {
    .history-card {
      padding: 18px;
      border-radius: 18px;
    }

    .history-filter-form {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="history-card">
  <h2 style="margin:0 0 10px 0">Activity History</h2>
  <div class="small">Audit log of all system activities organized by category.</div>

  <!-- Category Tabs -->
  <div class="category-tabs">
    <a href="<?php echo APP_BASE; ?>/staff/history.php?category=all<?= $filter_action ? '&action='.urlencode($filter_action) : '' ?><?= $filter_role ? '&role='.urlencode($filter_role) : '' ?><?= $filter_from ? '&from='.urlencode($filter_from) : '' ?><?= $filter_to ? '&to='.urlencode($filter_to) : '' ?>" 
       class="category-tab <?= ($category === 'all') ? 'active' : '' ?>">
      All Logs
    </a>
    <a href="<?php echo APP_BASE; ?>/staff/history.php?category=loan_payment<?= $filter_role ? '&role='.urlencode($filter_role) : '' ?><?= $filter_from ? '&from='.urlencode($filter_from) : '' ?><?= $filter_to ? '&to='.urlencode($filter_to) : '' ?>" 
       class="category-tab <?= ($category === 'loan_payment') ? 'active' : '' ?>">
      Loan & Payment
    </a>
    <a href="<?php echo APP_BASE; ?>/staff/history.php?category=customer<?= $filter_role ? '&role='.urlencode($filter_role) : '' ?><?= $filter_from ? '&from='.urlencode($filter_from) : '' ?><?= $filter_to ? '&to='.urlencode($filter_to) : '' ?>" 
       class="category-tab <?= ($category === 'customer') ? 'active' : '' ?>">
      Customer
    </a>
    <a href="<?php echo APP_BASE; ?>/staff/history.php?category=staff<?= $filter_role ? '&role='.urlencode($filter_role) : '' ?><?= $filter_from ? '&from='.urlencode($filter_from) : '' ?><?= $filter_to ? '&to='.urlencode($filter_to) : '' ?>" 
       class="category-tab <?= ($category === 'staff') ? 'active' : '' ?>">
      Staff/Admin
    </a>
    <a href="<?php echo APP_BASE; ?>/staff/history.php?category=authentication<?= $filter_role ? '&role='.urlencode($filter_role) : '' ?><?= $filter_from ? '&from='.urlencode($filter_from) : '' ?><?= $filter_to ? '&to='.urlencode($filter_to) : '' ?>" 
       class="category-tab <?= ($category === 'authentication') ? 'active' : '' ?>">
      Authentication
    </a>
    <a href="<?php echo APP_BASE; ?>/staff/history.php?category=tenant<?= $filter_role ? '&role='.urlencode($filter_role) : '' ?><?= $filter_from ? '&from='.urlencode($filter_from) : '' ?><?= $filter_to ? '&to='.urlencode($filter_to) : '' ?>" 
       class="category-tab <?= ($category === 'tenant') ? 'active' : '' ?>">
      Tenant Changes
    </a>
  </div>

  <!-- Filters -->
  <form method="get" class="history-filter-form">
    <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
    <div>
      <label class="label">Action Type</label>
      <select class="input" name="action">
        <option value="">All Actions</option>
        <!-- Loan & Payment Actions -->
        <optgroup label="Loan & Payment">
          <option value="Loan Approved" <?= $filter_action === 'Loan Approved' ? 'selected' : '' ?>>Loan Approved</option>
          <option value="Loan Denied" <?= $filter_action === 'Loan Denied' ? 'selected' : '' ?>>Loan Denied</option>
          <option value="CI Review" <?= $filter_action === 'CI Review' ? 'selected' : '' ?>>CI Review</option>
          <option value="Payment Recorded" <?= $filter_action === 'Payment Recorded' ? 'selected' : '' ?>>Payment Recorded</option>
          <option value="Payment Updated" <?= $filter_action === 'Payment Updated' ? 'selected' : '' ?>>Payment Updated</option>
          <option value="Interest Rate Updated" <?= $filter_action === 'Interest Rate Updated' ? 'selected' : '' ?>>Interest Rate Updated</option>
          <option value="Payment Term Updated" <?= $filter_action === 'Payment Term Updated' ? 'selected' : '' ?>>Payment Term Updated</option>
          <option value="Loan Officer Assigned" <?= $filter_action === 'Loan Officer Assigned' ? 'selected' : '' ?>>Loan Officer Assigned</option>
          <option value="Money Release Voucher Created" <?= $filter_action === 'Money Release Voucher Created' ? 'selected' : '' ?>>Money Release Voucher Created</option>
          <option value="Money Release Voucher Updated" <?= $filter_action === 'Money Release Voucher Updated' ? 'selected' : '' ?>>Money Release Voucher Updated</option>
        </optgroup>
        <!-- Customer Actions -->
        <optgroup label="Customer">
          <option value="Customer Registered" <?= $filter_action === 'Customer Registered' ? 'selected' : '' ?>>Customer Registered</option>
          <option value="Customer Updated" <?= $filter_action === 'Customer Updated' ? 'selected' : '' ?>>Customer Updated</option>
          <option value="Customer Activated" <?= $filter_action === 'Customer Activated' ? 'selected' : '' ?>>Customer Activated</option>
          <option value="Customer Deactivated" <?= $filter_action === 'Customer Deactivated' ? 'selected' : '' ?>>Customer Deactivated</option>
          <option value="Customer Permanently Deleted" <?= $filter_action === 'Customer Permanently Deleted' ? 'selected' : '' ?>>Customer Permanently Deleted</option>
        </optgroup>
        <!-- Staff/Admin Actions -->
        <optgroup label="Staff/Admin">
          <option value="STAFF_CREATED" <?= $filter_action === 'STAFF_CREATED' ? 'selected' : '' ?>>Staff Created</option>
          <option value="STAFF_DELETED" <?= $filter_action === 'STAFF_DELETED' ? 'selected' : '' ?>>Staff Deleted</option>
          <option value="Staff Role Changed" <?= $filter_action === 'Staff Role Changed' ? 'selected' : '' ?>>Staff Role Changed</option>
        </optgroup>
        <!-- Authentication Actions -->
        <optgroup label="Authentication">
          <option value="USER_LOGIN" <?= $filter_action === 'USER_LOGIN' ? 'selected' : '' ?>>User Login</option>
          <option value="USER_LOGOUT" <?= $filter_action === 'USER_LOGOUT' ? 'selected' : '' ?>>User Logout</option>
        </optgroup>
        <!-- Tenant Actions -->
        <optgroup label="Tenant">
          <option value="TENANT_CREATED" <?= $filter_action === 'TENANT_CREATED' ? 'selected' : '' ?>>Tenant Created</option>
          <option value="TENANT_UPDATED" <?= $filter_action === 'TENANT_UPDATED' ? 'selected' : '' ?>>Tenant Updated</option>
          <option value="TENANT_APPROVED" <?= $filter_action === 'TENANT_APPROVED' ? 'selected' : '' ?>>Tenant Approved</option>
          <option value="TENANT_ACTIVATED" <?= $filter_action === 'TENANT_ACTIVATED' ? 'selected' : '' ?>>Tenant Activated</option>
          <option value="TENANT_DEACTIVATED" <?= $filter_action === 'TENANT_DEACTIVATED' ? 'selected' : '' ?>>Tenant Deactivated</option>
          <option value="TENANT_REJECTED" <?= $filter_action === 'TENANT_REJECTED' ? 'selected' : '' ?>>Tenant Rejected</option>
          <option value="TENANT_SUSPENDED" <?= $filter_action === 'TENANT_SUSPENDED' ? 'selected' : '' ?>>Tenant Suspended</option>
        </optgroup>
      </select>
    </div>
    <div>
      <label class="label">User Role</label>
      <select class="input" name="role">
        <option value="">All</option>
        <?php
          $roles = ['SUPER_ADMIN','ADMIN','TENANT','MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER'];
          foreach ($roles as $r) {
            $sel = ($filter_role === $r) ? 'selected' : '';
            echo '<option '.$sel.' value="'.htmlspecialchars($r).'">'.htmlspecialchars($r).'</option>';
          }
        ?>
      </select>
    </div>
    <div>
      <label class="label">From</label>
      <input class="input" type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>">
    </div>
    <div>
      <label class="label">To</label>
      <input class="input" type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>">
    </div>
    <div class="history-filter-actions">
      <button class="btn btn-primary" type="submit">Filter</button>
      <a class="btn btn-ghost" href="<?php echo APP_BASE; ?>/staff/history.php">Reset</a>
    </div>
  </form>

  <!-- Category Label -->
  <div class="history-category-label">
    <?php
      $category_labels = [
        'all' => 'All Activity Logs',
        'loan_payment' => 'Loan & Payment Actions',
        'customer' => 'Customer Management',
        'staff' => 'Staff/Admin Management',
        'authentication' => 'Authentication Logs',
        'tenant' => 'Tenant-related Changes'
      ];
      echo htmlspecialchars($category_labels[$category] ?? 'All Logs');
    ?>
  </div>

  <!-- Logs Table -->
  <div class="history-table-wrap">
    <table class="table" style="font-size:13px">
      <thead>
        <tr>
          <th>Date & Time</th>
          <th>User</th>
          <th>Role</th>
          <th>Action</th>
          <th>Reference</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= htmlspecialchars($log['created_at']) ?></td>
            <td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
            <td><span class="badge gray"><?= htmlspecialchars($log['user_role']) ?></span></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
            <td><?= $log['reference_no'] ? '<a href="'.APP_BASE.'/staff/loan_view.php?id='.intval($log['loan_id']).'">'.htmlspecialchars($log['reference_no']).'</a>' : '—' ?></td>
            <td style="max-width:300px;word-break:break-word"><?= htmlspecialchars($log['description']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
          <tr><td colspan="6" class="small" style="text-align:center;padding:20px">No activity logs found in this category.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>

