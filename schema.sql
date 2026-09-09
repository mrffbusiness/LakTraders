CREATE DATABASE IF NOT EXISTS pos_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pos_saas;

CREATE TABLE IF NOT EXISTS shops (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slogan VARCHAR(255) NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'Rs.',
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  plan ENUM('starter','professional','business','custom') NOT NULL DEFAULT 'starter',
  plan_expires_at DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_shops_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','shop_admin','cashier') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_shop (shop_id),
  CONSTRAINT fk_users_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts (email, ip_address, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(80) NULL,
  name VARCHAR(180) NOT NULL,
  category VARCHAR(120) NULL,
  description VARCHAR(500) NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_products_shop (shop_id),
  INDEX idx_products_shop_name (shop_id, name),
  UNIQUE KEY uq_shop_sku (shop_id, sku),
  CONSTRAINT fk_products_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  movement_type ENUM('sale','cancel','refund','adjustment','opening') NOT NULL,
  quantity_change INT NOT NULL,
  stock_after INT NOT NULL,
  reference_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_shop_date (shop_id, created_at),
  CONSTRAINT fk_inventory_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_shop (shop_id),
  INDEX idx_customers_shop_phone (shop_id, phone),
  CONSTRAINT fk_customers_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  cashier_id BIGINT UNSIGNED NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method ENUM('cash','card','bank','other') NOT NULL DEFAULT 'cash',
  amount_received DECIMAL(12,2) NOT NULL DEFAULT 0,
  change_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('paid','cancelled','refunded') NOT NULL DEFAULT 'paid',
  cancellation_reason VARCHAR(255) NULL,
  refund_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_invoices_shop_date (shop_id, created_at),
  INDEX idx_invoices_shop_status (shop_id, status),
  UNIQUE KEY uq_shop_invoice_no (shop_id, invoice_no),
  CONSTRAINT fk_invoices_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  product_name VARCHAR(180) NOT NULL,
  sku VARCHAR(80) NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_sequences (
  shop_id BIGINT UNSIGNED PRIMARY KEY,
  next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_sequences_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_shop_date (shop_id, created_at),
  CONSTRAINT fk_audit_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id BIGINT UNSIGNED NOT NULL,
  plan ENUM('starter','professional','business','custom') NOT NULL,
  status ENUM('trial','active','past_due','cancelled','expired') NOT NULL DEFAULT 'trial',
  started_at DATE NOT NULL,
  expires_at DATE NULL,
  external_reference VARCHAR(190) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subscriptions_shop (shop_id),
  CONSTRAINT fk_subscriptions_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The installer creates the first Shop Admin for this standalone copy.
