-- R16.2 OPTIONAL ADDITIVE: apply only after a backup and R16 catalog migration.
-- Every property has its own database. No modification to canonical 113-table baseline.
-- No FK to transactions: canonical transaction deletion/tombstone workflows remain unblocked;
-- the historical attribution record must survive cancellation/deletion as audit evidence.
CREATE TABLE IF NOT EXISTS tamasya_catalog_transaction_lines (
  transaction_id VARCHAR(50) NOT NULL PRIMARY KEY,
  operation_id VARCHAR(100) DEFAULT NULL,
  item_id VARCHAR(50) NOT NULL,
  item_revision INT UNSIGNED NOT NULL,
  rate_id VARCHAR(50) NOT NULL,
  category_id VARCHAR(50) NOT NULL,
  subcategory_id VARCHAR(50) DEFAULT NULL,
  item_name VARCHAR(150) NOT NULL,
  unit_label VARCHAR(40) NOT NULL,
  quantity_milli BIGINT UNSIGNED NOT NULL,
  unit_price_cents BIGINT UNSIGNED NOT NULL,
  subtotal_cents BIGINT UNSIGNED NOT NULL,
  service_date DATE NOT NULL,
  rate_valid_from DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_snapshot_operation (operation_id),
  KEY idx_catalog_snapshot_item (item_id,service_date),
  CONSTRAINT fk_catalog_snapshot_item FOREIGN KEY(item_id) REFERENCES tamasya_catalog_items(id),
  CONSTRAINT fk_catalog_snapshot_rate FOREIGN KEY(rate_id) REFERENCES tamasya_catalog_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Idempotent rerun uses IF NOT EXISTS. Never UPDATE historic price snapshots.
