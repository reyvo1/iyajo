-- TAMASYA R16.4: idempotent per-property Night Audit mode toggle.
-- Run against property DB AFTER verified backup; never against a shared HQ DB.
-- Default ON preserves every existing production behavior.
-- No UPDATE/DELETE of historical bookings, audits, transactions, or journals.
SET @night_audit_mode_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'hotel_operational_settings'
    AND COLUMN_NAME = 'night_audit_enabled'
);
SET @night_audit_mode_migration_sql = IF(
  @night_audit_mode_column_exists = 0,
  'ALTER TABLE `hotel_operational_settings` ADD COLUMN `night_audit_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `require_night_audit_for_night_shift`',
  'SELECT ''Night Audit mode already installed'' AS migration_status'
);
PREPARE night_audit_mode_stmt FROM @night_audit_mode_migration_sql;
EXECUTE night_audit_mode_stmt;
DEALLOCATE PREPARE night_audit_mode_stmt;
