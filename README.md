# Loan Management System
**One Database | Web + Mobile**

## Quick Start

### 1. Database Setup
```bash
mysql < schema.sql
```
Creates single database: `loan_management` with all required tables.

### 2. Web Portal (Staff)
Access: `http://localhost/staff/login.php`
- Admin staff login and management
- Loan processing workflow
- Payment tracking
- Reports and Analytics

### 3. Mobile API (Apps)
Base URL: `http://localhost/api/v1/`
- Customer registration and login
- Loan application and tracking
- Payment history
- Document uploads

---

## Database Architecture

### Single Unified Database: `loan_management`

| Table | Purpose |
|-------|---------|
| `tenants` | Organization data (multi-tenant support) |
| `users` | Staff + customers (role-based) |
| `customers` | Borrower information |
| `loans` | Loan applications and tracking |
| `payments` | Payment records |
| `requirements` | Document/file tracking |
| `activity_logs` | Audit trail |
| `money_release_vouchers` | Fund disbursement |
| `interest_rate_history` | Rate change tracking |
| `system_settings` | Configuration per tenant |

---

## Connection Details

**Database**: `loan_management`  
**Host**: `localhost`  
**User**: `root`  
**Password**: (empty)  
**Charset**: utf8mb4

### Web Portal Connection
File: [`includes/db.php`](includes/db.php) (MySQLi)

### Mobile API Connection
File: [`includes/pdo_db.php`](includes/pdo_db.php) (PDO)

Both use same database, different connection methods.

---

## Project Structure

```
├── schema.sql                 # Database schema (one-time setup)
├── index.php                  # Entry point
├── composer.json              # PHP dependencies (PHPMailer)
│
├── staff/                     # Web Portal
│   ├── login.php             # Staff login
│   ├── dashboard.php         # Main dashboard
│   ├── loans.php             # Loan management
│   ├── payments.php          # Payment processing
│   └── ...
│
├── api/v1/                    # Mobile API
│   ├── config.php            # API configuration & CORS
│   ├── auth.php              # Customer auth endpoints
│   └── loans.php             # Loan endpoints
│
├── includes/                  # Core Services
│   ├── db.php                # MySQL connection
│   ├── pdo_db.php            # PDO connection
│   ├── auth.php              # Authentication functions
│   ├── AuthService.php       # Auth business logic
│   ├── LoanService.php       # Loan operations
│   ├── PaymentService.php    # Payment operations
│   ├── TenantService.php     # Multi-tenant logic
│   ├── TenantMiddleware.php  # Tenant isolation
│   ├── CustomerService.php   # Customer operations
│   ├── RequirementService.php # Document tracking
│   └── loan_helpers.php      # Utility functions
│
├── assets/                    # Frontend resources
│   ├── css/theme.css
│   └── img/
│
├── logs/                      # Application logs
└── uploads/                   # File uploads (requirements, logo)

```

---

## How It Works

### Staff Web Portal
1. Staff logs in at `/staff/login.php`
2. Session stored in `users` table (role: ADMIN/MANAGER/CREDIT_INVESTIGATOR/LOAN_OFFICER/CASHIER)
3. Access to loan management, payments, reporting
4. All actions logged in `activity_logs`

### Mobile Application
1. Customer registers via `/api/v1/auth.php?action=register`
2. Token-based authentication for API requests
3. Can view own loans, submit documents, track payments
4. Data isolated by tenant and user

### Database Isolation
- All tables include `tenant_id`
- Multi-tenant middleware enforces filtering
- No data leakage between organizations

---

## Configuration

### Database Credentials
Update if different:
- [`includes/db.php`](includes/db.php) lines 2-7
- [`includes/pdo_db.php`](includes/pdo_db.php) lines 17-22

### API Settings
- CORS origins: [`api/v1/config.php`](api/v1/config.php)
- Response handling: Same file

---

## Next Steps

1. ✅ Database initialized (`schema.sql`)
2. ✅ Web portal accessible (`/staff/`)
3. ✅ API ready for mobile (`/api/v1/`)
4. ⏳ Configure email (if needed for password resets)
5. ⏳ Deploy and test with mobile app

---

**Status**: Production-Ready  
**Shared Database**: Yes (loan_management)  
**Deployment**: Requires XAMPP/WAMP with MySQL
