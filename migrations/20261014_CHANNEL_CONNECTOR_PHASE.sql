-- TAMASYA R14.3: channel connector phase (additive).
-- Run against a property DB AFTER a verified backup; never against a shared HQ DB.
-- Idempotent: safe to run repeatedly.
--
-- Background: the channel foundation stopped at mapping room types and rate plans
-- plus recording external events, with no inbound webhook and no booking. This
-- phase adds the authenticated provider registry a real vendor needs. A verified
-- reservation event creates a RESERVED draft through the canonical booking
-- workflow: no money is ever posted, the rate comes from the mapped rate plan,
-- and an unmapped room type is recorded for a human instead of guessed.
SET @growth_channel_providers_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'growth_channel_providers'
);
SET @growth_channel_providers_sql = IF(
  @growth_channel_providers_exists = 0,
  'CREATE TABLE growth_channel_providers (
        id VARCHAR(100) PRIMARY KEY, code VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL,
        webhook_secret_encrypted TEXT NULL, signature_mode VARCHAR(20) NOT NULL DEFAULT ''hmac_sha256'',
        active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growth_channel_provider_code(code), INDEX idx_growth_channel_provider_active(active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT ''growth_channel_providers already installed'' AS migration_status'
);
PREPARE growth_channel_providers_stmt FROM @growth_channel_providers_sql;
EXECUTE growth_channel_providers_stmt;
DEALLOCATE PREPARE growth_channel_providers_stmt;
