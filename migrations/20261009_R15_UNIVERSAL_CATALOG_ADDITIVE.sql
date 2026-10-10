-- TAMASYA R15 universal catalog extension — additive only, per-property database.
-- Apply via approved maintenance (phpMyAdmin) to an isolated staging database FIRST.
-- NEVER drop/rename current categories, subcategories, transactions, system keys.
CREATE TABLE IF NOT EXISTS tamasya_catalog_items (
  id VARCHAR(50) NOT NULL PRIMARY KEY,
  category_id VARCHAR(50) NOT NULL,
  subcategory_id VARCHAR(50) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  unit_label VARCHAR(40) NOT NULL,
  price_mode ENUM('fixed','manual') NOT NULL DEFAULT 'fixed',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_category_name (category_id, name),
  KEY idx_catalog_subcategory (subcategory_id),
  KEY idx_catalog_active (is_active, category_id, name),
  CONSTRAINT fk_catalog_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_catalog_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tamasya_catalog_rates (
  id VARCHAR(50) NOT NULL PRIMARY KEY,
  item_id VARCHAR(50) NOT NULL,
  valid_from DATE NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  recorded_by VARCHAR(50) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_rate_date (item_id, valid_from),
  CONSTRAINT fk_catalog_rates_item FOREIGN KEY (item_id) REFERENCES tamasya_catalog_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Business notes:
-- 1) This catalog is DISABLED unless TAMASYA_UNIVERSAL_CATALOG_ENABLED=1.
-- 2) No finance posting/price tax is affected; tax is resolved exclusively by canonical finance policy.
-- 3) Each property stores its own catalog in its existing separate property DB.
-- 4) Migration rollback: deactivate feature flag. Do not DROP tables holding master history.
