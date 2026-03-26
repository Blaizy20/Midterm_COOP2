<?php
/**
 * Tenant Middleware
 * 
 * Enforces multi-tenant isolation and access control.
 * This middleware ensures all database queries filter by the current tenant.
 */

require_once __DIR__ . '/TenantService.php';
require_once __DIR__ . '/AuthService.php';

class TenantMiddleware {
    private $tenantService;
    private $authService;
    
    public function __construct() {
        $this->tenantService = new TenantService();
        $this->authService = new AuthService();
    }
    
    /**
     * Initialize tenant context from authenticated user
     * Call this after login and in every session start
     */
    public function initializeTenantContext() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        $tenant_id = $this->tenantService->verifyTenantAccess($_SESSION['user_id']);
        
        if (!$tenant_id) {
            // User doesn't belong to any valid tenant
            $this->destroySession();
            return false;
        }
        
        $_SESSION['tenant_id'] = $tenant_id;
        return true;
    }
    
    /**
     * Set tenant context from login
     */
    public function setTenantFromLogin($user_id) {
        $tenant_id = $this->tenantService->verifyTenantAccess($user_id);
        
        if ($tenant_id) {
            $_SESSION['tenant_id'] = $tenant_id;
            return true;
        }
        
        return false;
    }
    
    /**
     * Require valid tenant context
     * Redirects to login if no valid tenant context
     */
    public function requireTenantContext() {
        if (!isset($_SESSION['tenant_id']) || !isset($_SESSION['user_id'])) {
            $this->destroySession();
            header("Location: " . APP_BASE . "/staff/login.php?error=invalid_session");
            exit;
        }
        
        // Validate tenant still exists and is active
        $tenant = $this->tenantService->getTenantById($_SESSION['tenant_id']);
        if (!$tenant) {
            $this->destroySession();
            header("Location: " . APP_BASE . "/staff/login.php?error=tenant_inactive");
            exit;
        }
        
        return $_SESSION['tenant_id'];
    }
    
    /**
     * Get current tenant context
     */
    public function getCurrentTenant() {
        return $this->tenantService->getCurrentTenantId();
    }
    
    /**
     * Validate resource ownership by tenant
     * Checks if a resource (loan, customer, etc.) belongs to the user's tenant
     */
    public function validateResourceTenant($resource_type, $resource_id, $tenant_id = null) {
        if (!$tenant_id) {
            $tenant_id = $this->getCurrentTenant();
        }
        
        if (!$tenant_id) {
            return false;
        }
        
        try {
            $db = Database::getInstance();
            $sql = "SELECT 1 FROM $resource_type WHERE {$resource_type}_id = ? AND tenant_id = ? LIMIT 1";
            $result = $db->queryOne($sql, [$resource_id, $tenant_id]);
            return $result ? true : false;
        } catch (Exception $e) {
            error_log("Resource validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate loan belongs to tenant
     */
    public function validateLoanTenant($loan_id) {
        return $this->validateResourceTenant('loans', $loan_id);
    }
    
    /**
     * Validate customer belongs to tenant
     */
    public function validateCustomerTenant($customer_id) {
        return $this->validateResourceTenant('customers', $customer_id);
    }
    
    /**
     * Validate user belongs to tenant and check role access
     */
    public function validateUserAccess($user_id, $required_role = null) {
        $tenant_id = $this->getCurrentTenant();
        
        if (!$tenant_id) {
            return false;
        }
        
        try {
            $db = Database::getInstance();
            $sql = "SELECT role FROM users WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
            $user = $db->queryOne($sql, [$user_id, $tenant_id]);
            
            if (!$user) {
                return false;
            }
            
            if ($required_role && $user['role'] !== $required_role) {
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log("User access validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check user role
     */
    public function checkUserRole($user_id, $allowed_roles = []) {
        $tenant_id = $this->getCurrentTenant();
        
        if (!$tenant_id) {
            return false;
        }
        
        try {
            $db = Database::getInstance();
            $sql = "SELECT role FROM users WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
            $user = $db->queryOne($sql, [$user_id, $tenant_id]);
            
            if (!$user) {
                return false;
            }
            
            if (!empty($allowed_roles) && !in_array($user['role'], $allowed_roles)) {
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Role check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add tenant_id to WHERE clause for queries
     * Helper function for building secure queries
     */
    public static function addTenantFilter($sql, $tenant_id, $table_alias = '') {
        $table = $table_alias ? $table_alias : '';
        $column = $table ? "{$table}.tenant_id" : "tenant_id";
        
        if (stripos($sql, 'WHERE') !== false) {
            return str_ireplace('WHERE', "WHERE {$column} = {$tenant_id} AND", $sql);
        }
        
        return $sql . " WHERE {$column} = {$tenant_id}";
    }
    
    /**
     * Destroy session and clear tenant context
     */
    public function destroySession() {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
        if (isset($_SESSION['tenant_id'])) {
            unset($_SESSION['tenant_id']);
        }
        session_destroy();
    }
    
    /**
     * Require staff login with tenant context
     */
    public function requireStaffLogin() {
        if (!isset($_SESSION['user_id'])) {
            $_SESSION['return_url'] = $_SERVER['REQUEST_URI'];
            header("Location: " . APP_BASE . "/staff/login.php");
            exit;
        }
        
        return $this->requireTenantContext();
    }
    
    /**
     * Log activity for audit trail
     */
    public function logActivity($user_id, $user_role, $action, $description, $loan_id = null, $customer_id = null, $reference_no = null) {
        try {
            $db = Database::getInstance();
            $tenant_id = $this->getCurrentTenant();
            
            $sql = "INSERT INTO activity_logs (tenant_id, user_id, user_role, action, description, loan_id, customer_id, reference_no, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            return $db->execute($sql, [$tenant_id, $user_id, $user_role, $action, $description, $loan_id, $customer_id, $reference_no]);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
            return false;
        }
    }
}

// Require database for middleware
require_once __DIR__ . '/pdo_db.php';
?>
