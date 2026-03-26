<?php
/**
 * Login Diagnostic Script
 * Run at: http://localhost/diagnose_login.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><style>
body { font-family: sans-serif; margin: 20px; }
.test { padding: 15px; margin: 10px 0; border-left: 4px solid #333; }
.pass { border-left-color: green; background: #f0fff0; }
.fail { border-left-color: red; background: #fff0f0; }
code { background: #f4f4f4; padding: 2px 6px; }
</style></head><body>";

echo "<h1>🔍 Login System Diagnostic</h1>";

// Test 1: Database Connection
echo "<div class='test'>";
echo "<h3>Test 1: Database Connection</h3>";
try {
    require_once 'includes/db.php';
    $conn = db();
    echo "<div class='pass'>✓ Database connected (MySQLi)</div>";
} catch (Exception $e) {
    echo "<div class='fail'>✗ Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}
echo "</div>";

// Test 2: Check users table
echo "<div class='test'>";
echo "<h3>Test 2: Users Table Contents</h3>";
try {
    $stmt = $conn->prepare("SELECT user_id, tenant_id, username, full_name, role, is_active FROM users");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo "<div class='fail'>✗ No users in database!</div>";
        echo "<p>Run this SQL to create a user:</p>";
        echo "<code>INSERT INTO tenants (tenant_name, subdomain, display_name) VALUES ('Company A', 'company-a', 'Company A');</code><br>";
        echo "<code>INSERT INTO users (tenant_id, username, password_hash, full_name, role, email, is_active) VALUES (1, 'admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'ADMIN', 'admin@company.com', 1);</code>";
    } else {
        echo "<div class='pass'>✓ " . $result->num_rows . " user(s) found</div>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Tenant</th><th>Username</th><th>Name</th><th>Role</th><th>Active</th></tr>";
        
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['user_id'] . "</td>";
            echo "<td>" . $row['tenant_id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
            echo "<td>" . $row['role'] . "</td>";
            echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='fail'>✗ Query error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 3: Check tenants table
echo "<div class='test'>";
echo "<h3>Test 3: Tenants Table</h3>";
try {
    $stmt = $conn->prepare("SELECT tenant_id, tenant_name, subdomain, is_active FROM tenants");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo "<div class='fail'>✗ No tenants in database!</div>";
    } else {
        echo "<div class='pass'>✓ " . $result->num_rows . " tenant(s) found</div>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Name</th><th>Subdomain</th><th>Active</th></tr>";
        
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['tenant_id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['tenant_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['subdomain']) . "</td>";
            echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='fail'>✗ Query error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 4: Test password verification
echo "<div class='test'>";
echo "<h3>Test 4: Password Verification</h3>";
$test_password = 'password';
$test_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

if (password_verify($test_password, $test_hash)) {
    echo "<div class='pass'>✓ Password 'password' correctly matches the hash</div>";
} else {
    echo "<div class='fail'>✗ Password verification failed!</div>";
}
echo "</div>";

// Test 5: Test login query simulation
echo "<div class='test'>";
echo "<h3>Test 5: Login Query Simulation</h3>";
try {
    $test_username = 'admin';
    $stmt = $conn->prepare("SELECT user_id, tenant_id, username, password_hash, full_name, role, is_active FROM users WHERE username=? AND is_active=1");
    $stmt->bind_param("s", $test_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        echo "<div class='fail'>✗ No user found with username='admin' and is_active=1</div>";
    } else {
        echo "<div class='pass'>✓ User found: " . htmlspecialchars($user['username']) . " (" . $user['role'] . ")</div>";
        
        if (password_verify('password', $user['password_hash'])) {
            echo "<div class='pass'>✓ Password verification would succeed!</div>";
            echo "<div class='pass'>✓ LOGIN SHOULD WORK - Try: admin / password</div>";
        } else {
            echo "<div class='fail'>✗ Password verification would fail</div>";
        }
    }
} catch (Exception $e) {
    echo "<div class='fail'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

echo "<h2>Next Step</h2>";
echo "<p><a href='/staff/login.php'>Go to Staff Login</a></p>";
echo "</body></html>";
?>
