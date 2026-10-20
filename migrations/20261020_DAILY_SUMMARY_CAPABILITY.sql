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
