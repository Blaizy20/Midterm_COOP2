-- Multi-tenant hardening migration
-- Run this against the loan_management database.

START TRANSACTION;

-- Ensure every tenant has a system_settings row for the new per-tenant settings logic.
INSERT IGNORE INTO system_settings (tenant_id, system_name, logo_path, primary_color)
SELECT
  t.tenant_id,
  COALESCE(NULLIF(t.display_name, ''), t.tenant_name),
  t.logo_path,
  COALESCE(NULLIF(t.primary_color, ''), '#2c3ec5')
FROM tenants t;

COMMIT;

-- Add the missing tenant-focused index called out in the audit, if needed.
SET @activity_idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'activity_logs'
    AND index_name = 'idx_tenant_id'
);

SET @activity_idx_sql = IF(
  @activity_idx_exists = 0,
  'ALTER TABLE activity_logs ADD INDEX idx_tenant_id (tenant_id)',
  'SELECT 1'
);

PREPARE stmt FROM @activity_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
