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
