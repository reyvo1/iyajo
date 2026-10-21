<?php
/**
 * Regresi P2-5: tarif wholesale / kontrak (B2B).
 *
 * Paket tarif menyimpan harga per tanggal untuk satu perusahaan, channel, atau
 * grup. Tulis memakai capability, pembacaan mengikuti hak penjualan kamar, dan
 * paket yang dinonaktifkan tetap tersimpan sebagai riwayat.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$wholesaleCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$route = $read('api/routes/088_wholesale_rates.php');
$migration = $read('migrations/20261021_WHOLESALE_RATES.sql');
$canonical = $read('database_setup.sql');
$api = $read('api.php');
$router = $read('api/router.php');
$manifest = json_decode($read('api/MODULARIZATION_MANIFEST.json'), true);

/* Skema */
$wholesaleCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `wholesale_rate_plans`'), 'Migrasi membuat tabel wholesale_rate_plans');
$wholesaleCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `wholesale_rate_nights`'), 'Migrasi membuat tabel wholesale_rate_nights');
$wholesaleCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `wholesale_rate_bookings`'), 'Migrasi membuat tabel wholesale_rate_bookings (kunci tarif saat reservasi)');
$wholesaleCheck(str_contains($canonical, '`wholesale_rate_plans`'), 'Skema kanonik punya tabel paket wholesale');
$wholesaleCheck(str_contains($canonical, '`wholesale_rate_nights`'), 'Skema kanonik punya tabel harga per tanggal');
$wholesaleCheck(str_contains($canonical, '`wholesale_rate_bookings`'), 'Skema kanonik punya tabel kunci tarif reservasi');
$wholesaleCheck(str_contains($migration, 'UNIQUE KEY `uq_wholesale_plan_code`'), 'Kode paket unik per property');
$wholesaleCheck(str_contains($migration, 'UNIQUE KEY `uq_wholesale_booking`'), 'Satu reservasi tidak bisa punya dua paket tarif');

/* Registrasi */
$wholesaleCheck(str_contains($router, "require __DIR__ . '/routes/088_wholesale_rates.php';"), 'Router memuat route tarif wholesale');
$wholesaleCheck(in_array('088_wholesale_rates.php', (array)($manifest['routeModules'] ?? []), true), 'Manifest mendaftarkan route tarif wholesale');
$wholesaleCheck(str_contains($api, "'wholesale-rates'=>['operations','report','rooms']"), 'Endpoint wholesale mengikuti tab operations/report/rooms');

/* Aturan main */
$wholesaleCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'manage_operational_settings'"), 'Tulis tarif memakai capability manage_operational_settings');
$wholesaleCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'sell_room_access'"), 'Baca tarif memakai permission penjualan kamar');
$wholesaleCheck(str_contains($route, "'GET'") && str_contains($route, "METHOD"), 'Aksi tulis memakai POST, aksi baca GET');
$wholesaleCheck(str_contains($route, "Rentang tanggal paket tidak valid"), 'Rentang tanggal paket divalidasi');
$wholesaleCheck(str_contains($route, "Minimal satu harga per tanggal wajib diisi"), 'Paket wajib punya minimal satu harga per tanggal');
$wholesaleCheck(str_contains($route, 'ON DUPLICATE KEY UPDATE'), 'Penyimpanan paket tidak menggandakan baris');
$wholesaleCheck(str_contains($route, "writeRequiredEnterpriseAudit"), 'Perubahan paket dicatat di audit');
$wholesaleCheck(str_contains($route, "Paket tidak ditemukan di property ini"), 'Paket di luar scope property ditolak');
$wholesaleCheck(!preg_match('/\bDELETE FROM\b/i', $route), 'Paket tidak pernah dihapus fisik (dinonaktifkan)');

echo 'Wholesale rates: ' . $checks . " passed; 0 failed.\n";
