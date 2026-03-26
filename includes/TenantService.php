<?php
/**
 * Tenant Service
 * 
 * Handles multi-tenant operations and tenant context management.
 */

require_once __DIR__ . '/pdo_db.php';

class TenantService {
    private $db;
    private $current_tenant_id;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadCurrentTenant();
    }
    
    /**
     * Load current tenant from session
     */
    private function loadCurrentTenant() {
        $this->current_tenant_id = $_SESSION['tenant_id'] ?? null;
    }
    
    /**
     * Get current tenant ID
     */
    public function getCurrentTenantId() {
        return $this->current_tenant_id;
    }
    
    /**
     * Set current tenant ID
     */
    public function setCurrentTenantId($tenant_id) {
        $this->current_tenant_id = $tenant_id;
        $_SESSION['tenant_id'] = $tenant_id;
    }
    
    /**
     * Get tenant by ID with validation
     */
    public function getTenantById($tenant_id) {
        try {
            $sql = "SELECT tenant_id, tenant_name, subdomain, display_name, logo_path, primary_color, is_active
                    FROM tenants 
                    WHERE tenant_id = ? AND is_active = 1";
            
            $tenant = $this->db->queryOne($sql, [$tenant_id]);
            return $tenant ?: null;
        } catch (Exception $e) {
            error_log("Tenant lookup error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all active tenants
     */
    public function getAllActiveTenants() {
        try {
            $sql = "SELECT tenant_id, tenant_name, display_name, subdomain, is_active
                    FROM tenants 
                    WHERE is_active = 1
                    ORDER BY tenant_name";
            
            return $this->db->queryAll($sql, []);
        } catch (Exception $e) {
            error_log("Get all tenants error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get tenant by subdomain
     */
    public function getTenantBySubdomain($subdomain) {
        try {
            $sql = "SELECT tenant_id, tenant_name, subdomain, display_name, logo_path, primary_color, is_active
                    FROM tenants 
                    WHERE subdomain = ? AND is_active = 1";
            
            $tenant = $this->db->queryOne($sql, [$subdomain]);
            return $tenant ?: null;
        } catch (Exception $e) {
            error_log("Tenant subdomain lookup error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create new tenant
     */
    public function createTenant($tenant_name, $subdomain, $display_name, $logo_path = null, $primary_color = '#2c3ec5') {
        try {
            $sql = "INSERT INTO tenants (tenant_name, subdomain, display_name, logo_path, primary_color, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)";
            
            $result = $this->db->execute($sql, [$tenant_name, $subdomain, $display_name, $logo_path, $primary_color]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("Create tenant error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update tenant
     */
    public function updateTenant($tenant_id, $data) {
        try {
            $allowed_fields = ['tenant_name', 'subdomain', 'display_name', 'logo_path', 'primary_color', 'is_active'];
            $updates = [];
            $values = [];
            
            foreach ($allowed_fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                return false;
            }
            
            $values[] = $tenant_id;
            $sql = "UPDATE tenants SET " . implode(", ", $updates) . " WHERE tenant_id = ?";
            
            return $this->db->execute($sql, $values);
        } catch (Exception $e) {
            error_log("Update tenant error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deactivate tenant (soft delete)
     */
    public function deactivateTenant($tenant_id) {
        try {
            $sql = "UPDATE tenants SET is_active = 0 WHERE tenant_id = ?";
            return $this->db->execute($sql, [$tenant_id]);
        } catch (Exception $e) {
            error_log("Deactivate tenant error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user belongs to tenant
     */
    public function userBelongsToTenant($user_id, $tenant_id) {
        try {
            $sql = "SELECT 1 FROM users WHERE user_id = ? AND tenant_id = ? LIMIT 1";
            return $this->db->queryOne($sql, [$user_id, $tenant_id]) ? true : false;
        } catch (Exception $e) {
            error_log("User tenant check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all users for a tenant
     */
    public function getTenantUsers($tenant_id) {
        try {
            $sql = "SELECT user_id, username, full_name, role, email, is_active
                    FROM users 
                    WHERE tenant_id = ?
                    ORDER BY full_name";
            
            return $this->db->queryAll($sql, [$tenant_id]);
        } catch (Exception $e) {
            error_log("Get tenant users error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get tenant statistics
     */
    public function getTenantStats($tenant_id) {
        try {
            $stats = [];
            
            // Total customers
            $sql = "SELECT COUNT(*) as count FROM customers WHERE tenant_id = ? AND is_active = 1";
            $stats['total_customers'] = $this->db->queryOne($sql, [$tenant_id])['count'] ?? 0;
            
            // Total loans
            $sql = "SELECT COUNT(*) as count FROM loans WHERE tenant_id = ?";
            $stats['total_loans'] = $this->db->queryOne($sql, [$tenant_id])['count'] ?? 0;
            
            // Active loans
            $sql = "SELECT COUNT(*) as count FROM loans WHERE tenant_id = ? AND status = 'ACTIVE'";
            $stats['active_loans'] = $this->db->queryOne($sql, [$tenant_id])['count'] ?? 0;
            
            // Total payments
            $sql = "SELECT SUM(amount) as total FROM payments WHERE tenant_id = ?";
            $result = $this->db->queryOne($sql, [$tenant_id]);
            $stats['total_payments'] = $result['total'] ? (float)$result['total'] : 0;
            
            // Total loan amount
            $sql = "SELECT SUM(principal_amount) as total FROM loans WHERE tenant_id = ?";
            $result = $this->db->queryOne($sql, [$tenant_id]);
            $stats['total_loan_amount'] = $result['total'] ? (float)$result['total'] : 0;
            
            return $stats;
        } catch (Exception $e) {
            error_log("Get tenant stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verify tenant access with user ID
     */
    public function verifyTenantAccess($user_id) {
        try {
            $sql = "SELECT tenant_id FROM users WHERE user_id = ? AND is_active = 1";
            $result = $this->db->queryOne($sql, [$user_id]);
            
            if ($result) {
                $this->setCurrentTenantId($result['tenant_id']);
                return $result['tenant_id'];
            }
            return null;
        } catch (Exception $e) {
            error_log("Verify tenant access error: " . $e->getMessage());
            return null;
        }
    }
}
?>
