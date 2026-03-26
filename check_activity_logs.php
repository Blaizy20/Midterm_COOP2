<?php
require_once __DIR__ . '/includes/db.php';

echo "=== Activity Logs Table Diagnosis ===\n\n";

// Check table structure
$columns = fetch_all(q("DESCRIBE activity_logs"));
echo "Columns in activity_logs:\n";
foreach ($columns as $col) {
  echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
}

// Check if tenant_id column exists
$has_tenant_id = false;
foreach ($columns as $col) {
  if ($col['Field'] === 'tenant_id') {
    $has_tenant_id = true;
    break;
  }
}

echo "\n✓ Has tenant_id column: " . ($has_tenant_id ? 'YES' : 'NO') . "\n";

// Count total logs
$count = fetch_one(q("SELECT COUNT(*) as count FROM activity_logs"));
echo "\nTotal logs: " . ($count['count'] ?? 0) . "\n";

// Get logs with NULL tenant_id
if ($has_tenant_id) {
  $null_tenant = fetch_one(q("SELECT COUNT(*) as count FROM activity_logs WHERE tenant_id IS NULL OR tenant_id = 0"));
  echo "Logs with NULL/0 tenant_id: " . ($null_tenant['count'] ?? 0) . "\n";
}

// Sample recent logs
echo "\n=== Recent Activity Logs ===\n";
$sample = fetch_all(q("SELECT log_id, tenant_id, user_role, action, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 5"));
foreach ($sample as $log) {
  $tenant = $log['tenant_id'] ?? 'NULL';
  echo "Log #" . $log['log_id'] . ": action='" . $log['action'] . "', tenant_id=" . $tenant . ", role=" . $log['user_role'] . ", created=" . $log['created_at'] . "\n";
}

echo "\n";
?>
