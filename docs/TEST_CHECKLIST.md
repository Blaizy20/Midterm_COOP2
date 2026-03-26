========================================
ADMIN SYSTEM TEST CASES & VALIDATION CHECKLIST
========================================

Date: March 22, 2026
Purpose: Complete validation of Admin Dashboard, Tenant Management, Reports, Sales, History, and Settings modules

NOTE: All tests use existing schema.sql. No database modifications should be made.

========================================
1. DASHBOARD ANALYTICS VALIDATION
========================================

TEST 1.1: System Metrics Calculation
- Navigate to: /staff/dashboard.php (Admin login required)
- Verify Dashboard displays:
  [ ] Total Tenants count
  [ ] Total Users count
  [ ] Active Users count
  [ ] Inactive Users count
  [ ] Total Customers count
  [ ] Total Loans count
  [ ] Active Loans count
  [ ] Overdue Loans count
  [ ] Portfolio Value (₱ sum of principal_amount)
  [ ] Pending Approvals count

Expected: All metrics display > 0 or = 0 (for empty categories)
Status: ______ PASS / FAIL

TEST 1.2: Chart Rendering
- Verify the following charts load and display data:
  [ ] Daily Revenue (Last 14 Days) - Line chart
  [ ] Weekly Revenue (Last 12 Weeks) - Bar chart
  [ ] Monthly Revenue (Last 12 Months) - Line chart
  [ ] User Growth (Monthly) - Line chart
  [ ] Loan Status Distribution - Doughnut chart
  [ ] Tenant Activity - Multi-series bar chart
  [ ] Staff by Role - Bar chart
  [ ] Daily Activity (Last 30 days) - Bar chart
  [ ] Loan Applications (Monthly) - Line chart

Expected: All charts render without console errors
Status: ______ PASS / FAIL

TEST 1.2A: Activity Summary Panels
- Verify Dashboard displays explicit summary panels:
  [ ] Daily Activity Summary
  [ ] Monthly Activity Summary

- Verify Daily Activity Summary shows:
  [ ] New Users Today
  [ ] Loan Applications
  [ ] Revenue Today
  [ ] Logged Events

- Verify Monthly Activity Summary shows:
  [ ] New Users This Month
  [ ] Loan Applications
  [ ] Revenue This Month
  [ ] Logged Events

Expected: Summary values load on page without requiring chart interaction
Status: ______ PASS / FAIL

TEST 1.3: Chart Data Accuracy
- User Growth Chart
  Expected: Monthly user creation trend visible
  Actual: ____________
  Status: ______ PASS / FAIL

- Revenue Trend Charts
  Expected: Monthly payment aggregation showing ₱ values
  Actual: ____________
  Status: ______ PASS / FAIL

- Loan Applications Chart
  Expected: Monthly loan application counts
  Actual: ____________
  Status: ______ PASS / FAIL

- Tenant Activity Chart
  Expected: Each tenant displays visible grouped activity metrics
  Actual: ____________
  Status: ______ PASS / FAIL

TEST 1.3A: SUPER_ADMIN Dashboard End-to-End Verification
- Login as `SUPER_ADMIN`
- Navigate to: `/staff/dashboard.php`
- Open browser DevTools Network tab and refresh
- Verify requests succeed:
  [ ] `api/v1/analytics.php?endpoint=sales_trends_daily`
  [ ] `api/v1/analytics.php?endpoint=sales_trends_weekly`
  [ ] `api/v1/analytics.php?endpoint=sales_trends_monthly`
  [ ] `api/v1/analytics.php?endpoint=user_growth`
  [ ] `api/v1/analytics.php?endpoint=tenant_activity`

- Verify browser console:
  [ ] No JavaScript errors
  [ ] No 403/404 analytics failures

Expected: Dashboard cards, summaries, and charts all load end-to-end for SUPER_ADMIN
Status: ______ PASS / FAIL

TEST 1.4: Admin-Only Content
- Verify Dashboard shows ADMIN ONLY section:
  [ ] System Overview metrics visible
  [ ] Cannot access if not logged in as ADMIN

Expected: Non-admin users cannot see admin metrics
Status: ______ PASS / FAIL

TEST 1.5: Data Consistency
- Note: Run before making changes
  Metric Name       | Value | Time
  Total Tenants     | ___ | ____
  Active Users      | ___ | ____
  Total Loans       | ___ | ____
  Portfolio Value   | ___ | ____

- Expected: Values match database query results
Status: ______ PASS / FAIL

========================================
2. TENANT MANAGEMENT VALIDATION
========================================

TEST 2.1: Tenant Registration
- Navigate to: /staff/registration.php
- Select "Register Tenant" option
- Try to register a new tenant with:
  [ ] Valid Tenant Name (e.g., "Test Tenant Corp")
  [ ] Valid Subdomain (e.g., "testtenant")
  [ ] Click "Register Tenant"

Expected: 
  [ ] Success message displays
  [ ] Tenant appears in "Registered Tenants" list
  [ ] Database record created in tenants table

Status: ______ PASS / FAIL

TEST 2.2: Tenant Name Uniqueness
- Try to register duplicate tenant name
  Expected: Error message "Tenant name already exists"
  Status: ______ PASS / FAIL

TEST 2.3: Subdomain Uniqueness
- Try to register with existing subdomain
  Expected: Error message "Subdomain already exists"
  Status: ______ PASS / FAIL

TEST 2.4: Tenant Field Validation
- Register with missing fields:
  [ ] Empty tenant name - Expected: Error "Tenant name is required"
  [ ] Empty subdomain - Expected: Error "Subdomain is required"

Status: ______ PASS / FAIL

TEST 2.5: Tenant Data Display
- Navigate to: /staff/tenant_management.php
- Verify tenant list shows:
  [ ] Tenant ID
  [ ] Tenant Name
  [ ] Subdomain
  [ ] Status (Active/Inactive)
  [ ] Users assigned
  [ ] Created date

Status: ______ PASS / FAIL

TEST 2.6: Tenant Status Update
- Deactivate a tenant:
  [ ] Click tenant's status toggle
  [ ] Verify it changes to "Inactive"
  [ ] Database reflects change (is_active = 0)
  
- Reactivate tenant:
  [ ] Click status toggle again
  [ ] Verify it changes to "Active"

Status: ______ PASS / FAIL

TEST 2.7: Activity Logging
- After registering/updating tenant:
  [ ] Check History tab
  [ ] Verify action logged as "Tenant Created" or "Tenant Updated"
  [ ] Timestamp records correctly

Status: ______ PASS / FAIL

========================================
3. REPORTS MODULE VALIDATION
========================================

TEST 3.1: Loan Transactions Report
- Navigate to: /staff/reports.php
- Select "Loan Transactions" report
- Verify displays:
  [ ] Status filter (dropdown or tabs)
  [ ] Loan reference, customer, amount, status
  [ ] Filterable by date range

Expected: Report loads and shows categorized loan data
Status: ______ PASS / FAIL

TEST 3.2: Tenant Activity Report
- Select "Tenant Activity" report
- Verify displays:
  [ ] Activity counts grouped by tenant
  [ ] Tenant name and activity_count
  [ ] Proper data aggregation from activity_logs

Expected: Shows activity per tenant with counts > 0
Status: ______ PASS / FAIL

TEST 3.3: User Registrations Report
- Select "User Registrations" report
- Verify displays:
  [ ] Date ranges with user count
  [ ] Trend over time
  [ ] Grouped by registration date

Expected: Shows user registration trend data
Status: ______ PASS / FAIL

TEST 3.4: Usage Statistics Report
- Select "Usage Statistics" report
- Verify displays:
  [ ] Loan counts per tenant
  [ ] Customer counts
  [ ] Payment statistics

Expected: Aggregated usage metrics display correctly
Status: ______ PASS / FAIL

TEST 3.5: Report Filtering
- Test date range filters:
  [ ] Last 7 days - Expected: Data filtered correctly
  [ ] Last 30 days - Expected: Data filtered correctly
  [ ] Custom date range - Expected: Data filtered to specified range

Status: ______ PASS / FAIL

TEST 3.6: Empty Results Handling
- Generate report for date range with no data
  Expected: "No records found" message displays gracefully
  Status: ______ PASS / FAIL

========================================
4. SALES REPORT VALIDATION
========================================

TEST 4.1: Revenue Metrics
- Navigate to: /staff/sales_report.php
- Verify displays:
  [ ] Total Revenue (₱ amount)
  [ ] Total Transactions (count)
  [ ] Average Transaction (₱ amount)
  [ ] Daily Rate (₱ per day)

Expected: All metrics > 0 (if data exists)
Status: ______ PASS / FAIL

TEST 4.2: Sales Per Tenant Table
- Verify table shows:
  [ ] Tenant Name
  [ ] Revenue (SUM of amounts)
  [ ] Transaction Count
  [ ] Ranked by highest revenue first

Expected: Tenants sorted by revenue descending
Status: ______ PASS / FAIL

TEST 4.3: Daily Sales Aggregation
- Verify "Daily Sales" section shows:
  [ ] Date and sales ₱ amount for each day
  [ ] Last 30 days of data
  [ ] Proper SUM(amount) by payment_date

Expected: Daily totals visible and accurate
Status: ______ PASS / FAIL

TEST 4.4: Payment Method Breakdown
- Verify payment methods displayed:
  [ ] CASH - count and total
  [ ] CHEQUE - count and total
  [ ] GCASH - count and total
  [ ] DIGITAL - count and total

Expected: All payment methods properly categorized and summed
Status: ______ PASS / FAIL

TEST 4.5: Transaction History
- Verify table shows recent transactions:
  [ ] Payment ID, OR Number
  [ ] Loan Reference, Customer Name
  [ ] Amount, Payment Date, Method
  [ ] Sortable by date

Expected: Latest transactions visible and sortable
Status: ______ PASS / FAIL

TEST 4.6: Revenue Accuracy
- Manual verification:
  SQL Query: SELECT IFNULL(SUM(amount), 0) FROM payments
  Expected Value: ₱ ______
  Displayed Value: ₱ ______
  Match: [ ] YES [ ] NO

Status: ______ PASS / FAIL

========================================
5. AUDIT LOGS (HISTORY) VALIDATION
========================================

TEST 5.1: Log Categories
- Navigate to: /staff/history.php
- Verify tabs visible:
  [ ] All Logs
  [ ] Loan & Payment
  [ ] Customer
  [ ] Staff/Admin
  [ ] Authentication
  [ ] Tenant Changes

Status: ______ PASS / FAIL

TEST 5.2: Action Logging - Staff Registration
- Register a new staff member (from registration.php)
- Check History tab:
  [ ] Log entry shows "Staff Created"
  [ ] Shows staff name
  [ ] Shows role
  [ ] Timestamp recorded

Status: ______ PASS / FAIL

TEST 5.3: Action Logging - Tenant Registration
- Register a new tenant (from registration.php)
- Check History tab:
  [ ] Log entry shows "Tenant Registered"
  [ ] Shows tenant name and subdomain
  [ ] Timestamp recorded

Status: ______ PASS / FAIL

TEST 5.4: Login/Logout Logging
- Perform login and logout
- Check History - Authentication tab:
  [ ] Login action logged with timestamp
  [ ] Logout action logged with timestamp

Status: ______ PASS / FAIL

TEST 5.5: Update Actions Logging
- Make an update to any resource (Staff, Tenant, etc.)
- Check History:
  [ ] Action logged with "Updated" label
  [ ] Shows what was changed
  [ ] Timestamp and user recorded

Status: ______ PASS / FAIL

TEST 5.6: Delete Actions Logging
- Delete a resource (Staff account)
- Check History:
  [ ] Action logged as "Permanently Deleted"
  [ ] Shows deleted item details
  [ ] Timestamp and user recorded

Status: ______ PASS / FAIL

TEST 5.7: Log Details
- Click on any log entry
- Verify includes:
  [ ] Action type
  [ ] Description/details
  [ ] User who performed action
  [ ] Exact timestamp (date and time)
  [ ] Relevant record IDs

Status: ______ PASS / FAIL

========================================
6. SETTINGS MODULE VALIDATION
========================================

TEST 6.1: Setting Access Control
- As ADMIN:
  [ ] Can access /staff/manager_settings.php
  [ ] Settings form loads

- As MANAGER:
  [ ] Can access /staff/manager_settings.php
  [ ] Settings form loads

- As other roles (login as LOAN_OFFICER):
  [ ] Cannot access /staff/manager_settings.php
  [ ] Error page displays

Status: ______ PASS / FAIL

TEST 6.2: System Name Setting
- Update "System Name" field:
  [ ] Enter new name (e.g., "Test System v2")
  [ ] Click "Save Settings"
  [ ] Confirmation message displays
  [ ] Name updates in database

Expected: 
  Query: SELECT system_name FROM system_settings
  Value: "Test System v2"

Status: ______ PASS / FAIL

TEST 6.3: Primary Color Setting
- Update "Primary Color":
  [ ] Use color picker or type hex value
  [ ] Enter valid hex color (e.g., #FF5733)
  [ ] Click "Save Settings"
  [ ] Color updates in UI

Expected:
  Query: SELECT primary_color FROM system_settings
  Value: "#FF5733"

Status: ______ PASS / FAIL

TEST 6.4: Color Format Validation
- Try to enter invalid color format:
  [ ] Enter "FF5733" (without #)
  [ ] Try "GGGGGG" (invalid hex)
  [ ] Expected: Error or auto-correction

Status: ______ PASS / FAIL

TEST 6.5: Logo Upload
- Upload a PNG or JPG logo:
  [ ] Select file from computer
  [ ] File size < 5MB
  [ ] Click "Save Settings"
  [ ] Logo displays in topbar

Expected:
  [ ] File uploaded to /uploads/logo/
  [ ] Path stored in system_settings
  [ ] Logo visible in UI

Status: ______ PASS / FAIL

TEST 6.6: Logo Size Validation
- Try uploading file > 5MB:
  [ ] Select large file
  [ ] Expected: Error "Logo file size must not exceed 5MB"

Status: ______ PASS / FAIL

TEST 6.7: Settings Persistence
- Update a setting and save
- Reload page (/staff/manager_settings.php)
- Verify:
  [ ] Updated value still shows
  [ ] Data persisted to database

Status: ______ PASS / FAIL

TEST 6.8: Settings Activity Logging
- Update a setting
- Check History tab:
  [ ] Action logged with "Settings Updated"
  [ ] Timestamp recorded

Status: ______ PASS / FAIL

========================================
7. INTEGRATION TESTS
========================================

TEST 7.1: Tenant Registration → Dashboard Update
- Current tenant count: ____
- Register a new tenant
- Refresh Dashboard (/staff/dashboard.php)
- New tenant count should be: ____ (previous + 1)

Status: ______ PASS / FAIL

TEST 7.2: Tenant Data → Reports
- Register new tenant: "Integration Test Co"
- Navigate to Reports module
- Filter or view by tenant
- Verify tenant appears in data

Status: ______ PASS / FAIL

TEST 7.3: Payment Entry → Sales Report
- Create sample payment record (loan → payment)
- Navigate to Sales Report
- Verify payment appears in:
  [ ] Total Revenue
  [ ] Daily Sales
  [ ] Transaction History

Status: ______ PASS / FAIL

TEST 7.4: Action → Activity Log
- Create resource (Staff, Tenant, Payment)
- Navigate to History
- Verify action logged immediately:
  [ ] Action type correct
  [ ] Details accurate
  [ ] Timestamp current

Status: ______ PASS / FAIL

TEST 7.5: Settings Change → UI Reflection
- Change primary color in Settings
- Refresh Dashboard
- Verify brand colors updated throughout UI

Status: ______ PASS / FAIL

TEST 7.6: Tenant Deactivation → Data Access
- Register and activate tenant
- Create sample data for tenant
- Deactivate tenant in Tenant Management
- Verify:
  [ ] Tenant shows as Inactive
  [ ] Data still exists (soft delete)
  [ ] Can reactivate and access data again

Status: ______ PASS / FAIL

========================================
8. ERROR HANDLING TESTS
========================================

TEST 8.1: Required Field Validation
- Tenant Registration
  [ ] Empty Tenant Name: Error displays
  [ ] Empty Subdomain: Error displays
  
- Staff Registration
  [ ] Empty Full Name: Error displays
  [ ] Invalid Role: Error displays

Status: ______ PASS / FAIL

TEST 8.2: Duplicate Entry Handling
- Try to register tenant with existing name
  [ ] Error message displays: "Tenant name already exists"
  [ ] Record not created
  
- Try to register staff with existing username
  [ ] Error message displays: "Username already exists"
  [ ] Record not created

Status: ______ PASS / FAIL

TEST 8.3: Invalid Data Format
- Settings - Color field
  [ ] Enter invalid hex: Expected error
  [ ] Register - Subdomain field
  [ ] Enter special characters: Expected validation

Status: ______ PASS / FAIL

TEST 8.4: Session Expiration
- Log in as Admin
- Wait for session timeout (or manually clear session)
- Try accessing /staff/dashboard.php
  [ ] Redirected to login
  [ ] Session data cleared

Status: ______ PASS / FAIL

TEST 8.5: Unauthorized Access
- Log in as LOAN_OFFICER
- Try to access /staff/registration.php
  [ ] Access denied message
  [ ] Cannot register staff or tenants

Status: ______ PASS / FAIL

========================================
9. DATA INTEGRITY TESTS
========================================

TEST 9.1: Foreign Key Constraints
Run SQL query to check for orphaned records:
  
Query: SELECT COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id=u.tenant_id)
Expected: 0 orphaned users
Actual: ____

Status: ______ PASS / FAIL

TEST 9.2: Relationship Validation
- Loans to Customers:
  Query: SELECT COUNT(*) FROM loans l WHERE NOT EXISTS (SELECT 1 FROM customers c WHERE c.customer_id=l.customer_id)
  Expected: 0
  Actual: ____

- Payments to Loans:
  Query: SELECT COUNT(*) FROM payments p WHERE NOT EXISTS (SELECT 1 FROM loans l WHERE l.loan_id=p.loan_id)
  Expected: 0
  Actual: ____

Status: ______ PASS / FAIL

TEST 9.3: Payment Amount Validity
Query: SELECT COUNT(*) FROM payments WHERE amount <= 0
Expected: 0 zero or negative amounts
Actual: ____

Status: ______ PASS / FAIL

TEST 9.4: Timestamp Validity
Query: SELECT COUNT(*) FROM activity_logs WHERE created_at IS NULL
Expected: 0 null timestamps
Actual: ____

Status: ______ PASS / FAIL

========================================
10. PERFORMANCE TESTS
========================================

TEST 10.1: Dashboard Load Time
- Navigate to /staff/dashboard.php
- Open browser DevTools (F12) → Network tab
- Measure load time:
  Expected: < 3 seconds
  Actual: ____ seconds

Status: ______ PASS / FAIL

TEST 10.2: Reports Page Initial Load
- Navigate to /staff/reports.php
- Measure page load time:
  Expected: < 2 seconds
  Actual: ____ seconds

Status: ______ PASS / FAIL

TEST 10.3: Sales Report with Large Dataset
- Generate Sales Report
- Verify chart renders smoothly:
  [ ] No console errors
  [ ] Charts responsive
  [ ] Scrolling smooth

Status: ______ PASS / FAIL

TEST 10.4: History Page Pagination
- Navigate to /staff/history.php
- Verify pagination (if > 100 records):
  [ ] Next/Previous buttons work
  [ ] Records load without timeout

Status: ______ PASS / FAIL

========================================
OVERALL TEST SUMMARY
========================================

Total Tests: 45+
Passed: ____
Failed: ____
Warnings: ____
Success Rate: _____%

CRITICAL FAILURES (If any): 
1. _______________________
2. _______________________

RECOMMENDATIONS:
- [ ] All critical tests passed - System ready for production
- [ ] Some warnings identified - Review and address
- [ ] Critical failures found - Do NOT deploy until fixed

Sign-off:
Tester Name: ________________  Date: __________
Reviewed By: ________________  Date: __________

========================================
END OF TEST SUITE
========================================

NOTE: Use test_admin_system.php for automated validation
URL: /LOAN_MANAGEMENT_APP/test_admin_system.php
(Admin login required)
