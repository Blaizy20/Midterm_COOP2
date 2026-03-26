<?php
/**
 * Customer Service
 *
 * Handles all customer-related data operations.
 * Tenant context is required for tenant-owned operations.
 */

require_once __DIR__ . '/pdo_db.php';

class CustomerService {
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

    public function getCustomerById($customerId) {
        try {
            $sql = "SELECT * FROM customers WHERE customer_id = ? AND tenant_id = ? AND is_active = 1";
            return $this->db->queryOne($sql, [$customerId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get customer error: " . $e->getMessage());
            return null;
        }
    }

    public function getCustomerByUserId($userId) {
        try {
            $sql = "SELECT * FROM customers WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
            return $this->db->queryOne($sql, [$userId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get customer by user error: " . $e->getMessage());
            return null;
        }
    }

    public function getCustomerByNumber($customerNo) {
        try {
            $sql = "SELECT * FROM customers WHERE customer_no = ? AND tenant_id = ? AND is_active = 1";
            return $this->db->queryOne($sql, [$customerNo, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get customer by number error: " . $e->getMessage());
            return null;
        }
    }

    public function createCustomer($data) {
        try {
            $tenant_id = $this->requireTenantId();
            $required = ['customer_no', 'first_name', 'last_name', 'contact_no'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $existing = $this->getCustomerByNumber($data['customer_no']);
            if ($existing) {
                throw new Exception("Customer number already exists");
            }

            $sql = "INSERT INTO customers
                    (tenant_id, user_id, customer_no, first_name, last_name, contact_no, email,
                     province, city, barangay, street, created_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)";

            $userId = $data['user_id'] ?? null;
            $this->db->exec($sql, [
                $tenant_id,
                $userId,
                $data['customer_no'],
                $data['first_name'],
                $data['last_name'],
                $data['contact_no'],
                $data['email'] ?? null,
                $data['province'] ?? null,
                $data['city'] ?? null,
                $data['barangay'] ?? null,
                $data['street'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("Create customer error: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateCustomer($customerId, $data) {
        try {
            $updates = [];
            $params = [];
            $allowed = ['first_name', 'last_name', 'contact_no', 'email', 'province', 'city', 'barangay', 'street'];

            foreach ($allowed as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return true;
            }

            $params[] = $customerId;
            $params[] = $this->requireTenantId();
            $sql = "UPDATE customers SET " . implode(", ", $updates) . " WHERE customer_id = ? AND tenant_id = ?";

            $this->db->exec($sql, $params);
            return true;
        } catch (Exception $e) {
            error_log("Update customer error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getAllCustomers($activeOnly = true, $limit = 100, $offset = 0) {
        try {
            $where = "WHERE tenant_id = ?";
            $params = [$this->requireTenantId()];
            if ($activeOnly) {
                $where .= " AND is_active = 1";
            }

            $sql = "SELECT * FROM customers $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            return $this->db->queryAll($sql, $params);
        } catch (Exception $e) {
            error_log("Get all customers error: " . $e->getMessage());
            return [];
        }
    }

    public function searchCustomers($query, $limit = 20) {
        try {
            $searchTerm = "%$query%";
            $sql = "SELECT * FROM customers
                    WHERE tenant_id = ?
                    AND (customer_no LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
                    AND is_active = 1
                    ORDER BY created_at DESC
                    LIMIT ?";

            return $this->db->queryAll($sql, [$this->requireTenantId(), $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
        } catch (Exception $e) {
            error_log("Search customers error: " . $e->getMessage());
            return [];
        }
    }

    public function countCustomers($activeOnly = true) {
        try {
            $where = "WHERE tenant_id = ?";
            if ($activeOnly) {
                $where .= " AND is_active = 1";
            }
            $sql = "SELECT COUNT(*) as count FROM customers $where";
            $result = $this->db->queryOne($sql, [$this->requireTenantId()]);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("Count customers error: " . $e->getMessage());
            return 0;
        }
    }
}

?>
