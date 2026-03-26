<?php
/**
 * SUPER_ADMIN Login Debug Script
 * Tests the SUPER_ADMIN login process step by step
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/loan_helpers.php';

echo "<!DOCTYPE html><html><head><style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
.test { padding: 15px; margin: 10px 0; background: white; border: 1px solid #ddd; border-radius: 4px; }
.pass { border-left: 4px solid green; color: green; }
.fail { border-left: 4px solid red; color: red; }
.warn { border-left: 4px solid orange; color: #cc7700; }
code { background: #f0f0f0; padding: 4px 8px; border-radius: 3px; }
pre { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
</style></head><body>";

echo "<h1>🔐 SUPER_ADMIN Login Debug</h1>";

// Get all SUPER_ADMIN users in database
echo "<div class='test'>";
echo "<h2>Step 1: Check all SUPER_ADMIN users in database</h2>";
try {
    $result = q("SELECT user_id, tenant_id, username, full_name, role, is_active, created_at FROM users WHERE role='SUPER_ADMIN'", "");
    $superadmin_users = fetch_all($result);
    
    if (empty($superadmin_users)) {
        echo "<p class='fail'>✗ NO SUPER_ADMIN users found in database</p>";
    } else {
        echo "<p class='pass'>✓ Found " . count($superadmin_users) . " SUPER_ADMIN user(s)</p>";
        foreach ($superadmin_users as $user) {
            echo "<div style='background: #f0f9f0; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
            echo "<strong>User ID:</strong> " . $user['user_id'] . "<br>";
            echo "<strong>Tenant ID:</strong> " . $user['tenant_id'] . "<br>";
            echo "<strong>Username:</strong> <code>" . htmlspecialchars($user['username']) . "</code><br>";
            echo "<strong>Full Name:</strong> " . htmlspecialchars($user['full_name']) . "<br>";
            echo "<strong>Role:</strong> <code>" . $user['role'] . "</code><br>";
            echo "<strong>Status:</strong> " . ($user['is_active'] ? '<span class="pass">Active ✓</span>' : '<span class="fail">Inactive ✗</span>') . "<br>";
            echo "<strong>Created:</strong> " . $user['created_at'] . "<br>";
            echo "</div>";
        }
    }
} catch (Exception $e) {
    echo "<p class='fail'>✗ Error querying database: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// Test login with a SUPER_ADMIN user
echo "<div class='test'>";
echo "<h2>Step 2: Simulate login process for each SUPER_ADMIN user</h2>";

if (!empty($superadmin_users)) {
    foreach ($superadmin_users as $test_user) {
        echo "<div style='background: #f9f9f9; padding: 15px; margin: 15px 0; border-left: 3px solid #2c3ec5;'>";
        echo "<h3>Testing " . htmlspecialchars($test_user['username']) . "</h3>";
        
        // Simulate login query (without password, to test step by step)
        echo "<p><strong>Step 2a: Query user by username and active status</strong></p>";
        try {
            $stmt = q("SELECT * FROM users WHERE username=? AND is_active=1", "s", [$test_user['username']]);
            $login_user = fetch_one($stmt);
            
            if (!$login_user) {
                echo "<p class='fail'>✗ User not found OR not active</p>";
                // Debug check
                $check = q("SELECT user_id, is_active FROM users WHERE username=?", "s", [$test_user['username']]);
                $check_result = fetch_one($check);
                if ($check_result) {
                    echo "<p class='warn'>⚠ User exists but is_active=" . $check_result['is_active'] . "</p>";
                } else {
                    echo "<p class='fail'>⚠ User doesn't exist at all!</p>";
                }
            } else {
                echo "<p class='pass'>✓ User found and is active</p>";
                
                // Step 2b: Check if not CUSTOMER
                echo "<p><strong>Step 2b: Check role is not CUSTOMER</strong></p>";
                if ($login_user['role'] === 'CUSTOMER') {
                    echo "<p class='fail'>✗ Role is CUSTOMER (rejected)</p>";
                } else {
                    echo "<p class='pass'>✓ Role is " . $login_user['role'] . " (accepted)</p>";
                    
                    // Step 2c: Check if role is in allowed list
                    echo "<p><strong>Step 2c: Check if role is in allowed roles list</strong></p>";
                    $allowed_roles = ['SUPER_ADMIN', 'ADMIN', 'TENANT', 'MANAGER', 'CREDIT_INVESTIGATOR', 'LOAN_OFFICER', 'CASHIER'];
                    if (in_array($login_user['role'], $allowed_roles, true)) {
                        echo "<p class='pass'>✓ Role <code>" . $login_user['role'] . "</code> is in allowed list</p>";
                        
                        // Step 2d: Show password hash info
                        echo "<p><strong>Step 2d: Password hash information</strong></p>";
                        if (isset($login_user['password_hash']) && !empty($login_user['password_hash'])) {
                            echo "<p class='pass'>✓ Password hash exists: " . substr($login_user['password_hash'], 0, 30) . "...</p>";
                            
                            // Step 2e: Test password verify with common passwords
                            echo "<p><strong>Step 2e: Test password verification with common passwords</strong></p>";
                            $test_passwords = ['password', 'admin', 'admin123', '12345678', 'password123', 'superadmin', '123456'];
                            
                            foreach ($test_passwords as $test_pw) {
                                $verify = password_verify($test_pw, $login_user['password_hash']);
                                $status = $verify ? '<span class="pass">✓ WORKS</span>' : '✗';
                                echo "<p style='margin: 5px 0;'>Testing <code>$test_pw</code>: $status</p>";
                            }
                            
                            echo "<p class='warn'><strong>💡 Hint:</strong> If none of the above passwords work, the password hash in the database might be from a different password.</p>";
                        } else {
                            echo "<p class='fail'>✗ No password hash found!</p>";
                        }
                    } else {
                        echo "<p class='fail'>✗ Role <code>" . $login_user['role'] . "</code> is NOT in allowed list</p>";
                    }
                }
            }
        } catch (Exception $e) {
            echo "<p class='fail'>✗ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        echo "</div>";
    }
} else {
    echo "<p class='warn'>⚠ No SUPER_ADMIN users to test</p>";
}

echo "</div>";

echo "<div class='test' style='background: #fffacd;'>";
echo "<h2>💡 Troubleshooting Steps</h2>";
echo "<ol>";
echo "<li><strong>Check username spelling:</strong> Make sure you're typing the exact username (case-sensitive)</li>";
echo "<li><strong>Check password:</strong> Verify you're using the correct password. Common issue: caps lock or extra spaces</li>";
echo "<li><strong>Check is_active:</strong> The user account must have is_active=1. If it shows 0, activate it with: <code>UPDATE users SET is_active=1 WHERE user_id=X</code></li>";
echo "<li><strong>Verify password hash:</strong> If no passwords work above, the password might be wrong. Update it with:<br>";
echo "<code>UPDATE users SET password_hash='PASSWORD_HASH' WHERE user_id=X</code><br>";
echo "Generate hash with: <code>echo password_hash('yourpassword', PASSWORD_DEFAULT);</code></li>";
echo "</ol>";
echo "</div>";

echo "<div class='test' style='background: #e8f4f8;'>";
echo "<h2>Quick Fix: Reset SUPER_ADMIN Password</h2>";
echo "<p>Run this SQL to set password to '<code>password</code>':</p>";
echo "<pre>UPDATE users SET password_hash='\$2y\$10\$G/dPmVD8hVKznw9Dxl6nH.K0.yJ2OlVRxhZJlBCnjXmIr8EqX9d6G' WHERE role='SUPER_ADMIN';</pre>";
echo "</div>";

echo "</body></html>";
?>
