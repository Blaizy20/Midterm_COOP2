# Current Workflow Verification

This report verifies the supplied "current implemented workflow" claims against the actual PHP codebase. The code is treated as the source of truth.

## 1. Executive Summary

Most of the supplied workflow findings are accurate, but some need tightening or correction.

What is confirmed by code:

- `SUPER_ADMIN` and `ADMIN` are both global roles after login, because both are marked as `is_system_admin` in `includes/auth.php::login_user()` and therefore bypass tenant filtering through `tenant_condition()` and related helpers.
- The implementation is tenant-based only. There is no real `branch_id` model in `schema.sql`, `staff/`, `includes/`, or `api/v1/`.
- Staff are not explicitly assignable to a tenant or branch through a dedicated UI. New staff are inserted using the creator's current session tenant in `staff/registration.php`.
- `TENANT` can log in and can open the dashboard because `staff/dashboard.php` only calls `require_login()`, not `require_permission('view_dashboard')`.
- Only `SUPER_ADMIN` and `ADMIN` can manage staff and tenants.
- The customer/mobile API flow is not functional end-to-end. `api/v1/config.php::verify_auth_token()` always returns `null`, so authenticated API endpoints cannot work.

What is only partially accurate or needs correction:

- `SUPER_ADMIN` is not the only global role: true. But both `SUPER_ADMIN` and `ADMIN` still need a valid tenant session to log in, because `require_login()` validates `tenant_id` against `tenants.is_active`.
- The claim "release queue may not restrict loans by releasable/approved status" is accurate, and stronger than stated: `staff/release_queue.php` does not filter by status at all.
- The claim "payment_add.php may allow backend payment submission without enforcing ACTIVE/OVERDUE status" is accurate. The button is UI-limited in `staff/loan_view.php`, but `staff/payment_add.php` itself does not enforce that loan status.
- The claim "some POST handlers rely on UI restrictions" is accurate. `staff/loan_view.php` shows certain forms only for certain statuses, but the POST handlers do not always repeat those status checks.

Main conclusion:

The supplied workflow findings are mostly correct. The biggest verified realities are:

- `ADMIN` is global, not tenant-limited.
- `TENANT` is partially wired and bypasses the normal permission map on the dashboard.
- The system is genuinely tenant-based, but not branch-based.
- Staff/tenant ownership and explicit staff assignment flows do not exist.
- The customer API implementation is incomplete/broken.

## 2. Role Verification Table

| Role | Can log in? | Pages actually accessible | Actions actually allowed | Scope | Backend enforcement | Are the supplied workflow findings accurate? |
| --- | --- | --- | --- | --- | --- | --- |
| `SUPER_ADMIN` | Yes. Allowed by `staff/login.php` and `includes/AuthService.php::authenticateStaffUser()`. | Dashboard, loans, loan details, customers, payments, CI queue, manager queue, reports, staff, registration, tenant management, sales report, history, settings, release queue, release voucher, receipts, requirement download. Evidence: permission map in `includes/auth.php::auth_role_permissions()`, page guards across `staff/`. | Create/edit/delete staff; change staff roles/status; create tenants; activate/deactivate tenants; manage customers; CI review; approve/deny loans; update terms; assign loan officer; record/edit payments; print receipts; manage vouchers. | Global after login via `includes/auth.php::login_user()` and `includes/auth.php::tenant_condition()`. | Mostly strong page-level backend guards via `require_permission()`. Dashboard and analytics are weaker because they only use `require_login()`. | Accurate, except `SUPER_ADMIN` is not uniquely global. |
| `ADMIN` | Yes. Same login path as `SUPER_ADMIN`. | Effectively same page reach as `SUPER_ADMIN`. `manage_staff`, `manage_tenants`, `view_sales`, `view_history`, `view_settings` all include `ADMIN` in `includes/auth.php`. | Effectively same operational/admin actions as `SUPER_ADMIN`. | Global after login because `ADMIN` is also marked `is_system_admin` in `includes/auth.php::login_user()`. | Same guard pattern as `SUPER_ADMIN`. | Accurate. The supplied finding that `ADMIN` behaves almost like `SUPER_ADMIN` and is global is confirmed. |
| `TENANT` | Yes. Explicitly allowed in `staff/login.php`. | Dashboard by direct navigation; requirement download by direct URL in `staff/download_requirement.php`; analytics endpoints by direct URL in `api/v1/analytics.php`. Not in normal sidebar permission map. | Dashboard viewing and tenant-scoped summary data; requirement download if resource ID is known; analytics endpoint access if logged in. | Tenant-scoped because `TENANT` is not system admin. | Weak/partial. `TENANT` is absent from `includes/auth.php::auth_role_permissions()`, but dashboard still works because `staff/dashboard.php` only uses `require_login()`. | Accurate. The supplied finding that `TENANT` is partially wired and has extra direct access is confirmed. |
| `MANAGER` | Yes. | Dashboard, loans, loan details, customers, payments, CI queue, manager queue, reports, staff list, settings, release queue/voucher, receipts, requirement download. Evidence: permission map plus per-page guards. | Approve/deny loans, CI review, update loan terms, assign loan officers, record/edit/print payments, manage vouchers, view staff list, update tenant settings/branding in `staff/manager_settings.php`. | Tenant-scoped. | Mostly yes. Main guards exist in `staff/loan_view.php`, `staff/payment_add.php`, `staff/payment_edit.php`, `staff/release_queue.php`, `staff/release_voucher.php`. | Accurate, with one extra note: managers can also modify settings, not just open them. |
| `CREDIT_INVESTIGATOR` | Yes. | Dashboard, loans, loan details, customers, CI queue, reports, requirement download. | Review `PENDING` loans, add CI remarks, mark as `CI_REVIEWED` in `staff/loan_view.php`. Cannot approve/deny because `approve_applications` excludes this role. | Tenant-scoped. | Good on primary actions. | Accurate. |
| `LOAN_OFFICER` | Yes. | Dashboard, loans, loan details, customers, reports, release queue/voucher, requirement download. No payments page because `view_payments` excludes this role. | View loans and payment history, monitor active/overdue loans, prepare/view vouchers. Cannot directly record payments because `record_payments` excludes `LOAN_OFFICER`. | Tenant-scoped. | Good on the main pages. | Accurate. |
| `CASHIER` | Yes. | Dashboard, payments, loan details, reports, release queue/voucher, receipts, requirement download. | Record payments, edit payments, print receipts, manage vouchers. Payment edit requires OTP flow in `staff/payment_edit.php`, but that flow has security weaknesses. | Tenant-scoped. | Core route guards exist, but OTP/security logic is weak. | Accurate. |
| `CUSTOMER` | No for staff portal. `staff/login.php` rejects `CUSTOMER` by allowing only non-customer staff roles. | Intended API/mobile only. | Registration/login/reset endpoints exist in `api/v1/auth.php`, loan endpoints exist in `api/v1/loans.php`, but authenticated access cannot work because `api/v1/config.php::verify_auth_token()` always returns `null`. Registration also conflicts with required tenant handling. | Intended tenant-scoped, but not actually operational. | Not functional end-to-end. | Accurate. The supplied finding that API/mobile flow exists in code but may not actually work is confirmed. |

## 3. Staff/Tenant Control Findings

### Who can add staff?

- Only `SUPER_ADMIN` and `ADMIN`.
- Evidence:
  - `includes/auth.php::auth_role_permissions()` defines `manage_staff` as `['SUPER_ADMIN', 'ADMIN']`.
  - `staff/registration.php` begins with `require_permission('manage_staff')`.

### Who can edit staff?

- Only `SUPER_ADMIN` and `ADMIN`.
- Evidence:
  - `staff/registration.php` handles update/delete of staff accounts under `require_permission('manage_staff')`.
  - `staff/staff_settings.php` handles role changes and activate/deactivate under `require_permission('manage_staff')`.

### Who can create tenant?

- Only `SUPER_ADMIN` and `ADMIN` in practice.
- Evidence:
  - Tenant creation happens in `staff/registration.php`, and the page is protected by `require_permission('manage_staff')`, which only those two roles have.
  - Tenant management page `staff/tenant_management.php` is protected by `require_permission('manage_tenants')`, also only `SUPER_ADMIN` and `ADMIN`.

### Who can activate/deactivate tenant?

- Only `SUPER_ADMIN` and `ADMIN`.
- Evidence:
  - `staff/tenant_management.php` uses `require_permission('manage_tenants')`.
  - The POST handler updates `tenants.is_active`.

### Can any role explicitly assign a staff member to a tenant or branch?

- No, not from any dedicated assignment flow.
- Evidence:
  - There is no tenant selector in the staff registration workflow.
  - There is no branch model at all.
  - `staff/registration.php` inserts staff with `tenant_id = require_current_tenant_id()`, meaning the creator's session tenant is used implicitly.

### Is staff creation only implicitly tenant-scoped?

- Yes.
- Evidence:
  - `staff/registration.php::require_current_tenant_id()`
  - `INSERT INTO users (tenant_id, username, ...) VALUES (...)`

### Is `ADMIN` global?

- Yes.
- Evidence:
  - `includes/auth.php::login_user()` sets `$_SESSION['is_system_admin'] = true` for both `ADMIN` and `SUPER_ADMIN`.
  - `includes/auth.php::tenant_condition()` returns `1=1` for system admins, disabling tenant filtering.

### Is `SUPER_ADMIN` the only global role?

- No.
- `ADMIN` is also global.

## 4. Multi-Tenant Enforcement Findings

### Is `tenant_id` consistently present in schema?

- Yes, across the main tenant-owned entities.
- Confirmed in `schema.sql` for:
  - `users`
  - `customers`
  - `loans`
  - `requirements`
  - `payments`
  - `activity_logs`
  - `money_release_vouchers`
  - `interest_rate_history`
  - `system_settings`

### Does `branch_id` exist anywhere in the actual implementation?

- No.
- Repo-wide code inspection across `schema.sql`, `staff/`, `includes/`, and `api/v1/` found no real `branch_id` implementation.
- The only branch-like language appears in docs, not in executable code.

### Do queries really enforce tenant filtering?

- Mostly yes for non-system-admin roles.
- Main mechanism:
  - `includes/auth.php::tenant_condition()`
  - `includes/auth.php::tenant_types()`
  - `includes/auth.php::tenant_params()`
  - `includes/auth.php::enforce_tenant_resource_access()`
- These helpers are used in:
  - `staff/customers.php`
  - `staff/loans.php`
  - `staff/loan_view.php`
  - `staff/payments.php`
  - `staff/payment_add.php`
  - `staff/payment_edit.php`
  - `staff/ci_queue.php`
  - `staff/manager_queue.php`
  - `staff/reports.php`
  - `staff/payment_receipt.php`
  - `staff/receipt_summary.php`
  - `staff/download_requirement.php`

### Are non-global roles tenant-restricted?

- Yes, generally.
- `includes/auth.php::is_tenant_restricted()` returns true for logged-in users who are not system admins.
- That means all non-`ADMIN` / non-`SUPER_ADMIN` roles use tenant filtering where the helper functions are used.

### Does `ADMIN` bypass tenant restrictions?

- Yes.
- Confirmed in `includes/auth.php::login_user()` and the tenant helper functions.

### Can users from one tenant access another tenant's data unless they are global roles?

- In most staff flows, no.
- Non-global users are tenant-restricted by query helpers and `enforce_tenant_resource_access()`.
- Known exception pattern: routes that only use `require_login()` and then run unrestricted logic, such as `staff/dashboard.php` and `api/v1/analytics.php`, expose behavior based on the logged-in session rather than permission map.

### Does tenant ownership/admin ownership exist?

- No.
- There is no `owner_user_id`, no ownership relation on `tenants`, and no dedicated tenant-owner assignment workflow.

### Is multi-tenant real in behavior or only partial?

- Multi-tenant is real in the data model and in most staff page behavior.
- But it is incomplete as an operational model because:
  - `ADMIN` is global,
  - tenant ownership is missing,
  - staff assignment is implicit only,
  - and branch support does not exist.

## 5. Workflow Mismatches

### Verified as accurate

- `SUPER_ADMIN` and `ADMIN` are both global roles.
- `ADMIN` is not tenant-limited.
- There is no `branch_id` implementation.
- Staff are not explicitly assignable to tenant/branch in a dedicated flow.
- `TENANT` can log in and access the dashboard despite not being in the permission map.
- Only `SUPER_ADMIN` and `ADMIN` can manage staff and tenants.
- Staff creation is implicitly tied to the creator's current session tenant.
- Customer API/mobile flow is incomplete/broken.

### Verified as partially accurate and needing stronger wording

- "TENANT may have extra direct URL access beyond dashboard-only behavior"
  - Confirmed. `TENANT` can access `staff/download_requirement.php` because it explicitly allows `TENANT` in `require_roles(...)`.
  - `TENANT` can also hit `api/v1/analytics.php` because it only requires login.

- "release_queue.php may not restrict loans by releasable/approved status"
  - Confirmed, and stronger than "may". It does not filter by status at all.
  - `staff/release_queue.php` builds `WHERE` only from tenant filter and optional search.

- "payment_add.php may allow backend payment submission without enforcing ACTIVE/OVERDUE status"
  - Confirmed.
  - `staff/loan_view.php` only shows the button when status is `ACTIVE` or `OVERDUE`, but `staff/payment_add.php` itself has no backend status guard.

- "some POST handlers rely on UI restrictions but do not enforce them server-side"
  - Confirmed.
  - `staff/loan_view.php` only shows forms for certain statuses, but POST handlers for updating loan terms and assigning officer do not repeat those same status checks on submission.

### Additional mismatches found in code

- Manager approval can happen directly from `PENDING`, bypassing CI review.
  - `staff/loan_view.php` updates to `ACTIVE` or `DENIED` when loan status is in `('CI_REVIEWED', 'PENDING')`.
  - That weakens the documented approval chain.

- Release voucher workflow is not guarded by loan status.
  - `staff/release_voucher.php` checks tenant access but not whether the loan is in an approved/releasable state.

- Some pages/functions reference roles that do not exist in the schema.
  - `staff/view_reset_links.php` expects `$_SESSION['role'] === 'STAFF'`.
  - `staff/reports.php` counts `u.role='STAFF'` in user registration reporting.
  - `schema.sql` does not define `STAFF` as a valid role.

- `TENANT` is present in login/session/dashboard/history filters, but absent from the main permission map.
  - This is an internally inconsistent role model.

## 6. Security/Permission Risks

### Routes protected only by `require_login()` or session, not by permission map

- `staff/dashboard.php`
  - Uses `require_login()` only.
  - This is why `TENANT` can access the dashboard.

- `api/v1/analytics.php`
  - Uses `require_login()` only.
  - Most endpoints do not use `require_permission()`.
  - Only `tenant_activity` has an explicit admin-only check.

### Hidden but reachable pages

- `staff/staff_settings.php`
  - Not linked in the sidebar.
  - Still directly reachable by URL for `SUPER_ADMIN` and `ADMIN`.

- `staff/download_requirement.php`
  - Not in sidebar.
  - Reachable directly for `SUPER_ADMIN`, `ADMIN`, `TENANT`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, `CASHIER`.

### Action handlers missing backend status/role checks

- `staff/payment_add.php`
  - No backend check that loan status must be `ACTIVE` or `OVERDUE`.

- `staff/loan_view.php`
  - `update_terms` handler does not re-check the UI status restrictions before processing.
  - `assign_officer` handler does not re-check the same status gating used by the UI.
  - `manager_decision` allows approval/denial from `PENDING` as well as `CI_REVIEWED`.

- `staff/release_queue.php`
  - No loan-status restriction for voucher eligibility.

- `staff/release_voucher.php`
  - No loan-status restriction.

### OTP/payment edit security issues

- `includes/loan_helpers.php::verify_otp_for_payment()`
  - Reads `expires_at` but never checks whether the OTP is expired.

- `includes/loan_helpers.php::send_otp_notification()`
  - Sends OTP to a hardcoded email address, not dynamically to actual managers/admins.

- `includes/loan_helpers.php::send_via_gmail_otp()`
  - Hardcoded Gmail app password in source code.

### Forgot-password/reset issues

- Staff reset flow in `staff/forgot_password.php`
  - Stores `reset_token_expiry` but does not check expiry during reset.
  - It only checks whether the token exists.

- Customer API forgot-password in `api/v1/auth.php`
  - Returns the reset token directly in the API response.

### API auth is incomplete/broken

- `api/v1/config.php::verify_auth_token()`
  - Always returns `null`.
- Therefore:
  - `api/v1/loans.php` authenticated GET/POST flows cannot actually work.
  - `api/v1/auth.php?action=logout` also cannot work through real token validation.

### Dead or inconsistent roles

- `STAFF` is referenced in:
  - `staff/view_reset_links.php`
  - `staff/reports.php`
- But `STAFF` is not a valid role in `schema.sql`.

## 7. Final Conclusion

The supplied "current workflow / audit findings" are mostly correct.

Confirmed by code:

- `ADMIN` is global, not tenant-limited.
- `SUPER_ADMIN` is not the only global role.
- The implementation is tenant-based only, with no branch model.
- Staff are only implicitly assigned to the creator's current tenant during creation.
- `TENANT` is partially wired, can log in, and can reach the dashboard outside the normal permission map.
- Only `SUPER_ADMIN` and `ADMIN` can manage staff and tenants.
- The customer/mobile API flow is present in code but not functionally complete.

Important additional realities found during verification:

- The release queue and release voucher flows are looser than intended and are not status-guarded.
- Some UI-only restrictions are not repeated in backend POST handlers.
- The OTP and reset-token security logic is weak.
- A few role references are internally inconsistent (`STAFF`, `TENANT`).

Bottom line:

The codebase does behave largely according to the supplied current workflow findings, but those findings should be treated as "mostly true with important caveats," not as a cleanly enforced design.

## 8. Short Answer: Based on the code, what role can add a staff member and assign them to a tenant or branch?

Only `SUPER_ADMIN` and `ADMIN` can add a staff member.

No role can explicitly assign a staff member to a tenant or branch through a dedicated assignment flow.

In the current code, staff are only attached implicitly to the current session tenant of the user who creates them in `staff/registration.php`. There is no branch implementation at all.

## Plain-English Summary

This system mostly works the way your current audit says it works.

The two biggest facts are:

- both `SUPER_ADMIN` and `ADMIN` can see across all tenants
- staff are not manually assigned to a tenant or branch from a special screen; they just inherit the tenant of the admin who creates them

The `TENANT` role is not fully built properly. It can still log in and open the dashboard, even though it is not included in the normal permission list.

The customer mobile/API side is not finished. The code for it exists, but real token login and protected customer API use do not work fully.

There are also some security and workflow gaps:

- some pages trust the UI too much and do not fully re-check rules on the server
- voucher pages are too open for loans that may not be ready
- password reset and OTP logic need stronger protection

So for a client or business owner: the system is usable as a tenant-based staff system, but it is not yet a cleanly enforced, fully secure, fully finished multi-role workflow.
