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
