#!/usr/bin/env node
/**
 * Membangun deploy/IMPORT-RBAC-PHPMYADMIN.sql dari berkas migrasi.
 *
 * Operator tidak boleh membuka banyak berkas migrasi satu per satu di
 * phpMyAdmin. Berkas ini menggabungkan seluruh migrasi yang aman dijalankan
 * pada database satu properti, berurutan, dan idempoten.
 *
 * Berkas hasil selalu dibangun ulang dari sumbernya, sehingga tidak mungkin
 * tertinggal satu migrasi. Jalankan: node scripts/build-import-sql.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/* Urutan ini penting: dasar RBAC lebih dulu, baru fitur yang bergantung padanya. */
const sources = [
  'migrations/20261019_STAFF_JOB_TITLES.sql',
  'migrations/20261019_ROLE_CAPABILITY_PRESETS.sql',
  'migrations/20261019_ROLE_CAPABILITY_PRESETS_FASE3.sql',
  'migrations/20261020_DAILY_SUMMARY_CAPABILITY.sql',
  'migrations/20261021_DYNAMIC_RBAC.sql',
  'migrations/20261021_LEGACY_ROLE_SETS_COMPLETE.sql',
  'migrations/20261021_TRANSACTION_CURRENCY.sql',
  'migrations/20261021_TRANSACTION_PROPERTY_AMOUNT.sql',
  'migrations/20261021_GUEST_REVIEWS.sql',
  'migrations/20261021_WHOLESALE_RATES.sql',
  'migrations/20261021_WHOLESALE_RATE_LOCK.sql',
];

/* Tabel yang dibaca langkah 0 supaya operator tahu apa yang sudah ada. */
const watched = [
  'staff_job_titles', 'role_capability_presets', 'rbac_roles', 'rbac_capabilities',
  'rbac_desktop_tabs', 'rbac_telegram_notifications', 'rbac_access_groups',
  'rbac_legacy_role_sets', 'guest_reviews', 'guest_review_invites',
  'wholesale_rate_plans', 'wholesale_rate_nights', 'wholesale_rate_bookings', 'exchange_rates',
];

const header = `-- ---------------------------------------------------------------------------
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
${watched.map((table) => `--   ${table}`).join('\n')}
-- ---------------------------------------------------------------------------

SELECT 'LANGKAH 0: tabel yang sudah ada' AS migration_step;
${watched.map((table) => `SELECT '${table}' AS table_name, IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}') > 0, 1, 0) AS already_exists;`).join('\n')}
`;

const footer = `
-- ---------------------------------------------------------------------------
-- LANGKAH TERAKHIR: verifikasi
-- ---------------------------------------------------------------------------
SELECT 'LANGKAH TERAKHIR: verifikasi impor' AS migration_step;
SELECT COUNT(*) AS tabel_rbac FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (${watched.map((table) => `'${table}'`).join(',')});
SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'propertyAmount') > 0, 1, 0) AS transactions_property_amount;
SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wholesale_rate_bookings' AND COLUMN_NAME = 'nightly_breakdown') > 0, 1, 0) AS wholesale_nightly_breakdown;
-- Selesai. Kembali ke deploy/DEPLOY-ID.md untuk langkah berikutnya.
`;

const parts = [header];
for (const source of sources) {
  const file = path.join(root, source);
  if (!fs.existsSync(file)) {
    console.error(`GAGAL: berkas migrasi tidak ditemukan: ${source}`);
    process.exit(1);
  }
  const body = fs.readFileSync(file, 'utf8').trim();
  parts.push(`\n-- ===== sumber: ${source} =====\n${body}\n`);
}
parts.push(footer);

const outFile = path.join(root, 'deploy/IMPORT-RBAC-PHPMYADMIN.sql');
fs.writeFileSync(outFile, parts.join('\n'), 'utf8');
const size = fs.statSync(outFile).size;
console.log(`Importer database ditulis: deploy/IMPORT-RBAC-PHPMYADMIN.sql (${sources.length} migrasi, ${Math.round(size / 1024)} KB)`);
