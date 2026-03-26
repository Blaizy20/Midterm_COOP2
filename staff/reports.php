<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_reports');

$title = "Reports";
$active = "rep";
$is_system_admin = is_system_admin();
$is_admin_owner = is_admin_owner();
$can_view_advanced_reports = can_access('view_advanced_reports');
$advanced_report_types = ['tenant_activity', 'user_registrations', 'usage_statistics'];
$valid_report_types = array_merge(['loans'], $advanced_report_types);

// Handle interest rate update
$update_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_interest'])) {
  require_permission('update_loan_terms');
  $loan_id = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
  $interest_rate = isset($_POST['interest_rate']) ? (float)$_POST['interest_rate'] : 0;
  
  if ($loan_id > 0 && $interest_rate > 0) {
    try {
      enforce_tenant_resource_access('loans', 'loan_id', $loan_id);
      $current_loan = fetch_one(q("SELECT interest_rate, reference_no, customer_id FROM loans WHERE " . tenant_condition('tenant_id') . " AND loan_id = ?", tenant_types("i"), tenant_params([$loan_id])));
      if ($current_loan) {
        if (is_system_admin()) {
          q("UPDATE loans SET interest_rate = ?, payment_term = NULL WHERE loan_id = ?", "di", [$interest_rate, $loan_id]);
        } else {
          q("UPDATE loans SET interest_rate = ?, payment_term = NULL WHERE tenant_id=? AND loan_id = ?", "dii", [$interest_rate, require_current_tenant_id(), $loan_id]);
        }
        log_activity('Interest Rate Updated', 'Interest rate changed to ' . number_format($interest_rate, 2) . '%', $loan_id, $current_loan['customer_id'], $current_loan['reference_no']);
        recalc_loan($loan_id);
        header("Location: " . APP_BASE . "/staff/reports.php?status=" . urlencode($status) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&method=" . urlencode($method) . "&officer_id=" . urlencode($officer_id));
        exit;
      }
    } catch (Exception $e) {
      $update_msg = '<div class="alert red">Update failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
  }
}

$report_type = $_GET['report_type'] ?? 'loans';
$status = $_GET['status'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$method = $_GET['method'] ?? '';
$officer_id = $_GET['officer_id'] ?? '';
$tenant_id_filter = intval($_GET['tenant_id'] ?? 0);

// Validate report type
if (!in_array($report_type, $valid_report_types)) {
  $report_type = 'loans';
}
if (in_array($report_type, $advanced_report_types, true) && !$can_view_advanced_reports) {
  $report_type = 'loans';
}

$available_tenants = [];
if ($is_system_admin) {
  $available_tenants = fetch_all(q(
    "SELECT tenant_id, COALESCE(display_name, tenant_name) AS tenant_name
     FROM tenants
     WHERE is_active = 1
     ORDER BY COALESCE(display_name, tenant_name)"
  )) ?: [];
} elseif ($is_admin_owner) {
  foreach (user_owned_tenants($_SESSION['user_id'] ?? 0, true) as $tenant) {
    $available_tenants[] = [
      'tenant_id' => intval($tenant['tenant_id']),
      'tenant_name' => $tenant['display_name'] ?: $tenant['tenant_name'],
    ];
  }
}

$available_tenant_ids = array_map(static function ($tenant) {
  return intval($tenant['tenant_id']);
}, $available_tenants);

if ($tenant_id_filter > 0 && !in_array($tenant_id_filter, $available_tenant_ids, true)) {
  $tenant_id_filter = 0;
}

$report_tenant_id = 0;
if ($tenant_id_filter > 0) {
  $report_tenant_id = $tenant_id_filter;
} elseif (!$is_system_admin) {
  $report_tenant_id = intval(current_tenant_id() ?? 0);
  if ($report_tenant_id <= 0 && count($available_tenant_ids) === 1) {
    $report_tenant_id = $available_tenant_ids[0];
  }
}

if (!$is_system_admin && $tenant_id_filter <= 0 && $report_tenant_id > 0) {
  $tenant_id_filter = $report_tenant_id;
}

$build_tenant_scope = function ($column) use ($is_system_admin, $report_tenant_id) {
  if ($is_system_admin && $report_tenant_id <= 0) {
    return ['1=1', '', []];
  }

  if ($report_tenant_id > 0) {
    return ["{$column} = ?", 'i', [$report_tenant_id]];
  }

  return ['1=0', '', []];
};

$append_scope = function (&$where, &$types, &$params, $column) use ($build_tenant_scope) {
  [$scope_sql, $scope_types, $scope_params] = $build_tenant_scope($column);
  $where[] = $scope_sql;
  $types .= $scope_types;
  foreach ($scope_params as $scope_param) {
    $params[] = $scope_param;
  }
};

$format_money = static function ($amount) {
  return 'PHP ' . number_format((float)$amount, 2);
};

// Fetch all loan officers
$officer_where = ["role='LOAN_OFFICER'", "is_active=1"];
$officer_types = '';
$officer_params = [];
$append_scope($officer_where, $officer_types, $officer_params, 'tenant_id');
$loan_officers = fetch_all(q(
  "SELECT user_id, full_name FROM users WHERE " . implode(' AND ', $officer_where) . " ORDER BY full_name",
  $officer_types,
  $officer_params
)) ?: [];

$where = [];
$types = '';
$params = [];
$sql = '';
$summary_cards = [];
$report_title = 'Loan Transactions';
$report_description = 'Printable summary of loans and collections (payments).';
$show_tenant_filter = !empty($available_tenants);
$show_tenant_column = false;

// Build query based on report type
if ($report_type === 'loans') {
  // Existing Loan Transactions Report
  $report_title = 'Loan Transactions';
  $report_description = 'Loan-level balances, payment history, and officer activity.';
  $sql = "SELECT 
            l.loan_id,
            l.tenant_id,
            l.reference_no,
            COALESCE(t.display_name, t.tenant_name) AS tenant_name,
            l.status,
            l.principal_amount,
            l.interest_rate,
            l.payment_term,
            l.total_payable,
            l.remaining_balance,
            l.due_date,
            l.submitted_at,
            c.customer_no,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name,
            u.full_name AS officer_name,
            MAX(p.method) AS method
          FROM loans l
          JOIN tenants t ON t.tenant_id = l.tenant_id
          JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id = l.tenant_id
          LEFT JOIN users u ON u.user_id = l.loan_officer_id AND u.tenant_id = l.tenant_id
          LEFT JOIN payments p ON p.loan_id = l.loan_id AND p.tenant_id = l.tenant_id";

  $append_scope($where, $types, $params, 'l.tenant_id');

  if ($status !== '') { $where[] = "l.status = ?"; $types .= "s"; $params[] = trim($status); }
  if ($from !== '') { $where[] = "DATE(l.submitted_at) >= ?"; $types .= "s"; $params[] = $from; }
  if ($to !== '') { $where[] = "DATE(l.submitted_at) <= ?"; $types .= "s"; $params[] = $to; }
  if ($method !== '' && in_array($method, ['CASH','GCASH','BANK','CHEQUE'], true)) { $where[] = "p.method = ?"; $types .= "s"; $params[] = $method; }
  if ($officer_id !== '') { $where[] = "u.user_id = ?"; $types .= "i"; $params[] = intval($officer_id); }

  if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " GROUP BY l.loan_id, l.tenant_id, l.reference_no, COALESCE(t.display_name, t.tenant_name), l.status, l.principal_amount, l.interest_rate, l.payment_term, l.total_payable, l.remaining_balance, l.due_date, l.submitted_at, c.customer_no, CONCAT(c.first_name,' ',c.last_name), u.full_name";
  $sql .= " ORDER BY l.submitted_at DESC";
  $show_tenant_column = $is_system_admin && $report_tenant_id <= 0;
  
} elseif ($report_type === 'tenant_activity') {
  // Tenant Activity Report
  $report_title = 'Tenant Activity';
  $report_description = 'Tenant-level activity, loan volume, payments, and staffing.';
  $sql = "SELECT 
            t.tenant_id,
            COALESCE(t.display_name, t.tenant_name) AS tenant_name,
            COUNT(DISTINCT l.loan_id) AS total_loans,
            COUNT(DISTINCT CASE WHEN l.status='ACTIVE' THEN l.loan_id END) AS active_loans,
            COUNT(DISTINCT c.customer_id) AS total_customers,
            COUNT(DISTINCT p.payment_id) AS total_payments,
            IFNULL(SUM(l.principal_amount), 0) AS total_principal,
            IFNULL(SUM(p.amount), 0) AS total_paid,
            COUNT(DISTINCT u.user_id) AS total_staff,
            MAX(l.submitted_at) AS last_activity
          FROM tenants t
          LEFT JOIN users u ON u.tenant_id = t.tenant_id AND u.role != 'CUSTOMER'
          LEFT JOIN customers c ON c.tenant_id = t.tenant_id
          LEFT JOIN loans l ON l.customer_id = c.customer_id AND l.tenant_id = t.tenant_id
          LEFT JOIN payments p ON p.loan_id = l.loan_id AND p.tenant_id = t.tenant_id";

  $where = ["t.is_active = 1"];
  $types = "";
  $params = [];
  $append_scope($where, $types, $params, 't.tenant_id');
  if ($from !== '') {
    $where[] = "DATE(l.submitted_at) >= ?";
    $types .= "s";
    $params[] = $from;
  }
  if ($to !== '') {
    $where[] = "DATE(l.submitted_at) <= ?";
    $types .= "s";
    $params[] = $to;
  }

  if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " GROUP BY t.tenant_id, t.tenant_name ORDER BY t.tenant_name";
  
} elseif ($report_type === 'user_registrations') {
  // User Registration Report
  $report_title = 'User Registrations';
  $report_description = 'Daily registration counts with role breakdowns and optional tenant filter.';
  $sql = "SELECT 
            DATE_FORMAT(u.created_at, '%Y-%m-%d') AS registration_date,
            COUNT(DISTINCT u.user_id) AS total_registrations,
            SUM(CASE WHEN u.role IN ('MANAGER','LOAN_OFFICER','CREDIT_INVESTIGATOR','CASHIER') THEN 1 ELSE 0 END) AS staff_count,
            SUM(CASE WHEN u.role IN ('SUPER_ADMIN','ADMIN') THEN 1 ELSE 0 END) AS admin_count,
            SUM(CASE WHEN u.role='MANAGER' THEN 1 ELSE 0 END) AS manager_count,
            SUM(CASE WHEN u.role='LOAN_OFFICER' THEN 1 ELSE 0 END) AS officer_count,
            SUM(CASE WHEN u.role='CREDIT_INVESTIGATOR' THEN 1 ELSE 0 END) AS ci_count,
            SUM(CASE WHEN u.role='CASHIER' THEN 1 ELSE 0 END) AS cashier_count
          FROM users u";

  $where = [];
  $types = "";
  $params = [];
  $append_scope($where, $types, $params, 'u.tenant_id');
  if ($from !== '') {
    $where[] = "DATE(u.created_at) >= ?";
    $types .= "s";
    $params[] = $from;
  }
  if ($to !== '') {
    $where[] = "DATE(u.created_at) <= ?";
    $types .= "s";
    $params[] = $to;
  }

  if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " GROUP BY DATE_FORMAT(u.created_at, '%Y-%m-%d') ORDER BY registration_date DESC";
  
} elseif ($report_type === 'usage_statistics') {
  // Usage Statistics Report - Daily activity summary
  $report_title = 'Usage Statistics';
  $report_description = 'Daily operational activity across loans, customer registrations, and payments.';
  $sql = "SELECT 
            activity_date,
            SUM(CASE WHEN event_type='loan' THEN 1 ELSE 0 END) AS loans_submitted,
            SUM(CASE WHEN event_type='customer' THEN 1 ELSE 0 END) AS customers_registered,
            SUM(CASE WHEN event_type='payment' THEN 1 ELSE 0 END) AS payments_received,
            IFNULL(SUM(CASE WHEN event_type='payment' THEN amount ELSE 0 END), 0) AS total_amount_paid
          FROM (
            SELECT DATE_FORMAT(submitted_at, '%Y-%m-%d') AS activity_date, tenant_id, 'loan' AS event_type, NULL AS amount FROM loans
            UNION ALL
            SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS activity_date, tenant_id, 'customer' AS event_type, NULL AS amount FROM customers
            UNION ALL
            SELECT DATE_FORMAT(payment_date, '%Y-%m-%d') AS activity_date, tenant_id, 'payment' AS event_type, amount FROM payments
          ) activity";

  $where = [];
  $types = "";
  $params = [];
  $append_scope($where, $types, $params, 'activity.tenant_id');
  if ($from !== '') {
    $where[] = "DATE(activity_date) >= ?";
    $types .= "s";
    $params[] = $from;
  }
  if ($to !== '') {
    $where[] = "DATE(activity_date) <= ?";
    $types .= "s";
    $params[] = $to;
  }

  if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " GROUP BY activity_date ORDER BY activity_date DESC";
}

$err = '';
$rows = [];
try {
  if ($report_type === 'loans') {
    // Loan transactions with payment details
    $base_rows = fetch_all(q($sql, $types, $params)) ?: [];
    foreach ($base_rows as $r) {
      $paid = fetch_one(q(
        "SELECT IFNULL(SUM(amount),0) AS total_paid, MAX(payment_date) AS last_payment_date, COUNT(payment_id) AS payments_count
         FROM payments
         WHERE tenant_id=? AND loan_id=?",
        "ii",
        [intval($r['tenant_id'] ?? $report_tenant_id), $r['loan_id']]
      ));
      $r['total_paid'] = $paid['total_paid'] ?? 0;
      $r['last_payment_date'] = $paid['last_payment_date'] ?? null;
      $r['payments_count'] = $paid['payments_count'] ?? 0;
      $rows[] = $r;
    }
  } else {
    // Other report types - direct query results
    $rows = fetch_all(q($sql, $types, $params)) ?: [];
  }
} catch (Exception $e) {
  $err = "Query error: " . $e->getMessage();
}

switch ($report_type) {
  case 'loans':
    $summary_cards = [
      ['label' => 'Loans', 'value' => number_format(count($rows))],
      ['label' => 'Principal', 'value' => $format_money(array_sum(array_column($rows, 'principal_amount')))],
      ['label' => 'Total Paid', 'value' => $format_money(array_sum(array_column($rows, 'total_paid')))],
      ['label' => 'Remaining', 'value' => $format_money(array_sum(array_column($rows, 'remaining_balance')))],
    ];
    break;
  case 'tenant_activity':
    $summary_cards = [
      ['label' => 'Tenants', 'value' => number_format(count($rows))],
      ['label' => 'Customers', 'value' => number_format(array_sum(array_column($rows, 'total_customers')))],
      ['label' => 'Loans', 'value' => number_format(array_sum(array_column($rows, 'total_loans')))],
      ['label' => 'Total Paid', 'value' => $format_money(array_sum(array_column($rows, 'total_paid')))],
    ];
    break;
  case 'user_registrations':
    $summary_cards = [
      ['label' => 'Registrations', 'value' => number_format(array_sum(array_column($rows, 'total_registrations')))],
      ['label' => 'Admins', 'value' => number_format(array_sum(array_column($rows, 'admin_count')))],
      ['label' => 'Staff', 'value' => number_format(array_sum(array_column($rows, 'staff_count')))],
      ['label' => 'Days Tracked', 'value' => number_format(count($rows))],
    ];
    break;
  case 'usage_statistics':
    $summary_cards = [
      ['label' => 'Activity Days', 'value' => number_format(count($rows))],
      ['label' => 'Loans Submitted', 'value' => number_format(array_sum(array_column($rows, 'loans_submitted')))],
      ['label' => 'Customers Registered', 'value' => number_format(array_sum(array_column($rows, 'customers_registered')))],
      ['label' => 'Payments Received', 'value' => number_format(array_sum(array_column($rows, 'payments_received')))],
      ['label' => 'Amount Paid', 'value' => $format_money(array_sum(array_column($rows, 'total_amount_paid')))],
    ];
    break;
}

$report_type_labels = [
  'loans' => 'Loan Transactions',
  'tenant_activity' => 'Tenant Activity',
  'user_registrations' => 'User Registrations',
  'usage_statistics' => 'Usage Statistics',
];

$selected_tenant_name = '';
if ($tenant_id_filter > 0) {
  foreach ($available_tenants as $tenant) {
    if (intval($tenant['tenant_id']) === $tenant_id_filter) {
      $selected_tenant_name = $tenant['tenant_name'];
      break;
    }
  }
}

$active_filter_chips = [];
if ($selected_tenant_name !== '') {
  $active_filter_chips[] = 'Tenant: ' . $selected_tenant_name;
}
if ($report_type === 'loans' && $status !== '') {
  $active_filter_chips[] = 'Status: ' . $status;
}
if ($report_type === 'loans' && $method !== '') {
  $active_filter_chips[] = 'Method: ' . $method;
}
if ($report_type === 'loans' && $officer_id !== '') {
  foreach ($loan_officers as $officer) {
    if ((string)$officer['user_id'] === (string)$officer_id) {
      $active_filter_chips[] = 'Officer: ' . $officer['full_name'];
      break;
    }
  }
}
if ($from !== '') {
  $active_filter_chips[] = 'From: ' . $from;
}
if ($to !== '') {
  $active_filter_chips[] = 'To: ' . $to;
}

include __DIR__ . '/_layout_top.php';
?>
<style>
body{
  background:
    radial-gradient(circle at top, rgba(14,165,233,.12), transparent 30%),
    linear-gradient(180deg, #020617 0%, #081121 42%, #0f172a 100%);
  color:#e5eefb;
}
.topbar{
  background:linear-gradient(135deg, #081121, #0f1b35) !important;
  border-bottom:1px solid rgba(148,163,184,.14);
  box-shadow:0 18px 40px rgba(2,6,23,.35);
}
.topbar .small,
.topbar a.btn.btn-outline{
  color:#d8e4f5 !important;
  border-color:rgba(148,163,184,.24) !important;
}
.layout,
.main{
  background:transparent;
}
.layout{
  align-items:flex-start;
}
.sidebar{
  flex:0 0 var(--sidebar-w);
  min-width:var(--sidebar-w);
  background:rgba(4,10,24,.84);
  border-right:1px solid rgba(148,163,184,.12);
  backdrop-filter:blur(16px);
}
.main{
  flex:1 1 auto;
  min-width:0;
}
.sidebar h3{
  color:#7f93b0;
}
.sidebar a{
  color:#d7e3f4;
}
.sidebar a.active,
.sidebar a:hover{
  background:linear-gradient(135deg, rgba(14,165,233,.18), rgba(59,130,246,.2));
  color:#f8fbff;
}
.reports-shell{
  --reports-ink:#e5eefb;
  --reports-muted:#8ea3bf;
  --reports-line:rgba(148,163,184,.16);
  --reports-surface:#0f172a;
  --reports-surface-soft:rgba(15,23,42,.84);
  --reports-shadow:0 24px 60px rgba(2,6,23,.34);
}
.reports-shell .btn-ghost{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:rgba(15,23,42,.68);
  border:1px solid rgba(148,163,184,.18);
  color:var(--reports-ink);
}
.reports-shell .btn-ghost:hover{
  background:rgba(30,41,59,.92);
  text-decoration:none;
}
.reports-hero{
  position:relative;
  overflow:hidden;
  padding:28px;
  border-radius:24px;
  color:#fff;
  background:
    radial-gradient(circle at top left, rgba(125,211,252,.34), transparent 34%),
    radial-gradient(circle at right center, rgba(250,204,21,.18), transparent 28%),
    linear-gradient(135deg, #0f172a 0%, #152a72 52%, #2456d8 100%);
  box-shadow:var(--reports-shadow);
}
.reports-hero::after{
  content:"";
  position:absolute;
  inset:auto -70px -110px auto;
  width:240px;
  height:240px;
  border-radius:999px;
  background:rgba(255,255,255,.12);
}
.reports-hero-inner{
  position:relative;
  z-index:1;
  display:flex;
  gap:18px;
  align-items:flex-start;
  justify-content:space-between;
}
.reports-kicker{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.16);
  font-size:11px;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:rgba(255,255,255,.84);
}
.reports-kicker-dot{
  width:8px;
  height:8px;
  border-radius:999px;
  background:#7dd3fc;
  box-shadow:0 0 0 4px rgba(125,211,252,.14);
}
.reports-title{
  margin:16px 0 10px;
  font-size:clamp(32px, 5vw, 46px);
  line-height:1.02;
  letter-spacing:-.04em;
  font-weight:800;
  max-width:12ch;
}
.reports-copy{
  max-width:66ch;
  margin:0;
  color:rgba(255,255,255,.78);
  font-size:15px;
  line-height:1.65;
}
.reports-chip-row{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  margin-top:18px;
}
.reports-chip{
  display:inline-flex;
  align-items:center;
  padding:9px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.1);
  border:1px solid rgba(255,255,255,.14);
  color:#f8fafc;
  font-size:12px;
}
.reports-stat-stack{
  min-width:240px;
  display:grid;
  gap:12px;
}
.reports-stat-pill{
  padding:14px 16px;
  border-radius:18px;
  background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.16);
  backdrop-filter:blur(10px);
}
.reports-stat-pill strong{
  display:block;
  font-size:28px;
  letter-spacing:-.04em;
}
.reports-stat-pill span{
  display:block;
  margin-top:4px;
  color:rgba(255,255,255,.72);
  font-size:12px;
}
.reports-panel{
  margin-top:20px;
  background:linear-gradient(180deg, rgba(15,23,42,.96), rgba(8,15,30,.97));
  border:1px solid var(--reports-line);
  border-radius:24px;
  box-shadow:var(--reports-shadow);
  overflow:hidden;
}
.reports-panel-head,
.reports-table-toolbar{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:18px;
  padding:22px 24px 0;
}
.reports-panel-title,
.reports-table-copy h3{
  margin:0;
  color:var(--reports-ink);
  font-size:22px;
  letter-spacing:-.03em;
}
.reports-panel-subtitle,
.reports-table-copy p{
  margin:6px 0 0;
  color:var(--reports-muted);
  font-size:13px;
}
.reports-filter-form{
  padding:20px 24px 24px;
  display:grid;
  grid-template-columns:repeat(5,minmax(0,1fr));
  gap:14px;
  align-items:end;
}
.reports-filter-form > div{
  grid-column:auto;
}
.reports-filter-form .reports-filter-actions,
.reports-actions{
  display:flex;
  gap:10px;
  flex-wrap:nowrap;
  align-items:center;
}
.reports-filter-actions .btn{
  white-space:nowrap;
}
.reports-shell .label{
  margin:0 0 8px;
  font-size:12px;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--reports-muted);
}
.reports-shell .input{
  min-height:46px;
  border-radius:14px;
  border:1px solid rgba(148,163,184,.18);
  background:var(--reports-surface-soft);
  color:var(--reports-ink);
  box-shadow:none;
}
.reports-shell .input::placeholder{
  color:#6f86a6;
}
.reports-summary-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
  gap:14px;
  padding:0 24px 24px;
}
.reports-summary-card{
  position:relative;
  overflow:hidden;
  min-height:126px;
  padding:18px;
  border-radius:20px;
  border:1px solid rgba(148,163,184,.16);
  background:linear-gradient(160deg, rgba(15,23,42,.98) 0%, rgba(19,34,61,.94) 100%);
}
.reports-summary-card:nth-child(2){
  background:linear-gradient(160deg, rgba(8,47,73,.94) 0%, rgba(6,78,59,.84) 100%);
}
.reports-summary-card:nth-child(3){
  background:linear-gradient(160deg, rgba(69,26,3,.94) 0%, rgba(120,53,15,.86) 100%);
}
.reports-summary-card:nth-child(4),
.reports-summary-card:nth-child(5){
  background:linear-gradient(160deg, rgba(30,27,75,.94) 0%, rgba(67,56,202,.76) 100%);
}
.reports-summary-label{
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.12em;
  color:#a9bdd8;
}
.reports-summary-value{
  margin-top:16px;
  font-size:30px;
  line-height:1;
  letter-spacing:-.05em;
  font-weight:800;
  color:#f8fbff;
}
.reports-table-wrap{
  margin:20px 24px 24px;
  border:1px solid rgba(148,163,184,.18);
  border-radius:20px;
  background:rgba(2,6,23,.46);
  overflow:auto;
}
.reports-shell .table{
  min-width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.reports-shell .table th{
  position:sticky;
  top:0;
  z-index:2;
  padding:14px 16px;
  background:rgba(15,23,42,.98);
  color:#93a8c6;
  font-size:12px;
  letter-spacing:.08em;
  text-transform:uppercase;
  border-bottom:1px solid rgba(148,163,184,.18);
}
.reports-shell .table td{
  padding:14px 16px;
  border-bottom:1px solid rgba(148,163,184,.12);
  vertical-align:top;
  color:#e5eefb;
}
.reports-shell .table tbody tr:nth-child(even){
  background:rgba(15,23,42,.42);
}
.reports-shell .table tbody tr:hover{
  background:rgba(30,41,59,.9);
}
.reports-meta{
  color:#8ea3bf;
  font-size:12px;
}
.reports-money{
  font-weight:700;
  color:#f8fbff;
}
.reports-empty{
  text-align:center;
  padding:34px 18px;
  color:#8ea3bf;
}
.reports-modal{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(2,6,23,.74);
  z-index:1000;
  align-items:center;
  justify-content:center;
  padding:24px;
  backdrop-filter:blur(8px);
}
.reports-modal-card{
  width:min(100%, 520px);
  border-radius:24px;
  padding:24px;
  background:linear-gradient(180deg, rgba(15,23,42,.98), rgba(8,15,30,.98));
  border:1px solid rgba(148,163,184,.18);
  box-shadow:0 28px 90px rgba(2,6,23,.5);
  color:var(--reports-ink);
}
.reports-modal-card .small,
.reports-modal-card .label{
  color:var(--reports-muted);
}
@media (max-width: 1100px){
  .reports-filter-form{
    grid-template-columns:repeat(3,minmax(0,1fr));
  }
}
@media (max-width: 840px){
  .reports-hero-inner,
  .reports-panel-head,
  .reports-table-toolbar{
    flex-direction:column;
  }
  .reports-stat-stack{
    width:100%;
    min-width:0;
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
  .reports-filter-form{
    grid-template-columns:1fr;
  }
}
@media (max-width: 640px){
  .reports-hero,
  .reports-panel,
  .reports-modal-card{
    border-radius:20px;
  }
  .reports-hero,
  .reports-summary-grid,
  .reports-filter-form,
  .reports-table-toolbar,
  .reports-panel-head{
    padding-left:18px;
    padding-right:18px;
  }
  .reports-summary-grid{
    padding-bottom:18px;
  }
  .reports-table-wrap{
    margin:18px;
  }
  .reports-stat-stack{
    grid-template-columns:1fr;
  }
  .reports-actions .btn,
  .reports-filter-form .btn,
  .reports-filter-form .btn-ghost{
    width:100%;
  }
}
</style>

<div class="reports-shell">
  <section class="reports-hero">
    <div class="reports-hero-inner">
      <div>
        <div class="reports-kicker">
          <span class="reports-kicker-dot"></span>
          Reports Workspace
        </div>
        <h2 class="reports-title"><?= htmlspecialchars($report_title) ?></h2>
        <p class="reports-copy"><?= htmlspecialchars($report_description) ?></p>
        <?php if (!empty($active_filter_chips)): ?>
          <div class="reports-chip-row">
            <?php foreach ($active_filter_chips as $chip): ?>
              <span class="reports-chip"><?= htmlspecialchars($chip) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="reports-stat-stack">
        <div class="reports-stat-pill">
          <strong><?= number_format(count($rows)) ?></strong>
          <span>Rows in current report</span>
        </div>
        <div class="reports-stat-pill">
          <strong><?= htmlspecialchars($report_type_labels[$report_type] ?? 'Report') ?></strong>
          <span><?= $can_view_advanced_reports ? 'Advanced options available' : 'Standard report access' ?></span>
        </div>
      </div>
    </div>
  </section>

  <?php if ($update_msg): echo $update_msg; endif; ?>
  <?php if ($err): ?><div class="alert red" style="margin-top:14px"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <section class="reports-panel">
    <div class="reports-panel-head">
      <div>
        <h3 class="reports-panel-title">Filters</h3>
        <p class="reports-panel-subtitle">Adjust the current report view without changing any report behavior.</p>
      </div>
      <div class="reports-meta">
        <?= count($active_filter_chips) ?> active filter<?= count($active_filter_chips) === 1 ? '' : 's' ?>
      </div>
    </div>

    <form method="get" class="reports-filter-form">
      <div class="reports-filter-type">
        <label class="label">Report Type</label>
        <select class="input" name="report_type" onchange="this.form.submit();">
          <option value="loans" <?= ($report_type === 'loans') ? 'selected' : '' ?>>Loan Transactions</option>
          <?php if ($can_view_advanced_reports): ?>
            <option value="tenant_activity" <?= ($report_type === 'tenant_activity') ? 'selected' : '' ?>>Tenant Activity</option>
            <option value="user_registrations" <?= ($report_type === 'user_registrations') ? 'selected' : '' ?>>User Registrations</option>
            <option value="usage_statistics" <?= ($report_type === 'usage_statistics') ? 'selected' : '' ?>>Usage Statistics</option>
          <?php endif; ?>
        </select>
      </div>

      <?php if ($show_tenant_filter): ?>
        <div>
          <label class="label">Tenant</label>
          <select class="input" name="tenant_id">
            <option value="">All Accessible Tenants</option>
            <?php foreach ($available_tenants as $tenant): ?>
              <option value="<?= intval($tenant['tenant_id']) ?>" <?= ($tenant_id_filter === intval($tenant['tenant_id'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($tenant['tenant_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <?php if ($report_type === 'loans'): ?>
        <div>
          <label class="label">Status</label>
          <select class="input" name="status">
            <option value="">All</option>
            <?php
              $opts = ['PENDING','CI_REVIEWED','DENIED','ACTIVE','OVERDUE','CLOSED'];
              foreach ($opts as $o) {
                $sel = ($status === $o) ? 'selected' : '';
                echo '<option ' . $sel . ' value="' . htmlspecialchars($o) . '">' . htmlspecialchars($o) . '</option>';
              }
            ?>
          </select>
        </div>
        <div>
          <label class="label">Payment Method</label>
          <select class="input" name="method">
            <option value="">All</option>
            <option value="CASH" <?= ($method === 'CASH') ? 'selected' : '' ?>>Cash</option>
            <option value="GCASH" <?= ($method === 'GCASH') ? 'selected' : '' ?>>GCash</option>
            <option value="BANK" <?= ($method === 'BANK') ? 'selected' : '' ?>>Bank Transfer</option>
            <option value="CHEQUE" <?= ($method === 'CHEQUE') ? 'selected' : '' ?>>Cheque</option>
          </select>
        </div>
        <div>
          <label class="label">Loan Officer</label>
          <select class="input" name="officer_id">
            <option value="">All Officers</option>
            <?php foreach ($loan_officers as $officer): ?>
              <option value="<?= intval($officer['user_id']) ?>" <?= ($officer_id === (string)$officer['user_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($officer['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div>
        <label class="label">From</label>
        <input class="input" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
      </div>
      <div>
        <label class="label">To</label>
        <input class="input" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
      </div>
      <div class="reports-filter-actions">
        <button class="btn btn-primary" type="submit" name="generate" value="1">Generate Report</button>
        <a class="btn btn-ghost" href="<?php echo APP_BASE; ?>/staff/reports.php">Reset Filters</a>
      </div>
    </form>
  </section>

  <?php if (!empty($summary_cards)): ?>
    <section class="reports-panel">
      <div class="reports-panel-head">
        <div>
          <h3 class="reports-panel-title">Snapshot</h3>
          <p class="reports-panel-subtitle">High-level totals for the current report selection.</p>
        </div>
      </div>
      <div class="reports-summary-grid">
        <?php foreach ($summary_cards as $card): ?>
          <div class="reports-summary-card">
            <div class="reports-summary-label"><?= htmlspecialchars($card['label']) ?></div>
            <div class="reports-summary-value"><?= htmlspecialchars($card['value']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="reports-panel">
    <div class="reports-table-toolbar">
      <div class="reports-table-copy">
        <h3><?= htmlspecialchars($report_title) ?> Table</h3>
        <p>Live results below. Export and print actions are unchanged.</p>
      </div>
      <div class="reports-actions">
        <button class="btn btn-outline" type="button" onclick="openReportModal()">Print Report</button>
        <button class="btn btn-outline" type="button" onclick="downloadReportCSV()">Download CSV</button>
        <?php if ($report_type === 'loans' && can_access('print_receipts')): ?>
          <button class="btn btn-primary" type="button" onclick="openReceiptModal()">Print Receipt</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="reports-table-wrap" id="reportTableContainer">
    <?php if (false): ?>
    <!-- Loan Transactions Table -->
    <table class="table">
      <thead>
        <tr>
          <?php if ($show_tenant_column): ?><th>Tenant</th><?php endif; ?>
          <th>Loan Ref#</th>
          <th>Customer</th>
          <th>Status</th>
          <th>Officer</th>
          <th>Method</th>
          <th>Principal</th>
          <th>Int%</th>
          <th>Total Pay</th>
          <th>Total Paid</th>
          <th>Remaining</th>
          <th>Due Date</th>
          <th>Last Pay</th>
          <th>Submitted</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $interest_rate = null;
            if (!empty($r['payment_term'])) {
              $rates = ['daily' => 2.75, 'weekly' => 3.0, 'semi_monthly' => 3.50, 'monthly' => 4.0];
              $interest_rate = $rates[$r['payment_term']] ?? null;
            }
            if ($interest_rate === null) {
              $interest_rate = (!empty($r['interest_rate']) && $r['interest_rate'] > 0) ? $r['interest_rate'] : null;
            }
            if ($interest_rate === null && $r['status'] === 'PENDING') {
              $interest_rate = 5.0;
            }
          ?>
          <tr>
            <?php if ($show_tenant_column): ?><td><?= htmlspecialchars($r['tenant_name']) ?></td><?php endif; ?>
            <td><?= htmlspecialchars($r['reference_no']) ?></td>
            <td><?= htmlspecialchars($r['customer_no']) ?> <br> <?= htmlspecialchars($r['customer_name']) ?></td>
            <td><span class="badge <?= htmlspecialchars(status_badge_class($r['status'])) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
            <td><?= $r['officer_name'] ? htmlspecialchars($r['officer_name']) : '—' ?></td>
            <td><?= $r['method'] ? htmlspecialchars($r['method']) : '—' ?></td>
            <td>₱<?= number_format((float)$r['principal_amount'],2) ?></td>
            <td><?= number_format((float)$interest_rate,2) ?></td>
            <td><?= $r['total_payable']===null?'—':'₱'.number_format((float)$r['total_payable'],2) ?></td>
            <td>₱<?= number_format((float)$r['total_paid'],2) ?> <br><span class="small">(<?= (int)$r['payments_count'] ?>)</span></td>
            <td><?= $r['remaining_balance']===null?'—':'₱'.number_format((float)$r['remaining_balance'],2) ?></td>
            <td><?= $r['due_date'] ? htmlspecialchars($r['due_date']) : '—' ?></td>
            <td><?= $r['last_payment_date'] ? htmlspecialchars($r['last_payment_date']) : '—' ?></td>
            <td><?= htmlspecialchars($r['submitted_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?= $show_tenant_column ? 14 : 13 ?>" class="small" style="text-align:center;padding:20px">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php elseif ($report_type === 'loans'): ?>
    <!-- Loan Transactions Table -->
    <table class="table">
      <thead>
        <tr>
          <?php if ($show_tenant_column): ?><th>Tenant</th><?php endif; ?>
          <th>Loan Ref#</th>
          <th>Customer</th>
          <th>Status</th>
          <th>Officer</th>
          <th>Method</th>
          <th>Principal</th>
          <th>Int%</th>
          <th>Total Pay</th>
          <th>Total Paid</th>
          <th>Remaining</th>
          <th>Due Date</th>
          <th>Last Pay</th>
          <th>Submitted</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $interest_rate = null;
            if (!empty($r['payment_term'])) {
              $rates = ['daily' => 2.75, 'weekly' => 3.0, 'semi_monthly' => 3.50, 'monthly' => 4.0];
              $interest_rate = $rates[$r['payment_term']] ?? null;
            }
            if ($interest_rate === null) {
              $interest_rate = (!empty($r['interest_rate']) && $r['interest_rate'] > 0) ? $r['interest_rate'] : null;
            }
            if ($interest_rate === null && $r['status'] === 'PENDING') {
              $interest_rate = 5.0;
            }
          ?>
          <tr>
            <?php if ($show_tenant_column): ?><td><?= htmlspecialchars($r['tenant_name']) ?></td><?php endif; ?>
            <td><?= htmlspecialchars($r['reference_no']) ?></td>
            <td>
              <div><?= htmlspecialchars($r['customer_no']) ?></div>
              <div class="reports-meta" style="margin-top:4px"><?= htmlspecialchars($r['customer_name']) ?></div>
            </td>
            <td><span class="badge <?= htmlspecialchars(status_badge_class($r['status'])) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
            <td><?= $r['officer_name'] ? htmlspecialchars($r['officer_name']) : 'Not assigned' ?></td>
            <td><?= $r['method'] ? htmlspecialchars($r['method']) : 'No payment' ?></td>
            <td class="reports-money">PHP <?= number_format((float)$r['principal_amount'],2) ?></td>
            <td><?= number_format((float)$interest_rate,2) ?></td>
            <td class="reports-money"><?= $r['total_payable']===null ? 'Pending' : 'PHP ' . number_format((float)$r['total_payable'],2) ?></td>
            <td class="reports-money">PHP <?= number_format((float)$r['total_paid'],2) ?> <br><span class="small">(<?= (int)$r['payments_count'] ?> payments)</span></td>
            <td class="reports-money"><?= $r['remaining_balance']===null ? 'Pending' : 'PHP ' . number_format((float)$r['remaining_balance'],2) ?></td>
            <td><?= $r['due_date'] ? htmlspecialchars($r['due_date']) : '<span class="reports-meta">Not set</span>' ?></td>
            <td><?= $r['last_payment_date'] ? htmlspecialchars($r['last_payment_date']) : '<span class="reports-meta">No payment yet</span>' ?></td>
            <td class="reports-meta"><?= htmlspecialchars($r['submitted_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?= $show_tenant_column ? 14 : 13 ?>" class="reports-empty">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif (false): ?>
    <!-- Tenant Activity Table -->
    <table class="table">
      <thead>
        <tr>
          <th>Tenant Name</th>
          <th>Total Loans</th>
          <th>Active Loans</th>
          <th>Total Customers</th>
          <th>Total Payments</th>
          <th>Total Principal</th>
          <th>Total Paid</th>
          <th>Staff Members</th>
          <th>Last Activity</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['tenant_name']) ?></td>
            <td><?= intval($r['total_loans']) ?></td>
            <td><?= intval($r['active_loans']) ?></td>
            <td><?= intval($r['total_customers']) ?></td>
            <td><?= intval($r['total_payments']) ?></td>
            <td>₱<?= number_format((float)$r['total_principal'],2) ?></td>
            <td>₱<?= number_format((float)$r['total_paid'],2) ?></td>
            <td><?= intval($r['total_staff']) ?></td>
            <td><?= $r['last_activity'] ? htmlspecialchars($r['last_activity']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="small" style="text-align:center;padding:20px">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif ($report_type === 'tenant_activity'): ?>
    <!-- Tenant Activity Table -->
    <table class="table">
      <thead>
        <tr>
          <th>Tenant Name</th>
          <th>Total Loans</th>
          <th>Active Loans</th>
          <th>Total Customers</th>
          <th>Total Payments</th>
          <th>Total Principal</th>
          <th>Total Paid</th>
          <th>Staff Members</th>
          <th>Last Activity</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['tenant_name']) ?></td>
            <td><?= intval($r['total_loans']) ?></td>
            <td><?= intval($r['active_loans']) ?></td>
            <td><?= intval($r['total_customers']) ?></td>
            <td><?= intval($r['total_payments']) ?></td>
            <td class="reports-money">PHP <?= number_format((float)$r['total_principal'],2) ?></td>
            <td class="reports-money">PHP <?= number_format((float)$r['total_paid'],2) ?></td>
            <td><?= intval($r['total_staff']) ?></td>
            <td><?= $r['last_activity'] ? htmlspecialchars($r['last_activity']) : 'No recent activity' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="reports-empty">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif ($report_type === 'user_registrations'): ?>
    <!-- User Registrations Table -->
    <table class="table">
      <thead>
        <tr>
          <th>Registration Date</th>
          <th>Total Registrations</th>
          <th>Staff</th>
          <th>Admin</th>
          <th>Manager</th>
          <th>Loan Officer</th>
          <th>Credit Investigator</th>
          <th>Cashier</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['registration_date']) ?></td>
            <td><?= intval($r['total_registrations']) ?></td>
            <td><?= intval($r['staff_count'] ?? 0) ?></td>
            <td><?= intval($r['admin_count'] ?? 0) ?></td>
            <td><?= intval($r['manager_count'] ?? 0) ?></td>
            <td><?= intval($r['officer_count'] ?? 0) ?></td>
            <td><?= intval($r['ci_count'] ?? 0) ?></td>
            <td><?= intval($r['cashier_count'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="reports-empty">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif (false): ?>
    <!-- Usage Statistics Table -->
    <table class="table">
      <thead>
        <tr>
          <th>Activity Date</th>
          <th>Loans Submitted</th>
          <th>Customers Registered</th>
          <th>Payments Received</th>
          <th>Total Amount Paid</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['activity_date']) ?></td>
            <td><?= intval($r['loans_submitted']) ?></td>
            <td><?= intval($r['customers_registered']) ?></td>
            <td><?= intval($r['payments_received']) ?></td>
            <td>₱<?= number_format((float)$r['total_amount_paid'],2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="5" class="small" style="text-align:center;padding:20px">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php elseif ($report_type === 'usage_statistics'): ?>
    <!-- Usage Statistics Table -->
    <table class="table">
      <thead>
        <tr>
          <th>Activity Date</th>
          <th>Loans Submitted</th>
          <th>Customers Registered</th>
          <th>Payments Received</th>
          <th>Total Amount Paid</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['activity_date']) ?></td>
            <td><?= intval($r['loans_submitted']) ?></td>
            <td><?= intval($r['customers_registered']) ?></td>
            <td><?= intval($r['payments_received']) ?></td>
            <td class="reports-money">PHP <?= number_format((float)$r['total_amount_paid'],2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="5" class="reports-empty">No results found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
    </div>
  </section>
</div>

<div id="reportModal" class="reports-modal">
  <div class="reports-modal-card">
    <h3 style="margin-top:0">Print <?php 
      $report_titles = [
        'loans' => 'Loan Report',
        'tenant_activity' => 'Tenant Activity Report',
        'user_registrations' => 'User Registrations Report',
        'usage_statistics' => 'Usage Statistics Report'
      ];
      echo htmlspecialchars($report_titles[$report_type] ?? 'Report');
    ?></h3>
    <div class="small">Choose how you want to export the current view.</div>
    <label class="label">Choose Format:</label>
    
    <div style="margin-top:15px;display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-primary" onclick="printReport()">Print</button>
      <button class="btn btn-primary" onclick="downloadReportPDF()">Save as PDF</button>
      <button class="btn btn-outline" onclick="downloadReportCSV()">Download CSV</button>
      <button class="btn btn-outline" onclick="closeReportModal()">Cancel</button>
    </div>
  </div>
</div>

<?php if (can_access('print_receipts')): ?>
<div id="receiptModal" class="reports-modal" style="flex-direction:column">
  <div class="reports-modal-card" style="max-width:560px;max-height:90vh;overflow-y:auto">
    <h3 style="margin-top:0">Print Receipt</h3>
    <div class="small">Generate a single payment receipt or a customer summary from the current data set.</div>
    
    <label class="label">Select Receipt Type:</label>
    <select class="input" id="receiptType" onchange="updateReceiptOptions()" style="width:100%;padding:8px;margin-bottom:15px;box-sizing:border-box">
      <option value="">Choose...</option>
      <option value="individual">Individual Receipt (Single Payment)</option>
      <option value="summary">Summary Receipt (All Payments by Client)</option>
    </select>

    <div id="individualOptions" style="display:none;margin-top:15px">
      <label class="label">Select Customer:</label>
      <select class="input" id="customerSelect" onchange="updatePaymentList()" style="width:100%;padding:8px;margin-bottom:10px;box-sizing:border-box">
        <option value="">Choose Customer...</option>
        <?php 
          $customers = fetch_all(q("SELECT DISTINCT c.customer_id, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS name FROM customers c JOIN loans l ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id WHERE " . tenant_condition('c.tenant_id') . " ORDER BY c.first_name", tenant_types(), tenant_params()));
          foreach ($customers as $cust) {
            echo '<option value="'.intval($cust['customer_id']).'">'.htmlspecialchars($cust['customer_no'].' - '.$cust['name']).'</option>';
          }
        ?>
      </select>

      <label class="label" style="margin-top:10px">Select Payment:</label>
      <select class="input" id="paymentSelect" style="width:100%;padding:8px;box-sizing:border-box">
        <option value="">Choose Payment...</option>
      </select>
    </div>

    <div id="summaryOptions" style="display:none;margin-top:15px">
      <label class="label">Select Customer:</label>
      <select class="input" id="summaryCustomerSelect" style="width:100%;padding:8px;box-sizing:border-box">
        <option value="">Choose Customer...</option>
        <?php 
          foreach ($customers as $cust) {
            echo '<option value="'.intval($cust['customer_id']).'">'.htmlspecialchars($cust['customer_no'].' - '.$cust['name']).'</option>';
          }
        ?>
      </select>
    </div>

    <div style="margin-top:15px;display:flex;gap:10px">
      <button class="btn btn-primary" onclick="printReceipt()">Print</button>
      <button class="btn btn-outline" onclick="closeReceiptModal()">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
<?php if (can_access('print_receipts')): ?>
const receipts = {
  <?php 
    $payments = fetch_all(q("SELECT p.payment_id, p.loan_id, p.or_no, p.amount, p.payment_date, c.customer_id, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name FROM payments p JOIN loans l ON p.loan_id=l.loan_id AND l.tenant_id=p.tenant_id JOIN customers c ON l.customer_id=c.customer_id AND c.tenant_id=l.tenant_id WHERE " . tenant_condition('p.tenant_id') . " ORDER BY p.payment_date DESC", tenant_types(), tenant_params()));
    foreach ($payments as $p) {
      echo intval($p['payment_id']) . ": {customer_id: ".intval($p['customer_id']).", loan_id: ".intval($p['loan_id']).", or_no: '".addslashes($p['or_no'])."', amount: ".floatval($p['amount']).", date: '".htmlspecialchars($p['payment_date'])."', customer_name: '".addslashes($p['customer_name'])."'},\n";
    }
  ?>
};

function openReceiptModal() {
  document.getElementById('receiptModal').style.display = 'flex';
  document.getElementById('receiptType').value = '';
  document.getElementById('customerSelect').value = '';
  document.getElementById('summaryCustomerSelect').value = '';
  document.getElementById('paymentSelect').innerHTML = '<option value="">Choose Payment...</option>';
  updateReceiptOptions();
}

function closeReceiptModal() {
  document.getElementById('receiptModal').style.display = 'none';
  document.getElementById('receiptType').value = '';
  updateReceiptOptions();
}

function updateReceiptOptions() {
  const type = document.getElementById('receiptType').value;
  document.getElementById('individualOptions').style.display = type === 'individual' ? 'block' : 'none';
  document.getElementById('summaryOptions').style.display = type === 'summary' ? 'block' : 'none';
}

function updatePaymentList() {
  const custId = parseInt(document.getElementById('customerSelect').value);
  const select = document.getElementById('paymentSelect');
  select.innerHTML = '<option value="">Choose Payment...</option>';
  
  for (let paymentId in receipts) {
    if (receipts[paymentId].customer_id === custId) {
      const opt = document.createElement('option');
      opt.value = paymentId;
      opt.textContent = receipts[paymentId].or_no + ' - ₱' + receipts[paymentId].amount.toFixed(2) + ' (' + receipts[paymentId].date + ')';
      select.appendChild(opt);
    }
  }
}

function printReceipt() {
  const type = document.getElementById('receiptType').value;
  
  if (type === 'individual') {
    const paymentId = document.getElementById('paymentSelect').value;
    if (!paymentId) {
      alert('Please select a payment');
      return;
    }
    window.open('<?php echo APP_BASE; ?>/staff/payment_receipt.php?id=' + paymentId, '_blank');
  } else if (type === 'summary') {
    const custId = document.getElementById('summaryCustomerSelect').value;
    if (!custId) {
      alert('Please select a customer');
      return;
    }
    window.open('<?php echo APP_BASE; ?>/staff/receipt_summary.php?customer_id=' + custId, '_blank');
  }
  
  closeReceiptModal();
}

// Close modal when clicking outside
document.getElementById('receiptModal').addEventListener('click', function(e) {
  if (e.target === this) closeReceiptModal();
});
<?php endif; ?>

function openReportModal() {
  document.getElementById('reportModal').style.display = 'flex';
}

function closeReportModal() {
  document.getElementById('reportModal').style.display = 'none';
}

function printReport() {
  const tableContainer = document.getElementById('reportTableContainer').innerHTML;
  
  const printWindow = window.open('', '_blank');
  const reportTitles = {
    'loans': 'Loan Transactions Report',
    'tenant_activity': 'Tenant Activity Report',
    'user_registrations': 'User Registrations Report',
    'usage_statistics': 'Usage Statistics Report'
  };
  const reportType = new URLSearchParams(window.location.search).get('report_type') || 'loans';
  const title = reportTitles[reportType] || 'Report';
  const htmlContent = `
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="UTF-8">
      <title>${title}</title>
      <style>
        @page { size: landscape; margin: 3mm; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 5px; }
        
        .print-wrapper { width: 100%; zoom: 65%; }
        
        h2 { margin: 0 0 5px 0; font-size: 16px; }
        .small { font-size: 10px; color: #666; margin-bottom: 10px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th, td { 
            border: 1px solid #666; 
            padding: 8px 4px;
            text-align: left; 
            vertical-align: top;
            white-space: nowrap; 
        }
        
        td:nth-child(2) { white-space: normal; width: 140px; }
        td:last-child { white-space: normal; }
        
        th { background-color: #eee; font-weight: bold; font-size: 9px; }
        .badge { font-weight: bold; text-transform: uppercase; font-size: 8px; border:none; }
      </style>
    </head>
    <body>
      <h2>${title}</h2>
      <div class="small">Generated: ${new Date().toLocaleString()}</div>
      <div class="print-wrapper">
        ${tableContainer}
      </div>
    </body>
    </html>
  `;
  
  printWindow.document.write(htmlContent);
  printWindow.document.close();
  
  setTimeout(() => {
    printWindow.focus();
    printWindow.print();
  }, 500);
  
  closeReportModal();
}

function downloadReportCSV() {
  const table = document.querySelector('#reportTableContainer table');
  if (!table) {
    return;
  }

  const rows = Array.from(table.querySelectorAll('tr')).map((row) => {
    return Array.from(row.querySelectorAll('th, td')).map((cell) => {
      const text = cell.innerText.replace(/\s+/g, ' ').trim();
      return `"${text.replace(/"/g, '""')}"`;
    }).join(',');
  });

  const csv = rows.join('\n');
  const reportTitles = {
    loans: 'loan_transactions',
    tenant_activity: 'tenant_activity',
    user_registrations: 'user_registrations',
    usage_statistics: 'usage_statistics'
  };
  const reportType = new URLSearchParams(window.location.search).get('report_type') || 'loans';
  const filename = (reportTitles[reportType] || 'report') + '_<?= date('Y-m-d') ?>.csv';
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');

  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function downloadReportPDF() {
  const tableContainer = document.getElementById('reportTableContainer').cloneNode(true);
  const reportTitles = {
    'loans': 'loan_report',
    'tenant_activity': 'tenant_activity_report',
    'user_registrations': 'user_registrations_report',
    'usage_statistics': 'usage_statistics_report'
  };
  const reportType = new URLSearchParams(window.location.search).get('report_type') || 'loans';
  const filename = reportTitles[reportType] || 'report';
  
  const wrapper = document.createElement('div');
  wrapper.appendChild(tableContainer);
  
  const style = document.createElement('style');
  style.innerHTML = `
    table { 
        width: 98%;
        border-collapse: collapse; 
        font-size: 7px;
        font-family: Arial, sans-serif; 
    }
    th, td { 
        border: 1px solid #888; 
        padding: 8px 4px;
        white-space: nowrap;
        vertical-align: top;
    }
    
    td:nth-child(2) { white-space: normal; width: 13%; }
    td:nth-child(6), td:nth-child(8), td:nth-child(9) { width: 6%; }
    
    td:last-child {
        white-space: normal;
        word-wrap: break-word;
        width: 9%; 
    }
    
    th { background-color: #e8e8e8; font-weight: bold; }
    
    .badge { 
        font-size: 8px !important; 
        padding: 2px 4px !important; 
        font-weight: bold; 
        display: inline-block;
        border: 1px solid #ccc;
    }
    
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
  `;
  wrapper.appendChild(style);
  
  const opt = {
    margin: [3, 3, 3, 3], 
    filename: filename + '_<?= date('Y-m-d') ?>.pdf',
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
    jsPDF: { unit: 'mm', format: 'legal', orientation: 'landscape' },
    pagebreak: { mode: ['css', 'legacy'] } 
  };
  
  if (typeof html2pdf === 'undefined') {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    script.onload = function() {
      html2pdf().set(opt).from(wrapper).save();
      closeReportModal();
    };
    document.head.appendChild(script);
  } else {
    html2pdf().set(opt).from(wrapper).save();
    closeReportModal();
  }
}

document.getElementById('reportModal').addEventListener('click', function(e) {
  if (e.target === this) closeReportModal();
});
</script>

<div id="editModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;display:none">
  <div class="card" style="max-width:400px;width:90%">
    <h3 style="margin-top:0">Update Interest Rate</h3>
    <form method="post">
      <input type="hidden" id="modalLoanId" name="loan_id">
      <div style="margin-bottom:12px">
        <label class="label">Loan: <span id="modalRefNo"></span></label>
      </div>
      <div style="margin-bottom:12px">
        <label class="label">New Interest Rate (%)</label>
        <input class="input" type="number" step="0.01" id="modalInterestRate" name="interest_rate" required>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit" name="update_interest" value="1">Update</button>
        <button class="btn btn-outline" type="button" onclick="closeEditModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(loanId, currentRate, refNo) {
  document.getElementById('modalLoanId').value = loanId;
  document.getElementById('modalInterestRate').value = currentRate;
  document.getElementById('modalRefNo').textContent = refNo;
  document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
  document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});
</script>

<style>
/* Physical Print Styles */
@media print {
  @page { size: landscape; margin: 3mm; }
  body { margin: 0; padding: 0; font-size: 10px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .topbar, .sidebar, .layout > div:first-child, form, .btn, #editModal, #reportModal, #receiptModal, .card > div:last-child { display: none !important; }
  .card { box-shadow: none; border: none; padding: 0; margin: 0; width: 100%; }
  
  /* Zoom to fit physical paper */
  #reportTableContainer { display: block !important; width: 100% !important; overflow: visible !important; zoom: 65%; }
  
  #reportTableContainer table { width: 100%; border-collapse: collapse; font-size: 10px; }
  
  /* Increase padding for physical print too */
  #reportTableContainer th, #reportTableContainer td { border: 1px solid #666; padding: 8px 4px; white-space: nowrap; vertical-align: top; }
  
  #reportTableContainer td:nth-child(2) { white-space: normal; min-width: 100px; max-width: 160px; }
  #reportTableContainer th { background-color: #eee !important; color: #000 !important; }
  .badge { border: none; background: transparent !important; color: #000 !important; padding: 0; font-size: 9px; font-weight: bold; }
}
</style>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
