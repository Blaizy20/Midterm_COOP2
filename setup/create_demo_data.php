<?php
/**
 * ============================================
 * DEMO DATA CREATION SCRIPT
 * ============================================
 * 
 * Creates sample data for testing all Admin features:
 * - Tenants
 * - Staff/Users
 * - Customers
 * - Loans
 * - Payments
 * - Activity Logs
 * 
 * To run: http://localhost/LOAN_MANAGEMENT_APP/setup/create_demo_data.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

// Security check
if (!is_system_admin()) {
  die('Access Denied: Admin only');
}

echo "<h2>Creating Demo Data...</h2>";
echo "<style>
  body { font-family: Arial; margin: 20px; }
  .success { color: green; padding: 10px; background: #efe; border: 1px solid #0c0; margin: 10px 0; }
  .error { color: red; padding: 10px; background: #fee; border: 1px solid #c00; margin: 10px 0; }
  h3 { border-bottom: 2px solid #333; padding-bottom: 10px; margin-top: 30px; }
</style>";

$sql_executed = 0;
$errors = [];

// ============================================
// 1. CREATE DEMO TENANTS
// ============================================
echo "<h3>1. Creating Demo Tenants...</h3>";

$tenants = [
  ['name' => 'Metro Finance Corp', 'subdomain' => 'metrofinance'],
  ['name' => 'Sunrise Lending Ltd', 'subdomain' => 'sunriseloans'],
  ['name' => 'Progressive Credit Union', 'subdomain' => 'progressivecredit'],
];

foreach ($tenants as $tenant) {
  $result = q("INSERT IGNORE INTO tenants (tenant_name, subdomain, is_active, created_at) 
    VALUES (?, ?, 1, NOW())", 
    "ss", 
    [$tenant['name'], $tenant['subdomain']]
  );
  if ($result) {
    echo "<div class='success'>✓ Tenant created: {$tenant['name']}</div>";
    $sql_executed++;
  }
}

// Get first tenant for other data
$first_tenant = fetch_one(q("SELECT tenant_id FROM tenants LIMIT 1"));
$tenant_id = $first_tenant['tenant_id'] ?? 1;

// ============================================
// 2. CREATE DEMO STAFF
// ============================================
echo "<h3>2. Creating Demo Staff/Users...</h3>";

$staff = [
  ['name' => 'John Manager', 'username' => 'john_manager', 'email' => 'john@demo.com', 'role' => 'MANAGER', 'password' => password_hash('demo123', PASSWORD_DEFAULT)],
  ['name' => 'Jane Investigator', 'username' => 'jane_inv', 'email' => 'jane@demo.com', 'role' => 'CREDIT_INVESTIGATOR', 'password' => password_hash('demo123', PASSWORD_DEFAULT)],
  ['name' => 'Bob Officer', 'username' => 'bob_officer', 'email' => 'bob@demo.com', 'role' => 'LOAN_OFFICER', 'password' => password_hash('demo123', PASSWORD_DEFAULT)],
  ['name' => 'Alice Cashier', 'username' => 'alice_cashier', 'email' => 'alice@demo.com', 'role' => 'CASHIER', 'password' => password_hash('demo123', PASSWORD_DEFAULT)],
];

foreach ($staff as $user) {
  $result = q("INSERT IGNORE INTO users (full_name, username, email, password_hash, role, tenant_id, is_active, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())", 
    "sssssi", 
    [$user['name'], $user['username'], $user['email'], $user['password'], $user['role'], $tenant_id]
  );
  if ($result) {
    echo "<div class='success'>✓ Staff created: {$user['name']} ({$user['role']}) - Username: {$user['username']}</div>";
    $sql_executed++;
  }
}

// ============================================
// 3. CREATE DEMO CUSTOMERS
// ============================================
echo "<h3>3. Creating Demo Customers...</h3>";

$customers = [
  ['first' => 'Maria', 'last' => 'Santos', 'contact' => '09171234567', 'province' => 'Metro Manila', 'city' => 'Manila', 'barangay' => 'Barangay 1', 'street' => '123 Main St'],
  ['first' => 'Juan', 'last' => 'Dela Cruz', 'contact' => '09175678901', 'province' => 'Metro Manila', 'city' => 'Makati', 'barangay' => 'Barangay 2', 'street' => '456 Oak Ave'],
  ['first' => 'Rosa', 'last' => 'Reyes', 'contact' => '09179876543', 'province' => 'Metro Manila', 'city' => 'Quezon City', 'barangay' => 'Barangay 3', 'street' => '789 Pine Rd'],
  ['first' => 'Pedro', 'last' => 'Garcia', 'contact' => '09161234567', 'province' => 'Cavite', 'city' => 'Cavite City', 'barangay' => 'Barangay 4', 'street' => '321 Elm St'],
  ['first' => 'Ana', 'last' => 'Lopez', 'contact' => '09163456789', 'province' => 'Laguna', 'city' => 'Laguna', 'barangay' => 'Barangay 5', 'street' => '654 Maple Dr'],
];

$customer_ids = [];
foreach ($customers as $cust) {
  $cust_no = 'C-' . strtoupper(substr($cust['first'], 0, 1) . substr($cust['last'], 0, 1)) . '-' . rand(1000, 9999);
  $result = q("INSERT IGNORE INTO customers (tenant_id, customer_no, first_name, last_name, contact_no, province, city, barangay, street, is_active, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())", 
    "issssssss", 
    [$tenant_id, $cust_no, $cust['first'], $cust['last'], $cust['contact'], $cust['province'], $cust['city'], $cust['barangay'], $cust['street']]
  );
  if ($result) {
    $saved_cust = fetch_one(q("SELECT customer_id FROM customers WHERE customer_no = ?", "s", [$cust_no]));
    if ($saved_cust) {
      $customer_ids[] = $saved_cust['customer_id'];
      echo "<div class='success'>✓ Customer created: {$cust['first']} {$cust['last']}</div>";
      $sql_executed++;
    }
  }
}

// ============================================
// 4. CREATE DEMO LOANS
// ============================================
echo "<h3>4. Creating Demo Loans...</h3>";

$loan_statuses = ['PENDING', 'CI_REVIEWED', 'ACTIVE', 'OVERDUE', 'CLOSED'];
$loan_count = 0;

if (!empty($customer_ids)) {
  foreach ($customer_ids as $cust_id) {
    $principal = rand(10000, 100000);
    $status = $loan_statuses[rand(0, 4)];
    $ref_no = 'LN-' . date('Y') . rand(10000, 99999);
    $term_months = rand(6, 24);
    
    $result = q("INSERT IGNORE INTO loans (tenant_id, customer_id, reference_no, principal_amount, status, term_months) 
      VALUES (?, ?, ?, ?, ?, ?)", 
      "iisdsi", 
      [$tenant_id, $cust_id, $ref_no, $principal, $status, $term_months]
    );
    if ($result) {
      echo "<div class='success'>✓ Loan created: {$ref_no} - ₱" . number_format($principal, 2) . " ({$status})</div>";
      $sql_executed++;
      $loan_count++;
    }
  }
}

// ============================================
// 5. CREATE DEMO PAYMENTS
// ============================================
echo "<h3>5. Creating Demo Payments...</h3>";

$loan_result = fetch_all(q("SELECT loan_id, principal_amount FROM loans WHERE tenant_id = ? LIMIT 3", "i", [$tenant_id]));
$payment_methods = ['CASH', 'CHEQUE', 'GCASH', 'BANK_TRANSFER'];

if (!empty($loan_result)) {
  foreach ($loan_result as $loan) {
    for ($i = 1; $i <= rand(1, 3); $i++) {
      $amount = (float)$loan['principal_amount'] / rand(2, 4);
      $method = $payment_methods[rand(0, 3)];
      $payment_date = date('Y-m-d', strtotime('-' . rand(1, 30) . ' days'));
      
      $result = q("INSERT INTO payments (tenant_id, loan_id, amount, payment_date, method, or_no, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())", 
        "iidsss", 
        [$tenant_id, $loan['loan_id'], $amount, $payment_date, $method, 'OR-' . rand(100000, 999999)]
      );
      if ($result) {
        echo "<div class='success'>✓ Payment recorded: ₱" . number_format($amount, 2) . " via {$method}</div>";
        $sql_executed++;
      }
    }
  }
}

// ============================================
// 6. CREATE DEMO ACTIVITY LOGS
// ============================================
echo "<h3>6. Creating Demo Activity Logs...</h3>";

$activities = [
  ['action' => 'USER_LOGIN', 'description' => 'User logged in'],
  ['action' => 'LOAN_CREATED', 'description' => 'New loan application submitted'],
  ['action' => 'LOAN_APPROVED', 'description' => 'Loan approved by manager'],
  ['action' => 'PAYMENT_RECORDED', 'description' => 'Payment recorded in system'],
  ['action' => 'TENANT_CREATED', 'description' => 'New tenant registered'],
  ['action' => 'SETTINGS_CHANGED', 'description' => 'System settings updated'],
  ['action' => 'CUSTOMER_REGISTERED', 'description' => 'New customer registered'],
  ['action' => 'STAFF_CREATED', 'description' => 'New staff account created'],
];

$activity_count = 0;
foreach ($activities as $act) {
  for ($i = 0; $i < 3; $i++) {
    $result = q("INSERT INTO activity_logs (tenant_id, user_id, user_role, action, description) 
      VALUES (?, ?, ?, ?, ?)", 
      "iisss", 
      [$tenant_id, 1, 'ADMIN', $act['action'], $act['description']]
    );
    if ($result) {
      $activity_count++;
      $sql_executed++;
    }
  }
}
echo "<div class='success'>✓ Created {$activity_count} activity log entries</div>";

// ============================================
// 7. VERIFY DATA
// ============================================
echo "<h3>7. Data Verification Summary</h3>";

$counts = [
  'Tenants' => fetch_one(q("SELECT COUNT(*) AS count FROM tenants")),
  'Staff/Users' => fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE role NOT IN ('SUPER_ADMIN','ADMIN'")),
  'Customers' => fetch_one(q("SELECT COUNT(*) AS count FROM customers")),
  'Loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans")),
  'Payments' => fetch_one(q("SELECT COUNT(*) AS count FROM payments")),
  'Activity Logs' => fetch_one(q("SELECT COUNT(*) AS count FROM activity_logs")),
];

echo "<table style='border-collapse: collapse; margin: 20px 0;'>";
echo "<tr style='background: #f0f0f0;'><th style='border: 1px solid #ccc; padding: 10px;'>Entity</th><th style='border: 1px solid #ccc; padding: 10px;'>Count</th></tr>";

foreach ($counts as $label => $result) {
  $num = $result['count'] ?? 0;
  echo "<tr><td style='border: 1px solid #ccc; padding: 10px;'>{$label}</td><td style='border: 1px solid #ccc; padding: 10px;'>{$num}</td></tr>";
}
echo "</table>";

// ============================================
// 8. QUICK LINKS
// ============================================
echo "<h3>8. Next Steps - View Your Features</h3>";

echo "<div style='background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
echo "<h4>✓ Demo data created! Now you can see all features in action:</h4>";
echo "<ul style='font-size: 16px; line-height: 1.8;'>";
echo "<li><strong>📊 Admin Dashboard:</strong> <a href='../staff/dashboard.php' target='_blank'>View Dashboard</a> - See all metrics, charts, and system overview</li>";
echo "<li><strong>🏢 Tenant Management:</strong> <a href='../staff/registration.php' target='_blank'>View Registration</a> - See registered tenants and manage them</li>";
echo "<li><strong>📈 Reports:</strong> <a href='../staff/reports.php' target='_blank'>View Reports</a> - See all 4 report types with real data</li>";
echo "<li><strong>💰 Sales Report:</strong> <a href='../staff/sales_report.php' target='_blank'>View Sales</a> - See revenue and payment metrics</li>";
echo "<li><strong>📋 Audit/History Logs:</strong> <a href='../staff/history.php' target='_blank'>View History</a> - See all activity logs and actions</li>";
echo "<li><strong>⚙️ Settings:</strong> <a href='../staff/manager_settings.php' target='_blank'>View Settings</a> - Manage system configuration</li>";
echo "<li><strong>🧪 Run Tests:</strong> <a href='../test_admin_system.php' target='_blank'>Run Test Suite</a> - Automated validation of all features</li>";
echo "</ul>";
echo "</div>";

echo "<div style='background: #fffacd; border: 1px solid #daa520; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
echo "<h4>📝 Demo Credentials (4 Staff Accounts):</h4>";
echo "<ul>";
echo "<li><strong>Manager:</strong> Username: john_manager | Password: demo123</li>";
echo "<li><strong>Credit Investigator:</strong> Username: jane_inv | Password: demo123</li>";
echo "<li><strong>Loan Officer:</strong> Username: bob_officer | Password: demo123</li>";
echo "<li><strong>Cashier:</strong> Username: alice_cashier | Password: demo123</li>";
echo "</ul>";
echo "<p style='color: #999;'><em>Use any of these accounts to log in and see the features with demo data</em></p>";
echo "</div>";

echo "<div style='background: #f0f0f0; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
echo "<h4>✅ Total SQL Operations Executed: {$sql_executed}</h4>";
echo "<p>All demo data has been inserted into the database. You can now use the system with real-looking data to test all features.</p>";
echo "</div>";

echo "<p style='margin-top: 30px; color: #666;'><a href='javascript:location.reload()'>↻ Refresh to create more data</a> | <a href='../staff/dashboard.php'>→ Go to Dashboard</a></p>";
?>
