<?php
/**
 * Loan Service
 * 
 * Handles all loan-related operations including creation, status tracking,
 * and management. Used by staff portal and ready for mobile API integration.
 */

require_once __DIR__ . '/pdo_db.php';

class LoanService {
    private $db;
    private $tenant_id;
    
    public function __construct($tenant_id = null) {
        $this->db = Database::getInstance();
        $this->tenant_id = $tenant_id ?? ($_SESSION['tenant_id'] ?? null);
    }
    
    /**
     * Set tenant ID for multi-tenant operations
     */
    public function setTenantId($tenant_id) {
        $this->tenant_id = $tenant_id;
    }

    private function requireTenantId() {
        if (!$this->tenant_id) {
            throw new Exception("Tenant context is required");
        }
        return $this->tenant_id;
    }
    
    /**
     * Get loan by loan_id
     */
    public function getLoanById($loanId) {
        try {
            $sql = "SELECT l.*, 
                           c.customer_no, c.first_name, c.last_name, c.email,
                           ci.full_name as ci_name,
                           mgr.full_name as manager_name,
                           lo.full_name as loan_officer_name
                    FROM loans l
                    LEFT JOIN customers c ON c.customer_id = l.customer_id
                    LEFT JOIN users ci ON ci.user_id = l.ci_by
                    LEFT JOIN users mgr ON mgr.user_id = l.manager_by
                    LEFT JOIN users lo ON lo.user_id = l.loan_officer_id
                    WHERE l.loan_id = ? AND l.tenant_id = ?";
            
            return $this->db->queryOne($sql, [$loanId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get loan error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get loan by reference number
     */
    public function getLoanByReferenceNo($refNo) {
        try {
            $sql = "SELECT l.*, 
                           c.customer_no, c.first_name, c.last_name, c.email,
                           ci.full_name as ci_name,
                           mgr.full_name as manager_name,
                           lo.full_name as loan_officer_name
                    FROM loans l
                    LEFT JOIN customers c ON c.customer_id = l.customer_id
                    LEFT JOIN users ci ON ci.user_id = l.ci_by
                    LEFT JOIN users mgr ON mgr.user_id = l.manager_by
                    LEFT JOIN users lo ON lo.user_id = l.loan_officer_id
                    WHERE l.reference_no = ? AND l.tenant_id = ?";
            
            return $this->db->queryOne($sql, [$refNo, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get loan by ref error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create a new loan application
     * Can be called from staff portal or future mobile API
     */
    public function canCustomerApplyLoan($customerId) {
        try {
            // Check if customer has any unpaid/active loans
            $sql = "SELECT loan_id, reference_no, status, remaining_balance 
                    FROM loans 
                    WHERE customer_id = ? AND tenant_id = ? AND status IN ('PENDING','CI_REVIEWED','APPROVED','ACTIVE','OVERDUE')";
            
            $unpaidLoans = $this->db->queryAll($sql, [$customerId, $this->requireTenantId()]);
            
            if (!empty($unpaidLoans)) {
                return [
                    'eligible' => false,
                    'message' => 'Customer has unpaid loans. Please settle them first.',
                    'unpaid_loans' => $unpaidLoans
                ];
            }
            
            return [
                'eligible' => true,
                'message' => 'Customer is eligible for new loan application'
            ];
        } catch (Exception $e) {
            error_log("Check loan eligibility error: " . $e->getMessage());
            return [
                'eligible' => false,
                'message' => 'Error checking loan eligibility'
            ];
        }
    }
    
    public function createLoan($customerId, $data) {
        try {
            $required = ['principal_amount', 'interest_rate', 'payment_term', 'term_months'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            $principalAmount = floatval($data['principal_amount']);
            $interestRate = floatval($data['interest_rate']);
            $termMonths = intval($data['term_months']);
            
            if ($principalAmount <= 0 || $interestRate < 0 || $termMonths <= 0) {
                throw new Exception("Invalid loan parameters");
            }
            
            $totalPayable = $principalAmount * (1 + ($interestRate / 100));
            $referenceNo = $this->generateReferenceNo();
            
            $sql = "INSERT INTO loans 
                    (tenant_id, reference_no, customer_id, principal_amount, interest_rate, payment_term, 
                     term_months, total_payable, remaining_balance, status, submitted_at, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW(), ?)";
            
            $this->db->exec($sql, [
                $this->requireTenantId(),
                $referenceNo,
                $customerId,
                $principalAmount,
                $interestRate,
                $data['payment_term'],
                $termMonths,
                $totalPayable,
                $totalPayable,
                $data['notes'] ?? null
            ]);
            
            return [
                'loan_id' => $this->db->lastInsertId(),
                'reference_no' => $referenceNo
            ];
        } catch (Exception $e) {
            error_log("Create loan error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update loan status
     */
    public function updateLoanStatus($loanId, $newStatus, $userId = null, $notes = null) {
        try {
            $validStatuses = ['PENDING', 'CI_REVIEWED', 'APPROVED', 'DENIED', 'ACTIVE', 'OVERDUE', 'CLOSED'];
            if (!in_array($newStatus, $validStatuses, true)) {
                throw new Exception("Invalid status: $newStatus");
            }
            
            $loan = $this->getLoanById($loanId);
            if (!$loan) {
                throw new Exception("Loan not found");
            }
            
            // Map status changes to user roles who made the decision
            if ($newStatus === 'CI_REVIEWED' && $userId) {
                $sql = "UPDATE loans SET status = ?, ci_by = ?, ci_at = NOW() WHERE loan_id = ? AND tenant_id = ?";
                $this->db->exec($sql, [$newStatus, $userId, $loanId, $this->requireTenantId()]);
            } elseif ($newStatus === 'APPROVED' && $userId) {
                $sql = "UPDATE loans SET status = ?, manager_by = ?, manager_at = NOW() WHERE loan_id = ? AND tenant_id = ?";
                $this->db->exec($sql, [$newStatus, $userId, $loanId, $this->requireTenantId()]);
            } elseif ($newStatus === 'ACTIVE' && $userId) {
                $sql = "UPDATE loans SET status = ?, loan_officer_id = ?, activated_at = NOW(), 
                        due_date = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE loan_id = ? AND tenant_id = ?";
                $termMonths = $loan['term_months'] ?? 12;
                $termDays = $termMonths * 30; // Approximate
                $this->db->exec($sql, [$newStatus, $userId, $termDays, $loanId, $this->requireTenantId()]);
            } else {
                $sql = "UPDATE loans SET status = ? WHERE loan_id = ? AND tenant_id = ?";
                $this->db->exec($sql, [$newStatus, $loanId, $this->requireTenantId()]);
            }
            
            // Add note if provided
            if ($notes) {
                $sql = "UPDATE loans SET notes = CONCAT_WS('\\n', IFNULL(notes, ''), ?) WHERE loan_id = ? AND tenant_id = ?";
                $this->db->exec($sql, [$notes, $loanId, $this->requireTenantId()]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Update loan status error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get all loans for a customer (useful for mobile app later)
     */
    public function getCustomerLoans($customerId, $status = null) {
        try {
            $where = "WHERE l.customer_id = ? AND l.tenant_id = ?";
            $params = [$customerId, $this->requireTenantId()];
            
            if ($status) {
                $where .= " AND l.status = ?";
                $params[] = $status;
            }
            
            $sql = "SELECT l.loan_id, l.reference_no, l.principal_amount, l.interest_rate,
                           l.status, l.total_payable, l.remaining_balance, l.submitted_at,
                           l.payment_term, l.term_months
                    FROM loans l
                    $where
                    ORDER BY l.submitted_at DESC";
            
            return $this->db->queryAll($sql, $params);
        } catch (Exception $e) {
            error_log("Get customer loans error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all loans (for staff view)
     */
    public function getAllLoans($status = null, $limit = 100, $offset = 0) {
        try {
            $where = "WHERE l.tenant_id = ?";
            $params = [$this->requireTenantId()];
            
            if ($status) {
                $where .= " AND l.status = ?";
                $params[] = $status;
            }
            
            $sql = "SELECT l.*, 
                           c.customer_no, c.first_name, c.last_name
                    FROM loans l
                    LEFT JOIN customers c ON c.customer_id = l.customer_id
                    $where
                    ORDER BY l.submitted_at DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            return $this->db->queryAll($sql, $params);
        } catch (Exception $e) {
            error_log("Get all loans error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get loans by status queue (for staff queues)
     */
    public function getLoansByStatusQueue($status) {
        try {
            $sql = "SELECT l.loan_id, l.reference_no, l.principal_amount, l.interest_rate,
                           l.status, l.submitted_at, l.term_months, l.payment_term,
                           c.customer_no, c.first_name, c.last_name, c.email,
                           COUNT(r.requirement_id) as requirement_count
                    FROM loans l
                    LEFT JOIN customers c ON c.customer_id = l.customer_id
                    LEFT JOIN requirements r ON r.loan_id = l.loan_id
                    WHERE l.status = ? AND l.tenant_id = ?
                    GROUP BY l.loan_id
                    ORDER BY l.submitted_at ASC";
            
            return $this->db->queryAll($sql, [$status, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get loans by queue error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Count loans by status
     */
    public function countLoansByStatus($status) {
        try {
            $sql = "SELECT COUNT(*) as count FROM loans WHERE status = ? AND tenant_id = ?";
            $result = $this->db->queryOne($sql, [$status, $this->requireTenantId()]);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("Count loans error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Generate unique reference number
     */
    private function generateReferenceNo() {
        $timestamp = date('YmdHis');
        $random = str_pad(mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
        return "LN-" . $timestamp . "-" . $random;
    }
    
    /**
     * Get loan summary statistics
     */
    public function getLoanSummaryStats() {
        try {
            $sql = "SELECT 
                           SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                           SUM(CASE WHEN status = 'CI_REVIEWED' THEN 1 ELSE 0 END) as ci_reviewed,
                           SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
                           SUM(CASE WHEN status = 'DENIED' THEN 1 ELSE 0 END) as denied,
                           SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) as active,
                           SUM(CASE WHEN status = 'OVERDUE' THEN 1 ELSE 0 END) as overdue,
                           SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) as closed,
                           SUM(principal_amount) as total_principal,
                           SUM(remaining_balance) as total_remaining
                    FROM loans
                    WHERE tenant_id = ?";
            
            return $this->db->queryOne($sql, [$this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get loan stats error: " . $e->getMessage());
            return null;
        }
    }
}

?>
