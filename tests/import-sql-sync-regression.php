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
    'migrations/20261019_STAFF_JOB_TITLES.sql',
    'migrations/20261019_ROLE_CAPABILITY_PRESETS.sql',
    'migrations/20261021_DYNAMIC_RBAC.sql',
    'migrations/20261021_LEGACY_ROLE_SETS_COMPLETE.sql',
    'migrations/20261021_TRANSACTION_CURRENCY.sql',
    'migrations/20261021_TRANSACTION_PROPERTY_AMOUNT.sql',
    'migrations/20261021_GUEST_REVIEWS.sql',
    'migrations/20261021_WHOLESALE_RATES.sql',
    'migrations/20261021_WHOLESALE_RATE_LOCK.sql',
];
foreach ($required as $source) {
    $check(str_contains($import, '-- ===== sumber: ' . $source . ' ====='), 'Berkas impor memuat ' . $source);
}
$check(str_contains($import, 'LANGKAH 0'), 'Berkas impor punya langkah pemeriksaan tabel yang sudah ada');
$check(str_contains($import, 'LANGKAH TERAKHIR'), 'Berkas impor punya langkah verifikasi akhir');
$check(str_contains($import, 'information_schema'), 'Pemeriksaan memakai information_schema sehingga aman diulang');
$check(!preg_match('/DROP\s+TABLE/i', $import), 'Berkas impor tidak pernah menghapus tabel');
$check(is_file($root . '/scripts/build-import-sql.mjs'), 'Generator berkas impor tersedia');
$generator = (string)file_get_contents($root . '/scripts/build-import-sql.mjs');
foreach ($required as $source) {
    $check(str_contains($generator, $source), 'Generator memuat ' . $source);
}
echo 'Import SQL sync: ' . $checks . " passed; 0 failed.\n";
