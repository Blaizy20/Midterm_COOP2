# 1. Executive Summary

Based on the PHP codebase, `SUPER_ADMIN` does have broad access to the admin-facing system, but the implementation does not fully match the expected SUPER_ADMIN module definition.

The strongest match is tenant creation and tenant ownership management. The weakest areas are dashboard analytics reliability, tenant status/workflow depth, audit-log completeness, backup, and system-level settings. Several expected items are only partial, and some are missing entirely.

Important high-level conclusions:

- `SUPER_ADMIN` can access `dashboard`, `tenant_management`, `reports`, `sales_report`, `history`, and `manager_settings`, but not all of these are `SUPER_ADMIN`-only.
- `Tenant Management` is the main feature area that is truly `SUPER_ADMIN`-specific in the permission map.
- The dashboard has chart code, but the current JavaScript is likely broken because it fetches the wrong relative API path and writes to a missing DOM element.
- Audit logging exists, but login/logout history and tenant-change history are not actually written into `activity_logs` by the inspected code.
- Backup functionality is missing in code.
- Settings are tenant-level branding settings, not developer-level global system settings.

# 2. SUPER_ADMIN Feature Verification Table

| Feature Area      | Expected from Images                                                                                                            | Actual in Code                                                                                                                                                                                                                                                                                                                                               | Status (Implemented / Partial / Missing) | Evidence (file names / functions)                                                                                                                                              |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Dashboard         | System overview with tenants, active/inactive users, daily/monthly activity, user growth, tenant activity, sales trends, charts | Dashboard page exists and shows cards for total tenants and active/inactive users for `SUPER_ADMIN`. Multiple chart canvases exist, but chart JS likely fails because it uses `fetch('api/v1/analytics.php?...')` from `/staff/dashboard.php` and references missing `#debug-output`. No tenant activity chart is rendered on the dashboard.                 | Partial                                  | `staff/dashboard.php`, `api/v1/analytics.php`, `staff/_layout_top.php`, `includes/auth.php`                                                                                    |
| Tenant Management | Full tenant list, profile, owner, status, plan, active/pending/suspended, approve/reject/deactivate                             | `SUPER_ADMIN` can list tenants, view details, assign owner admin, activate/deactivate. Status is only boolean `Active/Inactive`. No `plan`, no `pending`, no `suspended`, no `approve`, no `reject`.                                                                                                                                                         | Partial                                  | `staff/tenant_management.php`, `staff/registration.php`, `schema.sql`, `includes/auth.php`                                                                                     |
| Reports           | Tenant activity, user registration, usage stats, filters by date and tenant                                                     | `SUPER_ADMIN` can access loan transactions plus advanced reports: `tenant_activity`, `user_registrations`, `usage_statistics`. Date filters exist. Tenant filter exists only for tenant activity.                                                                                                                                                            | Partial                                  | `staff/reports.php`, `includes/auth.php`                                                                                                                                       |
| Sales Report      | Revenue totals, per-tenant sales, daily/weekly/monthly sales, top performers, transaction history                               | `SUPER_ADMIN` can access sales report. Total sales, per-tenant sales, daily sales, monthly sales, top tenants, and transaction history exist. Weekly sales query exists but is not rendered. No tenant filter UI. No top-performing services dimension.                                                                                                      | Partial                                  | `staff/sales_report.php`, `includes/auth.php`                                                                                                                                  |
| Audit Logs        | Login/logout, admin actions, tenant changes, timestamps                                                                         | `activity_logs` table exists and history page exists. Admin/staff/customer/loan/payment actions are logged in places. Login/logout are not logged by inspected staff auth flow. Tenant create/activate/deactivate actions are not logged even though history UI expects them.                                                                                | Partial                                  | `schema.sql`, `includes/loan_helpers.php::log_activity()`, `staff/history.php`, `staff/login.php`, `staff/logout.php`, `staff/registration.php`, `staff/tenant_management.php` |
| Backup            | Backup history, status, stored backups, restore points                                                                          | No backup page, no backup table, no backup script, no restore-point tracking in inspected code. Only documentation mentions backup operationally.                                                                                                                                                                                                            | Missing                                  | `staff/` file list, `schema.sql`, `rg` search results in repo                                                                                                                  |
| Settings          | System branding, tenant limits/rules, roles/permissions                                                                         | Actual settings page is `staff/manager_settings.php`, not `staff/settings.php`. It supports tenant-level `system_name`, `primary_color`, and `logo`. No tenant limits/rules storage. No role/permission editor in this page. Separate `staff/staff_settings.php` can manage staff roles/status, but it is not the settings module and is not sidebar-linked. | Partial                                  | `staff/manager_settings.php`, `staff/staff_settings.php`, `schema.sql`, `includes/auth.php`, `staff/_layout_top.php`                                                           |

# 3. Detailed Findings by Module

## Dashboard

- Page exists at `staff/dashboard.php`.
- Sidebar route exists and is always shown as `Dashboard` in `staff/_layout_top.php`.
- Backend page guard is weak: `staff/dashboard.php` calls `require_login()` but does not call `require_permission('view_dashboard')`.
- Permission map does define `view_dashboard`, but the page does not enforce it.
- For `SUPER_ADMIN`, the dashboard renders cards for:
  - total tenants
  - total users
  - active users
  - inactive users
  - total customers
  - total loans
  - active loans
  - overdue loans
  - portfolio value
  - pending approvals
- Chart placeholders exist for:
  - user growth
  - loan status distribution
  - payment trends
  - staff by role
  - daily activity
  - loan applications monthly
- There is no dashboard chart for tenant activity even though `api/v1/analytics.php` has a `tenant_activity` endpoint.
- There is no explicit sales-trends chart labeled as sales; the closest existing item is `Payment Trends (Monthly)`.
- Chart implementation appears broken in code:
  - `staff/dashboard.php` fetches `api/v1/analytics.php?...` as a relative path from `/staff/dashboard.php`, which points to `/staff/api/v1/analytics.php` instead of the actual `/api/v1/analytics.php`.
  - The script references `document.getElementById('debug-output')`, but no `debug-output` element exists in `staff/dashboard.php`.
- Because of those two issues, the visual analytics should be treated as partial, not fully implemented.

## Tenant Management

- Dedicated tenant management page exists at `staff/tenant_management.php`.
- Access is truly `SUPER_ADMIN`-only via `require_permission('manage_tenants')`, and `manage_tenants` is only mapped to `['SUPER_ADMIN']` in `includes/auth.php`.
- Sidebar menu link exists only when `can_access('manage_tenants')` is true.
- Tenant list includes:
  - tenant display name
  - subdomain
  - owner name
  - status
  - created date
- Tenant detail view includes:
  - tenant name
  - subdomain
  - status
  - counts for users/customers/loans/payments
  - owner assignment UI
- Real status model is only `is_active` boolean shown as `Active` or `Inactive`.
- The schema has no tenant `plan`, `subscription`, `pending`, or `suspended` fields.
- Supported actions in code are:
  - assign owner admin
  - activate
  - deactivate
- Missing tenant-management actions:
  - approve
  - reject
  - suspend
- Tenant creation is implemented separately inside `staff/registration.php` under the super-admin-only tenant tab.
- Tenant creation inserts into `tenants` with `is_active = 1` immediately; there is no approval workflow.

## Reports

- Reports page exists at `staff/reports.php`.
- Route is protected by `require_permission('view_reports')`.
- Sidebar link exists when `can_access('view_reports')` is true.
- `SUPER_ADMIN` and `ADMIN` can use advanced report types through the shared `view_advanced_reports` permission.
- Advanced report types implemented:
  - `tenant_activity`
  - `user_registrations`
  - `usage_statistics`
- Filters actually implemented:
  - Loan transactions: tenant, status, payment method, loan officer, from date, to date
  - Tenant activity: tenant, from date, to date
  - User registrations: tenant, from date, to date
  - Usage statistics: tenant, from date, to date
- The advanced report UI/backend mismatch has been removed:
  - `view_advanced_reports` is now limited to `SUPER_ADMIN` and `ADMIN`
  - `MANAGER` no longer sees advanced report options
  - `staff/reports.php` now enforces advanced report access by permission instead of forcing everything through `is_system_admin()`
- Report summary cards now appear above the table for quick totals by report type.
- Report export/download options now include print, PDF, and CSV.

## Sales Report

- Sales report page exists at `staff/sales_report.php`.
- Route is protected by both:
  - `require_permission('view_sales')`
  - `require_roles(['SUPER_ADMIN', 'ADMIN'])`
- Sidebar link exists when `can_access('view_sales')` is true.
- Implemented sales-report outputs:
  - total sales / revenue
  - total transactions
  - top performing tenant
  - average transaction value
  - sales per tenant
  - daily sales
  - monthly sales
  - top 5 performing tenants
  - transaction history summary
- Partial or missing items:
  - weekly sales query exists in code, but no weekly sales section is rendered in the HTML
  - no top-performing services metric exists
  - `tenant_id_filter` variable is declared, tenants are queried for a dropdown, but there is no tenant filter UI and no tenant filter condition is applied from user input
- For `SUPER_ADMIN`, the report is system-wide because `is_system_admin()` skips tenant scoping.
- For `ADMIN`, the report is tenant-scoped because the query adds `p.tenant_id = require_current_tenant_id()`.

## Audit Logs

- Audit-log page exists at `staff/history.php`.
- Route is protected by `require_permission('view_history')`.
- Sidebar link exists when `can_access('view_history')` is true.
- `activity_logs` table exists in `schema.sql` with:
  - tenant_id
  - user_id
  - user_role
  - action
  - description
  - loan_id
  - customer_id
  - reference_no
  - created_at timestamp
- `includes/loan_helpers.php::log_activity()` inserts into `activity_logs`.
- Logged actions confirmed from code include examples such as:
  - `STAFF_CREATED`
  - `STAFF_UPDATED`
  - `STAFF_DELETED`
  - customer register/update/activate/deactivate/delete
  - payment recorded/updated
  - loan approved/denied
  - interest/payment-term updates
  - loan officer assignment
- Login/logout history is not actually implemented for staff auth:
  - `staff/login.php` performs login but does not call `log_activity('USER_LOGIN', ...)`
  - `includes/auth.php::logout_user()` only destroys the session
  - `staff/logout.php` only calls `logout_user()` and redirects
- Tenant-related audit history is not actually implemented:
  - `staff/history.php` expects `TENANT_CREATED`, `TENANT_UPDATED`, `TENANT_ACTIVATED`, `TENANT_DEACTIVATED`
  - repo search shows those action strings only in `staff/history.php`
  - `staff/registration.php` tenant creation flow does not call `log_activity()`
  - `staff/tenant_management.php` activate/deactivate flows do not call `log_activity()`
- Additional system-level limitation:
  - `log_activity()` returns early if there is no tenant in session
  - `SUPER_ADMIN` can operate without an active tenant context
  - therefore global developer-admin actions are not modeled cleanly in `activity_logs`

## Backup

- No backup page exists under `staff/`.
- No backup table exists in `schema.sql`.
- No backup script or restore-point tracking was found in the inspected PHP files.
- Repo search only surfaced backup mentions in docs/checklists, not in implemented application code.
- Result: backup is missing in code.

## Settings

- There is no `staff/settings.php` route in the codebase.
- The actual sidebar-linked settings route is `staff/manager_settings.php`.
- Access is not `SUPER_ADMIN`-only:
  - permission map grants `view_settings` to `SUPER_ADMIN`, `ADMIN`, and `MANAGER`
  - sidebar shows settings when `can_access('view_settings')` is true and either the user is not `SUPER_ADMIN` or a tenant is selected
- `manager_settings.php` requires `require_current_tenant_id()`, so settings are tenant-context settings, not platform-global settings.
- Implemented settings fields:
  - system name
  - primary color
  - logo upload
- These settings are stored per tenant in `system_settings(tenant_id, system_name, logo_path, primary_color)`.
- Missing settings capabilities:
  - tenant limits/rules storage and editing
  - global developer-admin system configuration
  - role/permission matrix editing within the settings page
- Partial related functionality exists in `staff/staff_settings.php`:
  - role changes
  - account activate/deactivate
  - role description UI
- But `staff/staff_settings.php` is a separate module protected by `manage_staff`, not the main settings page, and it is not linked in the sidebar.

# 4. Permission and Access Findings

## What SUPER_ADMIN can access

- `Dashboard`
  - Page exists: yes
  - Route exists: yes, `staff/dashboard.php`
  - Menu link exists: yes
  - Backend permission exists: permission key exists, but page does not enforce it
- `Tenant Management`
  - Page exists: yes
  - Route exists: yes, `staff/tenant_management.php`
  - Menu link exists: yes
  - Backend permission exists: yes, correctly enforced via `manage_tenants`
- `Reports`
  - Page exists: yes
  - Route exists: yes, `staff/reports.php`
  - Menu link exists: yes
  - Backend permission exists: yes, `view_reports`
- `Sales Report`
  - Page exists: yes
  - Route exists: yes, `staff/sales_report.php`
  - Menu link exists: yes
  - Backend permission exists: yes, `view_sales` plus explicit `require_roles(['SUPER_ADMIN','ADMIN'])`
- `Audit Logs / History`
  - Page exists: yes
  - Route exists: yes, `staff/history.php`
  - Menu link exists: yes
  - Backend permission exists: yes, `view_history`
- `Backup`
  - Page exists: no
  - Route exists: no confirmed implemented route
  - Menu link exists: no
  - Backend permission exists: no confirmed permission key or handler
- `Settings`
  - Page exists: yes, but actual route is `staff/manager_settings.php`
  - Route exists: yes
  - Menu link exists: yes, but only when a tenant context exists for `SUPER_ADMIN`
  - Backend permission exists: yes, `view_settings`

## What ADMIN can also access

- `ADMIN` also has `view_sales`, `view_history`, `view_settings`, `manage_staff`, `view_reports`, and most operational permissions in `includes/auth.php`.
- `ADMIN` can access:
  - dashboard
  - reports
  - sales report
  - history
  - settings
  - staff registration/manage staff
- `ADMIN` cannot access `manage_tenants` because that permission is `SUPER_ADMIN`-only.
- `ADMIN` can access the registration page, but tenant creation UI/actions inside `staff/registration.php` are wrapped in `$is_super_admin` checks.

## What is not uniquely SUPER_ADMIN

- Dashboard is not uniquely `SUPER_ADMIN`.
- Reports are not uniquely `SUPER_ADMIN`.
- Sales report is not uniquely `SUPER_ADMIN`; `ADMIN` has it too.
- Audit log/history is not uniquely `SUPER_ADMIN`; `ADMIN` has it too.
- Settings are not uniquely `SUPER_ADMIN`; `ADMIN` and `MANAGER` have them too.
- The uniquely `SUPER_ADMIN` areas confirmed in this audit are:
  - tenant management page access
  - tenant creation and tenant-owner assignment flows
  - global tenant context switching across all active tenants

## Any direct URL access risks

- `staff/dashboard.php` is a direct URL access risk relative to the permission map:
  - page uses `require_login()` only
  - no `require_permission('view_dashboard')`
  - sidebar always shows the dashboard link
- `api/v1/analytics.php` is also weakly protected:
  - it uses `require_login()` only
  - no permission-specific enforcement
  - logged-in roles outside the intended dashboard permission set can still hit analytics endpoints
- `staff/reports.php` advanced reports are now aligned with the permission map:
  - `SUPER_ADMIN` and `ADMIN` can open them
  - `MANAGER` no longer sees or reaches those options through direct URL access
- `staff/staff_settings.php` is reachable by direct URL for `SUPER_ADMIN` and `ADMIN`, even though it is not present in the sidebar.

# 5. Missing or Partial Features

- Dashboard tenant-activity chart is missing from the rendered dashboard.
- Dashboard chart implementation is partial and likely broken due to wrong analytics fetch path and missing `debug-output` element.
- Dashboard sales-trend feature is only partial through payment trends; no dedicated sales analytics panel is implemented.
- Tenant status model is incomplete:
  - no `pending`
  - no `suspended`
  - only `Active/Inactive`
- Tenant plan/subscription data is missing in schema and UI.
- Tenant approve/reject workflow is missing.
- Reports tenant filtering is partial:
  - tenant filter exists only for tenant-activity reports
  - not for loan transactions, user registrations, or usage statistics
- Sales report tenant filtering is incomplete:
  - tenant list is queried
  - `tenant_id_filter` exists
  - but there is no tenant filter control rendered and no filter application from user input
- Sales report weekly sales is partial:
  - query exists
  - UI section does not
- Top-performing services is missing.
- Login history is missing from actual logging.
- Logout history is missing from actual logging.
- Tenant change audit history is missing from actual logging.
- Backup module is missing entirely in code.
- System settings are tenant-level branding only, not developer-admin global settings.
- Tenant limits/rules settings are missing.
- Role/permission management is not part of the main settings module.

# 6. Final Conclusion

No. `SUPER_ADMIN` does not currently have the full feature set shown in the image-based module definition.

What is real in code:

- `SUPER_ADMIN` can access the core admin pages.
- `SUPER_ADMIN` uniquely manages tenants and can create tenants.
- `SUPER_ADMIN` has broad system visibility.

What is not fully real in code:

- Dashboard analytics are only partially implemented and likely not fully working.
- Tenant management does not support the richer status/workflow/plan features described.
- Audit logs do not truly cover login/logout and tenant lifecycle changes.
- Backup is not implemented.
- Settings are tenant-level branding settings, not full system-admin configuration.

# 7. Plain-English Summary

The super admin in this codebase is powerful, but the system does not fully match the richer admin module shown in the images.

The code does support the main admin pages like dashboard, tenant management, reports, sales report, history, and settings. But some of those are incomplete. The dashboard has chart code, but it looks broken. Tenant management only supports simple active/inactive control and owner assignment, not approval workflows or subscription plans. The audit log page exists, but the system is not actually recording important events like login, logout, and tenant lifecycle actions. There is also no real backup module in the code.

The settings page is also not a true developer-admin settings module. It only changes a tenant's branding, like the name, logo, and color. It does not manage global platform rules, tenant limits, or the full permission system.

# 8. TODO List To Fully Reach The Expected SUPER_ADMIN Feature Set

## Priority 1: Fix access control and broken admin analytics

- [✅ ] Enforce `view_dashboard` in `staff/dashboard.php` using `require_permission('view_dashboard')`, not just `require_login()`.
- [✅] Enforce permission checks in `api/v1/analytics.php` instead of relying only on `require_login()`.
- [ ✅ ] Fix dashboard chart API URLs in `staff/dashboard.php` to use the correct app-relative path, such as `APP_BASE . '/api/v1/analytics.php?...'`.
- [ ✅ ] Remove or implement the missing `debug-output` element referenced by the dashboard JavaScript.
- [ ✅ ] Verify all dashboard charts actually render for `SUPER_ADMIN` after the path fix.
- [ ✅ ] Add a real tenant-activity chart to the `SUPER_ADMIN` dashboard using the existing `tenant_activity` analytics endpoint or a corrected replacement.
- [ ✅ ] Add a real sales-trend chart/panel on the dashboard instead of relying only on generic payment trends.

## Priority 2: Complete dashboard analytics to match the expected module

- [ ✅ ] Keep current cards for total tenants, active users, and inactive users.
- [ ✅ ] Add explicit daily activity summaries on the page, not only chart placeholders.
- [ ✅ ] Add explicit monthly activity summaries on the page.
- [ ✅ ] Add a user-growth chart that is confirmed working end-to-end.
- [ ✅ ] Add a tenant-activity visualization that shows per-tenant usage/activity.
- [ ✅ ] Add a sales-trends visualization for daily/weekly/monthly revenue.
- [ ✅ ] Add tests or manual verification steps proving the dashboard works for `SUPER_ADMIN`.

## Priority 3: Expand tenant management to match the expected workflow

- [ ✅ ] Extend the `tenants` table in `loan_management_complete.sql` to support a richer status model instead of only `is_active`.
- [ ✅ ] Add a tenant status field with exact supported values, for example `PENDING`, `ACTIVE`, `SUSPENDED`, `REJECTED`, if those are the intended business states.
- [ ✅ ] Add a tenant plan/subscription field to store the tenant plan.
- [ ✅ ] Add owner information more explicitly in the tenant detail/profile view.
- [ ✅ ] Add backend actions for `approve`, `reject`, and `suspend` tenants.
- [ ✅ ] Add matching UI controls for `approve`, `reject`, `deactivate`, and `suspend` in tenant management.
- [ ✅ ] Ensure tenant creation in `staff/registration.php` does not always force `is_active = 1` if approval is required.
- [ ✅ ] Log all tenant lifecycle actions into `activity_logs`.
- [ ✅ ] Decide the real production status vocabulary and keep UI labels, database values, and audit actions consistent.

## Priority 4: Complete reports for SUPER_ADMIN

- [x] Keep `tenant_activity`, `user_registrations`, and `usage_statistics` reports.
- [x] Add tenant filtering to all report types where expected, not only `tenant_activity`.
- [x] Add date filtering coverage consistently to every advanced report.
- [x] Decide whether `ADMIN` and `MANAGER` should really see advanced report options.
- [ ] If advanced reports are truly `SUPER_ADMIN`-only, remove those options from the UI for non-system-admin users.
- [x] If advanced reports should be shared, remove the `is_system_admin()` restriction in `staff/reports.php` and enforce by permission only.
- [x] Add report summaries/cards at the top for quick totals by report type.
- [x] Add export/download options if they are part of the target module definition.

## Priority 5: Finish the sales report module

- [ x ] Add a tenant filter UI to `staff/sales_report.php` and actually apply `tenant_id_filter` in SQL.
- [x ] Render the existing weekly sales query in the page UI.
- [ x ] Add a weekly sales section beside daily and monthly sales.
- [ x ] Decide whether "top-performing tenants/services" requires service-level analytics.
- [ x ] If service-level analytics is required, add the missing service/product data model first.
- [ x ] Add charts for sales trends if the expected design includes visual analytics.
- [ x ] Keep transaction history summary, but consider adding pagination or stronger filters.
- [x] Keep `Sales Report` available to both `SUPER_ADMIN` and `ADMIN`.

## Priority 6: Make audit logs complete and reliable

- [x] Add `USER_LOGIN` logging in the staff login flow in `staff/login.php`.
- [x] Add `USER_LOGOUT` logging before session destruction in `logout_user()` or `staff/logout.php`.
- [x] Add tenant lifecycle logging:
- [x] `TENANT_CREATED`
- [x] `TENANT_UPDATED`
- [x] `TENANT_ACTIVATED`
- [x] `TENANT_DEACTIVATED`
- [x] `TENANT_REJECTED` and `TENANT_SUSPENDED` if those statuses are added
- [ ] Add system-admin-safe logging support for actions taken without a tenant context.
- [x] Keep `activity_logs.tenant_id` as-is and non-`NULL`; auth and tenant lifecycle logs remain tenant-scoped.
- [ ] Ensure all create/update/delete admin actions are consistently logged.
- [ ] Review all privileged POST handlers and add missing `log_activity()` calls.
- [ ] Keep timestamps in audit logs and verify sorting/filtering works correctly.

## Priority 7: Build the backup module

- [ ] Add a dedicated backup page for `SUPER_ADMIN`.
- [ ] Add a database table for backup history and status.
- [ ] Track:
- [ ] backup filename
- [ ] backup type
- [ ] created_at
- [ ] created_by
- [ ] status (`SUCCESS`, `FAILED`, etc.)
- [ ] storage path
- [ ] Add a PHP backup script or command integration for database backups.
- [ ] Add stored-backup listing in the UI.
- [ ] Add restore-point metadata if restore points are required.
- [ ] Add restore functionality only if the project really wants in-app restore support.
- [ ] Restrict the module to `SUPER_ADMIN`.
- [ ] Log backup and restore actions in `activity_logs`.

## Priority 8: Upgrade settings from tenant branding to true system admin settings

- [ ] Decide whether `SUPER_ADMIN` needs a separate global settings page in addition to tenant settings.
- [ ] Create a real `staff/settings.php` or equivalent system-admin settings route if that is the intended module.
- [ ] Split settings into:
- [ ] global platform settings for `SUPER_ADMIN`
- [ ] tenant branding/settings for tenant-scoped admins/managers
- [ ] Add global system name/branding controls if the platform has one true root identity.
- [ ] Add tenant limits/rules configuration storage in the database.
- [ ] Add settings for user-role rules and permission policy if that is part of the expected feature set.
- [ ] Decide whether permissions remain hardcoded in `includes/auth.php` or move to configurable storage.
- [ ] If permissions should be configurable, design a safe permission model before building a UI editor.
- [ ] Keep `manager_settings.php` only for tenant branding if that separation is desired.

## Priority 9: Clean up menu and route consistency

- [ ] Ensure every sidebar link has matching backend enforcement with `require_permission()` or stricter guards.
- [ ] Ensure every privileged route is protected even when accessed directly by URL.
- [ ] Add menu entries only for pages that are truly usable by that role.
- [ ] Remove or hide advanced report options that backend later rejects.
- [ ] Decide whether `staff/staff_settings.php` should be linked in the sidebar or merged into another module.

## Priority 10: Data model and schema work needed

- [ ] Extend `schema.sql` for tenant workflow/status and plan fields.
- [ ] Add backup-related tables if backup UI/history is required.
- [ ] Add any new analytics support tables only if computed reporting becomes too expensive from live transactional tables.
- [ ] Review whether `activity_logs` should support global records with nullable `tenant_id`.
- [ ] Add indexes needed for new reporting, logging, and backup queries.

## Priority 11: Verification and testing checklist after implementation

- [ ] Verify `SUPER_ADMIN` can access:
- [ ] dashboard
- [ ] tenant management
- [ ] reports
- [ ] sales report
- [ ] history/audit logs
- [ ] backup
- [ ] settings
- [ ] Verify chart data loads successfully from the real analytics API routes.
- [ ] Verify tenant workflow actions update both UI and database correctly.
- [ ] Verify all sensitive pages reject unauthorized direct URL access.
- [ ] Verify login/logout actions appear in audit logs.
- [ ] Verify tenant create/approve/reject/deactivate actions appear in audit logs.
- [ ] Verify backup records are written and visible in UI.
- [ ] Verify settings separation between global and tenant-level behavior.

## Suggested implementation order

- [ ✅ ] Step 1: Fix dashboard and analytics routing/permissions.
- [ ✅ ] Step 2: Complete tenant status model and tenant actions.
- [ ✅ ] Step 3: Complete audit logging for login/logout and tenant actions.
- [ ✅ ] Step 4: Finish reports and sales filtering/UI gaps.
- [ ] Step 5: Build backup module.
- [ ] Step 6: Split global settings from tenant settings.
- [ ] Step 7: Run a full permission and URL-access audit again.
