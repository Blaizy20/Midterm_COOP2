<?php
/**
 * Authentication Service
 * 
 * Handles user authentication for staff portal.
 * Mobile authentication will be handled separately via API gateway (future implementation).
 */

require_once __DIR__ . '/pdo_db.php';

class AuthService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Authenticate a staff user (non-customer users only)
     * 
     * @param string $username
     * @param string $password
     * @param int $tenant_id (optional - for multi-tenant support)
     * @return array|false User data or false on failure
     */
    public function authenticateStaffUser($username, $password, $tenant_id = null) {
        try {
            // Ensure user is NOT a customer (staff-only web portal)
            if ($tenant_id) {
                $sql = "SELECT user_id, tenant_id, username, password_hash, full_name, role, email, contact_no, is_active 
                        FROM users 
                        WHERE username = ? AND tenant_id = ? AND role != 'CUSTOMER' AND is_active = 1";
                $user = $this->db->queryOne($sql, [$username, $tenant_id]);
            } else {
                // Owner/global login path - prefer SUPER_ADMIN/ADMIN records when usernames collide.
                $sql = "SELECT user_id, tenant_id, username, password_hash, full_name, role, email, contact_no, is_active
                        FROM users
                        WHERE username = ? AND role != 'CUSTOMER' AND is_active = 1
                        ORDER BY
                          CASE role
                            WHEN 'SUPER_ADMIN' THEN 0
                            WHEN 'ADMIN' THEN 1
                            ELSE 2
                          END,
                          user_id ASC
                        LIMIT 1";
                $user = $this->db->queryOne($sql, [$username]);
            }
            
            if (!$user) {
                return false;
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                return false;
            }
            
            // Don't return password hash in response
            unset($user['password_hash']);
            return $user;
            
        } catch (Exception $e) {
            error_log("Auth error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authenticate a customer (for mobile app integration later)
     * This method is prepared for future mobile API usage.
     * 
     * @param string $username
     * @param string $password
     * @param int $tenant_id (optional - for multi-tenant support)
     * @return array|false Customer user data or false on failure
     */
    public function authenticateCustomer($username, $password, $tenant_id = null) {
        try {
            // Check credentials for CUSTOMER role users
            if ($tenant_id) {
                $sql = "SELECT u.user_id, u.tenant_id, u.username, u.password_hash, u.full_name, u.email, 
                               u.contact_no, c.customer_id, c.customer_no, c.first_name, c.last_name
                        FROM users u
                        LEFT JOIN customers c ON c.user_id = u.user_id AND c.tenant_id = u.tenant_id
                        WHERE u.username = ? AND u.tenant_id = ? AND u.role = 'CUSTOMER' AND u.is_active = 1";
                $user = $this->db->queryOne($sql, [$username, $tenant_id]);
            } else {
                $sql = "SELECT u.user_id, u.tenant_id, u.username, u.password_hash, u.full_name, u.email, 
                               u.contact_no, c.customer_id, c.customer_no, c.first_name, c.last_name
                        FROM users u
                        LEFT JOIN customers c ON c.user_id = u.user_id AND c.tenant_id = u.tenant_id
                        WHERE u.username = ? AND u.role = 'CUSTOMER' AND u.is_active = 1";
                $user = $this->db->queryOne($sql, [$username]);
            }
            
            if (!$user) {
                return false;
            }
            
            if (!password_verify($password, $user['password_hash'])) {
                return false;
            }
            
            // Check if customer account is active
            if (!$user['customer_id'] || empty($user['customer_id'])) {
                return false;
            }
            
            unset($user['password_hash']);
            return $user;
            
        } catch (Exception $e) {
            error_log("Customer auth error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user by ID (for session validation)
     */
    public function getUserById($userId) {
        try {
            $sql = "SELECT user_id, tenant_id, username, full_name, role, email, contact_no, is_active 
                    FROM users WHERE user_id = ?";
            
            return $this->db->queryOne($sql, [$userId]);
            
        } catch (Exception $e) {
            error_log("Get user error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if password meets strength requirements
     */
    public static function isPasswordStrong($password) {
        if (strlen($password) < 8) return false;
        if (!preg_match('/[A-Z]/', $password)) return false;
        if (!preg_match('/[a-z]/', $password)) return false;
        if (!preg_match('/[0-9]/', $password)) return false;
        if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
        return true;
    }
    
    /**
     * Hash password securely
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Generate password reset token
     */
    public function generateResetToken($userId) {
        try {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $sql = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE user_id = ?";
            $this->db->exec($sql, [$token, $expiry, $userId]);
            
            return $token;
        } catch (Exception $e) {
            error_log("Token generation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify and use reset token
     */
    public function verifyAndResetPassword($token, $newPassword) {
        try {
            if (!self::isPasswordStrong($newPassword)) {
                return false;
            }
            
            $sql = "SELECT user_id FROM users 
                    WHERE reset_token = ? AND reset_token_expiry > NOW()";
            $user = $this->db->queryOne($sql, [$token]);
            
            if (!$user) {
                return false;
            }
            
            $hashedPassword = self::hashPassword($newPassword);
            $sql = "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL 
                    WHERE user_id = ?";
            $this->db->exec($sql, [$hashedPassword, $user['user_id']]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            return false;
        }
    }
}

?>
