-- ============================================
-- ADMIN SYSTEM SQL VALIDATION QUERIES
-- ============================================
-- 
-- Purpose: Quick SQL checks for system integrity
-- Database: loan_management_app
-- Run these queries to validate data consistency
--
-- Copy and run each query in phpMyAdmin or command line
-- ============================================

-- ============================================
-- 1. DASHBOARD METRICS VERIFICATION
-- ============================================

-- 1.1 Total Tenants Count
SELECT COUNT(*) AS total_tenants FROM tenants;
-- Expected: Integer >= 1

-- 1.2 Active Users Count
SELECT COUNT(*) AS active_users FROM users WHERE is_active = 1;
-- Expected: Integer >= 1

-- 1.3 Inactive Users Count
SELECT COUNT(*) AS inactive_users FROM users WHERE is_active = 0;
-- Expected: Integer >= 0

-- 1.4 Total Customers
SELECT COUNT(*) AS total_customers FROM customers WHERE is_active = 1;
-- Expected: Integer >= 0

-- 1.5 Total Loans
SELECT COUNT(*) AS total_loans FROM loans;
-- Expected: Integer >= 0

-- 1.6 Loans by Status
SELECT 
  SUM(status='PENDING') AS pending,
  SUM(status='CI_REVIEWED') AS ci_reviewed,
  SUM(status='APPROVED') AS approved,
  SUM(status='DENIED') AS denied,
  SUM(status='ACTIVE') AS active,
  SUM(status='OVERDUE') AS overdue,
  SUM(status='CLOSED') AS closed
FROM loans;
-- Expected: Integers with expected breakdown

-- 1.7 Portfolio Value
SELECT IFNULL(SUM(principal_amount), 0) AS portfolio_value 
FROM loans 
WHERE status IN ('ACTIVE', 'OVERDUE');
-- Expected: Numeric value >= 0

-- 1.8 Total Payments & Revenue
SELECT 
  COUNT(*) AS total_transactions,
  IFNULL(SUM(amount), 0) AS total_revenue,
  IFNULL(AVG(amount), 0) AS average_transaction
FROM payments;
-- Expected: All numeric values

-- 1.9 Staff by Role
SELECT 
  role,
  COUNT(*) AS count
FROM users 
WHERE role IN ('ADMIN', 'MANAGER', 'CREDIT_INVESTIGATOR', 'LOAN_OFFICER', 'CASHIER')
GROUP BY role;
-- Expected: All staff roles with counts

-- ============================================
-- 2. TENANT MANAGEMENT INTEGRITY
-- ============================================

-- 2.1 All Tenants Details
SELECT 
  tenant_id,
  tenant_name,
  subdomain,
  display_name,
  is_active,
  created_at
FROM tenants
ORDER BY created_at DESC;
-- Expected: All tenants with complete info

-- 2.2 Check for Duplicate Tenant Names
SELECT tenant_name, COUNT(*) AS count
FROM tenants
GROUP BY tenant_name
HAVING COUNT(*) > 1;
-- Expected: 0 rows (no duplicates)

-- 2.3 Check for Duplicate Subdomains
SELECT subdomain, COUNT(*) AS count
FROM tenants
GROUP BY subdomain
HAVING COUNT(*) > 1;
-- Expected: 0 rows (no duplicates)

-- 2.4 Tenants with Invalid Status
SELECT COUNT(*) AS invalid_status_count
FROM tenants
WHERE is_active NOT IN (0, 1);
-- Expected: 0 rows

-- 2.5 Users Assigned Per Tenant
SELECT 
  t.tenant_id,
  t.tenant_name,
  COUNT(u.user_id) AS user_count
FROM tenants t
LEFT JOIN users u ON t.tenant_id = u.tenant_id
GROUP BY t.tenant_id
ORDER BY user_count DESC;
-- Expected: Tenant-user assignment counts

-- 2.6 Tenants without Admin User
SELECT DISTINCT
  t.tenant_id,
  t.tenant_name,
  COUNT(u.user_id) AS admin_count
FROM tenants t
LEFT JOIN users u ON t.tenant_id = u.tenant_id AND u.role IN ('SUPER_ADMIN','ADMIN')
GROUP BY t.tenant_id
HAVING COUNT(u.user_id) = 0;
-- Expected: 0 rows (each tenant should have admin)

-- ============================================
-- 3. DATA AGGREGATION & REPORTS
-- ============================================

-- 3.1 Loan Transactions by Status
SELECT 
  status,
  COUNT(*) AS count,
  IFNULL(SUM(principal_amount), 0) AS total_principal
FROM loans
GROUP BY status
ORDER BY count DESC;
-- Expected: Loan counts and totals grouped by status

-- 3.2 Tenant Activity Count
SELECT 
  t.tenant_id,
  t.tenant_name,
  COUNT(a.log_id) AS activity_count
FROM tenants t
LEFT JOIN activity_logs a ON FIND_IN_SET(t.tenant_id, a.tenant_id)
GROUP BY t.tenant_id
ORDER BY activity_count DESC;
-- Expected: Activity counts per tenant

-- 3.3 User Registration Trend (Last 7 Days)
SELECT 
  DATE(created_at) AS registration_date,
  COUNT(*) AS new_users
FROM users
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY registration_date DESC;
-- Expected: Daily user counts for last 7 days

-- 3.4 Usage Statistics (Loans Per Tenant)
SELECT 
  t.tenant_id,
  t.tenant_name,
  COUNT(l.loan_id) AS loan_count,
  IFNULL(SUM(l.principal_amount), 0) AS total_principal
FROM tenants t
LEFT JOIN loans l ON t.tenant_id = l.tenant_id
GROUP BY t.tenant_id
ORDER BY loan_count DESC;
-- Expected: Loan counts and totals per tenant

-- ============================================
-- 4. SALES & PAYMENT VERIFICATION
-- ============================================

-- 4.1 Total Revenue by Tenant (Top 10)
SELECT 
  t.tenant_id,
  t.tenant_name,
  COUNT(p.payment_id) AS payment_count,
  IFNULL(SUM(p.amount), 0) AS revenue
FROM tenants t
LEFT JOIN payments p ON t.tenant_id = p.tenant_id
GROUP BY t.tenant_id
ORDER BY revenue DESC
LIMIT 10;
-- Expected: Top 10 tenants by revenue

-- 4.2 Daily Sales (Last 30 Days)
SELECT 
  DATE(payment_date) AS sale_date,
  COUNT(*) AS transaction_count,
  IFNULL(SUM(amount), 0) AS daily_revenue
FROM payments
WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(payment_date)
ORDER BY payment_date DESC;
-- Expected: Daily sales aggregation

-- 4.3 Payment Methods Distribution
SELECT 
  method,
  COUNT(*) AS transaction_count,
  IFNULL(SUM(amount), 0) AS total_amount
FROM payments
GROUP BY method
ORDER BY total_amount DESC;
-- Expected: Payment method breakdown

-- 4.4 Monthly Revenue Trend (Last 12 Months)
SELECT 
  YEAR(payment_date) AS year,
  MONTH(payment_date) AS month,
  MONTHNAME(payment_date) AS month_name,
  COUNT(*) AS transaction_count,
  IFNULL(SUM(amount), 0) AS monthly_revenue
FROM payments
WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
GROUP BY YEAR(payment_date), MONTH(payment_date)
ORDER BY year DESC, month DESC;
-- Expected: Monthly revenue trend

-- 4.5 Top Customers by Payment Amount
SELECT 
  c.customer_id,
  CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
  COUNT(p.payment_id) AS payment_count,
  IFNULL(SUM(p.amount), 0) AS total_paid
FROM customers c
LEFT JOIN loans l ON c.customer_id = l.customer_id
LEFT JOIN payments p ON l.loan_id = p.loan_id
GROUP BY c.customer_id
ORDER BY total_paid DESC
LIMIT 10;
-- Expected: Top 10 customers by payment amount

-- ============================================
-- 5. ACTIVITY LOGGING VALIDATION
-- ============================================

-- 5.1 Activity Log Count
SELECT COUNT(*) AS total_logs FROM activity_logs;
-- Expected: Integer >= 0

-- 5.2 Activity Log Action Types
SELECT 
  action,
  COUNT(*) AS count
FROM activity_logs
GROUP BY action
ORDER BY count DESC;
-- Expected: Different action types with counts

-- 5.3 Recent Activity (Last 20 Records)
SELECT 
  log_id,
  action,
  description,
  user_id,
  created_at
FROM activity_logs
ORDER BY created_at DESC
LIMIT 20;
-- Expected: Recent activity logs with details

-- 5.4 Activity Count by Date (Last 7 Days)
SELECT 
  DATE(created_at) AS activity_date,
  COUNT(*) AS log_count
FROM activity_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY activity_date DESC;
-- Expected: Daily activity counts

-- ============================================
-- 6. SETTINGS VALIDATION
-- ============================================

-- 6.1 System Settings
SELECT 
  setting_id,
  system_name,
  primary_color,
  logo_path
FROM system_settings
LIMIT 1;
-- Expected: System settings record with valid values

-- 6.2 Check Primary Color Format
SELECT 
  setting_id,
  primary_color,
  CASE 
    WHEN primary_color REGEXP '^#[0-9A-Fa-f]{6}$' THEN 'VALID'
    ELSE 'INVALID'
  END AS color_format
FROM system_settings;
-- Expected: All colors should be VALID

-- ============================================
-- 7. DATA INTEGRITY & FOREIGN KEYS
-- ============================================

-- 7.1 Orphaned Users (No Valid Tenant)
SELECT 
  u.user_id,
  u.username,
  u.tenant_id
FROM users u
WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id = u.tenant_id);
-- Expected: 0 rows (no orphaned users)

-- 7.2 Orphaned Customers (No Valid Tenant)
SELECT 
  c.customer_id,
  c.customer_no,
  c.tenant_id
FROM customers c
WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id = c.tenant_id);
-- Expected: 0 rows

-- 7.3 Orphaned Loans (No Valid Tenant/Customer)
SELECT 
  l.loan_id,
  l.reference_no,
  l.tenant_id,
  l.customer_id
FROM loans l
WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id = l.tenant_id)
   OR NOT EXISTS (SELECT 1 FROM customers c WHERE c.customer_id = l.customer_id);
-- Expected: 0 rows

-- 7.4 Orphaned Payments (No Valid loan/tenant)
SELECT 
  p.payment_id,
  p.loan_id,
  p.tenant_id
FROM payments p
WHERE NOT EXISTS (SELECT 1 FROM loans l WHERE l.loan_id = p.loan_id)
   OR NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id = p.tenant_id);
-- Expected: 0 rows

-- 7.5 Payments Exceeding Loan Amount
SELECT 
  l.loan_id,
  l.reference_no,
  l.total_payable,
  IFNULL(SUM(p.amount), 0) AS total_paid,
  IFNULL(SUM(p.amount), 0) - l.total_payable AS overpayment
FROM loans l
LEFT JOIN payments p ON l.loan_id = p.loan_id
GROUP BY l.loan_id
HAVING IFNULL(SUM(p.amount), 0) > l.total_payable;
-- Expected: 0 rows (should not have overpayments)

-- ============================================
-- 8. ERROR DETECTION QUERIES
-- ============================================

-- 8.1 Null Primary Keys
SELECT 'users' AS table_name, COUNT(*) AS null_count FROM users WHERE user_id IS NULL
UNION
SELECT 'tenants', COUNT(*) FROM tenants WHERE tenant_id IS NULL
UNION
SELECT 'customers', COUNT(*) FROM customers WHERE customer_id IS NULL
UNION
SELECT 'loans', COUNT(*) FROM loans WHERE loan_id IS NULL
UNION
SELECT 'payments', COUNT(*) FROM payments WHERE payment_id IS NULL;
-- Expected: All counts should be 0

-- 8.2 Invalid Payment Amounts
SELECT 
  payment_id,
  amount,
  'INVALID' AS reason
FROM payments
WHERE amount <= 0 OR amount IS NULL;
-- Expected: 0 rows

-- 8.3 Invalid Loan Status Values
SELECT DISTINCT status 
FROM loans
WHERE status NOT IN ('PENDING', 'CI_REVIEWED', 'APPROVED', 'DENIED', 'ACTIVE', 'OVERDUE', 'CLOSED');
-- Expected: 0 rows

-- 8.4 Duplicate Usernames Per Tenant
SELECT 
  tenant_id,
  username,
  COUNT(*) AS count
FROM users
GROUP BY tenant_id, username
HAVING COUNT(*) > 1;
-- Expected: 0 rows (no duplicate usernames per tenant)

-- 8.5 Empty Tenant Names
SELECT 
  tenant_id,
  tenant_name
FROM tenants
WHERE tenant_name IS NULL OR tenant_name = '';
-- Expected: 0 rows

-- ============================================
-- 9. DATA CONSISTENCY CHECKS
-- ============================================

-- 9.1 Loan Status Transition Validation
-- Check if loans have approval workflow (CI -> Manager -> Active)
SELECT 
  l.loan_id,
  l.reference_no,
  l.status,
  CASE 
    WHEN l.status = 'PENDING' AND l.ci_by IS NOT NULL THEN 'ERROR: Status mismatch'
    WHEN l.status = 'CI_REVIEWED' AND l.manager_by IS NOT NULL THEN 'ERROR: Status mismatch'
    ELSE 'OK'
  END AS validation
FROM loans l
WHERE l.status IN ('PENDING', 'CI_REVIEWED')
LIMIT 10;
-- Expected: All show 'OK'

-- 9.2 Timestamp Validity
SELECT 
  'users' AS table_name,
  COUNT(*) AS invalid_count
FROM users
WHERE created_at IS NULL OR created_at = '0000-00-00 00:00:00'
UNION
SELECT 'activity_logs', COUNT(*) FROM activity_logs WHERE created_at IS NULL
UNION
SELECT 'loans', COUNT(*) FROM loans WHERE created_at IS NULL;
-- Expected: All counts should be 0

-- 9.3 Tenant Active Status Distribution
SELECT 
  is_active,
  COUNT(*) AS count
FROM tenants
GROUP BY is_active;
-- Expected: 0 and 1 with counts (should have mostly 1)

-- ============================================
-- 10. PERFORMANCE & OPTIMIZATION CHECKS
-- ============================================

-- 10.1 Table Sizes
SELECT 
  table_name,
  ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
  table_rows AS row_count
FROM information_schema.tables
WHERE table_schema = 'loan_management_app'
  AND table_name IN ('users', 'tenants', 'customers', 'loans', 'payments', 'activity_logs')
ORDER BY data_length DESC;
-- Expected: Shows table sizes and row counts

-- 10.2 Missing Indexes
-- Check if important fields are indexed
SELECT 
  'users.tenant_id' AS field,
  'COMPOUND(tenant_id, username)' AS recommended_index
WHERE 1=0
UNION
SELECT 'users.is_active', 'INDEX' WHERE 1=0
UNION
SELECT 'tenants.is_active', 'INDEX' WHERE 1=0
UNION
SELECT 'loans.status', 'INDEX' WHERE 1=0
UNION
SELECT 'payments.payment_date', 'INDEX' WHERE 1=0;
-- Use SHOW INDEXES FROM [table_name] to verify

-- ============================================
-- 11. QUICK HEALTH CHECK (Run All)
-- ============================================

-- Total Summary
SELECT 
  (SELECT COUNT(*) FROM tenants) AS total_tenants,
  (SELECT COUNT(*) FROM users WHERE is_active=1) AS active_users,
  (SELECT COUNT(*) FROM customers) AS total_customers,
  (SELECT COUNT(*) FROM loans) AS total_loans,
  (SELECT COUNT(*) FROM payments) AS total_payments,
  (SELECT IFNULL(SUM(amount), 0) FROM payments) AS total_revenue,
  (SELECT COUNT(*) FROM activity_logs) AS total_logs,
  NOW() AS check_timestamp;

-- Expected: Overview of all key metrics

-- ============================================
-- END OF VALIDATION QUERIES
-- ============================================
--
-- USAGE INSTRUCTIONS:
-- 1. Copy any query above
-- 2. Paste in phpMyAdmin SQL tab or MySQL command line
-- 3. Review results against "Expected" comments
-- 4. If any results differ, investigate and fix
-- 5. Run health check regularly (section 11)
--
-- For questions, refer to TEST_CHECKLIST.md or test_admin_system.php
-- ============================================
