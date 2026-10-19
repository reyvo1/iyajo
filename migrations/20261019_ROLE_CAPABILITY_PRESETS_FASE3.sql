-- TAMASYA Fase 3 "Kelola Jabatan": tiga capability baru untuk 095 laporan kanonik
-- (view_reports), 017/018 kotak masuk publik (manage_public_inbox), dan 096 master
-- fasilitas (manage_facilities). Baris paket yang sudah ada ditambahi kunci baru
-- dengan nilai default seed jabatan; centangan yang sudah disimpan pemilik TIDAK
-- ditimpa. Idempoten: baris yang sudah memuat view_reports dilewati seluruhnya
-- (guard JSON_EXTRACT ... IS NULL), jadi migrasi boleh dijalankan ulang.
-- Fresh install memakai database_setup.sql yang sudah memuat 22 kunci lengkap.
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":true,"manage_public_inbox":true,"manage_facilities":true}}') WHERE `role` IN ('admin','manager') AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":true,"manage_public_inbox":false,"manage_facilities":false}}') WHERE `role` = 'finance' AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":false,"manage_public_inbox":true,"manage_facilities":false}}') WHERE `role` = 'receptionist' AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
UPDATE `role_capability_presets` SET `permissions`=JSON_MERGE_PATCH(`permissions`,'{"capabilities":{"view_reports":false,"manage_public_inbox":false,"manage_facilities":false}}') WHERE `role` IN ('cleaning_service','keamanan','koki','tukang_kebun','lain_lain') AND JSON_EXTRACT(`permissions`,'$.capabilities.view_reports') IS NULL;
