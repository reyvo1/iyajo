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
