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
