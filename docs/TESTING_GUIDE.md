# Admin System Test Suite - Complete Guide

**Date Created:** March 22, 2026  
**Purpose:** Comprehensive testing and validation of Admin Dashboard, Tenant Management, Reports, Sales, History, and Settings modules  
**Status:** TESTING ONLY - Do not use in production

---

## 📋 Overview

This test suite provides three ways to validate the Admin system:

1. **Automated Test Runner** (`test_admin_system.php`) - Quick automated checks
2. **Manual Test Checklist** (`TEST_CHECKLIST.md`) - Step-by-step validation  
3. **SQL Validation Queries** (`SQL_VALIDATION_QUERIES.sql`) - Database integrity checks

---

## 🚀 Quick Start

### Option 1: Run Automated Test Suite (Recommended)

```
URL: http://localhost/LOAN_MANAGEMENT_APP/test_admin_system.php
```

**Requirements:**
- Must be logged in as ADMIN
- Takes ~5 minutes to complete
- Displays results in browser with color-coded status

**What It Tests:**
- ✅ Dashboard analytics (10 metrics)
- ✅ Tenant management (6 validations)
- ✅ Reports data aggregation (4 tests)
- ✅ Sales calculations (5 tests)
- ✅ Audit logs (4 tests)
- ✅ Settings configuration (3 tests)
- ✅ Integration points (6 tests)
- ✅ Error handling & edge cases (5 tests)

---

### Option 2: Manual Testing Checklist

```
File: TEST_CHECKLIST.md
```

**For:**
- Detailed functionality testing
- User acceptance testing (UAT)
- Testing specific workflows
- Documenting test results

**Contains:**
- 10 test sections with ~45+ individual test cases
- Expected results for each test
- Pass/Fail checkboxes
- Sign-off section for documentation

**Duration:** 30-60 minutes (depending on data volume)

---

### Option 3: Run SQL Validation Queries

```
File: SQL_VALIDATION_QUERIES.sql
Tool: phpMyAdmin or MySQL command line
```

**For:**
- Database integrity verification
- Data consistency checks
- Performance diagnostics
- Quick health checks

**Contains:**
- 11 sections with 50+ SQL queries
- Data aggregation validations
- Foreign key integrity checks
- Error detection queries

---

## 📊 Test Coverage Matrix

| Module | Automated | Manual | SQL | Coverage |
|--------|-----------|--------|-----|----------|
| Dashboard Analytics | ✅ | ✅ | ✅ | 100% |
| Tenant Management | ✅ | ✅ | ✅ | 100% |
| Reports Module | ✅ | ✅ | ✅ | 100% |
| Sales Report | ✅ | ✅ | ✅ | 100% |
| Audit Logs (History) | ✅ | ✅ | ✅ | 100% |
| Settings Module | ✅ | ✅ | ✅ | 100% |
| Integration | ✅ | ✅ | ⚠️ | 95% |
| Error Handling | ✅ | ✅ | ✅ | 100% |

---

## 📝 Test Execution Plan

### Phase 1: Pre-Deployment Validation (Day 1)
1. **Run Automated Test Suite**
   - Expected: 95%+ tests pass
   - Time: 5 minutes
   
2. **Run SQL Validation Queries** (Section 1-8)
   - Check data integrity
   - Verify no orphaned records
   - Time: 10 minutes

3. **Run Critical Manual Tests** (TEST_CHECKLIST.md)
   - Dashboard metrics display
   - Tenant registration
   - Settings persistence
   - Activity logging
   - Time: 15 minutes

### Phase 2: Detailed UAT (Days 2-3)
1. **Complete Manual Test Checklist**
   - All 45+ test cases
   - Document results
   - Time: 60 minutes

2. **Run Integration Tests** (TEST_CHECKLIST.md Section 7)
   - Cross-module data flow
   - Real-world workflows
   - Time: 20 minutes

3. **Performance Tests** (TEST_CHECKLIST.md Section 10)
   - Load time validation
   - Large dataset handling
   - Time: 10 minutes

### Phase 3: Sign-off (Day 4)
1. Review all test results
2. Document any issues discovered
3. Sign off on test summary
4. Archive test results

---

## 🔍 How to Interpret Results

### Automated Test Suite Results

**Color-Coded Status:**
- 🟢 **PASS** (Green) - Test successful, no action needed
- 🔴 **FAIL** (Red) - Test failed, immediate attention required
- 🟡 **WARN** (Yellow) - Warning condition, review recommended

**Success Rate:**
- ✅ 100% = All systems operational
- ✅ 95-99% = Minor issues, non-critical
- ⚠️ 90-94% = Some issues, review needed
- ❌ <90% = Critical issues, do not deploy

### Manual Test Results

Each test includes:
- **Expected:** What should happen
- **Actual:** What you observed
- **Status:** PASS/FAIL/WARN checkbox
- **Notes:** Any additional observations

### SQL Query Results

Each query includes:
- **Query description**
- **Expected result**
- **Actual result** (run from database)
- **Pass/Fail determination**

---

## 🧪 Test Scenarios

### Scenario A: Dashboard Analytics
**Time:** 10 minutes

**Steps:**
1. Run automated test (section: Dashboard Analytics)
2. Navigate to `/staff/dashboard.php`
3. Verify all metrics display
4. Check charts render correctly
5. Verify dashboard loads in < 3 seconds

**Expected:** All metrics and charts display data correctly

---

### Scenario B: Tenant Registration Flow
**Time:** 15 minutes

**Steps:**
1. Navigate to `/staff/registration.php` (Admin login)
2. Select "Register Tenant"
3. Enter: Name="Test Corp", Subdomain="testcorp"
4. Submit form
5. Verify success message
6. Check Tenant Management list
7. Check Activity Log
8. Run SQL: `SELECT * FROM tenants WHERE tenant_name='Test Corp'`

**Expected:** Tenant created, logged, and appears in all views

---

### Scenario C: Settings Update
**Time:** 10 minutes

**Steps:**
1. Navigate to `/staff/manager_settings.php`
2. Change system name to "Test System v2"
3. Update primary color to "#FF5733"
4. Click Save
5. Verify success message
6. Refresh page - verify changes persist
7. Check activity log for update action

**Expected:** Settings updated, persisted, and logged

---

### Scenario D: Sales Report Accuracy
**Time:** 10 minutes

**Steps:**
1. Navigate to `/staff/sales_report.php`
2. Note Total Revenue amount
3. Run SQL: `SELECT SUM(amount) FROM payments`
4. Compare values
5. Check Daily Sales section
6. Verify Payment Methods breakdown
7. Check Transaction History matches database

**Expected:** All values match database queries exactly

---

## ⚠️ Known Issues & Workarounds

### If Dashboard Charts Don't Load
1. Check browser console (F12 → Console tab)
2. Verify `/api/v1/analytics.php` is accessible
3. Run: `SELECT COUNT(*) FROM users` to verify data exists
4. Clear browser cache and reload

### If Tenant Registration Fails
1. Check error message displayed
2. Verify subdomain doesn't already exist
3. Check character limit (max 120 for name, 50 for subdomain)
4. Review activity_logs for error details

### If Settings Not Saving
1. Verify ADMIN or MANAGER role
2. Check file permissions on `/uploads/logo/` directory
3. Verify system_settings table exists
4. Try uploading smaller logo file

### If Activity Logs Missing
1. Run: `SELECT COUNT(*) FROM activity_logs`
2. Verify activity_logs table has records
3. Check created_at timestamp is current
4. Review query in `/staff/history.php`

---

## 📈 Performance Benchmarks

**Expected Load Times:**
- Dashboard page: < 3 seconds
- Reports page: < 2 seconds
- Sales Report: < 2 seconds
- History page: < 1.5 seconds
- Settings page: < 1 second

**Database Query Times:**
- Simple counts: < 100ms
- Aggregations: < 500ms
- Reports with filters: < 1 second

**Acceptable Values:**
- ✅ Meets benchmark
- ⚠️ 10% slower than benchmark (investigate if consistent)
- ❌ 25%+ slower (potential performance issue)

---

## 🔐 Security Validation

### Role-Based Access Control
```
Admin can access:     ✅ Dashboard, Registration, Tenant Mgmt, Reports, Sales, History, Settings
Manager can access:   ✅ Dashboard, Reports, Settings (NOT admin functions)
Loan Officer:         ✅ Dashboard, Loans, Payments (NOT admin functions)
```

### Test Access Control
1. Log in as different roles
2. Try accessing restricted pages
3. Verify appropriate error messages
4. Confirm no unauthorized data access

---

## 📋 Test Results Documentation

### Required for Sign-Off
- [ ] Automated test suite: 95%+ pass rate
- [ ] Critical manual tests: 100% pass
- [ ] SQL integrity checks: 0 errors
- [ ] No data loss or corruption
- [ ] Activity logs complete
- [ ] Performance acceptable
- [ ] Error handling working
- [ ] Access control verified

### Test Results Template
```
Test Date: __________
Tester: __________
Role: ADMIN / MANAGER

Overall Status: _____ PASS / FAIL / CONDITIONAL
Total Tests: ___
Passed: ___
Failed: ___
Warnings: ___

Critical Failures:
1. ________________________
2. ________________________

Recommendations:
_________________________

Sign-off:
Tester: ______________ Date: __________
Reviewer: ____________ Date: __________
```

---

## 🆘 Troubleshooting

### Test Suite Won't Load
- **Error:** "Access Denied: Admin access required"
- **Solution:** Log in as ADMIN user first
- **Url:** `http://localhost/LOAN_MANAGEMENT_APP/login.php`

### Database Connection Error
- **Error:** "mysqli_sql_exception" or "Cannot connect"
- **Solution:** Check database is running (MySQL/MariaDB)
- **Command:** `mysql -u root -p` (test connection)

### Charts Not Displaying
- **Error:** Charts show as blank rectangles
- **Solution:** Open browser DevTools (F12) → Network tab
- **Check:** API response from `/api/v1/analytics.php`

### Data Doesn't Match
- **Issue:** Dashboard count ≠ Database query result
- **Solution:** Run `test_admin_system.php` for exact counts
- **SQL:** `SELECT COUNT(*) FROM [table_name]`

---

## 📚 Related Files

- **Main Test File:** `test_admin_system.php`
- **Manual Checklist:** `TEST_CHECKLIST.md` (this file)
- **SQL Queries:** `SQL_VALIDATION_QUERIES.sql`
- **Schema Definition:** `schema.sql`
- **API Endpoints:** `/api/v1/analytics.php`
- **Main Dashboard:** `/staff/dashboard.php`
- **Tenant Manager:** `/staff/tenant_management.php`
- **Reports:** `/staff/reports.php`
- **Sales Report:** `/staff/sales_report.php`
- **History/Audit:** `/staff/history.php`
- **Settings:** `/staff/manager_settings.php`

---

## 📞 Support & Questions

**For Issues:**
1. Check TEST_CHECKLIST troubleshooting section
2. Review error message in test results
3. Consult SQL_VALIDATION_QUERIES for data checking
4. Check database integrity with SQL validation

**For Feature Questions:**
- Dashboard: See `dashboard.php` lines 1-369
- Reports: See `reports.php`
- Sales: See `sales_report.php`
- Settings: See `manager_settings.php`

---

## ✅ Pre-Deployment Checklist

Before deploying to production:

- [ ] Automated Test Suite: 95%+ PASS
- [ ] Critical SQL Queries: All show 0 errors
- [ ] Manual Checklist: 100% complete
- [ ] Performance benchmarks: Met
- [ ] Error handling: All error scenarios tested
- [ ] Access control: Verified for all roles
- [ ] Activity logging: Working for all actions
- [ ] Data integrity: No orphaned records
- [ ] Backups: Database backed up
- [ ] Sign-off: Documented and approved

---

## 📝 Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-03-22 | Initial test suite creation |
| | | - 45+ automated tests |
| | | - Complete manual checklist |
| | | - 50+ SQL validation queries |

---

**Last Updated:** March 22, 2026  
**Status:** Ready for Testing  
**Maintained By:** Admin Development Team

---

*For questions or issues, refer to the detailed documentation in each test file.*
