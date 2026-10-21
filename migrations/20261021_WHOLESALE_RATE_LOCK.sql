-- TAMASYA: kunci tarif kontrak pada reservasi (wholesale).
--
-- Paket tarif kontrak (wholesale_rate_plans + wholesale_rate_nights) sudah
-- ada. Yang ditambahkan di sini adalah pencatatan tarif yang benar-benar
-- dipakai sebuah reservasi:
--
--   - plan_code dan company_name: paket dan perusahaan pemilik kontrak, supaya
--     kunci tarif terbaca tanpa perlu melihat paketnya.
--   - nightly_breakdown: harga per tanggal yang dikunci. Nilai inilah yang
--     membuat perubahan paket di kemudian hari tidak mengubah reservasi lama.
--
-- Idempoten: kolom yang sudah ada tidak disentuh, tidak ada data yang dihapus.

SET @schema := DATABASE();

SET @wrb_code := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'wholesale_rate_bookings' AND COLUMN_NAME = 'plan_code'
);
SET @sql := IF(@wrb_code = 0,
  'ALTER TABLE `wholesale_rate_bookings` ADD COLUMN `plan_code` varchar(40) DEFAULT NULL AFTER `plan_id`',
  'SELECT ''wholesale_rate_bookings.plan_code already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @wrb_company := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'wholesale_rate_bookings' AND COLUMN_NAME = 'company_name'
);
SET @sql := IF(@wrb_company = 0,
  'ALTER TABLE `wholesale_rate_bookings` ADD COLUMN `company_name` varchar(150) DEFAULT NULL AFTER `plan_code`',
  'SELECT ''wholesale_rate_bookings.company_name already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @wrb_breakdown := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'wholesale_rate_bookings' AND COLUMN_NAME = 'nightly_breakdown'
);
SET @sql := IF(@wrb_breakdown = 0,
  'ALTER TABLE `wholesale_rate_bookings` ADD COLUMN `nightly_breakdown` text DEFAULT NULL AFTER `nights`',
  'SELECT ''wholesale_rate_bookings.nightly_breakdown already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
