# Multi-Tenant Post-Change Checklist

Use this after applying the backend multi-tenant hardening changes.

## 1. Database

- Run [20260325_multi_tenant_hardening.sql](C:\xampp\htdocs\Loan_management_app\setup\20260325_multi_tenant_hardening.sql).
- Confirm every tenant has one row in `system_settings`.
- Confirm `activity_logs` now has index `idx_tenant_id`.

Suggested SQL checks:

```sql
SELECT tenant_id, tenant_name FROM tenants ORDER BY tenant_id;

SELECT tenant_id, system_name FROM system_settings ORDER BY tenant_id;

SHOW INDEX FROM activity_logs;
```

## 2. Login And Reset

- Open staff login.
- Confirm a tenant dropdown is shown.
- Try logging in without selecting a tenant. It should fail.
- Try logging in with:
  - correct tenant + correct username/password: should succeed
  - wrong tenant + correct username/password from another tenant: should fail
- Open forgot password.
- Confirm tenant selection is required there too.
- Confirm reset requests only work for the selected tenant.

## 3. Branding And Settings

- Log in as a staff user from Tenant A.
- Open Settings.
- Change system name, color, or logo.
- Confirm only Tenant A branding changes.
- Log in as Tenant B.
- Confirm Tenant B still shows its own branding/settings.
- Confirm login page branding changes when a different tenant is selected.

## 4. Customer Isolation

- In Tenant A, open Customers.
- Confirm only Tenant A customers are listed.
- Register a new customer.
- Confirm the new `users` row and `customers` row both have Tenant A `tenant_id`.
- Edit, activate/deactivate, and delete a customer from Tenant A.
- Confirm actions do not affect Tenant B customers.
- Try opening a Tenant B customer by manually changing IDs in requests. It should fail or show not found.

## 5. Loan Isolation

- In Tenant A, open Loans.
- Confirm only Tenant A loans appear.
- Open a loan detail page.
- Confirm requirements and payments shown belong only to that tenant.
- Update interest rate or payment term.
- Confirm the update only applies to the tenant-owned loan.
- Try opening a Tenant B `loan_view.php?id=...` while logged into Tenant A. It should fail.

## 6. Payment Isolation

- In Tenant A, open Payments.
- Confirm only Tenant A payments appear.
- Record a payment from Tenant A.
- Confirm the inserted payment row has the same `tenant_id` as the loan.
- Edit a payment and print a receipt.
- Confirm receipt and edit only work for the tenant-owned payment.
- Try opening a Tenant B payment receipt or edit page by ID. It should fail.

Suggested SQL checks:

```sql
SELECT payment_id, tenant_id, loan_id, or_no
FROM payments
ORDER BY payment_id DESC
LIMIT 20;

SELECT loan_id, tenant_id, reference_no
FROM loans
ORDER BY loan_id DESC
LIMIT 20;
```

## 7. Requirements And Downloads

- Open a loan with uploaded requirements.
- Download a requirement file.
- Confirm it works for the correct tenant.
- Try accessing a requirement ID from another tenant directly. It should fail.

## 8. Reports And Dashboard

- Log in as a non-system-admin tenant user.
- Open Dashboard.
- Confirm counts and recent items are tenant-only.
- Open Reports.
- Confirm loan transaction data is tenant-only.
- Confirm receipt modal only shows tenant-owned customers and payments.
- Open Sales Report as a non-admin or non-system-admin user.
- Confirm access is blocked.

## 9. Staff Management Scope

- Open Staff Settings and Registration as a tenant-scoped admin.
- Confirm staff actions only work for:
  - users in the same tenant
  - `SUPER_ADMIN` accounts where allowed by the page
- Try editing/deleting a staff user from another tenant. It should fail.

## 10. Release Voucher Scope

- Open Release Queue.
- Confirm only tenant-owned loans appear.
- Open or edit a release voucher.
- Confirm voucher reads/writes stay within the loan tenant.

## 11. Session Safety

- Log in normally.
- Manually tamper with session or remove tenant-related data if you have a dev setup.
- Confirm protected staff pages redirect back to login when tenant session is invalid.

## 12. Regression Smoke Test

- Login
- Dashboard
- Customers list and create
- Loans list and detail
- Record payment
- Edit payment
- Print receipt
- Reports
- Settings
- Release voucher
- Logout

## 13. What To Investigate If Something Fails

- Missing `system_settings` rows per tenant
- Old data with unexpected `tenant_id` values
- Existing duplicate usernames or emails across tenants
- Hardcoded direct-ID links from old bookmarks
- Pages not covered in this pass, especially:
  - API/mobile endpoints
  - older admin pages not exercised yet
  - any custom scripts using shared services
