-- TAMASYA: fondasi multi-mata uang.
--
-- Rilis ini menambah dukungan mata uang per property. Yang dilakukan di sini
-- adalah fondasi DATA, bukan konversi:
--   - transactions menandai mata uang nominal, kurs, dan (sudah ada) baseAmount.
--   - exchange_rates menyimpan histori kurs untuk masa depan.
--   - Baris yang sudah ada diberi mata uang property saat ini (IDR bila
--     property belum mengatur), sehingga tidak ada data yang berubah nilai.
--
-- Semuanya idempoten dan tidak menghapus data.
--
-- PENTING: menambah kolom ini TIDAK membuat aplikasi menjumlahkan mata uang
-- berbeda. Penjumlahan lintas mata uang tetap dilarang dan dijaga oleh
-- tamasyaTransactionCurrencyGuard() pada sisi baca.

SET @schema := DATABASE();

-- 1) Kolom mata uang pada tabel transaksi.
SET @tx_currency := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'currency'
);
SET @sql := IF(@tx_currency = 0,
  'ALTER TABLE `transactions` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT ''IDR'' AFTER `amount`',
  'SELECT ''transactions.currency already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tx_rate := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'exchangeRate'
);
SET @sql := IF(@tx_rate = 0,
  'ALTER TABLE `transactions` ADD COLUMN `exchangeRate` decimal(18,8) NOT NULL DEFAULT 1 AFTER `currency`',
  'SELECT ''transactions.exchangeRate already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tx_base := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'baseAmount'
);
SET @sql := IF(@tx_base = 0,
  'ALTER TABLE `transactions` ADD COLUMN `baseAmount` decimal(18,2) DEFAULT NULL AFTER `exchangeRate`',
  'SELECT ''transactions.baseAmount already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Tabel histori kurs.
CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `id` varchar(64) NOT NULL,
  `base_currency` varchar(10) NOT NULL,
  `quote_currency` varchar(10) NOT NULL,
  `rate` decimal(18,8) NOT NULL,
  `rate_date` date NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exchange_rate_day` (`base_currency`,`quote_currency`,`rate_date`),
  KEY `idx_exchange_rate_pair` (`base_currency`,`quote_currency`,`rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Backfill: baris lama memakai mata uang property aktif.
-- Satu database = satu property (tabel staff tidak punya kolom property_id),
-- jadi mata uang property aktif dipakai untuk seluruh baris.
UPDATE `transactions` t
LEFT JOIN (SELECT MAX(`currency`) AS `currency` FROM `property_settings`) p ON 1 = 1
SET t.`currency` = COALESCE(p.`currency`, 'IDR')
WHERE t.`currency` IS NULL OR t.`currency` = '';

-- 4) baseAmount SENGAJA tidak diisi. Nilai NULL adalah tanda pajak belum
--    diketahui (taxSnapshotStatus unresolved) dan pembeda itu dipakai laporan
--    kanonik serta jurnal suspense 2199.
