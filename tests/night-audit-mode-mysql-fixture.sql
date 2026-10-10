CREATE TABLE IF NOT EXISTS hotel_operational_settings (
 id VARCHAR(40) PRIMARY KEY,
 require_night_audit_for_night_shift TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO hotel_operational_settings(id) VALUES ('system_default') ON DUPLICATE KEY UPDATE id=id;
CREATE TABLE IF NOT EXISTS night_audit_runs (
 id VARCHAR(80) PRIMARY KEY,shift_session_id VARCHAR(80),status VARCHAR(20),completed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS night_audit_items (
 id VARCHAR(80) PRIMARY KEY,audit_id VARCHAR(80),discrepancy_type VARCHAR(60),resolution_status VARCHAR(25)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
