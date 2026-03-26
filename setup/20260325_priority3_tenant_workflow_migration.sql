SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Priority 3 non-destructive migration for existing databases.
-- Adds tenant workflow fields, keeps is_active in sync, and enforces one owner per tenant.

CREATE TABLE IF NOT EXISTS tenant_admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NOT NULL,
  is_primary_owner TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_tenant_owner (tenant_id),
  KEY idx_tenant_admin_user (user_id),
  KEY idx_tenant_admin_primary (tenant_id, is_primary_owner),
  CONSTRAINT fk_tenant_admins_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_tenant_admins_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_admins (tenant_id, user_id, is_primary_owner)
SELECT
  u.tenant_id,
  u.user_id,
  1
FROM users u
WHERE u.role = 'ADMIN'
  AND u.tenant_id IS NOT NULL
  AND u.tenant_id > 0;

DELETE ta_old
FROM tenant_admins ta_old
JOIN tenant_admins ta_keep
  ON ta_old.tenant_id = ta_keep.tenant_id
 AND (
      ta_old.is_primary_owner < ta_keep.is_primary_owner
      OR (ta_old.is_primary_owner = ta_keep.is_primary_owner AND ta_old.id > ta_keep.id)
 );

UPDATE tenant_admins
SET is_primary_owner = 1;

SET @drop_old_owner_index = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'tenant_admins'
    AND index_name = 'unique_tenant_admin'
);

SET @drop_old_owner_index_sql = IF(
  @drop_old_owner_index > 0,
  'ALTER TABLE tenant_admins DROP INDEX unique_tenant_admin',
  'SELECT 1'
);

PREPARE stmt FROM @drop_old_owner_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tenant_owner_unique_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'tenant_admins'
    AND index_name = 'unique_tenant_owner'
);

SET @tenant_owner_unique_sql = IF(
  @tenant_owner_unique_exists = 0,
  'ALTER TABLE tenant_admins ADD UNIQUE KEY unique_tenant_owner (tenant_id)',
  'SELECT 1'
);

PREPARE stmt FROM @tenant_owner_unique_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tenant_status_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'tenants'
    AND column_name = 'tenant_status'
);

SET @tenant_status_sql = IF(
  @tenant_status_exists = 0,
  'ALTER TABLE tenants ADD COLUMN tenant_status ENUM(''PENDING'',''ACTIVE'',''INACTIVE'',''SUSPENDED'',''REJECTED'') NOT NULL DEFAULT ''PENDING'' AFTER display_name',
  'SELECT 1'
);

PREPARE stmt FROM @tenant_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @plan_code_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'tenants'
    AND column_name = 'plan_code'
);

SET @plan_code_sql = IF(
  @plan_code_exists = 0,
  'ALTER TABLE tenants ADD COLUMN plan_code ENUM(''BASIC'',''PROFESSIONAL'',''ENTERPRISE'') NOT NULL DEFAULT ''BASIC'' AFTER tenant_status',
  'SELECT 1'
);

PREPARE stmt FROM @plan_code_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tenant_status_backfill_sql = IF(
  @tenant_status_exists = 0,
  'UPDATE tenants SET tenant_status = CASE WHEN is_active = 1 THEN ''ACTIVE'' ELSE ''INACTIVE'' END',
  'SELECT 1'
);

PREPARE stmt FROM @tenant_status_backfill_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE tenants
SET is_active = CASE
  WHEN tenant_status = 'ACTIVE' THEN 1
  ELSE 0
END;

SET @tenant_status_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'tenants'
    AND index_name = 'idx_tenants_status'
);

SET @tenant_status_index_sql = IF(
  @tenant_status_index_exists = 0,
  'ALTER TABLE tenants ADD INDEX idx_tenants_status (tenant_status)',
  'SELECT 1'
);

PREPARE stmt FROM @tenant_status_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tenant_plan_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'tenants'
    AND index_name = 'idx_tenants_plan'
);

SET @tenant_plan_index_sql = IF(
  @tenant_plan_index_exists = 0,
  'ALTER TABLE tenants ADD INDEX idx_tenants_plan (plan_code)',
  'SELECT 1'
);

PREPARE stmt FROM @tenant_plan_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;
