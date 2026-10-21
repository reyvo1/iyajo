<?php
/**
 * Berkas impor database harus selalu berisi seluruh migrasi yang aman
 * dijalankan pada database satu properti. Kalau ada migrasi baru yang tidak
 * masuk ke berkas impor, operator akan menemukan fitur yang belum bisa dipakai
 * setelah impor.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$check = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$importPath = $root . '/deploy/IMPORT-RBAC-PHPMYADMIN.sql';
if (!is_file($importPath)) { throw new RuntimeException('Berkas impor wajib tidak ditemukan.'); }
$import = (string)file_get_contents($importPath);

$required = [
    'deploy/20261021_R19_FULL_SCHEMA_UPGRADE.sql',
];
foreach ($required as $source) {
    $check(str_contains($import, '-- ===== sumber: ' . $source . ' ====='), 'Berkas impor memuat ' . $source);
}
$upgrade = (string)file_get_contents($root . '/deploy/20261021_R19_FULL_SCHEMA_UPGRADE.sql');
foreach (['20261019_STAFF_JOB_TITLES','20261019_ROLE_CAPABILITY_PRESETS','20261021_DYNAMIC_RBAC','20261021_LEGACY_ROLE_SETS_COMPLETE','20261021_TRANSACTION_CURRENCY','20261021_TRANSACTION_PROPERTY_AMOUNT','20261021_GUEST_REVIEWS','20261021_WHOLESALE_RATES','20261021_WHOLESALE_RATE_LOCK'] as $migration) {
    $check(str_contains($upgrade, $migration), 'Migrasi gabungan memuat ' . $migration);
}
$check(str_contains($import, 'LANGKAH 0'), 'Berkas impor punya langkah pemeriksaan tabel yang sudah ada');
$check(str_contains($import, 'LANGKAH TERAKHIR'), 'Berkas impor punya langkah verifikasi akhir');
$check(str_contains($import, 'information_schema'), 'Pemeriksaan memakai information_schema sehingga aman diulang');
$check(!preg_match('/DROP\s+TABLE/i', $import), 'Berkas impor tidak pernah menghapus tabel');
foreach (['rbac_roles','rbac_capabilities','rbac_desktop_tabs','rbac_notification_groups','rbac_access_groups','rbac_access_group_roles','rbac_legacy_role_sets','staff_job_titles','role_capability_presets','guest_reviews','guest_review_invites','wholesale_rate_plans','wholesale_rate_nights','wholesale_rate_bookings','exchange_rates'] as $table) {
    $check(str_contains($import, $table), 'Berkas impor memuat tabel nyata: ' . $table);
}
$check(!str_contains($import, 'rbac_telegram_notifications'), 'Berkas impor tidak menyebut tabel yang tidak pernah ada');
$check(is_file($root . '/scripts/build-import-sql.mjs'), 'Generator berkas impor tersedia');
$generator = (string)file_get_contents($root . '/scripts/build-import-sql.mjs');
foreach ($required as $source) {
    $check(str_contains($generator, $source), 'Generator memuat ' . $source);
}
echo 'Import SQL sync: ' . $checks . " passed; 0 failed.\n";
