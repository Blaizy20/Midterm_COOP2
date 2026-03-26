<?php
/**
 * Loans API Endpoints
 * 
 * File: /api/v1/loans.php
 * 
 * Endpoints:
 * - GET /api/v1/loans.php - Get customer's loans
 * - GET /api/v1/loans.php?loan_id=1 - Get single loan details
 * - POST /api/v1/loans.php - Create new loan application
 * - GET /api/v1/loans.php?loan_id=1&action=payments - Get loan payments
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../includes/AuthService.php';
require_once __DIR__ . '/../../includes/LoanService.php';
require_once __DIR__ . '/../../includes/CustomerService.php';
require_once __DIR__ . '/../../includes/PaymentService.php';
require_once __DIR__ . '/../../includes/loan_helpers.php';

// Require authentication
$user = require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$loan_id = $_GET['loan_id'] ?? null;
$action = $_GET['action'] ?? null;

// ====================================================================
// GET Endpoints
// ====================================================================

if ($method === 'GET') {
    
    try {
        $loanService = new LoanService();
        $customerService = new CustomerService();
        
        // Get customer info
        $customer = $customerService->getCustomerByUserId($user['user_id']);
        
        if (!$customer) {
            api_error('Customer not found', 'CUSTOMER_NOT_FOUND', 404);
        }
        
        // ============================================================
        // Get Loan Payments
        // ============================================================
        
        if ($action === 'payments' && $loan_id) {
            // Verify customer owns this loan
            $loan = $loanService->getLoanById($loan_id);
            
            if (!$loan || $loan['customer_id'] != $customer['customer_id']) {
                api_error('Loan not found or access denied', 'LOAN_NOT_FOUND', 404);
            }
            
            $paymentService = new PaymentService();
            $payments = $paymentService->getLoanPayments($loan_id);
            
            api_response(true, $payments, 'Payments retrieved successfully');
        }
        
        // ============================================================
        // Get Single Loan Details
        // ============================================================
        
        else if ($loan_id) {
            // Verify customer owns this loan
            $loan = $loanService->getLoanById($loan_id);
            
            if (!$loan || $loan['customer_id'] != $customer['customer_id']) {
                api_error('Loan not found or access denied', 'LOAN_NOT_FOUND', 404);
            }
            
            api_response(true, $loan, 'Loan details retrieved successfully');
        }
        
        // ============================================================
        // Get All Customer Loans
        // ============================================================
        
        else {
            $status = $_GET['status'] ?? null;
            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);
            
            // Validate limits
            $limit = min($limit, 100); // Max 100
            $limit = max($limit, 1);   // Min 1
            
            $loans = $status ?
                $loanService->getCustomerLoans($customer['customer_id'], $status) :
                $loanService->getCustomerLoans($customer['customer_id']);
            
            // Apply pagination
            $loans = array_slice($loans, $offset, $limit);
            
            api_response(true, [
                'loans' => $loans,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'total' => count($loans)
                ]
            ], 'Loans retrieved successfully');
        }
        
    } catch (Exception $e) {
        log_error('Loan retrieval error', ['error' => $e->getMessage()]);
        api_error('Failed to retrieve loans', 'LOAN_ERROR', 500);
    }
}

// ====================================================================
// POST Endpoints
// ====================================================================

else if ($method === 'POST') {
    
    $data = get_json_input();
    
    try {
        $customerService = new CustomerService();
        $loanService = new LoanService();
        
        // Get customer info
        $customer = $customerService->getCustomerByUserId($user['user_id']);
        
        if (!$customer) {
            api_error('Customer not found', 'CUSTOMER_NOT_FOUND', 404);
        }
        
        // ============================================================
        // Create Loan Application
        // ============================================================
        
        if ($action === 'apply' || empty($action)) {
            validate_required_fields($data, [
                'principal_amount',
                'interest_rate',
                'payment_term',
                'term_months'
            ]);
            
            // Validate fields
            $principal = floatval($data['principal_amount']);
            $interest = floatval($data['interest_rate']);
            $term_months = intval($data['term_months']);
            $payment_term = strtolower($data['payment_term']);
            
            // Validation rules
            $valid_terms = ['daily', 'weekly', 'semi_monthly', 'monthly'];
            
            if ($principal <= 0) {
                api_error('Principal amount must be greater than 0', 'INVALID_AMOUNT', 422);
            }
            
            if ($interest < 0 || $interest > 100) {
                api_error('Interest rate must be between 0 and 100', 'INVALID_RATE', 422);
            }
            
            if ($term_months <= 0 || $term_months > 360) {
                api_error('Term must be between 1 and 360 months', 'INVALID_TERM', 422);
            }
            
            if (!in_array($payment_term, $valid_terms)) {
                api_error(
                    'Invalid payment term. Must be: ' . implode(', ', $valid_terms),
                    'INVALID_TERM',
                    422
                );
            }
            
            // Check if customer is eligible to apply for a new loan
            // (cannot have any unpaid/active loans)
            $eligibility = check_customer_loan_eligibility($customer['customer_id']);
            
            if (!$eligibility['eligible']) {
                api_error(
                    $eligibility['message'],
                    'UNPAID_LOANS_EXIST',
                    409
                );
            }
            
            // Create loan
            $result = $loanService->createLoan($customer['customer_id'], $data);
            
            api_response(true, [
                'loan_id' => $result['loan_id'],
                'reference_no' => $result['reference_no'],
                'status' => 'PENDING',
                'message' => 'Please upload required documents'
            ], 'Loan application created successfully', 201);
        }
        
        else {
            api_error('Invalid action', 'INVALID_ACTION', 400);
        }
        
    } catch (Exception $e) {
        log_error('Loan creation error', ['error' => $e->getMessage()]);
        api_error($e->getMessage(), 'LOAN_ERROR', 500);
    }
}

// ====================================================================
// Unsupported Methods
// ====================================================================

else {
    api_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

?>
