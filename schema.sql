SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS payment_edit_otp;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS interest_rate_history;
DROP TABLE IF EXISTS money_release_vouchers;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS requirements;
DROP TABLE IF EXISTS loans;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS tenant_admins;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS tenants;

CREATE TABLE tenants (
  tenant_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_name VARCHAR(120) NOT NULL UNIQUE,
  subdomain VARCHAR(50) NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  logo_path VARCHAR(500) NULL,
  primary_color VARCHAR(7) DEFAULT '#2c3ec5',
  is_active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NULL,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  reset_token VARCHAR(255) NULL,
  reset_token_expiry DATETIME NULL,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('SUPER_ADMIN','ADMIN','TENANT','MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER','CUSTOMER') NOT NULL,
  contact_no VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT DEFAULT 1,
  UNIQUE KEY unique_tenant_username (tenant_id, username),
  KEY idx_users_role (role),
  KEY idx_users_tenant_role (tenant_id, role),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE tenant_admins (
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
) ENGINE=InnoDB;

CREATE TABLE customers (
  customer_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NULL,
  customer_no VARCHAR(30) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  last_name VARCHAR(60) NOT NULL,
  contact_no VARCHAR(30) NOT NULL,
  email VARCHAR(120) NULL,
  province VARCHAR(80) NULL,
  city VARCHAR(80) NULL,
  barangay VARCHAR(80) NULL,
  street VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT DEFAULT 1,
  UNIQUE KEY unique_tenant_customer_no (tenant_id, customer_no),
  CONSTRAINT fk_customers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE loans (
  loan_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  reference_no VARCHAR(40) NOT NULL,
  customer_id INT NOT NULL,
  principal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  payment_term VARCHAR(20) NULL,
  term_months INT NOT NULL DEFAULT 0,
  total_payable DECIMAL(12,2) NULL,
  remaining_balance DECIMAL(12,2) NULL,
  status ENUM('PENDING','CI_REVIEWED','APPROVED','DENIED','ACTIVE','OVERDUE','CLOSED') NOT NULL DEFAULT 'PENDING',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ci_by INT NULL,
  ci_at TIMESTAMP NULL,
  manager_by INT NULL,
  manager_at TIMESTAMP NULL,
  approved_at TIMESTAMP NULL,
  loan_officer_id INT NULL,
  activated_at TIMESTAMP NULL,
  due_date DATE NULL,
  denial_reason TEXT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_active TINYINT DEFAULT 1,
  UNIQUE KEY unique_tenant_reference_no (tenant_id, reference_no),
  KEY idx_loans_status (status),
  KEY idx_loans_customer_id (customer_id),
  CONSTRAINT fk_loans_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_loans_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
  CONSTRAINT fk_loans_ci FOREIGN KEY (ci_by) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_loans_manager FOREIGN KEY (manager_by) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_loans_officer FOREIGN KEY (loan_officer_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE requirements (
  requirement_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  loan_id INT NOT NULL,
  requirement_code VARCHAR(50) NOT NULL,
  requirement_name VARCHAR(120) NOT NULL,
  file_path VARCHAR(500) NULL,
  uploaded_by_role ENUM('CUSTOMER','STAFF') NOT NULL DEFAULT 'STAFF',
  uploaded_by_user INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  is_active TINYINT DEFAULT 1,
  UNIQUE KEY unique_tenant_loan_req_code (tenant_id, loan_id, requirement_code),
  KEY idx_requirements_loan_id (loan_id),
  CONSTRAINT fk_requirements_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_requirements_loan FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
  CONSTRAINT fk_requirements_user FOREIGN KEY (uploaded_by_user) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  loan_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('CASH','CHEQUE','GCASH','DIGITAL','OTHER','BANK') NOT NULL DEFAULT 'CASH',
  cheque_number VARCHAR(50) NULL,
  cheque_date DATE NULL,
  bank_name VARCHAR(120) NULL,
  account_holder VARCHAR(120) NULL,
  bank_reference_no VARCHAR(50) NULL,
  gcash_reference_no VARCHAR(50) NULL,
  or_no VARCHAR(40) NOT NULL,
  loan_officer_id INT NULL,
  received_by INT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_tenant_or_no (tenant_id, or_no),
  KEY idx_payments_payment_date (payment_date),
  KEY idx_payments_loan_id (loan_id),
  CONSTRAINT fk_pay_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_loan FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_officer FOREIGN KEY (loan_officer_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_user FOREIGN KEY (received_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NULL,
  user_role VARCHAR(50) NOT NULL,
  action VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  loan_id INT NULL,
  customer_id INT NULL,
  reference_no VARCHAR(40) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activity_created_at (created_at),
  KEY idx_activity_user_id (user_id),
  KEY idx_activity_action (action),
  KEY idx_activity_loan_id (loan_id),
  CONSTRAINT fk_activity_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_activity_loan FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE SET NULL,
  CONSTRAINT fk_activity_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE money_release_vouchers (
  voucher_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  loan_id INT NOT NULL,
  voucher_no VARCHAR(40) NOT NULL,
  release_date DATE NOT NULL,
  check_no VARCHAR(40) NULL,
  check_amount DECIMAL(12,2) NULL,
  explanation TEXT NULL,
  prepared_by INT NULL,
  approved_by INT NULL,
  audited_by INT NULL,
  received_by_name VARCHAR(120) NULL,
  received_by_date DATE NULL,
  voucher_data LONGTEXT NULL,
  status ENUM('DRAFT','RELEASED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_tenant_voucher_no (tenant_id, voucher_no),
  KEY idx_voucher_loan_id (loan_id),
  KEY idx_voucher_no (voucher_no),
  KEY idx_voucher_status (status),
  CONSTRAINT fk_voucher_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_voucher_loan FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
  CONSTRAINT fk_voucher_prepared FOREIGN KEY (prepared_by) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_voucher_approved FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_voucher_audited FOREIGN KEY (audited_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE interest_rate_history (
  history_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  loan_id INT NOT NULL,
  old_rate DECIMAL(5,2) NOT NULL,
  new_rate DECIMAL(5,2) NOT NULL,
  changed_by INT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_irh_loan_id (loan_id),
  CONSTRAINT fk_irh_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_irh_loan FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
  CONSTRAINT fk_irh_user FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  system_name VARCHAR(255) NOT NULL DEFAULT 'CredenceLend',
  logo_path VARCHAR(500),
  primary_color VARCHAR(7) NOT NULL DEFAULT '#2c3ec5',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_tenant_setting (tenant_id),
  CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payment_edit_otp (
  otp_id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  user_id INT NOT NULL,
  otp_code VARCHAR(6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  is_used TINYINT DEFAULT 0,
  verified_at DATETIME NULL,
  KEY idx_payment_user (payment_id, user_id),
  KEY idx_payment_otp_expires (expires_at),
  CONSTRAINT fk_payment_otp FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE,
  CONSTRAINT fk_user_otp FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS=1;
