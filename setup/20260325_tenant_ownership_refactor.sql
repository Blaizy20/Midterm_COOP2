SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE users MODIFY tenant_id INT NULL;

CREATE TABLE IF NOT EXISTS tenant_admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NOT NULL,
  is_primary_owner TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_tenant_admin (tenant_id, user_id),
  KEY idx_tenant_admin_user (user_id),
  KEY idx_tenant_admin_primary (tenant_id, is_primary_owner),
  CONSTRAINT fk_tenant_admins_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_tenant_admins_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_admins (tenant_id, user_id, is_primary_owner)
SELECT tenant_id, user_id, 1
FROM users
WHERE role = 'ADMIN' AND tenant_id IS NOT NULL;

UPDATE users
SET tenant_id = NULL
WHERE role IN ('SUPER_ADMIN', 'ADMIN');

SET FOREIGN_KEY_CHECKS=1;
