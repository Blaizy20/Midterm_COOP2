# Actual Workflow Audit

## 1. Executive Summary

The actual implementation only partially matches the documented role workflow.

Key findings from the current code:

- `SUPER_ADMIN` and `ADMIN` are both treated as global roles, not just `SUPER_ADMIN` (`includes/auth.php:203-215`, `includes/auth.php:227-297`).
- The system is tenant-based, not tenant+branch-based. There is no `branch_id` model in the schema or PHP code; repo-wide code search only found `branch` in docs, not in implementation.
- `TENANT` can log in and open the dashboard because [`staff/dashboard.php`](C:\xampp\htdocs\Loan_management_app\staff\dashboard.php) only calls `require_login()` and does not enforce `view_dashboard` (`staff/dashboard.php:4-6`), but `TENANT` is missing from the permission map (`includes/auth.php:49-70`).
- Staff creation is only available to `SUPER_ADMIN` and `ADMIN`, and staff are not explicitly assignable to a tenant/branch in the UI. New staff are inserted using the current session tenant (`staff/registration.php:5`, `staff/registration.php:17`, `staff/registration.php:104`).
- Tenant creation exists, but tenant ownership and admin-to-tenant assignment are not modeled. Creating a tenant does not assign that tenant to the creating admin (`staff/registration.php:148-164`).
- The mobile/API customer workflow is not actually functional end-to-end. `api/v1/config.php` leaves token verification unimplemented and always returns `null` (`api/v1/config.php:120-149`), so authenticated customer API routes cannot work. Customer registration also conflicts with the schema and service tenant requirements (`api/v1/auth.php:68-92`, `includes/CustomerService.php:18-27`, `schema.sql:32-40`).
- Backend authorization is mixed: many pages use `require_permission()`, but some important routes rely only on `require_login()` or UI visibility. Dashboard and analytics are the clearest examples (`staff/dashboard.php:4-6`, `api/v1/analytics.php:3-15`).

Overall verdict: the role workflow is implemented enough for most staff portal use cases, but the actual behavior diverges from the documented model in several important areas: `ADMIN` scope, `TENANT` wiring, customer API behavior, tenant ownership/assignment, and a few action-level backend guards.

## 2. Role Verification Table

| Role | Can log in? | Pages actually accessible | Actions actually allowed | Tenant/branch scoping | Backend enforcement | Does docs match? |
| --- | --- | --- | --- | --- | --- | --- |
| `SUPER_ADMIN` | Yes. Staff login explicitly allows it (`staff/login.php:25-31`). | Dashboard, loans, loan details, customers, payments, CI queue, manager queue, reports, staff, registration, tenant management, sales report, history, settings, release queue/voucher, receipts, download requirement. | Create/edit/delete staff, change staff roles/status, create tenants, activate/deactivate tenants, create/update/delete customers, CI review, approve/deny loans, update terms, assign loan officer, record/edit payments, print receipts, create/edit vouchers. | Global after login because `is_system_admin` is true (`includes/auth.php:215`, `includes/auth.php:227-297`). Still requires a valid tenant in session to log in (`includes/auth.php:136-145`, `staff/login.php:13-31`). No branch support. | Mostly yes through `require_permission()`. Some routes only use `require_login()` (`staff/dashboard.php:4`, `api/v1/analytics.php:3-4`). | Partial match. Broad access is real, but `SUPER_ADMIN` is not uniquely global; `ADMIN` is global too. |
| `ADMIN` | Yes (`staff/login.php:25-31`). | Same effective page access as `SUPER_ADMIN` because permission map and `is_system_admin` behavior treat them almost identically (`includes/auth.php:49-70`, `includes/auth.php:215`). | Same effective core actions as `SUPER_ADMIN`. | Global, not tenant-limited. This is the biggest mismatch (`includes/auth.php:215`, `includes/auth.php:294-306`). No branch support. | Mostly yes. Same caveats as `SUPER_ADMIN`. | No. Docs describe operational/admin access, but implementation makes `ADMIN` global across tenants. |
| `TENANT` | Yes. Allowed in staff login (`staff/login.php:28`). | Dashboard only from normal navigation. Direct URL access also exists for `download_requirement.php` and analytics endpoints (`staff/download_requirement.php:10`, `api/v1/analytics.php:3-15`). | Dashboard viewing, tenant-scoped counts/recent applications/staff ranking (`staff/dashboard.php:18-21`, `staff/dashboard.php:49-58`, `staff/dashboard.php:276-281`). Can download requirements by direct URL if requirement ID is known (`staff/download_requirement.php:3-10`). | Tenant-scoped because `TENANT` is not system admin (`includes/auth.php:215`, `includes/auth.php:294-306`). No branch support. | Mixed. `TENANT` is not in the permission map, but dashboard still opens because it only checks login (`staff/dashboard.php:4-6`). | Partial. “Dashboard only” is close from UI, but `TENANT` is only partially wired and still has some hidden direct URL/API access. |
| `MANAGER` | Yes (`staff/login.php:28`). | Dashboard, loans, loan details, customers, payments, CI queue, manager queue, reports, staff list, settings, release queue/voucher, receipts, download requirement. | CI review, approve/deny, update loan terms, assign loan officer, record/edit payments, print receipts, manage vouchers, view staff list. Can also update tenant branding/settings in `manager_settings.php` because that page uses `view_settings`, not a stricter write permission (`staff/manager_settings.php:4-30`). | Tenant-scoped (`includes/auth.php:294-306`). No branch support. | Mostly yes. Action handlers usually call `require_permission()`. Some intended UI restrictions are not repeated in POST guards. | Mostly yes, with extra power in settings. |
| `CREDIT_INVESTIGATOR` | Yes (`staff/login.php:28`). | Dashboard, loans, loan details, customers, CI queue, reports, download requirement. | View customer/loan details, inspect requirements, add CI remarks, mark `PENDING` loans as `CI_REVIEWED` (`staff/loan_view.php:60-68`). Cannot approve/deny. | Tenant-scoped. No branch support. | Good on the main pages. | Mostly yes. |
| `LOAN_OFFICER` | Yes (`staff/login.php:28`). | Dashboard, loans, loan details, customers, reports, release queue/voucher, download requirement. No payments page (`includes/auth.php:54`). | View loan details and payment history, prepare/edit vouchers, access release queue, monitor loans. Cannot directly record payments because `record_payments` excludes this role (`includes/auth.php:55`, `staff/payment_add.php:5`). | Tenant-scoped. No branch support. | Mostly yes. | Mostly yes. |
| `CASHIER` | Yes (`staff/login.php:28`). | Dashboard, payments, loan details, reports, release queue/voucher, receipts, download requirement. No loans page or customers page through normal route guards. | Record payments, edit payments, print receipts, access release vouchers. Payment edit uses OTP flow for cashier, but the OTP control has security weaknesses (`staff/payment_edit.php:9-93`, `includes/loan_helpers.php:252-288`, `includes/loan_helpers.php:443-456`). | Tenant-scoped. No branch support. | Mixed. Core page permissions exist, but some action-level constraints rely on UI. | Mostly yes, but voucher access is broader than described. |
| `CUSTOMER` | No for staff portal. Staff login excludes it (`staff/login.php:28`, `includes/AuthService.php:32-38`). | Intended API only. | API register/login/reset routes exist, but authenticated customer API workflow is not actually usable because `require_auth()` always fails token validation (`api/v1/config.php:120-165`). Registration also lacks tenant handling while schema/services require tenant context (`api/v1/auth.php:68-92`, `includes/CustomerService.php:18-27`, `schema.sql:32-40`). | Intended tenant-scoped, but not practically working end-to-end. No branch support. | No, not for the documented mobile flow. | No. The documented customer/mobile workflow does not match the actual implementation. |

## 3. Staff/Tenant Assignment Findings

### Who can add staff?

- Only `SUPER_ADMIN` and `ADMIN`.
- Evidence:
  - Permission map: `manage_staff` is only `SUPER_ADMIN` and `ADMIN` in [`includes/auth.php`](C:\xampp\htdocs\Loan_management_app\includes\auth.php) (`includes/auth.php:66`).
  - Staff registration page is protected by `require_permission('manage_staff')` in [`staff/registration.php`](C:\xampp\htdocs\Loan_management_app\staff\registration.php) (`staff/registration.php:5`).

### Who can edit staff?

- Only `SUPER_ADMIN` and `ADMIN`.
- Actual edit surfaces:
  - [`staff/registration.php`](C:\xampp\htdocs\Loan_management_app\staff\registration.php) can update and delete staff (`staff/registration.php:42-71`, `staff/registration.php:26-40`).
  - [`staff/staff_settings.php`](C:\xampp\htdocs\Loan_management_app\staff\staff_settings.php) can change role and activate/deactivate staff (`staff/staff_settings.php:5`, `staff/staff_settings.php:16-68`).
- `MANAGER` can only view staff via [`staff/staff.php`](C:\xampp\htdocs\Loan_management_app\staff\staff.php), not manage them (`staff/staff.php:4`).

### Who can assign staff to a tenant/branch?

- No role can explicitly assign a staff member to a tenant or branch in the current UI/workflow.
- Actual behavior:
  - Staff creation uses `require_current_tenant_id()` and inserts the new user with that session tenant ID (`staff/registration.php:17`, `staff/registration.php:104`).
  - There is no tenant dropdown in staff registration and no branch model in the code/schema.
- So the current system only supports implicit tenant assignment: staff are attached to the current session tenant of the user creating them.

### Which role can create tenant/branch?

- Tenant creation: `SUPER_ADMIN` and `ADMIN`.
- Branch creation: not implemented.
- Evidence:
  - Tenant creation happens in [`staff/registration.php`](C:\xampp\htdocs\Loan_management_app\staff\registration.php) and that page only requires `manage_staff` (`staff/registration.php:5`, `staff/registration.php:148-164`).
  - Tenant status management page requires `manage_tenants` (`staff/tenant_management.php:4`).
  - Repo-wide code search found no application `branch_id` implementation.

### Which role can activate/deactivate tenant/branch?

- Only `SUPER_ADMIN` and `ADMIN`.
- Evidence:
  - `manage_tenants` permission map (`includes/auth.php:68`).
  - Status update handler in [`staff/tenant_management.php`](C:\xampp\htdocs\Loan_management_app\staff\tenant_management.php) (`staff/tenant_management.php:27-35`).

### Is `ADMIN` global or tenant-limited?

- Global.
- Evidence:
  - `login_user()` sets `$_SESSION['is_system_admin']` for both `ADMIN` and `SUPER_ADMIN` (`includes/auth.php:203-215`).
  - `tenant_condition()` disables tenant filtering for any system admin (`includes/auth.php:294-306`).

### Is `SUPER_ADMIN` the only truly global role?

- No.
- `ADMIN` is also global in the actual implementation.

## 4. Multi-Tenant Enforcement Findings

### What exists

- The schema is consistently tenant-oriented:
  - `users`, `customers`, `loans`, `requirements`, `payments`, `activity_logs`, `money_release_vouchers`, `system_settings` all carry `tenant_id` (`schema.sql:32`, `schema.sql:50`, `schema.sql:71`, `schema.sql:108`, `schema.sql:128`, `schema.sql:157`, `schema.sql:179`, `schema.sql:224`).
- Shared helpers centralize tenant filtering:
  - `tenant_condition()`, `tenant_types()`, `tenant_params()`, `enforce_tenant_resource_access()` in [`includes/auth.php`](C:\xampp\htdocs\Loan_management_app\includes\auth.php) (`includes/auth.php:294-322`).
- Most staff pages use those helpers correctly:
  - customers, loans, loan view, payments, queues, reports, receipts, requirement download.

### What does not exist

- There is no branch model.
- There is no `branch_id` in schema or code.
- There is no tenant owner model.
- There is no explicit “assign this staff to tenant X” workflow.

### Can users from one tenant access another tenant’s data?

- Non-system-admin roles are mostly tenant-restricted in the staff portal.
- `SUPER_ADMIN` and `ADMIN` are intentionally not tenant-restricted in code.
- For tenant-restricted roles, most page-level resource fetches are guarded with `tenant_condition()` or `enforce_tenant_resource_access()`.

### Is staff creation tenant-scoped?

- Yes, but only implicitly.
- New staff are inserted with the creator’s current session tenant (`staff/registration.php:17`, `staff/registration.php:104`).
- There is no separate tenant assignment control.

### Are page queries always tenant-scoped?

- No.
- For `SUPER_ADMIN` and `ADMIN`, many queries intentionally become global because `tenant_condition()` resolves to `1=1`.
- Some routes are not permission-scoped at all:
  - [`staff/dashboard.php`](C:\xampp\htdocs\Loan_management_app\staff\dashboard.php) only requires login (`staff/dashboard.php:4-6`).
  - [`api/v1/analytics.php`](C:\xampp\htdocs\Loan_management_app\api\v1\analytics.php) only requires login; only `tenant_activity` gets an explicit admin-only check (`api/v1/analytics.php:3-15`, `api/v1/analytics.php:101-109`).

### Multi-tenant quality verdict

- The data model is genuinely multi-tenant.
- The staff portal is mostly tenant-aware for non-system-admin roles.
- But the overall workflow is not a clean tenant-admin ownership model because:
  - `ADMIN` is global,
  - tenant ownership is missing,
  - tenant assignment is implicit,
  - and branch support does not exist.

## 5. Workflow Mismatches

### Documented but missing or not fully implemented

- `CUSTOMER` mobile/API workflow is not actually complete.
  - `require_auth()` depends on `verify_auth_token()`, which is unimplemented and always returns `null` (`api/v1/config.php:120-165`).
  - Customer registration inserts into `users` without `tenant_id`, while schema requires `tenant_id INT NOT NULL` (`api/v1/auth.php:68-77`, `schema.sql:32-40`).
  - Customer creation also calls `CustomerService::createCustomer()` without tenant context, but that service requires a tenant (`api/v1/auth.php:84-92`, `includes/CustomerService.php:18-27`, `includes/CustomerService.php:61-90`).
- Branch/tenant-branch workflow is missing entirely.
- Tenant ownership and “admin owns tenant” workflow are missing.
- Explicit staff-to-tenant assignment UI is missing.

### Documented behavior that does not match implementation

- `ADMIN` is not tenant-limited; it is global like `SUPER_ADMIN` (`includes/auth.php:215`, `includes/auth.php:294-306`).
- `TENANT` is not part of the main permission matrix, but can still log in and access the dashboard because dashboard only checks login (`includes/auth.php:49-70`, `staff/login.php:28`, `staff/dashboard.php:4-6`).
- `TENANT` also has direct requirement download access if it knows the ID (`staff/download_requirement.php:10`), which is beyond “dashboard only”.
- Release queue is not restricted to approved/releasable loans:
  - [`staff/release_queue.php`](C:\xampp\htdocs\Loan_management_app\staff\release_queue.php) filters only by tenant, not by status (`staff/release_queue.php:8-29`).
  - The page text says “No approved loans ready for release,” but the query includes all loans.
- Release voucher page also lacks a loan status guard (`staff/release_voucher.php:4-24`, `staff/release_voucher.php:79-99`).
- Payment recording UI only shows the button for `ACTIVE`/`OVERDUE` loans, but [`staff/payment_add.php`](C:\xampp\htdocs\Loan_management_app\staff\payment_add.php) itself does not enforce that loan status on the backend (`staff/loan_view.php:474-475`, `staff/payment_add.php:4-85`).
- Loan term updates and officer assignment are limited in the UI, but the POST handlers do not repeat all of those status restrictions server-side (`staff/loan_view.php:116-170`, `staff/loans.php:8-35`, `staff/reports.php:10-31`).

### Existing features not documented

- Cashier payment edit OTP workflow exists (`staff/payment_edit.php:17-93`, `includes/loan_helpers.php:214-288`, `includes/loan_helpers.php:443-456`).
- Managers can edit tenant branding/settings, not just open settings (`staff/manager_settings.php:4-30`).

### Roles documented or present in schema but not fully wired

- `TENANT` exists in schema/session/login/dashboard, but is absent from the core permission map (`schema.sql:38`, `staff/login.php:28`, `staff/dashboard.php:276-281`, `includes/auth.php:49-70`).
- `CUSTOMER` exists in schema and API auth code, but the authenticated API flow is not wired through.
- `STAFF` appears in `view_reset_links.php` and `reports.php` (`SUM(CASE WHEN u.role='STAFF'...)`) even though `STAFF` is not a valid role in the schema (`staff/view_reset_links.php:4-8`, `staff/reports.php:148`, `schema.sql:38`).

### Pages visible but actions blocked / pages hidden but still reachable

- `staff/staff_settings.php` is not linked from the sidebar, but is reachable directly by URL for `SUPER_ADMIN`/`ADMIN`.
- Dashboard is visible to `TENANT` even though permission map would imply otherwise.
- Analytics endpoints are reachable by any logged-in role through direct URL, even if the dashboard UI does not expose them (`api/v1/analytics.php:3-15`).

## 6. Security/Permission Risks

- `ADMIN` is globally privileged across all tenants.
  - If that was not intended, this is the largest role-isolation risk (`includes/auth.php:215`, `includes/auth.php:294-306`).
- Dashboard and analytics are not protected by `require_permission()`.
  - Any logged-in role with a valid session can hit them, including `TENANT` (`staff/dashboard.php:4-6`, `api/v1/analytics.php:3-15`).
- Payment add/status restrictions rely on UI in part.
  - Direct URL POST to [`staff/payment_add.php`](C:\xampp\htdocs\Loan_management_app\staff\payment_add.php) is not limited to `ACTIVE`/`OVERDUE` by backend logic.
- Release queue and voucher logic are broader than intended.
  - Voucher management is available for any loan returned by the tenant filter, not just approved/released loans (`staff/release_queue.php:8-29`, `staff/release_voucher.php:4-24`).
- `release_queue.php` breaks tenant parameter handling when search is used.
  - It starts with tenant-aware `$types/$params`, then overwrites them with only the search params (`staff/release_queue.php:8-16`). For tenant-restricted roles this can break the prepared statement contract and the tenant filter.
- Cashier OTP edit flow has multiple weaknesses:
  - OTP email recipient is hardcoded to a single external email (`includes/loan_helpers.php:252-260`).
  - Gmail app password is hardcoded in source (`includes/loan_helpers.php:288`).
  - OTP verification query reads `expires_at` but never checks expiry before accepting the OTP (`includes/loan_helpers.php:443-456`).
- Staff forgot-password flow stores expiry but does not validate it on reset.
  - Reset checks token only, not `reset_token_expiry` (`staff/forgot_password.php:41`, `staff/forgot_password.php:102-114`).
- Customer API forgot-password returns the reset token in the API response, which is unsafe for production (`api/v1/auth.php:219-221`).
- `view_reset_links.php` checks for a nonexistent `STAFF` role and is effectively dead/unreachable (`staff/view_reset_links.php:4-8`).

## 7. Final Conclusion: Does the actual system match the documented workflow?

No, not fully.

It matches the documented workflow reasonably well for the core staff roles `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, and `CASHIER` on the main staff pages.

It does **not** match cleanly in these critical areas:

- `ADMIN` is globally privileged, not tenant-limited.
- `SUPER_ADMIN` is not the only global role.
- `TENANT` is only partially wired and bypasses the permission map on dashboard access.
- Tenant/branch assignment is not explicitly implemented; branch support does not exist.
- Customer/mobile/API workflow is not actually operational end-to-end.
- Some important action restrictions are enforced in UI but not fully repeated in backend handlers.

## Short Answer

Based on the current code, only `SUPER_ADMIN` and `ADMIN` can add a staff member.

No role can explicitly assign staff to a tenant or branch through a dedicated assignment workflow. Staff are implicitly attached to the **current session tenant** of the creator during registration (`staff/registration.php:17`, `staff/registration.php:104`). There is no branch implementation in the system.
