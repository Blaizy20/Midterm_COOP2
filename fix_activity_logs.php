<?php
require_once __DIR__ . '/includes/db.php';

echo "=== Fixing Activity Logs Table ===\n\n";

$conn = db();

// Check if tenant_id column exists
$result = $conn->query("SHOW COLUMNS FROM activity_logs LIKE 'tenant_id'");
$has_tenant_id = $result && $result->num_rows > 0;

if (!$has_tenant_id) {
  echo "❌ tenant_id column missing - adding it now...\n";
  
  // Add tenant_id column
  $sql = "ALTER TABLE activity_logs 
          ADD COLUMN tenant_id INT NOT NULL DEFAULT 1 AFTER log_id";
  
  if ($conn->query($sql)) {
    echo "✓ Added tenant_id column\n";
  } else {
    echo "✗ Error adding tenant_id: " . $conn->error . "\n";
    exit(1);
  }
  
  // Add index on tenant_id
  $sql = "ALTER TABLE activity_logs ADD KEY idx_tenant_id (tenant_id)";
  if ($conn->query($sql)) {
    echo "✓ Added index on tenant_id\n";
  } else {
    echo "Note: Index already exists\n";
  }
} else {
  echo "✓ tenant_id column already exists\n";
}

// Check if foreign key exists
$result = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                        WHERE TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'tenant_id'");

if ($result && $result->num_rows === 0) {
  echo "\nAdding foreign key constraint...\n";
  $sql = "ALTER TABLE activity_logs 
          ADD CONSTRAINT fk_activity_tenant 
          FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE";
  
  if ($conn->query($sql)) {
    echo "✓ Added foreign key constraint\n";
  } else {
    echo "Note: Foreign key constraint already exists or error: " . $conn->error . "\n";
  }
} else {
  echo "✓ Foreign key constraint already exists\n";
}

// Get sample of logs
echo "\n=== Sample Activity Logs ===\n";
$sample = $conn->query("SELECT log_id, tenant_id, action, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 3");
if ($sample && $sample->num_rows > 0) {
  while ($log = $sample->fetch_assoc()) {
    echo "Log #" . $log['log_id'] . ": action='" . $log['action'] . "', tenant_id=" . $log['tenant_id'] . "\n";
  }
}

echo "\n✓ Fix complete! Activity logs table is now ready.\n";
?>
