# Mobile API Integration Guide

## Overview

This document describes how to integrate the mobile application (Android/Kotlin) with the refactored Loan Management System backend. The web application now serves only as a **Staff Portal**, while customer functionality is ready for mobile integration.

## Architecture

### Web Portal (Staff Only)
- **Location**: `/staff/` directory
- **Authentication**: Username/password (staff users only)
- **Roles**: ADMIN, MANAGER, CREDIT_INVESTIGATOR, LOAN_OFFICER, CASHIER
- **Technology**: PHP + MySQL + PDO

### Mobile App (Customers)
- **Responsibilities**: Customer registration, loan application, document upload, status tracking
- **Technology**: Android (Kotlin)
- **Backend Connection**: REST API Gateway (to be implemented)

## Database Schema (Ready for Mobile)

All database tables remain intact and are ready for mobile API access:

```
users (User account master)
├─ user_id (PK)
├─ username (UNIQUE)
├─ password_hash
├─ full_name
├─ role (ENUM: ADMIN, MANAGER, CREDIT_INVESTIGATOR, LOAN_OFFICER, CASHIER, CUSTOMER)
├─ email
├─ contact_no
├─ is_active

customers (Customer profiles)
├─ customer_id (PK)
├─ user_id (FK to users) ← Links to CUSTOMER role users
├─ customer_no (UNIQUE)
├─ first_name
├─ last_name
├─ contact_no
├─ email
├─ province, city, barangay, street
├─ created_at
├─ is_active

loans (Loan applications)
├─ loan_id (PK)
├─ reference_no (UNIQUE)
├─ customer_id (FK to customers)
├─ principal_amount
├─ interest_rate
├─ payment_term
├─ term_months
├─ total_payable
├─ remaining_balance
├─ status (ENUM: PENDING, CI_REVIEWED, APPROVED, DENIED, ACTIVE, OVERDUE, CLOSED)
├─ submitted_at
├─ ci_by, ci_at (Credit Investigator review)
├─ manager_by, manager_at (Manager approval)
├─ loan_officer_id (Assigned officer)
├─ activated_at
├─ due_date
├─ notes

requirements (Documents/Files)
├─ requirement_id (PK)
├─ loan_id (FK)
├─ requirement_code
├─ requirement_name
├─ file_path
├─ uploaded_by_role (ENUM: CUSTOMER, STAFF) ← Tracks mobile vs staff uploads
├─ uploaded_by_user (FK to users)
├─ uploaded_at
├─ notes

payments (Payment records)
├─ payment_id (PK)
├─ loan_id (FK)
├─ amount
├─ payment_date
├─ method (CASH, CHEQUE, DIGITAL, GCASH)
├─ or_no (UNIQUE)
├─ loan_officer_id
├─ received_by
├─ created_at
```

## Service Layer (Ready for API)

The refactored backend includes modular service classes designed for easy API integration:

### 1. AuthService (`includes/AuthService.php`)

**For Mobile Authentication:**

```php
$authService = new AuthService();

// Mobile customer login
$customer = $authService->authenticateCustomer($username, $password);

// Returns: [user_id, username, full_name, email, customer_id, customer_no, first_name, last_name]

// Generate password reset token
$token = $authService->generateResetToken($userId);

// Verify and reset password
$success = $authService->verifyAndResetPassword($token, $newPassword);
```

**Password Requirements:**
- Minimum 8 characters
- At least 1 uppercase letter
- At least 1 lowercase letter
- At least 1 digit
- At least 1 special character

### 2. CustomerService (`includes/CustomerService.php`)

**For Mobile Customer Profiles:**

```php
$customerService = new CustomerService();

// Get customer by user ID (for authenticated mobile sessions)
$customer = $customerService->getCustomerByUserId($userId);

// Update customer profile
$customerService->updateCustomer($customerId, [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'contact_no' => '09XXXXXXXXX',
    'email' => 'john@example.com',
    'province' => 'Metro Manila',
    'city' => 'Quezon City',
    'barangay' => 'Quezon City',
    'street' => '123 Main St'
]);

// Create new customer (only via mobile registration API)
$customerId = $customerService->createCustomer([
    'user_id' => $userId,
    'customer_no' => 'CUST-001',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'contact_no' => '09XXXXXXXXX',
    'email' => 'john@example.com'
]);

// Search customers (for staff use)
$customers = $customerService->searchCustomers('john', 20);
```

### 3. LoanService (`includes/LoanService.php`)

**For Mobile Loan Applications:**

```php
$loanService = new LoanService();

// Create new loan application
$loan = $loanService->createLoan($customerId, [
    'principal_amount' => 50000,
    'interest_rate' => 12.5,
    'payment_term' => 'monthly',
    'term_months' => 12,
    'notes' => 'Business expansion loan'
]);

// Returns: ['loan_id' => ..., 'reference_no' => 'LN-...']

// Get customer's loans (for mobile dashboard)
$loans = $loanService->getCustomerLoans($customerId);
$pendingLoans = $loanService->getCustomerLoans($customerId, 'PENDING');
$approvedLoans = $loanService->getCustomerLoans($customerId, 'APPROVED');

// Get single loan details
$loan = $loanService->getLoanById($loanId);
$loan = $loanService->getLoanByReferenceNo('LN-20260314120000-00001');

// Get loan stats (for staff dashboard)
$stats = $loanService->getLoanSummaryStats();
```

**Loan Status Flow:**
```
PENDING → (Staff Credit Investigator reviews) → CI_REVIEWED
    ↓
PENDING → (Denied) → DENIED
    ↓
CI_REVIEWED → (Staff Manager approves) → APPROVED
    ↓
APPROVED → (Loan activated) → ACTIVE
    ↓
ACTIVE → (Overdue if unpaid) → OVERDUE
    ↓
ACTIVE/OVERDUE → (All paid) → CLOSED
```

### 4. RequirementService (`includes/RequirementService.php`)

**For Mobile Document Uploads:**

```php
$requirementService = new RequirementService();

// Mobile app uploads requirement documents
$requirementId = $requirementService->addRequirement(
    $loanId,
    'ID_FRONT',                    // requirement code
    'Valid ID - Front',            // display name
    'filename.jpg',                // stored filename
    'CUSTOMER',                    // Mark as mobile upload
    $userId,                       // User who uploaded
    'Submitted by mobile app'      // notes
);

// Get loan requirements
$requirements = $requirementService->getLoanRequirements($loanId);

// Update requirement notes
$requirementService->updateRequirement($requirementId, [
    'notes' => 'Updated status'
]);

// Delete requirement
$requirementService->deleteRequirement($requirementId);
```

**Required Documents for Loan Application:**
- `ID_FRONT` - Valid ID front
- `ID_BACK` - Valid ID back
- `COLLATERAL_PROOF` - Collateral documentation
- `COMAKER_ID_FRONT` - Co-maker ID front
- `COMAKER_ID_BACK` - Co-maker ID back

### 5. PaymentService (`includes/PaymentService.php`)

**For Mobile Payment Tracking:**

```php
$paymentService = new PaymentService();

// Get loan payments (for tracking)
$payments = $paymentService->getLoanPayments($loanId);

// Get payment by OR number
$payment = $paymentService->getPaymentByORNo('OR-0001');

// Staff records payment
$result = $paymentService->recordPayment($loanId, [
    'amount' => 5000,
    'payment_date' => '2026-03-15',
    'or_no' => 'OR-0001',
    'method' => 'CASH',
    'loan_officer_id' => 123,
    'received_by' => 456,
    'notes' => 'Cash payment received'
]);

// Returns: ['payment_id' => ..., 'new_balance' => ..., 'loan_status' => 'ACTIVE'|'CLOSED']
```

## Setting Up the API Gateway

To enable mobile app connection, implement a REST API gateway as follows:

### 1. Create `/api/` Directory

```
├─ api/
│  ├─ v1/
│  │  ├─ auth.php           (Login, registration, password reset)
│  │  ├─ customers.php      (Profile management)
│  │  ├─ loans.php          (Application, tracking)
│  │  ├─ payments.php       (Payment tracking)
│  │  ├─ requirements.php   (Document upload)
│  │  └─ config.php         (API configuration)
│  └─ middleware/
│     ├─ cors.php           (CORS headers)
│     └─ auth_token.php     (JWT/Token validation)
```

### 2. Base API Endpoint Example

**File: `/api/v1/config.php`**

```php
<?php
define('API_VERSION', 'v1');
define('API_RESPONSE_FORMAT', 'json');
define('API_ALLOW_ORIGINS', ['*']); // Configure for production

function api_response($success, $data = null, $message = null, $code = 200) {
    header('Content-Type: application/json');
    http_response_code($code);
    
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => API_VERSION
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($message !== null) {
        $response['message'] = $message;
    }
    
    echo json_encode($response);
    exit;
}

function api_error($message, $code = 400) {
    api_response(false, null, $message, $code);
}
?>
```

### 3. Customer Authentication API Example

**File: `/api/v1/auth.php`**

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../includes/AuthService.php';
require_once __DIR__ . '/../../includes/CustomerService.php';

// Handle HTTP method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'login') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['username']) || empty($data['password'])) {
            api_error('Missing username or password', 400);
        }
        
        $authService = new AuthService();
        $user = $authService->authenticateCustomer($data['username'], $data['password']);
        
        if (!$user) {
            api_error('Invalid credentials', 401);
        }
        
        // Generate token (implement JWT or session token)
        $token = bin2hex(random_bytes(32));
        // Store token in cache/database with expiry
        
        api_response(true, [
            'token' => $token,
            'user' => [
                'user_id' => $user['user_id'],
                'customer_id' => $user['customer_id'],
                'full_name' => $user['full_name'],
                'email' => $user['email']
            ]
        ], 'Login successful');
    }
    
    elseif ($action === 'register') {
        // Registration endpoint (implement with validation)
    }
    
    elseif ($action === 'forgot-password') {
        // Password reset endpoint
    }
}

api_error('Method not allowed', 405);
?>
```

### 4. Loan API Example

**File: `/api/v1/loans.php`**

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../includes/LoanService.php';
require_once __DIR__ . '/../../includes/CustomerService.php';

// Validate authentication token (implement middleware)
$user_id = $_GET['user_id'] ?? null; // From token validation
if (!$user_id) {
    api_error('Unauthorized', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    if ($action === 'list') {
        // Get customer's loans
        $status = $_GET['status'] ?? null;
        $loanService = new LoanService();
        $customerService = new CustomerService();
        
        $customer = $customerService->getCustomerByUserId($user_id);
        if (!$customer) {
            api_error('Customer not found', 404);
        }
        
        $loans = $loanService->getCustomerLoans($customer['customer_id'], $status);
        api_response(true, $loans, 'Loans retrieved');
    }
    
    elseif ($action === 'view') {
        // Get single loan details
        $loan_id = $_GET['loan_id'] ?? null;
        $loanService = new LoanService();
        
        $loan = $loanService->getLoanById($loan_id);
        if (!$loan) {
            api_error('Loan not found', 404);
        }
        
        api_response(true, $loan, 'Loan details retrieved');
    }
}

elseif ($method === 'POST') {
    if ($action === 'apply') {
        // Create new loan application
        $data = json_decode(file_get_contents('php://input'), true);
        
        $loanService = new LoanService();
        $customerService = new CustomerService();
        
        $customer = $customerService->getCustomerByUserId($user_id);
        if (!$customer) {
            api_error('Customer not found', 404);
        }
        
        try {
            $result = $loanService->createLoan($customer['customer_id'], $data);
            api_response(true, $result, 'Loan application submitted');
        } catch (Exception $e) {
            api_error($e->getMessage(), 400);
        }
    }
}

api_error('Invalid action', 400);
?>
```

## Mobile App Development Checklist

### Phase 1: Authentication & User Management
- [ ] Implement login screen
- [ ] Implement registration screen
- [ ] Implement password reset flow
- [ ] Store authentication token securely (SharedPreferences/Keystore)
- [ ] Implement session token refresh

### Phase 2: Customer Dashboard
- [ ] Display customer profile information
- [ ] Show customer's active loans
- [ ] Allow profile update
- [ ] Display loan application status

### Phase 3: Loan Application
- [ ] Create loan application form
- [ ] Implement document upload (ID, collateral proof, etc.)
- [ ] Add co-maker information form
- [ ] Submit application and get reference number

### Phase 4: Payment Tracking
- [ ] Display payment history
- [ ] Show remaining loan balance
- [ ] Display next payment due date
- [ ] Calculate amortization schedule

### Phase 5: Document Management
- [ ] Upload/submit requirement documents
- [ ] View uploaded documents
- [ ] Delete documents if rejected

## Security Considerations

1. **Authentication**
   - Implement JWT tokens with expiry
   - Refresh tokens for session extension
   - Store tokens securely on mobile

2. **Data Validation**
   - Validate all inputs on API server
   - Sanitize file uploads
   - Check file type and size

3. **HTTPS**
   - All API calls must use HTTPS
   - Implement certificate pinning

4. **API Rate Limiting**
   - Implement rate limiting per user
   - Prevent brute force attacks

5. **Logging**
   - Log authentication attempts
   - Log data access for audit trail
   - Monitor database queries

## Testing the Integration

### 1. Test Authentication Service

```php
<?php
require_once 'includes/AuthService.php';

$authService = new AuthService();
$customer = $authService->authenticateCustomer('john_doe', 'SecurePass123!');

if ($customer) {
    echo "Authentication successful!\n";
    print_r($customer);
} else {
    echo "Authentication failed\n";
}
?>
```

### 2. Test Loan Service

```php
<?php
require_once 'includes/LoanService.php';
require_once 'includes/CustomerService.php';

$customerService = new CustomerService();
$loanService = new LoanService();

$customer = $customerService->getCustomerByUserId(1);

if ($customer) {
    $loans = $loanService->getCustomerLoans($customer['customer_id']);
    echo "Found " . count($loans) . " loans\n";
    print_r($loans);
}
?>
```

## Transition Timeline

1. **Week 1-2**: Remove customer web portal, implement service layer
2. **Week 3-4**: Create API gateway endpoints
3. **Week 5-6**: Mobile app development kickoff
4. **Week 7-12**: Parallel development and integration testing
5. **Week 13+**: Production deployment and monitoring

## Support & Maintenance

- Monitor API error logs regularly
- Track API usage and performance metrics
- Implement automatic backups of customer data
- Plan regular security audits
- Update dependencies and patches

---

**Document Version**: 1.0  
**Last Updated**: March 14, 2026  
**Status**: Ready for Mobile Development
