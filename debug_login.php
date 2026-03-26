<?php
/**
 * Login Debug Script
 * Test login with admin_a and admin_b
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
.test { padding: 15px; margin: 10px 0; background: white; border: 1px solid #ddd; }
.pass { border-left: 4px solid green; }
.fail { border-left: 4px solid red; }
code { background: #f0f0f0; padding: 4px; }
</style></head><body>";

echo "<h1>🔐 Login Debug - Testing admin_a and admin_b</h1>";

// Test login logic
require_once 'includes/db.php';

$test_cases = [
    ['username' => 'admin_a', 'password' => 'password'],
    ['username' => 'admin_b', 'password' => 'password'],
];

foreach ($test_cases as $test) {
    echo "<div class='test'>";
    echo "<h3>Testing: " . htmlspecialchars($test['username']) . " / " . htmlspecialchars($test['password']) . "</h3>";
    
    $username = $test['username'];
    $password = $test['password'];
    
    try {
        // Step 1: Query user
        echo "<p><strong>Step 1: Query database for username</strong></p>";
        $stmt = q("SELECT user_id, tenant_id, username, password_hash, full_name, role, is_active FROM users WHERE username=? AND is_active=1", "s", [$username]);
        $user = fetch_one($stmt);
        
        if (!$user) {
            echo "<p class='fail'>✗ No user found with username='$username' and is_active=1</p>";
            
            // Check if user exists at all
            echo "<p><strong>Debug: Checking if user exists (any status)</strong></p>";
            $stmt2 = q("SELECT user_id, username, is_active FROM users WHERE username=?", "s", [$username]);
            $user2 = fetch_one($stmt2);
            
            if ($user2) {
                echo "<p>User exists: ID=" . $user2['user_id'] . ", is_active=" . $user2['is_active'] . "</p>";
            } else {
                echo "<p>User doesn't exist at all</p>";
            }
        } else {
            echo "<p class='pass'>✓ User found</p>";
            echo "<pre>ID: " . $user['user_id'] . "
Tenant: " . $user['tenant_id'] . "
Username: " . htmlspecialchars($user['username']) . "
Full Name: " . htmlspecialchars($user['full_name']) . "
Role: " . $user['role'] . "
is_active: " . $user['is_active'] . "</pre>";
            
            // Step 2: Check role
            echo "<p><strong>Step 2: Check if role is not CUSTOMER</strong></p>";
            if ($user['role'] === 'CUSTOMER') {
                echo "<p class='fail'>✗ Role is CUSTOMER (rejected)</p>";
            } else {
                echo "<p class='pass'>✓ Role is " . $user['role'] . " (accepted)</p>";
                
                // Step 3: Verify password
                echo "<p><strong>Step 3: Verify password</strong></p>";
                echo "<p>Hash: " . substr($user['password_hash'], 0, 20) . "...</p>";
                
                if (password_verify($password, $user['password_hash'])) {
                    echo "<p class='pass'>✓ Password verified!</p>";
                    echo "<p style='background: #d4edda; padding: 10px; border-radius: 4px;'><strong>✅ LOGIN SHOULD WORK!</strong></p>";
                } else {
                    echo "<p class='fail'>✗ Password verification failed</p>";
                    echo "<p>Expected password hash: " . $user['password_hash'] . "</p>";
                }
            }
        }
    } catch (Exception $e) {
        echo "<p class='fail'>✗ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    echo "</div>";
}

echo "<h2>What to do:</h2>";
echo "<ol>";
echo "<li>If any shows ✅ LOGIN SHOULD WORK - go to <a href='/staff/login.php'>/staff/login.php</a> and try it</li>";
echo "<li>If password verification failed - the hash is wrong, we need to recreate users</li>";
echo "<li>If user not found - users were deleted, we need to recreate users</li>";
echo "</ol>";

echo "</body></html>";
?>
