# Multi-Tenant Ownership Refactor Validation Checklist

Use this document to verify that the PHP loan management system is aligned with the client's required tenant ownership workflow.

This is not a generic QA list. It is a focused validation guide for the target rules below:

- ✅ `SUPER_ADMIN` is the only role that can create tenants
- ✅ `ADMIN` is no longer global
- ✅ `ADMIN` can access only tenant(s) they own
- ✅ One `ADMIN` can own multiple tenants
- ✅ `ADMIN` must choose a tenant after login if they own multiple tenants
- ✅ After tenant selection, all admin pages and queries are scoped only to the selected tenant
- ✅ `ADMIN` must never see tenants they do not own
- ✅ `SUPER_ADMIN` can still manage all tenants
- ✅ `TENANT` role is not used as the owner workflow

## 1. Summary Of Current Problem

Before the refactor is accepted, confirm the old problems are gone:

- ❌ `ADMIN` should not have global tenant access
- ❌ `ADMIN` should not be allowed to create tenants
- ❌ `ADMIN` should not see all tenants in tenant management
- ❌ `ADMIN` should not bypass tenant filtering in queries
- ❌ Owner workflow should not rely on `TENANT` role
- ❌ A single `users.tenant_id` relationship is not enough for multi-tenant ownership by `ADMIN`

How to test:
- Review login behavior for `ADMIN`
- Review tenant selection behavior
- Review tenant registration permissions
- Review tenant-scoped pages after login
- Review database ownership mapping

## 2. Proposed Architecture Change

Expected final architecture after refactor:

- ✅ `tenant_admins` table exists as the ownership mapping table
- ✅ One `ADMIN` can have multiple rows in `tenant_admins`
- ✅ One tenant can have one or more `ADMIN` owners if needed
- ✅ `users.tenant_id` is still used for tenant-scoped staff and customers
- ✅ `ADMIN` ownership comes from `tenant_admins`, not from `users.tenant_id`
- ✅ `SUPER_ADMIN` remains global
- ✅ `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, `CASHIER`, `CUSTOMER` remain tenant-scoped

How to test:
- Check schema in phpMyAdmin
- Confirm `tenant_admins` has foreign keys, indexes, and uniqueness on `tenant_id + user_id`
- Confirm code uses `current_active_tenant_id` in session for scoping

## 3. SQL Migration

Expected migration outcome:

- ✅ `tenant_admins` table exists
- ✅ It contains:
  - `id`
  - `tenant_id`
  - `user_id`
  - `is_primary_owner`
  - `created_at`
- ✅ `tenant_id` references `tenants.tenant_id`
- ✅ `user_id` references `users.user_id`
- ✅ Duplicate `tenant_id + user_id` pairs are blocked
- ✅ Existing admin ownership data is migrated safely

How to test:
- Run:

```sql
SHOW TABLES LIKE 'tenant_admins';
```

- Run:

```sql
SHOW CREATE TABLE tenant_admins;
```

- Run:

```sql
SELECT
  u.username,
  u.role,
  t.tenant_name,
  t.subdomain,
  ta.is_primary_owner,
  ta.created_at
FROM tenant_admins ta
JOIN users u ON u.user_id = ta.user_id
JOIN tenants t ON t.tenant_id = ta.tenant_id
ORDER BY u.username, t.tenant_name;
```

Pass / Fail:
- ✅ Table exists and structure matches
- ✅ Ownership rows exist for admin owners
- ❌ No duplicate owner rows
- ❌ No non-`ADMIN` users assigned as tenant owners

## 4. Files To Check

These are the main files that must reflect the new ownership workflow:

- `includes/auth.php`
- `includes/AuthService.php`
- `includes/TenantService.php`
- `includes/TenantMiddleware.php`
- `staff/login.php`
- `staff/select_tenant.php`
- `staff/dashboard.php`
- `staff/registration.php`
- `staff/tenant_management.php`
- `staff/staff.php`
- `staff/staff_settings.php`
- `staff/customers.php`
- `staff/loans.php`
- `staff/loan_view.php`
- `staff/payments.php`
- `staff/payment_add.php`
- `staff/payment_edit.php`
- `staff/reports.php`
- `staff/release_queue.php`
- `staff/release_voucher.php`
- `staff/manager_queue.php`
- `staff/ci_queue.php`
- API files that use tenant-scoping logic

How to test:
- Search for old `ADMIN` bypass logic
- Search for tenant filtering logic
- Search for direct use of `users.tenant_id` for owner behavior

Recommended code search:

```text
ADMIN
tenant_id
current_active_tenant_id
is_system_admin
tenant_admins
user_owned_tenants
set_current_active_tenant_id
tenant_condition
enforce_tenant_resource_access
```

## 5. Code Change Validation

Expected code behavior:

- ✅ `require_login()` ensures active authenticated session
- ✅ `login_user()` stores `user_id`, `role`, `is_system_admin`, and `current_active_tenant_id`
- ✅ `SUPER_ADMIN` remains global
- ✅ `ADMIN` is not global anymore
- ✅ `ADMIN` tenant access is determined from `tenant_admins`
- ✅ tenant filtering uses `current_active_tenant_id`
- ✅ backend guards block access even if the frontend is bypassed

How to test:
- Read the helper functions in `includes/auth.php`
- Confirm `ADMIN` does not bypass tenant restrictions
- Confirm `require_current_tenant_id()` returns selected tenant context for admin
- Confirm `tenant_condition()` uses current active tenant
- Confirm `enforce_tenant_resource_access()` blocks URL tampering across tenants

Pass / Fail:
- ✅ All tenant checks use session active tenant
- ❌ No page treats `ADMIN` as a system-wide role

## 6. New Tenant Selection Flow

Expected flow:

### `SUPER_ADMIN`

- ✅ Logs in normally
- ✅ No required tenant selection to access global functions
- ✅ Can optionally switch tenant context for viewing

### `ADMIN` With One Owned Tenant

- ✅ Authenticates normally
- ✅ System loads owned tenants from `tenant_admins`
- ✅ If only one owned tenant exists, it is auto-selected
- ✅ Redirect goes directly to dashboard

### `ADMIN` With Multiple Owned Tenants

- ✅ Authenticates normally
- ✅ Redirected first to tenant selection page
- ✅ Sees only owned tenants
- ✅ Clicks a tenant card/button
- ✅ Selected tenant is stored in session as `current_active_tenant_id`
- ✅ Redirect goes to selected tenant dashboard

### Other Tenant-Scoped Staff

- ✅ Continue normal single-tenant login
- ✅ No owner-style tenant selection flow

How to test:

#### Test A: `SUPER_ADMIN`

1. Login as `SUPER_ADMIN`
2. Confirm no forced tenant selection blocks global admin pages
3. Open tenant management
4. Confirm all tenants are visible

Expected:
- ✅ Global pages available
- ✅ Tenant creation available

#### Test B: `ADMIN` owning one tenant

1. Login as an `ADMIN` with one row in `tenant_admins`
2. Confirm no tenant chooser is shown
3. Confirm redirect goes straight to dashboard
4. Confirm dashboard data belongs only to owned tenant

Expected:
- ✅ Auto-select one tenant
- ❌ No access to unowned tenants

#### Test C: `ADMIN` owning multiple tenants

1. Give one `ADMIN` at least two rows in `tenant_admins`
2. Login
3. Confirm redirect goes to tenant selection page
4. Confirm only owned tenants appear as cards/buttons
5. Click Tenant A
6. Confirm redirect to Tenant A dashboard
7. Use Switch Tenant
8. Click Tenant B
9. Confirm dashboard changes to Tenant B

Expected:
- ✅ Tenant chooser appears
- ✅ Only owned tenants shown
- ✅ Switching updates active tenant context
- ❌ Cannot choose tenants not owned

## 7. Security Checks Added

Expected backend protection:

- ✅ `ADMIN` cannot access unowned tenants by URL tampering
- ✅ `ADMIN` cannot submit another tenant ID in POST and gain access
- ✅ `ADMIN` cannot view unowned customers
- ✅ `ADMIN` cannot view unowned loans
- ✅ `ADMIN` cannot view unowned payments
- ✅ `ADMIN` cannot view unowned reports
- ✅ `ADMIN` cannot manage unowned staff
- ✅ `ADMIN` cannot manage all tenants globally
- ✅ Frontend restrictions are backed by backend validation

How to test:

1. Login as an `ADMIN`
2. Select Tenant A
3. Change URL IDs to records known to belong to Tenant B
4. Try editing POST values in browser devtools or an HTTP client
5. Try direct access to:
   - `/staff/customers.php`
   - `/staff/loan_view.php?id=...`
   - `/staff/payment_edit.php?id=...`
   - `/staff/reports.php`
   - `/staff/staff_settings.php?id=...`
   - `/staff/release_voucher.php?id=...`

Expected:
- ✅ Access denied, not found, or safe redirect
- ❌ No cross-tenant data leak

## 8. Notes About `TENANT` Role

Expected rule:

- ✅ `TENANT` is not the owner login for this workflow
- ✅ Owner behavior belongs to `ADMIN` via `tenant_admins`
- ✅ `TENANT` may remain in the system only if used for some other separate business meaning

How to test:
- Review login behavior for `TENANT`
- Confirm `TENANT` does not receive owner tenant-selection behavior
- Confirm tenant creation and tenant ownership assignment do not rely on `TENANT`

Recommendation:
- Keep `TENANT` only if it still has a separate business purpose
- Deprecate it for ownership logic

## 9. Role Capability Checklist

Use this section to verify what each role can do, cannot do, and who registers the account.

### `SUPER_ADMIN`

Registered by:
- System seed or existing super admin setup

Can do:
- ✅ Create/register tenants
- ✅ View all tenants
- ✅ Assign one or more `ADMIN` owners to a tenant
- ✅ Access system-wide pages
- ✅ Optionally switch tenant context

Cannot do:
- ❌ Be restricted to one owned tenant only

How to test:
1. Login as `SUPER_ADMIN`
2. Open tenant management and registration
3. Create a new tenant
4. Assign an `ADMIN` owner
5. Confirm success in UI and DB

### `ADMIN`

Registered by:
- `SUPER_ADMIN`

Can do:
- ✅ Access only owned tenants
- ✅ Switch only among owned tenants
- ✅ Manage customers, loans, payments, staff, reports, and settings only inside selected owned tenant

Cannot do:
- ❌ Create tenants
- ❌ View all tenants globally
- ❌ Access tenants they do not own
- ❌ Use `users.tenant_id` alone as global owner authority

How to test:
1. Login as `ADMIN`
2. Confirm owned tenant logic comes from `tenant_admins`
3. If one tenant is owned, confirm auto-select
4. If multiple tenants are owned, confirm chooser appears
5. Try to access unowned tenant data

### `MANAGER`

Registered by:
- `SUPER_ADMIN` or owned-tenant `ADMIN`, depending on business rules implemented

Can do:
- ✅ Access manager workflow only in assigned tenant
- ✅ View tenant-scoped data for their tenant

Cannot do:
- ❌ Create tenants
- ❌ Switch owner tenants
- ❌ Access other tenants

How to test:
1. Login as `MANAGER`
2. Open queue and reports pages allowed to manager
3. Confirm only assigned tenant data is visible

### `CREDIT_INVESTIGATOR`

Registered by:
- `SUPER_ADMIN` or owned-tenant `ADMIN`

Can do:
- ✅ Work only inside assigned tenant

Cannot do:
- ❌ Create tenants
- ❌ Switch tenants as owner
- ❌ Access other tenants

How to test:
1. Login as `CREDIT_INVESTIGATOR`
2. Open CI queue
3. Confirm only assigned tenant applications appear

### `LOAN_OFFICER`

Registered by:
- `SUPER_ADMIN` or owned-tenant `ADMIN`

Can do:
- ✅ Work only inside assigned tenant

Cannot do:
- ❌ Create tenants
- ❌ Switch tenants as owner
- ❌ Access other tenants

How to test:
1. Login as `LOAN_OFFICER`
2. Open loans and customers
3. Confirm only assigned tenant data appears

### `CASHIER`

Registered by:
- `SUPER_ADMIN` or owned-tenant `ADMIN`

Can do:
- ✅ Work with payments only inside assigned tenant

Cannot do:
- ❌ Create tenants
- ❌ Switch tenants as owner
- ❌ Access other tenants

How to test:
1. Login as `CASHIER`
2. Open payment pages
3. Confirm only assigned tenant data appears

### `CUSTOMER`

Registered by:
- Customer self-registration or tenant-side process, depending on system flow

Can do:
- ✅ Access only their own tenant/customer data

Cannot do:
- ❌ Act as tenant owner
- ❌ Create tenants
- ❌ Access staff functions

How to test:
1. Login via customer/API flow
2. Confirm only own data is returned

### `TENANT`

Registered by:
- Only if legacy workflow still uses it

Can do:
- ✅ Only whatever legacy non-owner purpose still exists

Cannot do:
- ❌ Act as the owner workflow for this refactor
- ❌ Replace `ADMIN` ownership mapping

How to test:
1. Login as `TENANT` if such account still exists
2. Confirm it does not receive owner tenant selection behavior
3. Confirm it does not bypass tenant restrictions

## 10. Tenant Registration And Management Validation

Expected result after refactor:

- ✅ Only `SUPER_ADMIN` can create/register tenants
- ✅ `ADMIN` cannot create tenants
- ✅ `ADMIN` cannot manage all tenants globally
- ✅ `SUPER_ADMIN` can assign one or more `ADMIN` owners to a tenant
- ✅ Tenant forms validate that owners are real `ADMIN` users

How to test:

### As `SUPER_ADMIN`

1. Login
2. Open tenant registration page
3. Create a tenant
4. Assign one or more `ADMIN` owners
5. Save
6. Confirm `tenants`, `system_settings`, and `tenant_admins` were updated

Expected:
- ✅ Tenant created
- ✅ Owners assigned

### As `ADMIN`

1. Login
2. Try to access tenant registration page
3. Try to access tenant management page
4. Try to submit a tenant creation POST manually

Expected:
- ❌ No create access
- ❌ No global tenant management access
- ✅ Backend blocks POST tampering

## 11. Query And Page Enforcement Checklist

Test every page below while logged in as an owned-tenant `ADMIN`:

- `staff/dashboard.php`
- `staff/registration.php`
- `staff/staff.php`
- `staff/staff_settings.php`
- `staff/customers.php`
- `staff/loans.php`
- `staff/loan_view.php`
- `staff/payments.php`
- `staff/payment_add.php`
- `staff/payment_edit.php`
- `staff/reports.php`
- `staff/release_queue.php`
- `staff/release_voucher.php`
- `staff/manager_queue.php`
- `staff/ci_queue.php`

How to test each page:

1. Login as `ADMIN`
2. Select Tenant A
3. Open the page
4. Confirm only Tenant A data is visible
5. Try direct URL tampering using Tenant B record IDs
6. Try form submission using Tenant B values
7. Repeat after switching to Tenant B

Pass rules for every page:
- ✅ Only selected tenant data appears
- ✅ Cross-tenant record access is blocked
- ✅ POST handlers enforce the same tenant restriction as GET pages
- ❌ No page falls back to old global `ADMIN` behavior

## 12. Final Testing Checklist

Mark each item after testing.

### Database And Ownership

- [ ] ✅ `tenant_admins` exists
- [ ] ✅ `tenant_admins` has foreign keys and unique tenant-owner mapping
- [ ] ✅ One `ADMIN` can own multiple tenants
- [ ] ✅ Ownership is not handled by `TENANT` role

### Authentication Flow

- [ ] ✅ `SUPER_ADMIN` logs in without required tenant selection
- [ ] ✅ Single-tenant `ADMIN` auto-selects owned tenant
- [ ] ✅ Multi-tenant `ADMIN` is redirected to tenant chooser
- [ ] ✅ Tenant chooser shows owned tenants only
- [ ] ✅ Tenant selection stores `current_active_tenant_id`

### Session And Access Control

- [ ] ✅ Session stores `user_id`
- [ ] ✅ Session stores `role`
- [ ] ✅ Session stores `is_system_admin`
- [ ] ✅ Session stores `current_active_tenant_id`
- [ ] ✅ `ADMIN` is not treated as global
- [ ] ✅ Tenant filters use active tenant session

### Tenant Registration Rules

- [ ] ✅ Only `SUPER_ADMIN` can create tenants
- [ ] ✅ `ADMIN` cannot create tenants
- [ ] ✅ Tenant owner assignment supports one or more `ADMIN` users

### Tenant Selection UI

- [ ] ✅ Tenant selection page exists
- [ ] ✅ Owned tenants are shown as buttons/cards
- [ ] ✅ Clicking one tenant redirects to that tenant dashboard
- [ ] ✅ Switch Tenant action works for owned tenants only

### Tenant Isolation

- [ ] ✅ `ADMIN` sees only selected owned tenant data
- [ ] ✅ `ADMIN` never sees unowned tenants
- [ ] ✅ Reports are tenant-scoped
- [ ] ✅ Customers are tenant-scoped
- [ ] ✅ Loans are tenant-scoped
- [ ] ✅ Payments are tenant-scoped
- [ ] ✅ Staff are tenant-scoped
- [ ] ✅ Settings are tenant-scoped

### Security

- [ ] ✅ URL tampering is blocked
- [ ] ✅ POST tampering is blocked
- [ ] ✅ Backend enforces ownership
- [ ] ✅ Frontend restrictions are not the only protection

### Role Behavior

- [ ] ✅ `SUPER_ADMIN` remains the only tenant creator
- [ ] ✅ `ADMIN` behaves as tenant owner, not global admin
- [ ] ✅ `MANAGER` remains tenant-scoped
- [ ] ✅ `CREDIT_INVESTIGATOR` remains tenant-scoped
- [ ] ✅ `LOAN_OFFICER` remains tenant-scoped
- [ ] ✅ `CASHIER` remains tenant-scoped
- [ ] ✅ `CUSTOMER` remains tenant-scoped
- [ ] ✅ `TENANT` is not used for owner workflow

## 13. Final Answer To The Client Question

After this refactor:

- ✅ Only `SUPER_ADMIN` can create tenants
- ✅ `ADMIN` accesses multiple owned tenants through the `tenant_admins` ownership mapping
- ✅ If the `ADMIN` owns one tenant, the system auto-selects it after login
- ✅ If the `ADMIN` owns multiple tenants, the system shows a tenant selection screen first
- ✅ After selection, all admin access is limited to that selected tenant until they switch again
