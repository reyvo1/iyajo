-- TAMASYA R17.1: OTA commission (net settlement) support.
-- Run against a property DB AFTER a verified backup; never against a shared HQ DB.
-- Idempotent: safe to run repeatedly.
-- Default 0.00 keeps every existing disbursement behaving exactly as before, so no
-- OTA backfill or historical import row changes meaning.
--
-- Background: OTA channels such as Booking.com and Traveloka collect the guest
-- payment and remit only the net amount after deducting their commission. The
-- receivable was booked at the gross room rate, so recording the remittance alone
-- left the commission stranded in Piutang OTA forever, and recording the gross
-- overstated banked cash. This column records the deduction explicitly.
SET @ota_commission_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ota_disbursements'
    AND COLUMN_NAME = 'commission_amount'
);
SET @ota_commission_migration_sql = IF(
  @ota_commission_column_exists = 0,
  'ALTER TABLE `ota_disbursements` ADD COLUMN `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `amount`',
  'SELECT ''OTA commission column already installed'' AS migration_status'
);
PREPARE ota_commission_stmt FROM @ota_commission_migration_sql;
EXECUTE ota_commission_stmt;
DEALLOCATE PREPARE ota_commission_stmt;