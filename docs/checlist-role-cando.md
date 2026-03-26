# Role Can/Can't Do Checklist

This checklist is based on the current role and permission logic in:

- [includes/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/auth.php)
- [staff/_layout_top.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/_layout_top.php)
- direct page guards under [staff](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff)

## Notes Before Testing

- `SUPER_ADMIN` and `ADMIN` currently share the same permission matrix in `auth_role_permissions()`.
- `SUPER_ADMIN` has higher rank than `ADMIN`, but in the current web portal both can access the same protected features.
- `TENANT` exists in the database and login allow-list, but it is not included in the main permission matrix.
- Because [dashboard.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/dashboard.php) only uses `require_login()`, a logged-in `TENANT` user can still open the dashboard even though the role is not in `view_dashboard`.
- [download_requirement.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/download_requirement.php) also explicitly allows `TENANT`.

## Permission Reference

| Permission | Allowed Roles |
| --- | --- |
| `view_dashboard` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, `CASHIER` |
| `view_loans` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER` |
| `view_loan_details` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, `CASHIER` |
| `view_customers` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER` |
| `manage_customers` | `SUPER_ADMIN`, `ADMIN` |
| `view_payments` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CASHIER` |
| `record_payments` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CASHIER` |
| `edit_payments` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CASHIER` |
| `print_receipts` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CASHIER` |
| `manage_vouchers` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `LOAN_OFFICER`, `CASHIER` |
| `review_applications` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR` |
| `approve_applications` | `SUPER_ADMIN`, `ADMIN`, `MANAGER` |
| `update_loan_terms` | `SUPER_ADMIN`, `ADMIN`, `MANAGER` |
| `assign_loan_officer` | `SUPER_ADMIN`, `ADMIN`, `MANAGER` |
| `view_reports` | `SUPER_ADMIN`, `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, `CASHIER` |
| `view_advanced_reports` | `SUPER_ADMIN`, `ADMIN` |
| `view_staff` | `SUPER_ADMIN`, `ADMIN`, `MANAGER` |
| `manage_staff` | `SUPER_ADMIN`, `ADMIN` |
| `view_history` | `SUPER_ADMIN`, `ADMIN` |
| `manage_tenants` | `SUPER_ADMIN`, `ADMIN` |
| `view_sales` | `SUPER_ADMIN`, `ADMIN` |
| `view_settings` | `SUPER_ADMIN`, `ADMIN`, `MANAGER` |

## SUPER_ADMIN Checklist

### Can do

- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php`
- [ ] Can see menu links for Loans, Customers, Payments, Money Release, CI Review, Manager Approval, Reports, Staff, Register Staff, Tenant Management, Sales Report, History, Settings
- [ ] Can open `/staff/loans.php`
- [ ] Can open `/staff/loan_view.php`
- [ ] Can review loan applications
- [ ] Can approve loan applications
- [ ] Can update loan terms
- [ ] Can assign loan officers
- [ ] Can open `/staff/customers.php`
- [ ] Can create, edit, and manage customers
- [ ] Can open `/staff/payments.php`
- [ ] Can record payments in `/staff/payment_add.php`
- [ ] Can edit payments in `/staff/payment_edit.php`
- [ ] Can print receipts in `/staff/payment_receipt.php` and `/staff/receipt_summary.php`
- [ ] Can manage vouchers in `/staff/release_queue.php` and `/staff/release_voucher.php`
- [ ] Can open `/staff/reports.php`
- [ ] Can access advanced reports in `/staff/reports.php?report_type=tenant_activity`
- [ ] Can view staff list in `/staff/staff.php`
- [ ] Can create, edit, and delete staff in `/staff/registration.php` and `/staff/staff_settings.php`
- [ ] Can manage tenants in `/staff/tenant_management.php`
- [ ] Can register tenants in `/staff/registration.php`
- [ ] Can open sales report in `/staff/sales_report.php`
- [ ] Can open history in `/staff/history.php`
- [ ] Can open settings in `/staff/manager_settings.php`
- [ ] Can download requirements in `/staff/download_requirement.php`

### Cannot do

- [ ] Cannot use customer-only web access because the portal is staff-only

## ADMIN Checklist

### Can do

- [ ] Same functional access as `SUPER_ADMIN` in the current permission matrix
- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php`
- [ ] Can access all protected staff pages listed under `SUPER_ADMIN`
- [ ] Can access advanced reports in `/staff/reports.php?report_type=tenant_activity`

### Cannot do

- [ ] Cannot exceed `SUPER_ADMIN` authority in rank-based comparisons
- [ ] Cannot use customer-only web access

## MANAGER Checklist

### Can do

- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php`
- [ ] Can see menu links for Loans, Customers, Payments, Money Release, CI Review, Manager Approval, Reports, Staff, Settings
- [ ] Can open `/staff/loans.php`
- [ ] Can open `/staff/loan_view.php`
- [ ] Can review loan applications
- [ ] Can approve loan applications
- [ ] Can update loan terms
- [ ] Can assign loan officers
- [ ] Can open `/staff/customers.php`
- [ ] Can view customer records
- [ ] Can open `/staff/payments.php`
- [ ] Can record payments
- [ ] Can edit payments
- [ ] Can print receipts
- [ ] Can manage vouchers
- [ ] Can open `/staff/ci_queue.php`
- [ ] Can open `/staff/manager_queue.php`
- [ ] Can open `/staff/reports.php`
- [ ] Can open `/staff/staff.php`
- [ ] Can open `/staff/manager_settings.php`
- [ ] Can download requirements in `/staff/download_requirement.php`

### Cannot do

- [ ] Cannot manage customers
- [ ] Cannot access advanced reports
- [ ] Cannot register, edit, or delete staff accounts
- [ ] Cannot access `/staff/registration.php`
- [ ] Cannot access `/staff/staff_settings.php`
- [ ] Cannot manage tenants
- [ ] Cannot access `/staff/tenant_management.php`
- [ ] Cannot access sales report
- [ ] Cannot access history

## CREDIT_INVESTIGATOR Checklist

### Can do

- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php`
- [ ] Can see menu links for Loans, Customers, CI Review, Reports
- [ ] Can open `/staff/loans.php`
- [ ] Can open `/staff/loan_view.php`
- [ ] Can review loan applications
- [ ] Can open `/staff/ci_queue.php`
- [ ] Can open `/staff/customers.php`
- [ ] Can view customer records
- [ ] Can open `/staff/reports.php`
- [ ] Can view basic reports
- [ ] Can download requirements in `/staff/download_requirement.php`

### Cannot do

- [ ] Cannot approve loan applications
- [ ] Cannot access `/staff/manager_queue.php`
- [ ] Cannot update loan terms
- [ ] Cannot assign loan officers
- [ ] Cannot view payments list
- [ ] Cannot record payments
- [ ] Cannot edit payments
- [ ] Cannot print receipts
- [ ] Cannot manage vouchers
- [ ] Cannot manage customers
- [ ] Cannot access advanced reports
- [ ] Cannot view staff list
- [ ] Cannot manage staff
- [ ] Cannot access sales report
- [ ] Cannot access history
- [ ] Cannot access settings
- [ ] Cannot manage tenants

## LOAN_OFFICER Checklist

### Can do

- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php`
- [ ] Can see menu links for Loans, Customers, Money Release, Reports
- [ ] Can open `/staff/loans.php`
- [ ] Can open `/staff/loan_view.php`
- [ ] Can open `/staff/customers.php`
- [ ] Can view customer records
- [ ] Can manage vouchers
- [ ] Can open `/staff/release_queue.php`
- [ ] Can open `/staff/reports.php`
- [ ] Can view basic reports
- [ ] Can download requirements in `/staff/download_requirement.php`

### Cannot do

- [ ] Cannot review loan applications
- [ ] Cannot approve loan applications
- [ ] Cannot access `/staff/ci_queue.php`
- [ ] Cannot access `/staff/manager_queue.php`
- [ ] Cannot update loan terms
- [ ] Cannot assign loan officers
- [ ] Cannot view payments list
- [ ] Cannot record payments
- [ ] Cannot edit payments
- [ ] Cannot print receipts
- [ ] Cannot manage customers
- [ ] Cannot access advanced reports
- [ ] Cannot view staff list
- [ ] Cannot manage staff
- [ ] Cannot access sales report
- [ ] Cannot access history
- [ ] Cannot access settings
- [ ] Cannot manage tenants

## CASHIER Checklist

### Can do

- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php`
- [ ] Can see menu links for Payments, Money Release, Reports
- [ ] Can open `/staff/loan_view.php`
- [ ] Can open `/staff/payments.php`
- [ ] Can record payments
- [ ] Can edit payments
- [ ] Can print receipts
- [ ] Can manage vouchers
- [ ] Can open `/staff/release_queue.php`
- [ ] Can open `/staff/reports.php`
- [ ] Can view basic reports
- [ ] Can download requirements in `/staff/download_requirement.php`

### Cannot do

- [ ] Cannot access `/staff/loans.php`
- [ ] Cannot access `/staff/customers.php`
- [ ] Cannot review loan applications
- [ ] Cannot approve loan applications
- [ ] Cannot update loan terms
- [ ] Cannot assign loan officers
- [ ] Cannot manage customers
- [ ] Cannot access advanced reports
- [ ] Cannot view staff list
- [ ] Cannot manage staff
- [ ] Cannot access sales report
- [ ] Cannot access history
- [ ] Cannot access settings
- [ ] Cannot manage tenants

## TENANT Checklist

### Can do

- [ ] Can log in to `/staff/login.php`
- [ ] Can open `/staff/dashboard.php` because the page currently checks only `require_login()`
- [ ] Can download requirements in `/staff/download_requirement.php`

### Cannot do

- [ ] Cannot see normal sidebar links controlled by `can_access(...)`
- [ ] Cannot access pages protected by `require_permission(...)` unless separately whitelisted
- [ ] Cannot access `/staff/loans.php`
- [ ] Cannot access `/staff/customers.php`
- [ ] Cannot access `/staff/payments.php`
- [ ] Cannot access `/staff/reports.php`
- [ ] Cannot access `/staff/staff.php`
- [ ] Cannot access `/staff/registration.php`
- [ ] Cannot access `/staff/tenant_management.php`
- [ ] Cannot access `/staff/history.php`
- [ ] Cannot access `/staff/sales_report.php`
- [ ] Cannot access `/staff/manager_settings.php`

## CUSTOMER Checklist

### Can do

- [ ] Can exist in the database and mobile/API flows

### Cannot do

- [ ] Cannot log in to the staff web portal
- [ ] Cannot use `/staff/login.php`
- [ ] Cannot access staff pages

## Suggested Test Order

- [ ] Test login success for each role
- [ ] Test dashboard access for each role
- [ ] Test sidebar visibility for each role
- [ ] Test direct URL access to every protected page
- [ ] Test action buttons inside `/staff/loan_view.php` for each role
- [ ] Test payment add, edit, and receipt pages
- [ ] Test report type restrictions, especially advanced reports
- [ ] Test staff management and tenant management with `ADMIN` and `SUPER_ADMIN`
- [ ] Test `TENANT` separately because it is partially defined and currently inconsistent with the permission matrix
