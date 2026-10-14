-- TAMASYA R14.1: configurable shift boundaries (additive).
-- Run against a property DB AFTER a verified backup; never against a shared HQ DB.
-- Idempotent: safe to run repeatedly.
--
-- Background: the shift slot pagi/siang/malam was a fixed enum whose clock
-- boundaries were hardcoded twice and inconsistently (web 07:00-15:00-23:00 vs a
-- server legacy night window of 18:00-05:00). A hotel that runs a different rota
-- could not express it, and a shift opened at 20:00 could be classified as
-- "siang" by the web form but "malam" by the server rule, which changes the
-- Night Audit gate. These three boundaries tile the day exactly:
--   pagi  = [day_start, siang_start)
--   siang = [siang_start, malam_start)
--   malam = [malam_start, day_start) overnight
-- Defaults preserve today's behaviour (07:00 / 15:00 / 23:00), so no existing
-- shift row changes meaning. Ordering day_start < siang_start < malam_start is
-- validated on save and the reader fails safe to the defaults when corrupt.
SET @shift_day_start_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'hotel_operational_settings'
    AND COLUMN_NAME = 'shift_day_start'
);
SET @shift_day_start_sql = IF(
  @shift_day_start_exists = 0,
  'ALTER TABLE `hotel_operational_settings` ADD COLUMN `shift_day_start` time NOT NULL DEFAULT ''07:00:00'' AFTER `checkout_time`',
  'SELECT ''shift_day_start already installed'' AS migration_status'
);
PREPARE shift_day_start_stmt FROM @shift_day_start_sql;
EXECUTE shift_day_start_stmt;
DEALLOCATE PREPARE shift_day_start_stmt;

SET @shift_siang_start_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'hotel_operational_settings'
    AND COLUMN_NAME = 'shift_siang_start'
);
SET @shift_siang_start_sql = IF(
  @shift_siang_start_exists = 0,
  'ALTER TABLE `hotel_operational_settings` ADD COLUMN `shift_siang_start` time NOT NULL DEFAULT ''15:00:00'' AFTER `shift_day_start`',
  'SELECT ''shift_siang_start already installed'' AS migration_status'
);
PREPARE shift_siang_start_stmt FROM @shift_siang_start_sql;
EXECUTE shift_siang_start_stmt;
DEALLOCATE PREPARE shift_siang_start_stmt;

SET @shift_malam_start_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'hotel_operational_settings'
    AND COLUMN_NAME = 'shift_malam_start'
);
SET @shift_malam_start_sql = IF(
  @shift_malam_start_exists = 0,
  'ALTER TABLE `hotel_operational_settings` ADD COLUMN `shift_malam_start` time NOT NULL DEFAULT ''23:00:00'' AFTER `shift_siang_start`',
  'SELECT ''shift_malam_start already installed'' AS migration_status'
);
PREPARE shift_malam_start_stmt FROM @shift_malam_start_sql;
EXECUTE shift_malam_start_stmt;
DEALLOCATE PREPARE shift_malam_start_stmt;
