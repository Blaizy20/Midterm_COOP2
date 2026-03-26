<?php
/**
 * Payment Service
 *
 * Handles all payment-related operations.
 * Tenant context is required for tenant-owned operations.
 */

require_once __DIR__ . '/pdo_db.php';

class PaymentService {
    private $db;
    private $tenant_id;

    public function __construct($tenant_id = null) {
        $this->db = Database::getInstance();
        $this->tenant_id = $tenant_id ?? ($_SESSION['tenant_id'] ?? null);
    }

    public function setTenantId($tenant_id) {
        $this->tenant_id = $tenant_id;
    }

    private function requireTenantId() {
        if (!$this->tenant_id) {
            throw new Exception("Tenant context is required");
        }
        return $this->tenant_id;
    }

    public function getPaymentById($paymentId) {
        try {
            $sql = "SELECT p.*,
                           l.reference_no, l.principal_amount, l.remaining_balance,
                           c.customer_no, c.first_name, c.last_name,
                           lo.full_name as loan_officer_name,
                           rb.full_name as received_by_name
                    FROM payments p
                    LEFT JOIN loans l ON l.loan_id = p.loan_id AND l.tenant_id = p.tenant_id
                    LEFT JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id = l.tenant_id
                    LEFT JOIN users lo ON lo.user_id = p.loan_officer_id
                    LEFT JOIN users rb ON rb.user_id = p.received_by
                    WHERE p.payment_id = ? AND p.tenant_id = ?";

            return $this->db->queryOne($sql, [$paymentId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get payment error: " . $e->getMessage());
            return null;
        }
    }

    public function getPaymentByORNo($orNo) {
        try {
            $sql = "SELECT p.*, l.reference_no, c.customer_no, c.first_name, c.last_name
                    FROM payments p
                    LEFT JOIN loans l ON l.loan_id = p.loan_id AND l.tenant_id = p.tenant_id
                    LEFT JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id = l.tenant_id
                    WHERE p.or_no = ? AND p.tenant_id = ?";

            return $this->db->queryOne($sql, [$orNo, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get payment by OR error: " . $e->getMessage());
            return null;
        }
    }

    public function recordPayment($loanId, $data) {
        try {
            $tenant_id = $this->requireTenantId();
            $required = ['amount', 'payment_date', 'or_no'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $sql = "SELECT loan_id, remaining_balance FROM loans WHERE loan_id = ? AND tenant_id = ?";
            $loan = $this->db->queryOne($sql, [$loanId, $tenant_id]);
            if (!$loan) {
                throw new Exception("Loan not found");
            }

            $amount = floatval($data['amount']);
            if ($amount <= 0) {
                throw new Exception("Invalid payment amount");
            }

            $existingPayment = $this->getPaymentByORNo($data['or_no']);
            if ($existingPayment) {
                throw new Exception("OR number already exists");
            }

            $method = strtoupper($data['method'] ?? 'CASH');
            $methodDetails = [];

            if ($method === 'CHEQUE') {
                $methodDetails = [
                    'cheque_number' => $data['cheque_number'] ?? null,
                    'cheque_date' => $data['cheque_date'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'account_holder' => $data['account_holder'] ?? null,
                    'bank_reference_no' => $data['bank_reference_no'] ?? null
                ];
            } elseif ($method === 'GCASH' || $method === 'DIGITAL') {
                $methodDetails = [
                    'gcash_reference_no' => $data['gcash_reference_no'] ?? null,
                    'bank_reference_no' => $data['bank_reference_no'] ?? null
                ];
            }

            $sql = "INSERT INTO payments
                    (tenant_id, loan_id, amount, payment_date, method, cheque_number, cheque_date,
                     bank_name, account_holder, bank_reference_no, gcash_reference_no,
                     or_no, loan_officer_id, received_by, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $this->db->exec($sql, [
                $tenant_id,
                $loanId,
                $amount,
                $data['payment_date'],
                $method,
                $methodDetails['cheque_number'] ?? null,
                $methodDetails['cheque_date'] ?? null,
                $methodDetails['bank_name'] ?? null,
                $methodDetails['account_holder'] ?? null,
                $methodDetails['bank_reference_no'] ?? null,
                $methodDetails['gcash_reference_no'] ?? null,
                $data['or_no'],
                $data['loan_officer_id'] ?? null,
                $data['received_by'] ?? null,
                $data['notes'] ?? null
            ]);

            $paymentId = $this->db->lastInsertId();
            $newBalance = max(0, $loan['remaining_balance'] - $amount);
            $newStatus = ($newBalance <= 0) ? 'CLOSED' : 'ACTIVE';

            $sql = "UPDATE loans SET remaining_balance = ?, status = ? WHERE loan_id = ? AND tenant_id = ?";
            $this->db->exec($sql, [$newBalance, $newStatus, $loanId, $tenant_id]);

            return [
                'payment_id' => $paymentId,
                'new_balance' => $newBalance,
                'loan_status' => $newStatus
            ];
        } catch (Exception $e) {
            error_log("Record payment error: " . $e->getMessage());
            throw $e;
        }
    }

    public function editPayment($paymentId, $data) {
        try {
            $payment = $this->getPaymentById($paymentId);
            if (!$payment) {
                throw new Exception("Payment not found");
            }

            $updates = [];
            $params = [];
            $allowed = ['method', 'cheque_number', 'cheque_date', 'bank_name', 'account_holder', 'bank_reference_no', 'gcash_reference_no', 'notes'];
            $amountChanged = false;
            $oldAmount = $payment['amount'];
            $newAmount = $oldAmount;

            if (isset($data['amount'])) {
                $newAmount = floatval($data['amount']);
                if ($newAmount <= 0) {
                    throw new Exception("Invalid payment amount");
                }
                $updates[] = "amount = ?";
                $params[] = $newAmount;
                $amountChanged = true;
            }

            foreach ($allowed as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return true;
            }

            $this->db->beginTransaction();

            try {
                $params[] = $paymentId;
                $params[] = $this->requireTenantId();
                $sql = "UPDATE payments SET " . implode(", ", $updates) . " WHERE payment_id = ? AND tenant_id = ?";
                $this->db->exec($sql, $params);

                if ($amountChanged) {
                    $difference = $newAmount - $oldAmount;
                    $sql = "UPDATE loans SET remaining_balance = remaining_balance - ? WHERE loan_id = ? AND tenant_id = ?";
                    $this->db->exec($sql, [$difference, $payment['loan_id'], $this->tenant_id]);
                }

                $this->db->commit();
                return true;
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("Edit payment error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getLoanPayments($loanId) {
        try {
            $sql = "SELECT p.*, lo.full_name as loan_officer_name, rb.full_name as received_by_name
                    FROM payments p
                    LEFT JOIN users lo ON lo.user_id = p.loan_officer_id
                    LEFT JOIN users rb ON rb.user_id = p.received_by
                    WHERE p.loan_id = ? AND p.tenant_id = ?
                    ORDER BY p.payment_date DESC";

            return $this->db->queryAll($sql, [$loanId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get loan payments error: " . $e->getMessage());
            return [];
        }
    }

    public function getAllPayments($limit = 100, $offset = 0) {
        try {
            $sql = "SELECT p.*,
                           l.reference_no, c.customer_no, c.first_name, c.last_name,
                           lo.full_name as loan_officer_name
                    FROM payments p
                    LEFT JOIN loans l ON l.loan_id = p.loan_id AND l.tenant_id = p.tenant_id
                    LEFT JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id = l.tenant_id
                    LEFT JOIN users lo ON lo.user_id = p.loan_officer_id
                    WHERE p.tenant_id = ?
                    ORDER BY p.payment_date DESC
                    LIMIT ? OFFSET ?";

            return $this->db->queryAll($sql, [$this->requireTenantId(), $limit, $offset]);
        } catch (Exception $e) {
            error_log("Get all payments error: " . $e->getMessage());
            return [];
        }
    }

    public function getPaymentsByDateRange($startDate, $endDate) {
        try {
            $sql = "SELECT p.*,
                           l.reference_no, c.customer_no, c.first_name, c.last_name,
                           lo.full_name as loan_officer_name
                    FROM payments p
                    LEFT JOIN loans l ON l.loan_id = p.loan_id AND l.tenant_id = p.tenant_id
                    LEFT JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id = l.tenant_id
                    LEFT JOIN users lo ON lo.user_id = p.loan_officer_id
                    WHERE p.tenant_id = ? AND DATE(p.payment_date) >= ? AND DATE(p.payment_date) <= ?
                    ORDER BY p.payment_date DESC";

            return $this->db->queryAll($sql, [$this->requireTenantId(), $startDate, $endDate]);
        } catch (Exception $e) {
            error_log("Get payments by date error: " . $e->getMessage());
            return [];
        }
    }

    public function getPaymentSummaryStats() {
        try {
            $sql = "SELECT
                           COUNT(*) as total_payments,
                           SUM(amount) as total_amount,
                           AVG(amount) as avg_amount,
                           MAX(amount) as max_amount,
                           MIN(amount) as min_amount,
                           COUNT(DISTINCT loan_id) as loans_with_payments
                    FROM payments
                    WHERE tenant_id = ?";

            return $this->db->queryOne($sql, [$this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get payment stats error: " . $e->getMessage());
            return null;
        }
    }

    public function getPaymentSummaryByMethod() {
        try {
            $sql = "SELECT method, COUNT(*) as count, SUM(amount) as total_amount
                    FROM payments
                    WHERE tenant_id = ?
                    GROUP BY method
                    ORDER BY total_amount DESC";

            return $this->db->queryAll($sql, [$this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get payment summary by method error: " . $e->getMessage());
            return [];
        }
    }
}

?>
