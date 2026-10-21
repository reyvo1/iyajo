-- ---------------------------------------------------------------------------
-- TAMASYA: impor database satu berkas (idempoten)
--
-- BERKAS INI DIBANGUN OTOMATIS oleh scripts/build-import-sql.mjs.
-- Jangan menyunting manual: perubahan akan hilang saat berkas dibangun ulang.
--
-- Jalankan pada DATABASE SATU PROPERTI, bukan database HQ bersama.
-- Backup dulu (phpMyAdmin -> Export -> SQL).
-- Aman dijalankan berulang kali: setiap langkah memakai CREATE TABLE IF NOT
-- EXIST, INSERT IGNORE, dan pemeriksaan information_schema.
--
-- LANGKAH 0: cek tabel yang sudah ada. Semua bernilai 1 -> tidak wajib impor,
-- tapi mengimpor tetap aman. Nilai 0 -> tabel itu belum ada di database ini.
--   staff_job_titles
--   role_capability_presets
--   rbac_roles
--   rbac_capabilities
--   rbac_desktop_tabs
--   rbac_notification_groups
--   rbac_access_groups
--   rbac_access_group_roles
--   rbac_legacy_role_sets
--   guest_reviews
--   guest_review_invites
--   wholesale_rate_plans
--   wholesale_rate_nights
--   wholesale_rate_bookings
--   exchange_rates
-- ---------------------------------------------------------------------------

SELECT 'LANGKAH 0: tabel yang sudah ada' AS migration_step;
SELECT 'staff_job_titles' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_job_titles') > 0, 1, 0) AS already_exists;
SELECT 'role_capability_presets' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_capability_presets') > 0, 1, 0) AS already_exists;
SELECT 'rbac_roles' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_roles') > 0, 1, 0) AS already_exists;
SELECT 'rbac_capabilities' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_capabilities') > 0, 1, 0) AS already_exists;
SELECT 'rbac_desktop_tabs' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_desktop_tabs') > 0, 1, 0) AS already_exists;
SELECT 'rbac_notification_groups' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_notification_groups') > 0, 1, 0) AS already_exists;
SELECT 'rbac_access_groups' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_access_groups') > 0, 1, 0) AS already_exists;
SELECT 'rbac_access_group_roles' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_access_group_roles') > 0, 1, 0) AS already_exists;
SELECT 'rbac_legacy_role_sets' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_legacy_role_sets') > 0, 1, 0) AS already_exists;
SELECT 'guest_reviews' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_reviews') > 0, 1, 0) AS already_exists;
SELECT 'guest_review_invites' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_review_invites') > 0, 1, 0) AS already_exists;
SELECT 'wholesale_rate_plans' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wholesale_rate_plans') > 0, 1, 0) AS already_exists;
SELECT 'wholesale_rate_nights' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wholesale_rate_nights') > 0, 1, 0) AS already_exists;
SELECT 'wholesale_rate_bookings' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wholesale_rate_bookings') > 0, 1, 0) AS already_exists;
SELECT 'exchange_rates' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exchange_rates') > 0, 1, 0) AS already_exists;


-- ===== sumber: deploy/20261021_R19_FULL_SCHEMA_UPGRADE.sql =====
-- ---------------------------------------------------------------------------
-- TAMASYA: PENINGKATAN SKEMA LENGKAP (satu berkas, idempoten)
--
-- Jalankan berkas INI SAJA pada database properti yang sudah ada. Isinya
-- menggabungkan seluruh migrasi yang pernah dibuat untuk rilis 20261021:
-- jabatan dinamis, paket izin, registry RBAC, daftar role lama, mata uang
-- dan kurs, ulasan tamu, serta tarif kontrak B2B.
--
-- Aman dijalankan berulang kali: CREATE TABLE IF NOT EXISTS, INSERT IGNORE, dan
-- pemeriksaan information_schema untuk setiap kolom tambahan.
--
-- Jangan jalankan di database HQ bersama.
-- ---------------------------------------------------------------------------

SET @schema := DATABASE();


-- ===== sumber: migrations/20261019_STAFF_JOB_TITLES.sql =====
-- TAMASYA R19: jabatan dinamis (tambah/rename/hapus jabatan dari UI).
-- Jalankan terhadap DB properti SETELAH backup terverifikasi; jangan pada DB HQ bersama.
-- Idempoten: aman dijalankan berulang.
--
-- Latar: pemilik butuh jabatan di luar 9 bawaan tanpa mengubah kode. Desain aman:
-- setiap jabatan punya `base_role` (salah satu 9 role kanonik). `staff.role` TETAP
-- menyimpan role basis sehingga seluruh enforcement lama (requireRoles/hasCapability/
-- requireDesktopTabAccess dan perbandingan role langsung) tidak berubah; `staff.job_title`
-- hanya menyimpan label jabatan untuk tampilan + pengelompokan paket izin.
--
-- Paket izin default jabatan baru disalin dari seed base_role-nya, lalu dapat diatur
-- lewat panel "Kelola Jabatan" (route 052); penerapan ke akun tetap eksplisit per akun.
CREATE TABLE IF NOT EXISTS `staff_job_titles` (
  `title_key` varchar(30) NOT NULL,
  `label` varchar(60) NOT NULL,
  `base_role` varchar(20) NOT NULL DEFAULT 'lain_lain',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`title_key`),
  KEY `idx_staff_job_titles_base` (`base_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 9 jabatan sistem = role kanonik; key dan base_role sama (perilaku lama utuh).
INSERT IGNORE INTO `staff_job_titles` (`title_key`,`label`,`base_role`,`is_system`,`sort_order`) VALUES
('admin','Admin','admin',1,10),
('manager','Manajer','manager',1,20),
('finance','Keuangan','finance',1,30),
('receptionist','Resepsionis','receptionist',1,40),
('cleaning_service','Cleaning Service','cleaning_service',1,50),
('keamanan','Keamanan','keamanan',1,60),
('koki','Koki','koki',1,70),
('tukang_kebun','Tukang Kebun','tukang_kebun',1,80),
('lain_lain','Lain-lain','lain_lain',1,90);

SET @staff_job_title_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'job_title'
);
SET @staff_job_title_col_sql := IF(
  @staff_job_title_col = 0,
  'ALTER TABLE `staff` ADD COLUMN `job_title` varchar(30) DEFAULT NULL AFTER `role`',
  'SELECT ''staff.job_title already installed'' AS migration_status'
);
PREPARE staff_job_title_col_stmt FROM @staff_job_title_col_sql;
EXECUTE staff_job_title_col_stmt;
DEALLOCATE PREPARE staff_job_title_col_stmt;

SET @staff_job_title_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND INDEX_NAME = 'idx_staff_job_title'
);
SET @staff_job_title_sql := IF(
  @staff_job_title_idx = 0,
  'ALTER TABLE `staff` ADD INDEX `idx_staff_job_title` (`job_title`)',
  'SELECT 1'
);
PREPARE staff_job_title_stmt FROM @staff_job_title_sql;
EXECUTE staff_job_title_stmt;
DEALLOCATE PREPARE staff_job_title_stmt;

-- Jabatan baru memakai seed paket base_role-nya. Baris paket untuk jabatan sistem
-- sudah diurus migrasi role_capability_presets; untuk jabatan dinamis, route 052
-- menyisipkan baris paket saat jabatan dibuat (INSERT IGNORE, tidak menimpa).


-- ===== sumber: migrations/20261019_ROLE_CAPABILITY_PRESETS.sql =====
-- TAMASYA Fase 2: paket jabatan (role capability presets).
-- Jalankan terhadap DB properti SETELAH backup terverifikasi; jangan pada DB HQ bersama.
-- Idempoten: aman dijalankan berulang.
--
-- Latar: pemilik butuh mengatur centangan capability/tab/notifikasi per jabatan
-- (mis. "kasir hanya lihat laporan") tanpa mengubah enforcement. Tabel ini menyimpan
-- paket per jabatan sebagai DATA; penerapan ke akun dilakukan lewat aksi eksplisit
-- "Terapkan ke jabatan" (route 052) yang menulis staff.permissions per akun. Baris di
-- sini tidak pernah dibaca langsung oleh hasCapability: jalur izin tetap override akun
-- lalu fallback role (017), jadi akun lama tidak berubah sampai paket diterapkan.
--
-- Seed = nilai preset editor staf saat ini (cermin PA(role,null)/Eh(role) app-core.js),
-- termasuk perform_night_audit=true untuk finance. Owner tidak di-seed: paketnya tetap
-- read-only lewat tamasyaSessionPermissions (017).
CREATE TABLE IF NOT EXISTS `role_capability_presets` (
  `role` varchar(40) NOT NULL,
  `permissions` text NOT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- INSERT IGNORE: paket yang sudah pernah disimpan pemilik tidak ditimpa saat migrasi diulang.
INSERT IGNORE INTO `role_capability_presets` (`role`,`permissions`) VALUES
('admin', '{"desktopTabs":{"dashboard":true,"rooms":true,"finance":true,"savings":true,"report":true,"operations":true,"website":true,"support":true,"telegram":true,"staff":true,"config":true,"db_config":true,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":true,"finance":true,"inventory":true,"operations":true,"hr":true,"system":true},"capabilities":{"approve_sensitive_actions":true,"manage_sessions":true,"resolve_sync_conflict":true,"manage_tax_rules":true,"manage_backup":true,"view_audit_log":true,"reconcile_payments":true,"correct_booking_audit":true,"view_guest_identity":true,"manage_room_access":true,"sell_room_access":true,"manage_stay_access":true,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":true,"manage_operational_settings":true,"override_key_checkout":true,"manage_public_website":true}}'),
('manager', '{"desktopTabs":{"dashboard":true,"rooms":true,"finance":false,"savings":true,"report":true,"operations":true,"website":true,"support":true,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":true,"finance":true,"inventory":true,"operations":true,"hr":true,"system":true},"capabilities":{"approve_sensitive_actions":true,"manage_sessions":true,"resolve_sync_conflict":true,"manage_tax_rules":true,"manage_backup":false,"view_audit_log":true,"reconcile_payments":true,"correct_booking_audit":true,"view_guest_identity":true,"manage_room_access":true,"sell_room_access":true,"manage_stay_access":true,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":true,"manage_operational_settings":true,"override_key_checkout":true,"manage_public_website":true}}'),
('finance', '{"desktopTabs":{"dashboard":true,"rooms":false,"finance":true,"savings":true,"report":true,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":true,"inventory":false,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":true,"reconcile_payments":true,"correct_booking_audit":true,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}'),
('receptionist', '{"desktopTabs":{"dashboard":false,"rooms":true,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":true,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":true,"finance":false,"inventory":true,"operations":true,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":true,"manage_room_access":true,"sell_room_access":true,"manage_stay_access":true,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}'),
('cleaning_service', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":true,"operations":true,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":true,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}'),
('keamanan', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":false,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":false,"operations":true,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":true,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":true,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}'),
('koki', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":true,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}'),
('tukang_kebun', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":false,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":false,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}'),
('lain_lain', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":false,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":false,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false}}');


-- ===== sumber: migrations/20261019_ROLE_CAPABILITY_PRESETS_FASE3.sql =====
-- TAMASYA Fase 3 "Kelola Jabatan": tiga capability baru untuk 095 laporan kanonik
-- (view_reports), 017/018 kotak masuk publik (view_support_inbox), dan 096 master
-- fasilitas (manage_facilities). Baris paket yang sudah ada ditambahi kunci baru
-- dengan nilai default seed jabatan; centangan yang sudah disimpan pemilik TIDAK
-- ditimpa. Idempoten: baris yang sudah memuat view_reports dilewati seluruhnya
-- (guard JSON_EXTRACT ... IS NULL), jadi migrasi boleh dijalankan ulang.
-- Fresh install memakai database_setup.sql yang sudah memuat 22 kunci lengkap.
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":true,"view_support_inbox":true,"manage_facilities":true}}') WHERE `role` IN ('admin','manager') AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":true,"view_support_inbox":false,"manage_facilities":false}}') WHERE `role` = 'finance' AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":false,"view_support_inbox":true,"manage_facilities":false}}') WHERE `role` = 'receptionist' AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":false,"view_support_inbox":false,"manage_facilities":false}}') WHERE `role` IN ('cleaning_service','keamanan','koki','tukang_kebun','lain_lain') AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;


-- ===== sumber: migrations/20261020_DAILY_SUMMARY_CAPABILITY.sql =====
-- TAMASYA Sprint A #1: daftarkan capability `daily_summary` yang sudah dipakai
-- route 090 (`operations-center` command `daily-summary`). Nilai default mengikuti
-- fallback route saat ini: admin, manager, dan receptionist terbuka; role lain
-- tertutup. Baris yang sudah memiliki kunci ini tidak diubah (idempoten).
--
-- Jabatan dinamis tidak di-update di sini karena tabel staff_job_titles mungkin
-- belum ada pada DB lama; tamasyaRolePresetRows() akan menambal baris dinamis dari
-- seed base role saat pertama kali dibaca.
UPDATE `role_capability_presets`
SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"daily_summary":true}}')
WHERE `role` IN ('admin','manager','receptionist')
  AND JSON_EXTRACT(`permissions`,'$.capabilities.daily_summary') IS NULL;

UPDATE `role_capability_presets`
SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"daily_summary":false}}')
WHERE `role` IN ('finance','cleaning_service','keamanan','koki','tukang_kebun','lain_lain')
  AND JSON_EXTRACT(`permissions`,'$.capabilities.daily_summary') IS NULL;


-- ===== sumber: migrations/20261021_DYNAMIC_RBAC.sql =====
-- TAMASYA Dynamic RBAC
-- Registry role/capability/tab/notifikasi dan access group untuk enforcement.
-- Idempoten: CREATE TABLE IF NOT EXISTS + INSERT IGNORE. Data runtime dibaca
-- oleh api/support/022_dynamic_rbac.php; fallback statis hanya untuk instalasi
-- yang belum menjalankan migrasi ini.

CREATE TABLE IF NOT EXISTS `rbac_roles` (
  `role_key` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `is_owner` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_key`),
  KEY `idx_rbac_roles_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_capabilities` (
  `capability_key` varchar(64) NOT NULL,
  `label` varchar(160) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`capability_key`),
  KEY `idx_rbac_capabilities_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_desktop_tabs` (
  `tab_key` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tab_key`),
  KEY `idx_rbac_tabs_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_notification_groups` (
  `group_key` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_key`),
  KEY `idx_rbac_notification_groups_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `rbac_roles` (`role_key`,`label`,`is_owner`,`is_system`,`sort_order`) VALUES
('owner','Owner',1,1,0),
('admin','Admin',0,1,10),
('manager','Manajer',0,1,20),
('finance','Keuangan',0,1,30),
('receptionist','Resepsionis',0,1,40),
('cleaning_service','Cleaning Service',0,1,50),
('keamanan','Keamanan',0,1,60),
('koki','Koki',0,1,70),
('tukang_kebun','Tukang Kebun',0,1,80),
('lain_lain','Lain-lain',0,1,90);

INSERT IGNORE INTO `rbac_capabilities` (`capability_key`,`label`,`is_system`,`sort_order`) VALUES
('approve_sensitive_actions','Setujui transaksi sensitif',1,10),
('manage_sessions','Kelola session perangkat',1,20),
('resolve_sync_conflict','Selesaikan konflik sync',1,30),
('manage_tax_rules','Kelola aturan pajak',1,40),
('manage_backup','Backup dan restore',1,50),
('view_audit_log','Lihat audit log',1,60),
('reconcile_payments','Rekonsiliasi pembayaran',1,70),
('correct_booking_audit','Koreksi Reservasi & Transaksi Audit',1,80),
('view_guest_identity','Lihat KTP Tamu (tercatat audit)',1,90),
('manage_room_access','Serahkan/Kembalikan Kunci & PIN Smart Lock',1,100),
('sell_room_access','Jual Kamar & Reservasi (Telegram & Web)',1,110),
('manage_stay_access','Perpanjang, Pindah & Check-out (Telegram & Web)',1,120),
('receive_payment_access','Terima Panjar/DP (Telegram & Web)',1,130),
('perform_night_audit','Melakukan Pemeriksaan Night Audit',1,140),
('perform_housekeeping','Menjalankan Tugas Housekeeping',1,150),
('resolve_night_audit','Menyelesaikan Temuan Night Audit',1,160),
('manage_operational_settings','Mengatur Jam Check-out & Smart Lock',1,170),
('override_key_checkout','Override Check-out Tanpa Kunci Kembali',1,180),
('manage_public_website','Kelola dan Terbitkan Website Publik',1,190),
('view_reports','Lihat & Kirim Laporan Kanonik',1,200),
('view_support_inbox','Kelola Kotak Masuk Publik (Bantuan & Reservasi)',1,210),
('manage_facilities','Kelola Master Fasilitas (Tipe & Unit)',1,220),
('daily_summary','Lihat Ringkasan Harian Operasional',1,230);

INSERT IGNORE INTO `rbac_desktop_tabs` (`tab_key`,`label`,`is_system`,`sort_order`) VALUES
('dashboard','Dasbor',1,10),
('rooms','Kamar & Sewa',1,20),
('finance','Kas & Transaksi',1,30),
('savings','Simpanan Karyawan',1,40),
('report','Laporan',1,50),
('operations','Pusat Operasional',1,60),
('website','Website Publik',1,70),
('support','Layanan Tamu Publik',1,80),
('telegram','Pusat Tugas Harian',1,90),
('staff','Kelola Staf',1,100),
('config','Set Kredensial',1,110),
('db_config','Koneksi Database',1,120),
('inventory','Aset & Inventaris',1,130),
('leaves','Pengajuan Cuti',1,140),
('attendance','Absensi Karyawan',1,150);

INSERT IGNORE INTO `rbac_notification_groups` (`group_key`,`label`,`is_system`,`sort_order`) VALUES
('bookings','Reservasi & Kamar',1,10),
('finance','Keuangan & Gaji',1,20),
('inventory','Aset & Perbaikan',1,30),
('operations','Operasional & Housekeeping',1,40),
('hr','SDM, Absensi & Cuti',1,50),
('system','Sistem & Keamanan Akun',1,60);

CREATE TABLE IF NOT EXISTS `rbac_access_groups` (
  `group_key` varchar(64) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_access_group_roles` (
  `group_key` varchar(64) NOT NULL,
  `role_key` varchar(40) NOT NULL,
  PRIMARY KEY (`group_key`,`role_key`),
  KEY `idx_rbac_access_group_roles_role` (`role_key`),
  CONSTRAINT `fk_rbac_access_group_roles_group` FOREIGN KEY (`group_key`) REFERENCES `rbac_access_groups` (`group_key`) ON DELETE CASCADE,
  CONSTRAINT `fk_rbac_access_group_roles_role` FOREIGN KEY (`role_key`) REFERENCES `rbac_roles` (`role_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_legacy_role_sets` (
  `role_set` varchar(255) NOT NULL,
  `group_key` varchar(64) NOT NULL,
  PRIMARY KEY (`role_set`),
  KEY `idx_rbac_legacy_role_sets_group` (`group_key`),
  CONSTRAINT `fk_rbac_legacy_role_sets_group` FOREIGN KEY (`group_key`) REFERENCES `rbac_access_groups` (`group_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `rbac_access_groups` (`group_key`,`label`,`is_system`) VALUES
('admin_only','Admin',1),
('management','Admin + Manajer',1),
('finance_ops','Admin + Manajer + Keuangan',1),
('finance_ops_owner','Admin + Manajer + Keuangan + Owner',1),
('front_desk_management','Admin + Manajer + Resepsionis',1),
('all_staff','Semua staf operasional',1),
('finance_front_desk','Admin + Manajer + Keuangan + Resepsionis',1),
('admin_owner','Admin + Owner',1),
('front_desk_cleaning','Admin + Manajer + Resepsionis + Cleaning Service',1),
('admin_manager_owner','Admin + Manajer + Owner',1),
('finance_ops_cleaning','Admin + Manajer + Keuangan + Cleaning Service',1),
('security_front_desk','Admin + Manajer + Resepsionis + Keamanan',1),
('admin_finance_owner','Admin + Keuangan + Owner',1),
('admin_finance','Admin + Keuangan',1);

INSERT IGNORE INTO `rbac_access_group_roles` (`group_key`,`role_key`) VALUES
('admin_only','admin'),
('management','admin'),
('management','manager'),
('finance_ops','admin'),
('finance_ops','finance'),
('finance_ops','manager'),
('finance_ops_owner','admin'),
('finance_ops_owner','finance'),
('finance_ops_owner','manager'),
('finance_ops_owner','owner'),
('front_desk_management','admin'),
('front_desk_management','manager'),
('front_desk_management','receptionist'),
('all_staff','admin'),
('all_staff','cleaning_service'),
('all_staff','finance'),
('all_staff','keamanan'),
('all_staff','koki'),
('all_staff','lain_lain'),
('all_staff','manager'),
('all_staff','receptionist'),
('all_staff','tukang_kebun'),
('finance_front_desk','admin'),
('finance_front_desk','finance'),
('finance_front_desk','manager'),
('finance_front_desk','receptionist'),
('admin_owner','admin'),
('admin_owner','owner'),
('front_desk_cleaning','admin'),
('front_desk_cleaning','cleaning_service'),
('front_desk_cleaning','manager'),
('front_desk_cleaning','receptionist'),
('admin_manager_owner','admin'),
('admin_manager_owner','manager'),
('admin_manager_owner','owner'),
('finance_ops_cleaning','admin'),
('finance_ops_cleaning','cleaning_service'),
('finance_ops_cleaning','finance'),
('finance_ops_cleaning','manager'),
('security_front_desk','admin'),
('security_front_desk','keamanan'),
('security_front_desk','manager'),
('security_front_desk','receptionist'),
('admin_finance_owner','admin'),
('admin_finance_owner','finance'),
('admin_finance_owner','owner'),
('admin_finance','admin'),
('admin_finance','finance');

INSERT IGNORE INTO `rbac_legacy_role_sets` (`role_set`,`group_key`) VALUES
('admin','admin_only'),
('admin,manager','management'),
('admin,finance,manager','finance_ops'),
('admin,finance,manager,owner','finance_ops_owner'),
('admin,manager,receptionist','front_desk_management'),
('admin,cleaning_service,finance,keamanan,koki,lain_lain,manager,receptionist,tukang_kebun','all_staff'),
('admin,finance,manager,receptionist','finance_front_desk'),
('admin,owner','admin_owner'),
('admin,cleaning_service,manager,receptionist','front_desk_cleaning'),
('admin,manager,owner','admin_manager_owner'),
('admin,cleaning_service,finance,manager','finance_ops_cleaning'),
('admin,keamanan,manager,receptionist','security_front_desk'),
('admin,finance,owner','admin_finance_owner'),
('admin,finance','admin_finance');


-- ===== sumber: migrations/20261021_LEGACY_ROLE_SETS_COMPLETE.sql =====
-- TAMASYA: lengkapi peta role statis -> access group.
--
-- requireRoles() sudah menyelesaikan daftar role statis lewat access group
-- (rbac_legacy_role_sets -> rbac_access_groups -> rbac_access_group_roles).
-- Tiga daftar role yang dipakai di route TIDAK punya peta, sehingga 51 call site
-- masih Closure Role statis dan tidak bisa dikelola dari panel Kelola Izin.
--
-- Setiap daftar di bawah dipetakan ke access group yanganggotanya PERSIS SAMA
-- dengan daftar role statis, jadi perilaku route tidak berubah sama sekali;
-- yang berubah hanya route tersebut menjadi dapat dikelola dari UI.
--
-- Idempoten: INSERT IGNORE, aman dijalankan berulang.
--
-- 1) "admin" -> grup Admin yang sudah ada.
-- 2) Sembilan role (termasuk Keuangan) -> grup "all_staff" yang sudah ada.
-- 3) Delapan role (tanpa Keuangan) -> grup baru, karena "all_staff" memuat
--    Keuangan dan memakainya akan menambah akses bagi Keuangan.
INSERT IGNORE INTO `rbac_legacy_role_sets` (`role_set`,`group_key`) VALUES
  ('admin','admin_only'),
  ('admin,cleaning_service,finance,keamanan,koki,lain_lain,manager,receptionist,tukang_kebun','all_staff');

INSERT IGNORE INTO `rbac_access_groups` (`group_key`,`label`,`is_system`) VALUES
  ('all_staff_no_finance','Seluruh staf operasional tanpa Keuangan',1);

INSERT IGNORE INTO `rbac_access_group_roles` (`group_key`,`role_key`) VALUES
  ('all_staff_no_finance','admin'),
  ('all_staff_no_finance','cleaning_service'),
  ('all_staff_no_finance','keamanan'),
  ('all_staff_no_finance','koki'),
  ('all_staff_no_finance','lain_lain'),
  ('all_staff_no_finance','manager'),
  ('all_staff_no_finance','receptionist'),
  ('all_staff_no_finance','tukang_kebun');

INSERT IGNORE INTO `rbac_legacy_role_sets` (`role_set`,`group_key`) VALUES
  ('admin,cleaning_service,keamanan,koki,lain_lain,manager,receptionist,tukang_kebun','all_staff_no_finance');


-- ===== sumber: migrations/20261021_TRANSACTION_CURRENCY.sql =====
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


-- ===== sumber: migrations/20261021_TRANSACTION_PROPERTY_AMOUNT.sql =====
-- TAMASYA: nilai transaksi dalam mata uang property.
--
-- Transaksi boleh dicatat dalam mata uang lain (misalnya USD pada property
-- yang menagih IDR). Nominal aslinya tetap disimpan pada `amount` bersama
-- `currency` dan `exchangeRate`, dan hasil konversinya disimpan pada kolom
-- baru `propertyAmount` supaya laporan dapat menjumlahkan seluruh transaksi
-- dalam satu angka mata uang property.
--
-- Kolom `baseAmount` tidak dipakai untuk ini: pada skema kanonik `baseAmount`
-- yang NULL berarti "pajak belum diketahui", bukan "belum dikonversi".
--
-- Idempoten dan tidak mengubah data lama: baris lama dibiarkan NULL, dan
-- pembacanya memperlakukan NULL sebagai "belum ada nilai konversi".

SET @schema := DATABASE();

SET @tx_property_amount := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'propertyAmount'
);
SET @sql := IF(@tx_property_amount = 0,
  'ALTER TABLE `transactions` ADD COLUMN `propertyAmount` decimal(15,2) DEFAULT NULL AFTER `exchangeRate`',
  'SELECT ''transactions.propertyAmount already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ===== sumber: migrations/20261021_GUEST_REVIEWS.sql =====
-- TAMASYA: ulasan/review tamu.
--
-- Alur: staf membuat undangan ulasan (token acak) untuk kamar yang sudah
-- checkout; tamu membuka tautan dan memberi rating + komentar; staf melihat,
-- membalas, dan memoderasi (tampilkan/sembunyikan).
--
-- Semua idempoten dan tidak menghapus data.

CREATE TABLE IF NOT EXISTS `guest_reviews` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `booking_id` varchar(64) NOT NULL,
  `token` varchar(80) DEFAULT NULL,
  `room_number` varchar(40) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `rating` tinyint(1) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'invited',
  `published` tinyint(1) NOT NULL DEFAULT 0,
  `invited_by` varchar(50) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_review_token` (`token`),
  KEY `idx_guest_review_booking` (`booking_id`),
  KEY `idx_guest_review_status` (`property_id`,`status`),
  KEY `idx_guest_review_rating` (`property_id`,`published`,`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tautan undangan yang sudah dipakai tidak boleh dipakai ulang.
CREATE TABLE IF NOT EXISTS `guest_review_invites` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `booking_id` varchar(64) NOT NULL,
  `token` varchar(80) NOT NULL,
  `room_number` varchar(40) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_review_invite_token` (`token`),
  KEY `idx_guest_review_invite_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ===== sumber: migrations/20261021_WHOLESALE_RATES.sql =====
-- TAMASYA: tarif wholesale / kontrak (B2B).
--
-- Hoteloften menjual kamar ke perusahaan dan channel dengan harga kontrak
-- yang berbeda dari tarif rack. Paket ini menyimpan tarif per tanggal, Looked
-- berlaku pada rentang tanggal tertentu, dan dipakai saat reservasi dibuat.
--
-- Semua idempoten dan tidak menghapus data: paket yang dinonaktifkan tetap
-- tersimpan sebagai riwayat.

CREATE TABLE IF NOT EXISTS `wholesale_rate_plans` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `room_type` varchar(80) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `valid_from` date NOT NULL,
  `valid_to` date NOT NULL,
  `min_nights` tinyint(3) NOT NULL DEFAULT 1,
  `max_nights` tinyint(3) NOT NULL DEFAULT 30,
  `cut_off_days` tinyint(3) NOT NULL DEFAULT 0,
  `include_tax` tinyint(1) NOT NULL DEFAULT 1,
  `breakfast_included` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wholesale_plan_code` (`property_id`,`code`),
  KEY `idx_wholesale_plan_window` (`property_id`,`status`,`valid_from`,`valid_to`),
  KEY `idx_wholesale_plan_company` (`property_id`,`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `wholesale_rate_nights` (
  `plan_id` varchar(64) NOT NULL,
  `stay_date` date NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `min_available` int(11) NOT NULL DEFAULT 0,
  `closed` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`plan_id`,`stay_date`),
  KEY `idx_wholesale_night_date` (`stay_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Paket yang dipakai sebuah reservasi agar tarif kontrak tidak berubah diam-diam.
CREATE TABLE IF NOT EXISTS `wholesale_rate_bookings` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `booking_id` varchar(64) NOT NULL,
  `plan_id` varchar(64) NOT NULL,
  `stay_from` date NOT NULL,
  `stay_to` date NOT NULL,
  `nightly_price` decimal(15,2) NOT NULL,
  `nights` tinyint(3) NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wholesale_booking` (`property_id`,`booking_id`),
  KEY `idx_wholesale_booking_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ===== sumber: migrations/20261021_WHOLESALE_RATE_LOCK.sql =====
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


-- ---------------------------------------------------------------------------
-- Verifikasi akhir
-- ---------------------------------------------------------------------------
SELECT 'TAMASYA 20261021: verifikasi peningkatan skema' AS migration_step;
SELECT COUNT(*) AS tabel_terpasang FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
 ('staff_job_titles','role_capability_presets','rbac_roles','rbac_capabilities','rbac_desktop_tabs',
  'rbac_notification_groups','rbac_access_groups','rbac_access_group_roles','rbac_legacy_role_sets',
  'guest_reviews','guest_review_invites','wholesale_rate_plans','wholesale_rate_nights','wholesale_rate_bookings','exchange_rates');
SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='transactions' AND COLUMN_NAME='propertyAmount')>0,1,0) AS transactions_property_amount;
SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='wholesale_rate_bookings' AND COLUMN_NAME='nightly_breakdown')>0,1,0) AS wholesale_nightly_breakdown;
-- Selesai. Lanjut ke deploy/DEPLOY-ID.md.


-- ---------------------------------------------------------------------------
-- LANGKAH TERAKHIR: verifikasi
-- ---------------------------------------------------------------------------
SELECT 'LANGKAH TERAKHIR: verifikasi impor' AS migration_step;
SELECT COUNT(*) AS tabel_rbac FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('staff_job_titles','role_capability_presets','rbac_roles','rbac_capabilities','rbac_desktop_tabs','rbac_notification_groups','rbac_access_groups','rbac_access_group_roles','rbac_legacy_role_sets','guest_reviews','guest_review_invites','wholesale_rate_plans','wholesale_rate_nights','wholesale_rate_bookings','exchange_rates');
SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'propertyAmount') > 0, 1, 0) AS transactions_property_amount;
SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wholesale_rate_bookings' AND COLUMN_NAME = 'nightly_breakdown') > 0, 1, 0) AS wholesale_nightly_breakdown;
-- Selesai. Kembali ke deploy/DEPLOY-ID.md untuk langkah berikutnya.
