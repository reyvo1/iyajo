-- TAMASYA R15: facility management (additive).
-- Run against a property DB AFTER a verified backup; never against a shared HQ DB.
-- Idempotent: safe to run repeatedly.
--
-- Background: hotels sell facilities with different shapes (pool day-pass, gym
-- visit/member, karaoke room by the hour, bar items, spa by therapist). A static
-- per-facility table would need code for every new facility, so the engine is
-- data-driven instead: facility_types describe the capacity and pricing model,
-- facilities are units of a type, and facility_bookings consume capacity.
-- Money never moves here: usage posts a folio extra through the canonical
-- booking workflow, exactly like a service charge, and settlement stays in the
-- normal payment/checkout flow.
SET @facility_types_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_types'
);
SET @facility_types_sql = IF(
  @facility_types_exists = 0,
  'CREATE TABLE facility_types (
        id VARCHAR(100) PRIMARY KEY, code VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL,
        capacity_model VARCHAR(20) NOT NULL DEFAULT ''open'', pricing_model VARCHAR(20) NOT NULL DEFAULT ''visit'',
        requires_staff TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_facility_type_code(code), INDEX idx_facility_type_active(active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT ''facility_types already installed'' AS migration_status'
);
PREPARE facility_types_stmt FROM @facility_types_sql;
EXECUTE facility_types_stmt;
DEALLOCATE PREPARE facility_types_stmt;

SET @facilities_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facilities'
);
SET @facilities_sql = IF(
  @facilities_exists = 0,
  'CREATE TABLE facilities (
        id VARCHAR(100) PRIMARY KEY, code VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL,
        facility_type_id VARCHAR(100) NOT NULL, capacity INT NOT NULL DEFAULT 0,
        open_time TIME NULL, close_time TIME NULL, base_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_facility_code(code), INDEX idx_facility_type(facility_type_id,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT ''facilities already installed'' AS migration_status'
);
PREPARE facilities_stmt FROM @facilities_sql;
EXECUTE facilities_stmt;
DEALLOCATE PREPARE facilities_stmt;

SET @facility_bookings_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings'
);
SET @facility_bookings_sql = IF(
  @facility_bookings_exists = 0,
  'CREATE TABLE facility_bookings (
        id VARCHAR(100) PRIMARY KEY, facility_id VARCHAR(100) NOT NULL, booking_id VARCHAR(100) NULL,
        guest_name VARCHAR(150) NOT NULL, use_date DATE NOT NULL,
        start_time TIME NULL, end_time TIME NULL, pax INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(15,2) NOT NULL DEFAULT 0, total DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT ''reserved'', payment_status VARCHAR(20) NOT NULL DEFAULT ''unpaid'',
        folio_extra_id VARCHAR(100) NULL, operation_id VARCHAR(100) NULL, notes VARCHAR(500) NULL,
        created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_facility_booking_operation(operation_id), INDEX idx_facility_booking_slot(facility_id,use_date,status),
        INDEX idx_facility_booking_guest(booking_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT ''facility_bookings already installed'' AS migration_status'
);
PREPARE facility_bookings_stmt FROM @facility_bookings_sql;
EXECUTE facility_bookings_stmt;
DEALLOCATE PREPARE facility_bookings_stmt;
