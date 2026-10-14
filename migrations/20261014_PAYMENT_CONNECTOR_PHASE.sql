-- TAMASYA R14.2: payment connector phase (additive).
-- Run against a property DB AFTER a verified backup; never against a shared HQ DB.
-- Idempotent: safe to run repeatedly.
--
-- Background: the payment foundation deliberately stopped at recording intents
-- and provider events without touching money. This phase adds the only piece
-- needed for a real vendor webhook: an authenticated provider registry. The
-- webhook secret is stored encrypted and is used solely to verify the inbound
-- HMAC; a provider without a secret can never authenticate, and an inactive
-- provider always fails closed.
SET @growth_payment_providers_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'growth_payment_providers'
);
SET @growth_payment_providers_sql = IF(
  @growth_payment_providers_exists = 0,
  'CREATE TABLE growth_payment_providers (
        id VARCHAR(100) PRIMARY KEY, code VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL,
        webhook_secret_encrypted TEXT NULL, signature_mode VARCHAR(20) NOT NULL DEFAULT ''hmac_sha256'',
        active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growth_payment_provider_code(code), INDEX idx_growth_payment_provider_active(active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT ''growth_payment_providers already installed'' AS migration_status'
);
PREPARE growth_payment_providers_stmt FROM @growth_payment_providers_sql;
EXECUTE growth_payment_providers_stmt;
DEALLOCATE PREPARE growth_payment_providers_stmt;
