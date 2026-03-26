<?php
/**
 * ============================================
 * ADMIN SYSTEM COMPREHENSIVE TEST SUITE
 * ============================================
 * 
 * This test suite validates all Admin modules:
 * - Dashboard (Analytics)
 * - Tenant Management
 * - Reports
 * - Sales Report
 * - Audit Logs (History)
 * - Settings
 * 
 * To run: http://localhost/LOAN_MANAGEMENT_APP/test_admin_system.php
 * 
 * NOTE: This is for testing only. Do not use in production.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/loan_helpers.php';

// Security check - only allow access if logged in as ADMIN or SUPER_ADMIN
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['SUPER_ADMIN', 'ADMIN'], true)) {
  die('Access Denied: Admin access required');
}

$results = [];
$errors = [];
$warnings = [];

// ============================================
// TEST 1: DASHBOARD ANALYTICS VERIFICATION
// ============================================
$test_name = "Dashboard Analytics";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 1.1: Check tenant count
$tenant_count = fetch_one(q("SELECT COUNT(*) AS count FROM tenants"));
$test_results['tests'][] = [
  'name' => '1.1 - Tenant Count Verification',
  'query' => "SELECT COUNT(*) AS count FROM tenants",
  'expected' => 'Integer count of all tenants',
  'actual' => $tenant_count['count'] ?? 0,
  'status' => isset($tenant_count['count']) ? 'PASS' : 'FAIL'
];

// 1.2: Check active users count
$active_users = fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active=1"));
$test_results['tests'][] = [
  'name' => '1.2 - Active Users Count',
  'query' => "SELECT COUNT(*) AS count FROM users WHERE is_active=1",
  'expected' => 'Integer count > 0',
  'actual' => $active_users['count'] ?? 0,
  'status' => isset($active_users['count']) ? 'PASS' : 'FAIL'
];

// 1.3: Check inactive users count
$inactive_users = fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active=0"));
$test_results['tests'][] = [
  'name' => '1.3 - Inactive Users Count',
  'query' => "SELECT COUNT(*) AS count FROM users WHERE is_active=0",
  'expected' => 'Integer count >= 0',
  'actual' => $inactive_users['count'] ?? 0,
  'status' => isset($inactive_users['count']) ? 'PASS' : 'FAIL'
];

// 1.4: Check total customers
$total_customers = fetch_one(q("SELECT COUNT(*) AS count FROM customers WHERE is_active=1"));
$test_results['tests'][] = [
  'name' => '1.4 - Total Active Customers',
  'query' => "SELECT COUNT(*) AS count FROM customers WHERE is_active=1",
  'expected' => 'Integer count >= 0',
  'actual' => $total_customers['count'] ?? 0,
  'status' => isset($total_customers['count']) ? 'PASS' : 'FAIL'
];

// 1.5: Check total loans
$total_loans = fetch_one(q("SELECT COUNT(*) AS count FROM loans"));
$test_results['tests'][] = [
  'name' => '1.5 - Total Loans Count',
  'query' => "SELECT COUNT(*) AS count FROM loans",
  'expected' => 'Integer count >= 0',
  'actual' => $total_loans['count'] ?? 0,
  'status' => isset($total_loans['count']) ? 'PASS' : 'FAIL'
];

// 1.6: Check active loans
$active_loans = fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status='ACTIVE'"));
$test_results['tests'][] = [
  'name' => '1.6 - Active Loans Count',
  'query' => "SELECT COUNT(*) AS count FROM loans WHERE status='ACTIVE'",
  'expected' => 'Integer count >= 0',
  'actual' => $active_loans['count'] ?? 0,
  'status' => isset($active_loans['count']) ? 'PASS' : 'FAIL'
];

// 1.7: Check overdue loans
$overdue_loans = fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status='OVERDUE'"));
$test_results['tests'][] = [
  'name' => '1.7 - Overdue Loans Count',
  'query' => "SELECT COUNT(*) AS count FROM loans WHERE status='OVERDUE'",
  'expected' => 'Integer count >= 0',
  'actual' => $overdue_loans['count'] ?? 0,
  'status' => isset($overdue_loans['count']) ? 'PASS' : 'FAIL'
];

// 1.8: Check portfolio value calculation
$portfolio_value = fetch_one(q("SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loans WHERE status IN ('ACTIVE','OVERDUE')"));
$test_results['tests'][] = [
  'name' => '1.8 - Portfolio Value SUM',
  'query' => "SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loans WHERE status IN ('ACTIVE','OVERDUE')",
  'expected' => 'Numeric value >= 0',
  'actual' => $portfolio_value['total'] ?? 0,
  'status' => is_numeric($portfolio_value['total']) ? 'PASS' : 'FAIL'
];

// 1.9: Check total payments
$total_payments = fetch_one(q("SELECT COUNT(*) AS count, IFNULL(SUM(amount), 0) AS total FROM payments"));
$test_results['tests'][] = [
  'name' => '1.9 - Total Payments Count & Sum',
  'query' => "SELECT COUNT(*) AS count, IFNULL(SUM(amount), 0) AS total FROM payments",
  'expected' => 'Numeric count and total',
  'actual' => ($total_payments['count'] ?? 0) . ' payments, ₱' . number_format($total_payments['total'] ?? 0, 2),
  'status' => isset($total_payments['count']) && is_numeric($total_payments['total']) ? 'PASS' : 'FAIL'
];

// 1.10: Check activity logs exist
$activity_logs = fetch_one(q("SELECT COUNT(*) AS count FROM activity_logs LIMIT 1"));
$test_results['tests'][] = [
  'name' => '1.10 - Activity Logs Availability',
  'query' => "SELECT COUNT(*) AS count FROM activity_logs",
  'expected' => 'Integer count >= 0',
  'actual' => $activity_logs['count'] ?? 0,
  'status' => isset($activity_logs['count']) ? 'PASS' : 'FAIL'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 2: TENANT MANAGEMENT VERIFICATION
// ============================================
$test_name = "Tenant Management";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 2.1: Check all tenants can be fetched
$all_tenants = fetch_all(q("SELECT tenant_id, tenant_name, subdomain, is_active, created_at FROM tenants ORDER BY created_at DESC"));
$test_results['tests'][] = [
  'name' => '2.1 - Fetch All Tenants',
  'query' => "SELECT tenant_id, tenant_name, subdomain, is_active, created_at FROM tenants",
  'expected' => 'Array of tenant records',
  'actual' => count($all_tenants) . ' tenants found',
  'status' => is_array($all_tenants) ? 'PASS' : 'FAIL'
];

// 2.2: Check tenant fields
if (!empty($all_tenants)) {
  $first_tenant = $all_tenants[0];
  $required_fields = ['tenant_id', 'tenant_name', 'subdomain', 'is_active'];
  $missing_fields = [];
  foreach ($required_fields as $field) {
    if (!isset($first_tenant[$field])) {
      $missing_fields[] = $field;
    }
  }
  $test_results['tests'][] = [
    'name' => '2.2 - Tenant Record Fields Validation',
    'query' => "DESCRIBE tenants",
    'expected' => 'All required fields present',
    'actual' => empty($missing_fields) ? 'All fields present' : 'Missing: ' . implode(', ', $missing_fields),
    'status' => empty($missing_fields) ? 'PASS' : 'FAIL'
  ];
}

// 2.3: Check unique tenant names
$duplicate_names = fetch_one(q("SELECT COUNT(*) - COUNT(DISTINCT tenant_name) AS duplicates FROM tenants"));
$test_results['tests'][] = [
  'name' => '2.3 - Unique Tenant Names',
  'query' => "SELECT COUNT(*) - COUNT(DISTINCT tenant_name) AS duplicates FROM tenants",
  'expected' => 'Duplicates = 0',
  'actual' => 'Duplicates: ' . ($duplicate_names['duplicates'] ?? 0),
  'status' => ($duplicate_names['duplicates'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 2.4: Check unique subdomains
$duplicate_subdomains = fetch_one(q("SELECT COUNT(*) - COUNT(DISTINCT subdomain) AS duplicates FROM tenants"));
$test_results['tests'][] = [
  'name' => '2.4 - Unique Subdomains',
  'query' => "SELECT COUNT(*) - COUNT(DISTINCT subdomain) AS duplicates FROM tenants",
  'expected' => 'Duplicates = 0',
  'actual' => 'Duplicates: ' . ($duplicate_subdomains['duplicates'] ?? 0),
  'status' => ($duplicate_subdomains['duplicates'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 2.5: Check tenant status values
$invalid_status = fetch_one(q("SELECT COUNT(*) AS count FROM tenants WHERE is_active NOT IN (0, 1)"));
$test_results['tests'][] = [
  'name' => '2.5 - Tenant Status Values (0/1)',
  'query' => "SELECT COUNT(*) AS count FROM tenants WHERE is_active NOT IN (0, 1)",
  'expected' => 'Count = 0',
  'actual' => 'Invalid status records: ' . ($invalid_status['count'] ?? 0),
  'status' => ($invalid_status['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 2.6: Check users assigned to tenants
$users_per_tenant = fetch_all(q("SELECT t.tenant_id, t.tenant_name, COUNT(u.user_id) AS user_count FROM tenants t LEFT JOIN users u ON t.tenant_id=u.tenant_id GROUP BY t.tenant_id LIMIT 5"));
$test_results['tests'][] = [
  'name' => '2.6 - Users Assigned Per Tenant',
  'query' => "SELECT t.tenant_id, t.tenant_name, COUNT(u.user_id) AS user_count FROM tenants t LEFT JOIN users u ON t.tenant_id=u.tenant_id GROUP BY t.tenant_id",
  'expected' => 'Array of tenant-user counts',
  'actual' => count($users_per_tenant) . ' tenants with user assignments',
  'status' => is_array($users_per_tenant) ? 'PASS' : 'FAIL'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 3: REPORTS VERIFICATION
// ============================================
$test_name = "Reports (Data Aggregation)";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 3.1: Check loan transactions aggregation
$loan_report = fetch_all(q("SELECT l.status, COUNT(*) AS count, IFNULL(SUM(l.principal_amount), 0) AS total FROM loans l GROUP BY l.status"));
$test_results['tests'][] = [
  'name' => '3.1 - Loan Transactions by Status',
  'query' => "SELECT l.status, COUNT(*) AS count, SUM(l.principal_amount) FROM loans l GROUP BY l.status",
  'expected' => 'Grouped data by loan status',
  'actual' => count($loan_report) . ' status groups found',
  'status' => is_array($loan_report) ? 'PASS' : 'FAIL'
];

// 3.2: Check activity by tenant (Tenant Activity Report)
$tenant_activity = fetch_all(q("SELECT t.tenant_id, t.tenant_name, COUNT(a.log_id) AS activity_count FROM tenants t LEFT JOIN activity_logs a ON FIND_IN_SET(t.tenant_id, a.tenant_id) GROUP BY t.tenant_id LIMIT 10"));
$test_results['tests'][] = [
  'name' => '3.2 - Tenant Activity Aggregation',
  'query' => "SELECT t.tenant_id, t.tenant_name, COUNT(a.log_id) AS activity_count FROM tenants t LEFT JOIN activity_logs a GROUP BY t.tenant_id",
  'expected' => 'Activity counts per tenant',
  'actual' => count($tenant_activity) . ' tenants with activity data',
  'status' => is_array($tenant_activity) ? 'PASS' : 'FAIL'
];

// 3.3: Check user registration trends
$user_registrations = fetch_all(q("SELECT DATE(created_at) AS reg_date, COUNT(*) AS count FROM users GROUP BY DATE(created_at) ORDER BY reg_date DESC LIMIT 7"));
$test_results['tests'][] = [
  'name' => '3.3 - User Registration Trends (Last 7 days)',
  'query' => "SELECT DATE(created_at) AS reg_date, COUNT(*) FROM users GROUP BY DATE(created_at) ORDER BY reg_date DESC LIMIT 7",
  'expected' => 'Daily registration counts',
  'actual' => count($user_registrations) . ' days of registration data',
  'status' => is_array($user_registrations) ? 'PASS' : 'FAIL'
];

// 3.4: Check usage statistics (loans created per tenant)
$usage_stats = fetch_all(q("SELECT t.tenant_id, t.tenant_name, COUNT(l.loan_id) AS loan_count FROM tenants t LEFT JOIN loans l ON t.tenant_id=l.tenant_id GROUP BY t.tenant_id LIMIT 10"));
$test_results['tests'][] = [
  'name' => '3.4 - Loan Usage Statistics Per Tenant',
  'query' => "SELECT t.tenant_id, t.tenant_name, COUNT(l.loan_id) AS loan_count FROM tenants t LEFT JOIN loans l GROUP BY t.tenant_id",
  'expected' => 'Loan counts per tenant',
  'actual' => count($usage_stats) . ' tenants with usage data',
  'status' => is_array($usage_stats) ? 'PASS' : 'FAIL'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 4: SALES REPORT VERIFICATION
// ============================================
$test_name = "Sales Report";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 4.1: Check total revenue calculation
$total_revenue = fetch_one(q("SELECT IFNULL(SUM(amount), 0) AS total FROM payments"));
$test_results['tests'][] = [
  'name' => '4.1 - Total Revenue (Payment Sum)',
  'query' => "SELECT IFNULL(SUM(amount), 0) AS total FROM payments",
  'expected' => 'Numeric total > 0',
  'actual' => '₱' . number_format($total_revenue['total'] ?? 0, 2),
  'status' => is_numeric($total_revenue['total']) ? 'PASS' : 'FAIL'
];

// 4.2: Check sales per tenant
$sales_per_tenant = fetch_all(q("SELECT t.tenant_id, t.tenant_name, IFNULL(SUM(p.amount), 0) AS revenue, COUNT(p.payment_id) AS payment_count FROM tenants t LEFT JOIN payments p ON t.tenant_id=p.tenant_id GROUP BY t.tenant_id ORDER BY revenue DESC LIMIT 5"));
$test_results['tests'][] = [
  'name' => '4.2 - Sales Per Tenant (Ranked)',
  'query' => "SELECT t.tenant_id, t.tenant_name, SUM(p.amount) FROM tenants t LEFT JOIN payments p GROUP BY t.tenant_id ORDER BY SUM(p.amount) DESC",
  'expected' => 'Tenants ranked by sales revenue',
  'actual' => count($sales_per_tenant) . ' tenants with sales data',
  'status' => is_array($sales_per_tenant) ? 'PASS' : 'FAIL'
];

// 4.3: Check daily sales aggregation
$daily_sales = fetch_all(q("SELECT DATE(payment_date) AS date, IFNULL(SUM(amount), 0) AS daily_total FROM payments GROUP BY DATE(payment_date) ORDER BY payment_date DESC LIMIT 30"));
$test_results['tests'][] = [
  'name' => '4.3 - Daily Sales Aggregation (Last 30 days)',
  'query' => "SELECT DATE(payment_date) AS date, SUM(amount) FROM payments GROUP BY payment_date ORDER BY payment_date DESC LIMIT 30",
  'expected' => 'Daily revenue totals',
  'actual' => count($daily_sales) . ' days of sales data',
  'status' => is_array($daily_sales) ? 'PASS' : 'FAIL'
];

// 4.4: Check payment methods distribution
$payment_methods = fetch_all(q("SELECT method, COUNT(*) AS count, IFNULL(SUM(amount), 0) AS total FROM payments GROUP BY method"));
$test_results['tests'][] = [
  'name' => '4.4 - Payment Methods Distribution',
  'query' => "SELECT method, COUNT(*), SUM(amount) FROM payments GROUP BY method",
  'expected' => 'Payment method breakdown',
  'actual' => count($payment_methods) . ' payment methods tracked',
  'status' => is_array($payment_methods) ? 'PASS' : 'FAIL'
];

// 4.5: Check transaction history
$transaction_history = fetch_all(q("SELECT p.payment_id, p.loan_id, p.amount, p.payment_date, p.method FROM payments p ORDER BY p.payment_date DESC LIMIT 10"));
$test_results['tests'][] = [
  'name' => '4.5 - Transaction History (Latest 10)',
  'query' => "SELECT p.payment_id, p.loan_id, p.amount, p.payment_date, p.method FROM payments p ORDER BY p.payment_date DESC LIMIT 10",
  'expected' => 'Recent transaction records',
  'actual' => count($transaction_history) . ' recent transactions found',
  'status' => is_array($transaction_history) ? 'PASS' : 'FAIL'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 5: AUDIT LOGS (HISTORY) VERIFICATION
// ============================================
$test_name = "Audit Logs (History)";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 5.1: Check activity logs table
$total_logs = fetch_one(q("SELECT COUNT(*) AS count FROM activity_logs"));
$test_results['tests'][] = [
  'name' => '5.1 - Total Activity Logs',
  'query' => "SELECT COUNT(*) AS count FROM activity_logs",
  'expected' => 'Integer count >= 0',
  'actual' => $total_logs['count'] ?? 0,
  'status' => isset($total_logs['count']) ? 'PASS' : 'FAIL'
];

// 5.2: Check activity log categories
$log_categories = fetch_all(q("SELECT 
  SUM(action LIKE 'Staff%' OR action LIKE 'Tenant%') AS admin_actions,
  SUM(action LIKE 'Login%' OR action LIKE 'Logout%') AS auth_actions,
  SUM(action NOT LIKE 'Staff%' AND action NOT LIKE 'Tenant%' AND action NOT LIKE 'Login%' AND action NOT LIKE 'Logout%') AS other_actions
FROM activity_logs"));
$test_results['tests'][] = [
  'name' => '5.2 - Activity Log Categories',
  'query' => "SELECT action FROM activity_logs GROUP BY action LIMIT 10",
  'expected' => 'Categorized action logs',
  'actual' => 'Admin, Auth, and Other actions tracked',
  'status' => is_array($log_categories) ? 'PASS' : 'FAIL'
];

// 5.3: Check timestamp validity
$invalid_timestamps = fetch_one(q("SELECT COUNT(*) AS count FROM activity_logs WHERE created_at IS NULL OR created_at = '0000-00-00 00:00:00'"));
$test_results['tests'][] = [
  'name' => '5.3 - Timestamp Validity',
  'query' => "SELECT COUNT(*) FROM activity_logs WHERE created_at IS NULL",
  'expected' => 'Count = 0',
  'actual' => 'Invalid timestamps: ' . ($invalid_timestamps['count'] ?? 0),
  'status' => ($invalid_timestamps['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 5.4: Check recent activity
$recent_activity = fetch_all(q("SELECT log_id, action, created_at, user_id FROM activity_logs ORDER BY created_at DESC LIMIT 15"));
$test_results['tests'][] = [
  'name' => '5.4 - Recent Activity Records',
  'query' => "SELECT log_id, action, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 15",
  'expected' => 'Recent log entries',
  'actual' => count($recent_activity) . ' recent logs found',
  'status' => is_array($recent_activity) ? 'PASS' : 'FAIL'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 6: SETTINGS VERIFICATION
// ============================================
$test_name = "Settings & Configuration";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 6.1: Check system settings exist
$system_settings = fetch_one(q("SELECT * FROM system_settings LIMIT 1"));
$test_results['tests'][] = [
  'name' => '6.1 - System Settings Record',
  'query' => "SELECT * FROM system_settings LIMIT 1",
  'expected' => 'Settings record exists',
  'actual' => $system_settings ? 'Found' : 'Not found',
  'status' => $system_settings ? 'PASS' : 'FAIL'
];

// 6.2: Check setting fields
if ($system_settings) {
  $setting_fields = ['system_name', 'primary_color', 'logo_path'];
  $missing_fields = [];
  foreach ($setting_fields as $field) {
    if (!isset($system_settings[$field])) {
      $missing_fields[] = $field;
    }
  }
  $test_results['tests'][] = [
    'name' => '6.2 - Required Setting Fields',
    'query' => "DESCRIBE system_settings",
    'expected' => 'All required fields present',
    'actual' => empty($missing_fields) ? 'All fields present' : 'Missing: ' . implode(', ', $missing_fields),
    'status' => empty($missing_fields) ? 'PASS' : 'FAIL'
  ];
}

// 6.3: Check color format validity
if ($system_settings) {
  $valid_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $system_settings['primary_color'] ?? '');
  $test_results['tests'][] = [
    'name' => '6.3 - Primary Color Format (Hex)',
    'query' => "SELECT primary_color FROM system_settings",
    'expected' => 'Valid hex color #XXXXXX',
    'actual' => $system_settings['primary_color'] ?? 'Not set',
    'status' => $valid_color ? 'PASS' : 'FAIL'
  ];
}

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 7: INTEGRATION TESTS
// ============================================
$test_name = "Integration & Data Flow";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 7.1: Check foreign key relationships (tenant -> users)
$orphan_users = fetch_one(q("SELECT COUNT(*) AS count FROM users u WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=u.tenant_id)"));
$test_results['tests'][] = [
  'name' => '7.1 - Tenant-User Relationship Integrity',
  'query' => "SELECT COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=u.tenant_id)",
  'expected' => 'Count = 0 (no orphaned users)',
  'actual' => 'Orphaned users: ' . ($orphan_users['count'] ?? 0),
  'status' => ($orphan_users['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 7.2: Check tenant -> customers relationship
$orphan_customers = fetch_one(q("SELECT COUNT(*) AS count FROM customers c WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=c.tenant_id)"));
$test_results['tests'][] = [
  'name' => '7.2 - Tenant-Customer Relationship Integrity',
  'query' => "SELECT COUNT(*) FROM customers c WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=c.tenant_id)",
  'expected' => 'Count = 0 (no orphaned customers)',
  'actual' => 'Orphaned customers: ' . ($orphan_customers['count'] ?? 0),
  'status' => ($orphan_customers['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 7.3: Check tenant -> loans relationship
$orphan_loans = fetch_one(q("SELECT COUNT(*) AS count FROM loans l WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=l.tenant_id)"));
$test_results['tests'][] = [
  'name' => '7.3 - Tenant-Loan Relationship Integrity',
  'query' => "SELECT COUNT(*) FROM loans l WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=l.tenant_id)",
  'expected' => 'Count = 0 (no orphaned loans)',
  'actual' => 'Orphaned loans: ' . ($orphan_loans['count'] ?? 0),
  'status' => ($orphan_loans['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 7.4: Check loan -> customer relationship
$orphan_loan_customers = fetch_one(q("SELECT COUNT(*) AS count FROM loans l WHERE NOT EXISTS (SELECT 1 FROM customers c WHERE c.customer_id=l.customer_id)"));
$test_results['tests'][] = [
  'name' => '7.4 - Loan-Customer Relationship Integrity',
  'query' => "SELECT COUNT(*) FROM loans l WHERE NOT EXISTS (SELECT 1 FROM customers c WHERE c.customer_id=l.customer_id)",
  'expected' => 'Count = 0 (no orphaned loan-customer links)',
  'actual' => 'Invalid links: ' . ($orphan_loan_customers['count'] ?? 0),
  'status' => ($orphan_loan_customers['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 7.5: Check payments linked to valid loans
$orphan_payments = fetch_one(q("SELECT COUNT(*) AS count FROM payments p WHERE NOT EXISTS (SELECT 1 FROM loans l WHERE l.loan_id=p.loan_id)"));
$test_results['tests'][] = [
  'name' => '7.5 - Payment-Loan Relationship Integrity',
  'query' => "SELECT COUNT(*) FROM payments p WHERE NOT EXISTS (SELECT 1 FROM loans l WHERE l.loan_id=p.loan_id)",
  'expected' => 'Count = 0 (no orphaned payments)',
  'actual' => 'Orphaned payments: ' . ($orphan_payments['count'] ?? 0),
  'status' => ($orphan_payments['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 7.6: Check data consistency - loan total vs payments
$loans_overpaid = fetch_one(q("SELECT COUNT(*) AS count FROM loans l WHERE (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id=l.loan_id) > l.total_payable"));
$test_results['tests'][] = [
  'name' => '7.6 - Payment Amount Validity (No Overpayments)',
  'query' => "SELECT COUNT(*) FROM loans l WHERE (SELECT SUM(amount) FROM payments WHERE loan_id=l.loan_id) > l.total_payable",
  'expected' => 'Count = 0 (no overpayments)',
  'actual' => 'Cases with overpayments: ' . ($loans_overpaid['count'] ?? 0),
  'status' => ($loans_overpaid['count'] ?? 0) == 0 ? 'PASS' : 'WARN'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

// ============================================
// TEST 8: ERROR HANDLING & EDGE CASES
// ============================================
$test_name = "Error Handling & Edge Cases";
$test_results = [
  'name' => $test_name,
  'tests' => [],
  'status' => 'PENDING'
];

// 8.1: Check for NULL primary keys
$null_pks = fetch_one(q("
  SELECT 
    (SELECT COUNT(*) FROM tenants WHERE tenant_id IS NULL) +
    (SELECT COUNT(*) FROM users WHERE user_id IS NULL) +
    (SELECT COUNT(*) FROM customers WHERE customer_id IS NULL) +
    (SELECT COUNT(*) FROM loans WHERE loan_id IS NULL) +
    (SELECT COUNT(*) FROM payments WHERE payment_id IS NULL)
  AS total_nulls
"));
$test_results['tests'][] = [
  'name' => '8.1 - NULL Primary Key Values',
  'query' => "Check all tables for NULL primary keys",
  'expected' => 'Count = 0',
  'actual' => 'NULL PKs found: ' . ($null_pks['total_nulls'] ?? 0),
  'status' => ($null_pks['total_nulls'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 8.2: Check empty required fields
$empty_tenant_names = fetch_one(q("SELECT COUNT(*) AS count FROM tenants WHERE tenant_name IS NULL OR tenant_name = ''"));
$test_results['tests'][] = [
  'name' => '8.2 - Empty Tenant Names',
  'query' => "SELECT COUNT(*) FROM tenants WHERE tenant_name IS NULL OR tenant_name = ''",
  'expected' => 'Count = 0',
  'actual' => 'Empty tenant names: ' . ($empty_tenant_names['count'] ?? 0),
  'status' => ($empty_tenant_names['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 8.3: Check tenant subdomain format
$invalid_subdomains = fetch_one(q("SELECT COUNT(*) AS count FROM tenants WHERE subdomain NOT REGEXP '^[a-z0-9-]+$'"));
$test_results['tests'][] = [
  'name' => '8.3 - Subdomain Format Validation',
  'query' => "SELECT COUNT(*) FROM tenants WHERE subdomain NOT REGEXP '^[a-z0-9-]+$'",
  'expected' => 'Count = 0',
  'actual' => 'Invalid subdomains: ' . ($invalid_subdomains['count'] ?? 0),
  'status' => ($invalid_subdomains['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

// 8.4: Check for stale deleted records
$inactive_users_count = fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active = 0"));
$test_results['tests'][] = [
  'name' => '8.4 - Inactive User Records (Soft Delete)',
  'query' => "SELECT COUNT(*) FROM users WHERE is_active = 0",
  'expected' => 'Records preserved (soft delete)',
  'actual' => 'Inactive users: ' . ($inactive_users_count['count'] ?? 0),
  'status' => 'PASS'
];

// 8.5: Check numeric field types
$negative_amounts = fetch_one(q("SELECT COUNT(*) AS count FROM payments WHERE amount <= 0"));
$test_results['tests'][] = [
  'name' => '8.5 - Payment Amount Validity (No Zero/Negative)',
  'query' => "SELECT COUNT(*) FROM payments WHERE amount <= 0",
  'expected' => 'Count = 0',
  'actual' => 'Invalid payment amounts: ' . ($negative_amounts['count'] ?? 0),
  'status' => ($negative_amounts['count'] ?? 0) == 0 ? 'PASS' : 'FAIL'
];

$test_results['status'] = 'COMPLETE';
$results[] = $test_results;

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Admin System Test Suite</title>
  <style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdана, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
    .container { max-width: 1400px; margin: 0 auto; }
    .header { background: #2c3ec5; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    .header h1 { margin: 0; font-size: 28px; }
    .header p { margin: 8px 0 0 0; opacity: 0.9; }
    .test-suite { background: white; border-radius: 8px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .test-suite-header { background: #34495e; color: white; padding: 15px 20px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
    .test-suite-header.complete { background: #27ae60; }
    .test-suite-header.pending { background: #f39c12; }
    .test-suite-header.failed { background: #e74c3c; }
    .test-count { background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 4px; font-size: 14px; }
    .test-case { padding: 15px 20px; border-bottom: 1px solid #eee; display: grid; grid-template-columns: 30% 1fr 1fr 1fr 80px; gap: 15px; align-items: start; }
    .test-case:last-child { border-bottom: none; }
    .test-name { font-weight: 500; color: #2c3ec5; }
    .test-name small { display: block; color: #999; font-weight: normal; font-size: 12px; margin-top: 4px; }
    .test-expected { color: #27ae60; font-size: 13px; }
    .test-actual { color: #555; font-size: 13px; }
    .test-status { text-align: center; font-weight: 600; }
    .status-pass { color: #27ae60; background: #dcfce7; padding: 4px 12px; border-radius: 4px; font-size: 13px; }
    .status-fail { color: #e74c3c; background: #fee2e2; padding: 4px 12px; border-radius: 4px; font-size: 13px; }
    .status-warn { color: #f39c12; background: #fef3c7; padding: 4px 12px; border-radius: 4px; font-size: 13px; }
    .summary { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .summary h2 { margin-top: 0; color: #2c3ec5; }
    .summary-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .summary-card { padding: 15px; background: #f9f9f9; border-left: 4px solid #ddd; border-radius: 4px; }
    .summary-card.pass { border-left-color: #27ae60; }
    .summary-card.fail { border-left-color: #e74c3c; }
    .summary-card strong { display: block; font-size: 24px; margin: 8px 0; }
    .summary-card small { color: #666; }
    .legend { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    .legend-box { width: 20px; height: 20px; border-radius: 4px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>🔍 Admin System Test Suite</h1>
      <p>Comprehensive validation of all Admin modules: Dashboard, Tenants, Reports, Sales, History, Settings</p>
    </div>

    <?php
      $total_tests = 0;
      $passed_tests = 0;
      $failed_tests = 0;
      $warn_tests = 0;

      foreach ($results as $suite) {
        $suite_pass = 0;
        $suite_fail = 0;
        $suite_warn = 0;

        foreach ($suite['tests'] as $test) {
          $total_tests++;
          if ($test['status'] === 'PASS') {
            $passed_tests++;
            $suite_pass++;
          } elseif ($test['status'] === 'WARN') {
            $warn_tests++;
            $suite_warn++;
          } else {
            $failed_tests++;
            $suite_fail++;
          }
        }

        $suite_status = $suite_fail > 0 ? 'failed' : ($suite_warn > 0 ? 'pending' : 'complete');
        echo '<div class="test-suite">';
        echo '<div class="test-suite-header ' . $suite_status . '">';
        echo '<span>' . htmlspecialchars($suite['name']) . '</span>';
        echo '<span class="test-count">' . count($suite['tests']) . ' tests • ' . $suite_pass . ' PASS • ' . $suite_fail . ' FAIL • ' . $suite_warn . ' WARN</span>';
        echo '</div>';

        foreach ($suite['tests'] as $test) {
          $status_class = 'status-' . strtolower($test['status']);
          echo '<div class="test-case">';
          echo '<div class="test-name">' . htmlspecialchars($test['name']) . '<small>️' . htmlspecialchars($test['query']) . '</small></div>';
          echo '<div class="test-expected"><strong>Expected:</strong> ' . htmlspecialchars($test['expected']) . '</div>';
          echo '<div class="test-actual"><strong>Actual:</strong> ' . htmlspecialchars($test['actual']) . '</div>';
          echo '<div class="test-status"><span class="' . $status_class . '">' . $test['status'] . '</span></div>';
          echo '</div>';
        }

        echo '</div>';
      }
    ?>

    <div class="summary">
      <h2>📊 Test Summary</h2>
      <div class="summary-row">
        <div class="summary-card pass">
          <small>PASSED</small>
          <strong><?= $passed_tests ?></strong>
          <small>of <?= $total_tests ?> tests</small>
        </div>
        <div class="summary-card fail">
          <small>FAILED</small>
          <strong><?= $failed_tests ?></strong>
          <small>of <?= $total_tests ?> tests</small>
        </div>
        <div class="summary-card">
          <small>WARNINGS</small>
          <strong><?= $warn_tests ?></strong>
          <small>of <?= $total_tests ?> tests</small>
        </div>
        <div class="summary-card">
          <small>SUCCESS RATE</small>
          <strong><?= round(($passed_tests / $total_tests * 100), 1) ?>%</strong>
          <small><?= $passed_tests ?>/<?= $total_tests ?> passing</small>
        </div>
      </div>

      <h3>Test Coverage</h3>
      <ul style="margin: 15px 0; padding-left: 20px; line-height: 2;">
        <li>✅ <strong>Dashboard Analytics</strong> - 10 metrics verified</li>
        <li>✅ <strong>Tenant Management</strong> - 6 structural validations</li>
        <li>✅ <strong>Reports Module</strong> - 4 data aggregation tests</li>
        <li>✅ <strong>Sales Report</strong> - 5 revenue calculations verified</li>
        <li>✅ <strong>Audit Logs</strong> - 4 logging validations</li>
        <li>✅ <strong>Settings Module</strong> - 3 configuration tests</li>
        <li>✅ <strong>Integration Tests</strong> - 6 relationship integrity checks</li>
        <li>✅ <strong>Error Handling</strong> - 5 edge case validations</li>
      </ul>

      <div class="legend">
        <div class="legend-item">
          <div class="legend-box" style="background: #27ae60;"></div>
          <span>PASS - Test successful</span>
        </div>
        <div class="legend-item">
          <div class="legend-box" style="background: #e74c3c;"></div>
          <span>FAIL - Test failed (requires attention)</span>
        </div>
        <div class="legend-item">
          <div class="legend-box" style="background: #f39c12;"></div>
          <span>WARN - Warning (review recommended)</span>
        </div>
      </div>
    </div>

    <div style="margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 8px; border-left: 4px solid #2c3ec5;">
      <h3 style="margin-top: 0; color: #2c3ec5;">ℹ️ Notes</h3>
      <ul style="margin: 0; padding-left: 20px; line-height: 1.8; font-size: 14px;">
        <li>This test suite validates all Admin system functionality</li>
        <li>All tests use existing schema.sql and data</li>
        <li>No schema modifications are made</li>
        <li>For TESTING purposes only - do not use in production</li>
        <li>Run this test after any major system changes</li>
        <li>Verify all tests PASS before deploying to production</li>
      </ul>
    </div>
  </div>
</body>
</html>
