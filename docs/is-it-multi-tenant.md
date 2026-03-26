# 1. Multi-Tenant Verdict

**PARTIAL**

The database schema is clearly designed for multi-tenancy, and the auth/session layer stores `tenant_id` after login. However, the application layer does **not** enforce tenant isolation consistently. There are many unscoped reads and writes, several pages that can expose or modify cross-tenant data by ID, and multiple create flows that do not write `tenant_id` at all.

This is **not** a properly enforced multi-tenant system yet.

# 2. Evidence Found

## Strengths

- There is a real `tenants` table in [schema.sql](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/schema.sql).
- Core business tables include `tenant_id` in the schema:
  - `users`
  - `customers`
  - `loans`
  - `requirements`
  - `payments`
  - `activity_logs`
  - `money_release_vouchers`
  - `interest_rate_history`
  - `system_settings`
- Login stores the authenticated user tenant in session in [includes/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/auth.php): line `203`.
  - Snippet: `$_SESSION['tenant_id'] = $user['tenant_id'];`
- Tenant helper/middleware exists:
  - [includes/TenantService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/TenantService.php)
  - [includes/TenantMiddleware.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/TenantMiddleware.php)
- Some files do apply tenant filtering correctly for non-system-admin users:
  - [api/v1/analytics.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/analytics.php)
  - [staff/staff.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/staff.php)
  - [staff/staff_settings.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/staff_settings.php)
  - parts of [staff/history.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/history.php)
- `tenant_id` is server-side session state, not normally taken from public forms for day-to-day staff actions.

## Database Structure Check

### `tenants` table

- Present in [schema.sql](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/schema.sql)

### Tables that already have `tenant_id`

- `users`
- `customers`
- `loans`
- `requirements`
- `payments`
- `activity_logs`
- `money_release_vouchers`
- `interest_rate_history`
- `system_settings`

### Tables that are missing `tenant_id` but are probably acceptable

- `payment_edit_otp`
  - This is tied to `payment_id` and `user_id`, so it can inherit tenant scope indirectly.

### Tenant index status

- Most main tables have a composite unique key beginning with `tenant_id`, for example:
  - `users`: `unique_tenant_username`
  - `customers`: `unique_tenant_customer_no`
  - `loans`: `unique_tenant_reference_no`
  - `payments`: `unique_tenant_or_no`
  - `money_release_vouchers`: `unique_tenant_voucher_no`
  - `system_settings`: `unique_tenant_setting`
- That means `tenant_id` is indexed through leftmost composite keys in many tables.
- But tenant-focused indexing is still uneven:
  - [schema.sql](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/schema.sql) does **not** define a standalone `idx_tenant_id` on all hot tables.
  - `activity_logs` in schema lacks a tenant index even though tenant filtering is common there.
  - A runtime fallback in [includes/loan_helpers.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/loan_helpers.php) creates `idx_tenant_id` only if the table is created there, which is not a reliable migration strategy.

## Authentication and Session Flow

- Staff login does **not** authenticate within a tenant boundary.
- [staff/login.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/login.php):12
  - `SELECT * FROM users WHERE username=? AND is_active=1`
- Because usernames are only unique per tenant in schema, this is a critical flaw.
- The code then stores whatever matched user's `tenant_id` into session:
  - [includes/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/auth.php):203
- Result:
  - same username can exist in multiple tenants
  - login has no tenant selector, subdomain resolver, or tenant-scoped lookup
  - the first matched active username across all tenants wins

## Query Safety Summary

- Tenant filtering is **inconsistent**.
- Some code uses `tenant_id` correctly.
- Many pages and services ignore it entirely.
- Several write flows omit `tenant_id` even though schema requires it.

# 3. Missing Multi-Tenant Requirements

- Login is not tenant-scoped.
- Many `SELECT` queries do not filter by `tenant_id`.
- Many `UPDATE` and `DELETE` queries target records only by primary key and do not confirm the record belongs to the current tenant.
- Several `INSERT` statements omit `tenant_id` on tables where schema requires it.
- Tenant middleware exists but is not used as the central enforcement layer for staff pages.
- Reports and settings pages leak cross-tenant data.
- Some service classes are written like single-tenant/global services rather than tenant-aware services.
- Mobile/API flow is not truly multi-tenant:
  - customer registration does not set `tenant_id`
  - token auth is unfinished
  - tenant resolution is missing

# 4. Dangerous Issues

## High Risk 1: Staff login is not tenant-scoped

- File: [staff/login.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/login.php):12
- Query:

```php
SELECT * FROM users WHERE username=? AND is_active=1
```

Why this is dangerous:
- `users.username` is unique per tenant, not globally.
- If two tenants both have `admin`, this query is ambiguous.
- A user may be authenticated into the wrong tenant account depending on which row is returned first.

Impact:
- Wrong tenant session
- Cross-tenant access
- Authentication ambiguity

## High Risk 2: Customer module is effectively global, not tenant-isolated

- File: [staff/customers.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/customers.php)
- Problematic lines:
  - list query at line `157`
  - deactivate line `16`
  - create user line `137`
  - create customer line `142`

Examples:

```php
SELECT ... FROM customers c LEFT JOIN users u ON u.user_id=c.user_id ORDER BY c.created_at DESC
UPDATE customers SET is_active=0 WHERE customer_id=?
INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)
INSERT INTO customers (customer_no, user_id, first_name, last_name, ...)
```

Why this is dangerous:
- Customer list is not filtered by tenant.
- Update/delete actions trust `customer_id` only.
- New users/customers are inserted without `tenant_id`.

Impact:
- Tenant A admin can view or modify Tenant B customers by direct action or ID reuse.
- Insert flow does not match schema-required tenant model.

## High Risk 3: Loans list and loan detail pages can expose and modify cross-tenant loans

- Files:
  - [staff/loans.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loans.php)
  - [staff/loan_view.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loan_view.php)

Problematic queries:
- [staff/loans.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loans.php):34
  - global loan listing query with no `tenant_id`
- [staff/loans.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loans.php):18
  - `UPDATE loans SET interest_rate = ? WHERE loan_id = ?`
- [staff/loan_view.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loan_view.php):15, 28, 112, 124
  - loan fetch by `loan_id` only
- [staff/loan_view.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loan_view.php):31
  - requirements by `loan_id` only
- [staff/loan_view.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loan_view.php):140
  - `UPDATE loans SET loan_officer_id=? WHERE loan_id=?`
- [staff/loan_view.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/loan_view.php):520
  - officer list is global across tenants

Why this is dangerous:
- Direct URL access like `loan_view.php?id=123` has no tenant ownership check.
- A user with permission can act on another tenant's loan if they know or guess the ID.
- Loan officer assignment can cross tenants.

## High Risk 4: Payments pages are not tenant-isolated

- Files:
  - [staff/payments.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payments.php)
  - [staff/payment_add.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payment_add.php)
  - [staff/payment_edit.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payment_edit.php)

Examples:
- [staff/payments.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payments.php):16-20
  - base query from `payments`, `loans`, `customers` with `WHERE 1=1`
  - no tenant filter
- [staff/payment_add.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payment_add.php)
  - loan fetch by `loan_id` only
  - payment insert omits `tenant_id`
  - officer list is global
- [staff/payment_edit.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payment_edit.php)
  - payment fetch by `payment_id` only
  - payment update by `payment_id` only

Impact:
- Cross-tenant payment viewing/editing by guessed IDs
- wrong-tenant officer assignment
- insert path not aligned to schema tenant design

## High Risk 5: Reports leak data across tenants

- File: [staff/reports.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/reports.php)

Problematic areas:
- loan officer list is global: line `51`
- loan transactions report joins loans/customers/payments without tenant filter
- `user_registrations` report reads all users globally
- `usage_statistics` unions all loans/customers/payments globally
- receipt helper data at lines around `534` and `571` reads all customers/payments globally
- interest update acts on `loan_id` only: lines `19` and `21`

Impact:
- Managers and other report viewers can see data outside their tenant.
- Export/print flows can include other tenants' customers and payments.

## High Risk 6: System settings are global, not per-tenant

- Files:
  - [includes/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/auth.php):327
  - [staff/manager_settings.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/manager_settings.php):61, 69, 72, 93

Queries:

```php
SELECT system_name, logo_path, primary_color FROM system_settings LIMIT 1
SELECT * FROM system_settings LIMIT 1
UPDATE system_settings SET ... LIMIT 1
```

Why this is dangerous:
- `system_settings` has `tenant_id` in schema, but code reads the first row globally.
- One tenant can overwrite branding/settings for another tenant.

## High Risk 7: Download endpoints and receipt pages allow ID-based cross-tenant access

- Files:
  - [staff/download_requirement.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/download_requirement.php)
  - [staff/receipt_summary.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/receipt_summary.php)

Examples:
- `SELECT * FROM requirements WHERE requirement_id=?`
- `SELECT * FROM customers WHERE customer_id=?`
- payment summary reads all payments for that customer without tenant verification

Impact:
- IDOR-style cross-tenant document and receipt access

## High Risk 8: Core service classes are not tenant-aware

- Files:
  - [includes/CustomerService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/CustomerService.php)
  - [includes/PaymentService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/PaymentService.php)
  - [includes/RequirementService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/RequirementService.php)

Examples:
- [includes/CustomerService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/CustomerService.php):28
  - `SELECT * FROM customers WHERE customer_id = ? AND is_active = 1`
- [includes/CustomerService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/CustomerService.php):83
  - `INSERT INTO customers ...` with no `tenant_id`
- [includes/PaymentService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/PaymentService.php):34, 52, 75, 112, 316
  - gets and inserts payments without tenant checks
- [includes/RequirementService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/RequirementService.php):33, 50, 86, 98, 168
  - gets, inserts, deletes requirements without tenant checks

Impact:
- Any future controller or API using these services can easily become cross-tenant.
- These services are not safe defaults for a multi-tenant app.

## High Risk 9: Mobile/API path is not multi-tenant-ready

- Files:
  - [api/v1/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/auth.php)
  - [api/v1/config.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/config.php)

Problems:
- [api/v1/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/auth.php):53
  - username uniqueness checked globally, not by tenant
- [api/v1/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/auth.php):68
  - customer user insert omits `tenant_id`
- [api/v1/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/auth.php):202
  - forgot-password lookup is global by email
- [api/v1/config.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/config.php):111, 137, 143, 148, 154
  - `verify_auth_token()` always returns `null`
  - authenticated endpoints cannot establish trusted tenant context

Impact:
- mobile/customer side is not a working multi-tenant implementation
- tenant resolution is missing

## Medium Risk 10: Admin and management pages mix tenant-wide and global data without a consistent model

- [staff/dashboard.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/dashboard.php)
  - non-system-admin metrics like total payments/customers/staff are global at the top of the file
- [staff/sales_report.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/sales_report.php)
  - designed as a global cross-tenant sales report; acceptable only for global admins, but page access currently depends on `view_sales` role, not explicit system-admin enforcement
- [staff/history.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/history.php)
  - includes logs with matching tenant OR `NULL/0` tenant for backward compatibility, which weakens strict isolation

# 5. Recommended Fixes

## Fix login and tenant resolution first

- Make login tenant-scoped.
- Required options:
  - use subdomain-based tenant resolution, or
  - require tenant code/subdomain on login, or
  - choose tenant before authenticating
- Replace:

```php
SELECT * FROM users WHERE username=? AND is_active=1
```

- With:

```php
SELECT * FROM users WHERE username=? AND tenant_id=? AND is_active=1
```

## Enforce tenant ownership on every record fetch and mutation

- For all staff pages, every read/write on tenant-owned tables must include:
  - record ID
  - current tenant from server-side session
- Example:

```php
SELECT * FROM loans WHERE loan_id=? AND tenant_id=?
UPDATE customers SET is_active=0 WHERE customer_id=? AND tenant_id=?
DELETE FROM requirements WHERE requirement_id=? AND tenant_id=?
```

## Make service classes tenant-aware by default

- Add a required `$tenant_id` property to:
  - `CustomerService`
  - `PaymentService`
  - `RequirementService`
  - any remaining data service
- Reject construction or execution if tenant context is missing for tenant-owned operations.
- Never expose global `getById()` on tenant-owned tables without tenant constraint.

## Fix all insert flows to populate `tenant_id` server-side

- Never trust tenant from form input in normal tenant-scoped flows.
- Use current authenticated tenant:

```php
$tenant_id = current_tenant_id();
```

- Apply to:
  - [staff/customers.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/customers.php)
  - [staff/payment_add.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/payment_add.php)
  - [staff/release_voucher.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/staff/release_voucher.php)
  - [includes/CustomerService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/CustomerService.php)
  - [includes/PaymentService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/PaymentService.php)
  - [includes/RequirementService.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/includes/RequirementService.php)
  - [api/v1/auth.php](/C:/xampp/htdocs/LOAN_MANAGEMENT_APP/api/v1/auth.php)

## Make `system_settings` truly per-tenant

- Replace all `LIMIT 1` reads/writes with `WHERE tenant_id=?`
- Cache settings by tenant, not globally in session under one shared key without tenant separation logic.

## Lock down reports, downloads, receipts, and exports

- Every report query must filter by current tenant unless the user is explicitly a global system admin.
- For global reports, enforce `is_system_admin()` in the controller, not only menu visibility.
- Document and receipt endpoints must validate the resource belongs to the current tenant before serving.

## Use middleware centrally, not optionally

- Require tenant context on every staff page through a shared bootstrap.
- Use `TenantMiddleware::requireTenantContext()` or equivalent in one common include path.
- Add reusable safe resource loaders:
  - `getLoanForCurrentTenant($loan_id)`
  - `getCustomerForCurrentTenant($customer_id)`
  - `getPaymentForCurrentTenant($payment_id)`

## Fix mobile/API before calling it multi-tenant

- Implement real token verification
- include tenant in token claims
- resolve tenant at registration/login
- scope all API queries to tenant
- do not register customers globally

## Improve schema/indexing and migration discipline

- Add explicit `KEY idx_tenant_id (tenant_id)` where high-volume tenant filters are common, especially:
  - `activity_logs`
  - possibly `loans`, `payments`, `customers`, `users` depending on workload
- Do not rely on runtime table creation in helpers for production schema correctness.

# 6. Priority Order (highest priority first)

1. Fix staff login so authentication is tenant-scoped and cannot bind the wrong tenant.
2. Fix all ID-based read/write pages to require `tenant_id` in queries, especially customers, loans, payments, requirements, receipts, and downloads.
3. Fix insert flows that omit `tenant_id`, because they break the tenant model and may already fail against the schema.
4. Fix `system_settings` to be tenant-specific instead of `LIMIT 1`.
5. Refactor shared service classes to require tenant context and reject global access by default.
6. Lock down reports, dashboards, sales, and export paths so non-system-admin users only see their own tenant’s data.
7. Finish the mobile/API auth model with tenant-aware registration, login, token claims, and scoped queries.
8. Add stronger tenant-focused indexing and formal schema migrations.

