# Role Flowcharts

This document describes the current role flows implemented in this project.

Scope:
- Based on the current PHP code in `includes/auth.php`, `staff/`, and `api/v1/`
- Focused on actual reachable flows, not intended future behavior
- Mermaid diagrams are included so this file can be previewed in Markdown viewers that support Mermaid

## Roles Covered

| Role | Channel | Current State |
| --- | --- | --- |
| `SUPER_ADMIN` | Staff web portal | Full system-wide access |
| `ADMIN` | Staff web portal | Full operational/admin access, effectively system-wide |
| `TENANT` | Staff web portal | Can log in, but permissions are only partially mapped |
| `MANAGER` | Staff web portal | Loan decisions, reports, vouchers, settings |
| `CREDIT_INVESTIGATOR` | Staff web portal | CI review and validation flow |
| `LOAN_OFFICER` | Staff web portal | Loan viewing and operational follow-up |
| `CASHIER` | Staff web portal | Payment processing and receipts |
| `CUSTOMER` | Mobile/API | Registration, login, loan application, payment/history view |

## Overall Loan Lifecycle

```mermaid
flowchart TD
    A[Customer registered] --> B[Loan submitted]
    B --> C[Status: PENDING]
    C --> D[Credit Investigator reviews]
    D --> E[Status: CI_REVIEWED]
    E --> F{Manager decision}
    F -->|Approve| G[Status: ACTIVE]
    F -->|Deny| H[Status: DENIED]
    G --> I[Loan officer assigned]
    I --> J[Payments recorded]
    J --> K{Balance cleared?}
    K -->|No| L[Remain ACTIVE or become OVERDUE]
    K -->|Yes| M[Status: CLOSED]
    G --> N[Money release voucher]
```

## SUPER_ADMIN

Primary flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[View all tenants]
    B --> D[View all users and staff]
    B --> E[Review all loans]
    B --> F[View all payments]
    B --> G[Open reports and sales report]
    B --> H[Open history logs]
    B --> I[Open settings]
    B --> J[Register staff]
    B --> K[Register tenant]
    B --> L[Manage tenant activation]
    E --> M[Open loan details]
    M --> N[CI review]
    M --> O[Approve or deny loan]
    M --> P[Update loan terms]
    M --> Q[Assign loan officer]
    F --> R[Print receipt]
    F --> S[Edit payment]
    B --> T[Open money release queue]
    T --> U[View or edit voucher]
```

Current capabilities:
- Everything available to `ADMIN`
- System-wide visibility across tenants
- Staff account management
- Tenant registration and activation/deactivation
- History and sales reporting

## ADMIN

Primary flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[Customers]
    B --> D[Loans]
    B --> E[Payments]
    B --> F[CI Review queue]
    B --> G[Manager Approval queue]
    B --> H[Reports]
    B --> I[Staff]
    B --> J[Register Staff and Tenant]
    B --> K[Tenant Management]
    B --> L[Sales Report]
    B --> M[History]
    B --> N[Settings]
    C --> O[Register or edit customer]
    D --> P[Open loan details]
    P --> Q[CI review]
    P --> R[Approve or deny]
    P --> S[Update terms]
    P --> T[Assign officer]
    E --> U[Record payment]
    E --> V[Edit payment]
    E --> W[Print receipt]
    B --> X[Money Release]
    X --> Y[View or edit voucher]
```

Current capabilities:
- Full operational control
- Customer creation and maintenance
- Staff registration and maintenance
- Tenant registration and status management
- Loan approval chain access

## TENANT

Current-state flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[Tenant-scoped summary cards]
    C --> D[Recent applications view]
    C --> E[Staff ranking widget]
    E --> F[Logout]
```

Notes:
- `TENANT` can log in and view the dashboard
- In the current permission map, `TENANT` is not granted the normal sidebar permissions
- This means the role is present in the schema/session logic, but not fully wired like the other staff roles

## MANAGER

Primary flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[Loans]
    B --> D[Customers view]
    B --> E[Payments]
    B --> F[CI Review queue]
    B --> G[Manager Approval queue]
    B --> H[Reports]
    B --> I[Staff view]
    B --> J[Money Release queue]
    B --> K[Settings]
    G --> L[Open loan details]
    L --> M{Decision}
    M -->|Approve| N[Set ACTIVE and due date]
    M -->|Deny| O[Set DENIED]
    L --> P[Update interest rate]
    L --> Q[Update payment term]
    L --> R[Assign loan officer]
    E --> S[Record payment]
    E --> T[Edit payment]
    E --> U[Print receipt]
    J --> V[Generate or edit release voucher]
```

Current capabilities:
- Final decision-maker for loan approval
- Can update loan terms
- Can assign loan officers
- Can process vouchers
- Can see reports and settings

## CREDIT_INVESTIGATOR

Primary flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[Loans]
    B --> D[Customers view]
    B --> E[CI Review queue]
    B --> F[Reports]
    E --> G[Open pending loan]
    G --> H[Review customer info]
    G --> I[Review uploaded requirements]
    G --> J[Download requirement files]
    G --> K[Add CI remarks]
    K --> L[Mark as CI_REVIEWED]
    L --> M[Forward to Manager Approval queue]
```

Current capabilities:
- Reviews `PENDING` loans
- Verifies requirements and customer details
- Moves applications to `CI_REVIEWED`
- Does not approve or deny the loan

## LOAN_OFFICER

Primary flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[Loans]
    B --> D[Customers view]
    B --> E[Reports]
    B --> F[Money Release queue]
    C --> G[Open loan details]
    G --> H[Monitor ACTIVE and OVERDUE loans]
    G --> I[Review payment history]
    G --> J[View assigned officer field]
    F --> K[Open release voucher]
    K --> L[Prepare release documents]
```

Current capabilities:
- Can view loans and loan details
- Can participate in voucher/money-release flow
- Can monitor active accounts
- Cannot record payments directly in the current permission map

## CASHIER

Primary flow:

```mermaid
flowchart TD
    A[Login] --> B[Dashboard]
    B --> C[Payments]
    B --> D[Money Release queue]
    B --> E[Reports]
    C --> F[Open loan details]
    F --> G{Loan status ACTIVE or OVERDUE?}
    G -->|Yes| H[Record payment]
    H --> I[Choose method]
    I --> J[Cash]
    I --> K[GCash]
    I --> L[Bank transfer]
    I --> M[Cheque]
    H --> N[Generate OR number]
    N --> O[Save payment]
    O --> P[Recalculate balance]
    P --> Q{Fully paid?}
    Q -->|Yes| R[Loan becomes CLOSED]
    Q -->|No| S[Loan remains ACTIVE or OVERDUE]
    C --> T[Edit payment]
    C --> U[Print receipt]
    D --> V[Open voucher]
```

Current capabilities:
- Records payments
- Edits payments
- Prints receipts
- Can work with active/overdue loan collections

## CUSTOMER

Primary mobile/API flow:

```mermaid
flowchart TD
    A[Mobile app or API client] --> B[Register]
    B --> C[Create CUSTOMER user]
    C --> D[Create customer profile]
    D --> E[Login]
    E --> F[Receive auth token]
    F --> G[View own loans]
    F --> H[View loan details]
    F --> I[View payment history]
    F --> J[Submit new loan application]
    F --> K[Forgot password]
    K --> L[Reset password]
```

Notes:
- Customer access is API/mobile oriented, not the staff web portal
- Implemented endpoints live in `api/v1/auth.php` and `api/v1/loans.php`

## Role-to-Page Summary

| Role | Main Pages / Flow Entry Points |
| --- | --- |
| `SUPER_ADMIN` | Dashboard, Loans, Payments, Reports, Staff, Registration, Tenant Management, Sales, History, Settings |
| `ADMIN` | Dashboard, Customers, Loans, Payments, CI Queue, Manager Queue, Reports, Staff, Registration, Tenant Management, Sales, History, Settings |
| `TENANT` | Dashboard only in current wiring |
| `MANAGER` | Dashboard, Loans, Customers, Payments, CI Queue, Manager Queue, Reports, Staff, Money Release, Settings |
| `CREDIT_INVESTIGATOR` | Dashboard, Loans, Customers, CI Queue, Reports |
| `LOAN_OFFICER` | Dashboard, Loans, Customers, Reports, Money Release |
| `CASHIER` | Dashboard, Payments, Money Release, Reports |
| `CUSTOMER` | API registration, API login, API loans, API payments/history |

## Implementation Notes

- Loan decisions happen in `staff/loan_view.php`
- Customer management happens in `staff/customers.php`
- Staff and tenant registration happens in `staff/registration.php`
- Tenant activation/deactivation happens in `staff/tenant_management.php`
- Payment recording happens in `staff/payment_add.php`
- Voucher flow happens in `staff/release_queue.php` and `staff/release_voucher.php`
- Permission mapping is defined in `includes/auth.php`

