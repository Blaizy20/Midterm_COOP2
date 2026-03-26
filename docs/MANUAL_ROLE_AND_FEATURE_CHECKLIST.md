# Manual Role And Feature Checklist

Use this checklist to manually verify the system by role, feature, and account-creation flow.

This document is based on the current code behavior in the system.

## 1. Purpose

Use this checklist to answer:

- What each role can do
- What each role cannot do
- Who is allowed to create that role
- How to create the account manually
- How to test the account manually

## 2. Basic Test Setup

Before testing, prepare these accounts:

✅- 1 `SUPER_ADMIN`
✅- 1 `ADMIN`

- 1 `MANAGER`
- 1 `CREDIT_INVESTIGATOR`
- 1 `LOAN_OFFICER`
- 1 `CASHIER`
- 1 `CUSTOMER`

Recommended tenant setup:

✅- Tenant A
✅- Tenant B

Recommended test data:

- At least 1 customer in each tenant
- At least 1 loan in each tenant
- At least 1 payment in each tenant

## 3. Quick Role Matrix

| Role                  | Who Creates It                          | Where To Create                   | Main Access                                                                | Must Not Do                                       |
| --------------------- | --------------------------------------- | --------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------- |
| `SUPER_ADMIN`         | Seed/manual DB setup                    | SQL seed or DB                    | Full system, tenants, owners, staff                                        | Must not be tenant-limited                        |
| `ADMIN`               | `SUPER_ADMIN`                           | `/staff/registration.php`         | Owned tenant(s) only, staff, customers, loans, payments, reports, settings | Must not create tenants or access unowned tenants |
| `MANAGER`             | `SUPER_ADMIN` or owned-tenant `ADMIN`   | `/staff/registration.php`         | Manager approval, reports, dashboard, staff view, settings                 | Must not create tenants or switch owner tenants   |
| `CREDIT_INVESTIGATOR` | `SUPER_ADMIN` or owned-tenant `ADMIN`   | `/staff/registration.php`         | CI queue, dashboard, reports, loans, customers                             | Must not create tenants or manage staff globally  |
| `LOAN_OFFICER`        | `SUPER_ADMIN` or owned-tenant `ADMIN`   | `/staff/registration.php`         | Loans, customers, reports, release-related pages                           | Must not create tenants or access other tenants   |
| `CASHIER`             | `SUPER_ADMIN` or owned-tenant `ADMIN`   | `/staff/registration.php`         | Payments, receipts, dashboard, reports                                     | Must not create tenants or access other tenants   |
| `CUSTOMER`            | Customer self-registration/API flow     | `api/v1/auth.php?action=register` | Own customer/mobile data only                                              | Must not access staff portal                      |
| `TENANT`              | No normal owner creation flow confirmed | Legacy/manual only if still used  | Legacy only                                                                | Must not be used as owner login                   |

## 4. Core Manual Checks

Run these first before role-by-role testing.

### 4.1 Check Login Page

Goal:

- ✅ Confirm staff login page works

How to test:

1. Open `http://localhost/LOAN_MANAGEMENT_APP/staff/login.php`
2. Confirm the page loads
3. Confirm there are no PHP warnings or fatal errors

Pass:

- ✅ Page loads normally

Fail:

- ❌ Login page broken

### 4.2 Check Tenant Selection Flow

Goal:

- ✅ Confirm tenant owners can switch among owned tenants only

How to test:

1. Login as `SUPER_ADMIN`
2. Open `/staff/select_tenant.php`
3. Confirm active tenants are listed
4. Login as `ADMIN`
5. If the admin owns one tenant, confirm auto-redirect to dashboard
6. If the admin owns multiple tenants, confirm the tenant selection page shows cards/buttons

Pass:

- ✅ `SUPER_ADMIN` sees all active tenants
- ✅ `ADMIN` sees owned tenants only

Fail:

- ❌ `ADMIN` sees unowned tenants

## 5. SUPER_ADMIN Checklist

### Who Creates This Account

- Usually system seed or direct database setup

### What SUPER_ADMIN Can Do

- ✅ Access all tenants
- ✅ Open tenant selection
- ✅ Create/register tenants
- ✅ Assign `ADMIN` owners to tenants
- ✅ Create `ADMIN`, `MANAGER`, `CREDIT_INVESTIGATOR`, `LOAN_OFFICER`, and `CASHIER`
- ✅ View tenant management
- ✅ View staff
- ✅ View reports and sales
- ✅ Access settings

### What SUPER_ADMIN Cannot Do

- ❌ Should not be treated as a tenant-only user

### Manual Creation Reference

If a `SUPER_ADMIN` does not exist:

- Create it through SQL seed or direct DB insert

### Manual Test Steps

#### Check if SUPER_ADMIN can create tenant

1. Login as `SUPER_ADMIN`
2. Go to `/staff/registration.php`
3. Open the tenant registration section
4. Input:
   - Tenant Name: `Manual Test Tenant`
   - Subdomain: `manual-test-tenant`
5. Select one or more `ADMIN` owner accounts if available
6. Click the tenant create/register button

Expected:

- ✅ Tenant is created
- ✅ Tenant appears in `/staff/tenant_management.php`
- ✅ Owners are assigned if selected

#### Check if SUPER_ADMIN can create ADMIN account

1. Login as `SUPER_ADMIN`
2. Go to `/staff/registration.php`
3. In the staff form, choose role `ADMIN`
4. Input:
   - Full Name
   - Username
   - Email
   - Password
5. Submit the form

Expected:

- ✅ `ADMIN` account is created
- ✅ Success message appears
- ✅ New `ADMIN` can later be assigned to tenant ownership

#### Check if SUPER_ADMIN can manage tenant owners

1. Login as `SUPER_ADMIN`
2. Go to `/staff/tenant_management.php`
3. Click `View` on a tenant
4. In `Assigned Owner Admins`, check one or more `ADMIN` accounts
5. Click `Save Owners`

Expected:

- ✅ Owners are saved
- ✅ Only active `ADMIN` accounts can be assigned

#### Check if SUPER_ADMIN can see all tenants

1. Login as `SUPER_ADMIN`
2. Go to `/staff/tenant_management.php`
3. Review the tenant list

Expected:

- ✅ All tenants are visible

## 6. ADMIN Checklist

### Who Creates This Account

- `SUPER_ADMIN`

### Where To Create

- `/staff/registration.php`

### What ADMIN Can Do

- ✅ Access dashboard
- ✅ Access owned tenant(s) only
- ✅ Switch only among owned tenants
- ✅ View and manage customers
- ✅ View and manage loans within selected tenant
- ✅ View and manage payments within selected tenant
- ✅ View reports within selected tenant
- ✅ View sales report
- ✅ Manage staff in selected tenant
- ✅ Access settings in selected tenant

### What ADMIN Cannot Do

- ❌ Cannot create tenants
- ❌ Cannot manage all tenants globally
- ❌ Cannot access tenants they do not own
- ❌ Cannot use unowned tenant IDs by URL tampering

### How To Create ADMIN Manually

1. Login as `SUPER_ADMIN`
2. Go to `/staff/registration.php`
3. In the staff registration section, choose role `ADMIN`
4. Input:
   - Full Name
   - Username
   - Email
   - Password
   - Confirm Password
5. Submit
6. Go to `/staff/tenant_management.php`
7. Open a tenant
8. In `Assigned Owner Admins`, check the new admin
9. Click `Save Owners`

Expected:

- ✅ `ADMIN` account exists
- ✅ `ADMIN` is assigned as tenant owner

### Manual Test Steps

#### Check if ADMIN cannot create tenant

1. Login as `ADMIN`
2. Go to `/staff/registration.php`
3. Check if tenant registration UI is visible
4. Try to open `/staff/tenant_management.php`

Expected:

- ❌ Tenant registration must not be available
- ❌ Global tenant management must not be available

#### Check if ADMIN can create tenant-scoped staff

1. Login as `ADMIN`
2. Go to `/staff/registration.php`
3. Create one `MANAGER` or `CASHIER`
4. Fill in the form and submit

Expected:

- ✅ Staff account is created for current active tenant
- ❌ Admin must not be able to create another `ADMIN` from a tenant-scoped flow unless the current code explicitly still allows only super admin for `ADMIN`

#### Check if ADMIN sees only owned tenant data

1. Login as `ADMIN`
2. If multiple tenants are owned, choose Tenant A
3. Open:
   - `/staff/dashboard.php`
   - `/staff/customers.php`
   - `/staff/loans.php`
   - `/staff/payments.php`
   - `/staff/reports.php`
4. Confirm the data belongs only to Tenant A
5. Switch to Tenant B
6. Repeat

Expected:

- ✅ Only selected owned tenant data is shown
- ❌ Unowned tenant data is never shown

#### Check if ADMIN cannot access unowned tenant by URL tampering

1. Login as `ADMIN`
2. Open a known record in current tenant
3. Replace the record ID with another tenant's ID
4. Repeat on:
   - `/staff/loan_view.php?id=...`
   - `/staff/payment_edit.php?id=...`
   - `/staff/payment_receipt.php?id=...`
   - `/staff/release_voucher.php?id=...`

Expected:

- ✅ Access blocked or safe error shown
- ❌ No cross-tenant data is displayed

## 7. MANAGER Checklist

### Who Creates This Account

- `SUPER_ADMIN` or owned-tenant `ADMIN`

### Where To Create

- `/staff/registration.php`

### What MANAGER Can Do

- ✅ Access dashboard
- ✅ View loans
- ✅ View loan details
- ✅ View payments
- ✅ Record payments
- ✅ Edit payments
- ✅ Print receipts
- ✅ Manage vouchers
- ✅ Review applications
- ✅ Approve applications
- ✅ Update loan terms
- ✅ Assign loan officer
- ✅ View reports
- ✅ View advanced reports
- ✅ View staff
- ✅ View settings

### What MANAGER Cannot Do

- ❌ Cannot create tenants
- ❌ Cannot manage tenants
- ❌ Cannot manage staff registration
- ❌ Cannot access other tenants

### How To Create MANAGER Manually

1. Login as `SUPER_ADMIN` or `ADMIN`
2. Go to `/staff/registration.php`
3. Choose role `MANAGER`
4. Fill required fields
5. Submit

Expected:

- ✅ Manager account created inside active tenant

### Manual Test Steps

1. Login as `MANAGER`
2. Open `/staff/dashboard.php`
3. Open `/staff/manager_queue.php`
4. Open `/staff/reports.php`
5. Open `/staff/staff.php`
6. Try to open `/staff/registration.php`
7. Try to open `/staff/tenant_management.php`

Expected:

- ✅ Manager pages load
- ❌ Staff registration blocked
- ❌ Tenant management blocked

## 8. CREDIT_INVESTIGATOR Checklist

### Who Creates This Account

- `SUPER_ADMIN` or owned-tenant `ADMIN`

### Where To Create

- `/staff/registration.php`

### What CREDIT_INVESTIGATOR Can Do

- ✅ Access dashboard
- ✅ View loans
- ✅ View loan details
- ✅ View customers
- ✅ Review applications
- ✅ View reports

### What CREDIT_INVESTIGATOR Cannot Do

- ❌ Cannot create tenants
- ❌ Cannot manage staff
- ❌ Cannot approve manager-only actions
- ❌ Cannot access other tenants

### How To Create CREDIT_INVESTIGATOR Manually

1. Login as `SUPER_ADMIN` or `ADMIN`
2. Go to `/staff/registration.php`
3. Choose role `CREDIT_INVESTIGATOR`
4. Fill required fields
5. Submit

Expected:

- ✅ Credit investigator account created

### Manual Test Steps

1. Login as `CREDIT_INVESTIGATOR`
2. Open `/staff/ci_queue.php`
3. Open `/staff/loans.php`
4. Open `/staff/customers.php`
5. Try to open `/staff/registration.php`
6. Try to open `/staff/tenant_management.php`

Expected:

- ✅ CI pages load
- ❌ Staff registration blocked
- ❌ Tenant management blocked

## 9. LOAN_OFFICER Checklist

### Who Creates This Account

- `SUPER_ADMIN` or owned-tenant `ADMIN`

### Where To Create

- `/staff/registration.php`

### What LOAN_OFFICER Can Do

- ✅ Access dashboard
- ✅ View loans
- ✅ View loan details
- ✅ View customers
- ✅ Manage vouchers
- ✅ View reports

### What LOAN_OFFICER Cannot Do

- ❌ Cannot create tenants
- ❌ Cannot manage staff
- ❌ Cannot access other tenants

### How To Create LOAN_OFFICER Manually

1. Login as `SUPER_ADMIN` or `ADMIN`
2. Go to `/staff/registration.php`
3. Choose role `LOAN_OFFICER`
4. Fill required fields
5. Submit

Expected:

- ✅ Loan officer account created

### Manual Test Steps

1. Login as `LOAN_OFFICER`
2. Open `/staff/loans.php`
3. Open `/staff/customers.php`
4. Open `/staff/release_queue.php`
5. Try to open `/staff/tenant_management.php`
6. Try to open `/staff/registration.php`

Expected:

- ✅ Allowed pages load
- ❌ Restricted pages blocked

## 10. CASHIER Checklist

### Who Creates This Account

- `SUPER_ADMIN` or owned-tenant `ADMIN`

### Where To Create

- `/staff/registration.php`

### What CASHIER Can Do

- ✅ Access dashboard
- ✅ View loan details
- ✅ View payments
- ✅ Record payments
- ✅ Edit payments
- ✅ Print receipts
- ✅ Manage vouchers
- ✅ View reports

### What CASHIER Cannot Do

- ❌ Cannot create tenants
- ❌ Cannot manage staff
- ❌ Cannot access other tenants

### How To Create CASHIER Manually

1. Login as `SUPER_ADMIN` or `ADMIN`
2. Go to `/staff/registration.php`
3. Choose role `CASHIER`
4. Fill required fields
5. Submit

Expected:

- ✅ Cashier account created

### Manual Test Steps

1. Login as `CASHIER`
2. Open `/staff/payments.php`
3. Open add payment flow
4. Open edit payment flow
5. Open receipt/print flow
6. Try to open `/staff/tenant_management.php`
7. Try to open `/staff/registration.php`

Expected:

- ✅ Payment pages load
- ❌ Restricted pages blocked

## 11. CUSTOMER Checklist

### Who Creates This Account

- Self-registration or API/mobile registration flow

### Where To Create

- `api/v1/auth.php?action=register`

### What CUSTOMER Can Do

- ✅ Use customer/mobile flow
- ✅ Access own customer data only

### What CUSTOMER Cannot Do

- ❌ Cannot use staff portal
- ❌ Cannot create tenants
- ❌ Cannot manage staff

### How To Create CUSTOMER Manually

Use Postman or another API client.

1. Send a request to customer registration endpoint
2. Provide required registration payload
3. Submit

Expected:

- ✅ Customer account is created with role `CUSTOMER`

### Manual Test Steps

1. Register a customer through the API
2. Login through the API customer login flow
3. Try to access staff pages in browser

Expected:

- ✅ API auth works
- ❌ Staff portal access is not allowed

## 12. TENANT Checklist

### Who Creates This Account

- No normal owner workflow should use this role

### Where To Create

- No standard manual creation path should be relied on for owner logic

### What TENANT Can Do

- ✅ Only legacy behavior if still retained somewhere else

### What TENANT Cannot Do

- ❌ Must not be used as owner login
- ❌ Must not replace `ADMIN` ownership logic

### Manual Test Steps

1. If a `TENANT` role account exists, log in with it
2. Check whether it gets owner behavior
3. Check whether it sees tenant selection as an owner

Expected:

- ❌ It must not behave like tenant owner workflow

## 13. Feature Checklist

Use this to check major features manually.

### Staff Creation

Check:

- ✅ Correct roles can create staff
- ❌ Wrong roles cannot create staff

How to test:

1. Login as `SUPER_ADMIN`
2. Go to `/staff/registration.php`
3. Create one staff account
4. Login as `ADMIN`
5. Go to `/staff/registration.php`
6. Create one tenant-scoped staff account
7. Login as `MANAGER`
8. Try to open `/staff/registration.php`

Expected:

- ✅ `SUPER_ADMIN` can create staff
- ✅ `ADMIN` can create tenant-scoped staff
- ❌ `MANAGER` cannot create staff

### Tenant Creation

Check:

- ✅ Only `SUPER_ADMIN` can create tenants

How to test:

1. Login as `SUPER_ADMIN`
2. Go to `/staff/registration.php`
3. Create a tenant
4. Login as `ADMIN`
5. Try to find tenant registration UI
6. Try to open `/staff/tenant_management.php`

Expected:

- ✅ `SUPER_ADMIN` can create tenant
- ❌ `ADMIN` cannot create tenant

### Tenant Owner Assignment

Check:

- ✅ Tenant owners can be assigned only to active `ADMIN` accounts

How to test:

1. Login as `SUPER_ADMIN`
2. Go to `/staff/tenant_management.php`
3. Open a tenant
4. Check an `ADMIN` in `Assigned Owner Admins`
5. Save

Expected:

- ✅ Save works for valid active `ADMIN`
- ❌ Non-`ADMIN` owner assignment must not be accepted

### Customer Management

Check:

- ✅ Only allowed roles can manage/view customers

How to test:

1. Login as `ADMIN`
2. Open `/staff/customers.php`
3. Create or update a customer
4. Login as `LOAN_OFFICER`
5. Open `/staff/customers.php`
6. Login as `CASHIER`
7. Try to open `/staff/customers.php`

Expected:

- ✅ `ADMIN` allowed
- ✅ `LOAN_OFFICER` can view
- ❌ `CASHIER` should be blocked from customers page

### Loan Management

Check:

- ✅ Allowed roles can open loans

How to test:

1. Login as `ADMIN`
2. Open `/staff/loans.php`
3. Login as `MANAGER`
4. Open `/staff/loans.php`
5. Login as `CASHIER`
6. Try `/staff/loans.php`

Expected:

- ✅ `ADMIN` allowed
- ✅ `MANAGER` allowed
- ❌ `CASHIER` blocked from loans list

### Payments

Check:

- ✅ Allowed roles can record and print payments

How to test:

1. Login as `CASHIER`
2. Open `/staff/payments.php`
3. Record a payment
4. Print receipt
5. Login as `CREDIT_INVESTIGATOR`
6. Try to open `/staff/payments.php`

Expected:

- ✅ `CASHIER` allowed
- ❌ `CREDIT_INVESTIGATOR` blocked from payments page

### Reports

Check:

- ✅ Allowed roles can access reports

How to test:

1. Login as `ADMIN`
2. Open `/staff/reports.php`
3. Login as `MANAGER`
4. Open `/staff/reports.php`
5. Login as `CASHIER`
6. Open `/staff/reports.php`

Expected:

- ✅ Allowed roles can open reports

### Settings

Check:

- ✅ Only allowed roles can access settings

How to test:

1. Login as `ADMIN`
2. Open `/staff/manager_settings.php`
3. Login as `MANAGER`
4. Open `/staff/manager_settings.php`
5. Login as `LOAN_OFFICER`
6. Try to open `/staff/manager_settings.php`

Expected:

- ✅ `ADMIN` allowed
- ✅ `MANAGER` allowed
- ❌ `LOAN_OFFICER` blocked

## 14. Final Signoff Checklist

- [ ] ✅ `SUPER_ADMIN` exists and can create tenants
- [ ] ✅ `SUPER_ADMIN` can create `ADMIN`
- [ ] ✅ `SUPER_ADMIN` can assign tenant owners
- [ ] ✅ `ADMIN` can access owned tenants only
- [ ] ✅ `ADMIN` cannot create tenants
- [ ] ✅ `ADMIN` can create tenant-scoped staff
- [ ] ✅ `MANAGER` access matches allowed pages only
- [ ] ✅ `CREDIT_INVESTIGATOR` access matches allowed pages only
- [ ] ✅ `LOAN_OFFICER` access matches allowed pages only
- [ ] ✅ `CASHIER` access matches allowed pages only
- [ ] ✅ `CUSTOMER` uses API/mobile flow only
- [ ] ✅ `TENANT` is not used as owner workflow
- [ ] ✅ Cross-tenant access is blocked
- [ ] ✅ URL tampering is blocked
- [ ] ✅ Wrong roles are blocked from restricted pages
