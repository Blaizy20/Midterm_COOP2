# Bugs Found Before Role-Based Access Changes

## 1. Wrong Localhost Redirect

The root entry file redirected to `/staff/login.php` instead of the project subfolder path.

- File: [index.php](C:/xampp/htdocs/LOAN_MANAGEMENT_APP/index.php)
- Problem: `http://localhost/staff/login.php` was used even though the project is inside `LOAN_MANAGEMENT_APP`
- Result: browser showed `Not Found`
- Correct path: `http://localhost/LOAN_MANAGEMENT_APP/staff/login.php`

## 2. Role Checks Were Inconsistent

Access control was implemented with many scattered `require_roles(...)` checks across different pages.

- Different pages used different role lists for similar actions
- The checks did not fully match the intended business rules
- Some roles had access to pages or actions they should not have

Examples:

- [staff/customers.php](C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/customers.php)
  - Allowed broader access than intended
- [staff/loan_view.php](C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loan_view.php)
  - Mixed payment, review, approval, and assignment permissions in separate hardcoded role lists
- [staff/reports.php](C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/reports.php)
  - Exposed report actions too broadly

## 3. Sidebar and Backend Permissions Did Not Always Match

The navigation menu and the actual page protection logic were maintained separately.

- A user could sometimes see a page link that did not properly match their real permission
- A user could sometimes open a page directly by URL if a page check was too broad

This made the system harder to maintain and easier to misconfigure.

## 4. Payment Flow Exposed Loan Officer Assignment Too Loosely

In the payment recording flow, loan officer assignment was shown in a way that did not cleanly match the intended role rules.

- File: [staff/payment_add.php](C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payment_add.php)
- Problem: payment recording and loan officer reassignment were mixed together
- Risk: roles that should only record payments could be given officer-assignment behavior

## 5. Reporting Permissions Were Too Broad

The reports module did not separate basic operational reports from broader admin-style reports.

- File: [staff/reports.php](C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/reports.php)
- Problem: all report types were not clearly restricted by responsibility
- Result: non-admin roles could access reporting options beyond the intended matrix

## Summary

The main issue before the fix was not a single broken feature. The bigger problem was that role-based access was fragmented, inconsistent, and spread across many files using hardcoded role arrays instead of one clear permission model.
