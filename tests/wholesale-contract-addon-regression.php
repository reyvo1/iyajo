<?php
/**
 * Regresi add-on tarif kontrak B2B.
 *
 * Add-on antarmuka untuk tarif kontrak tidak boleh menyentuh bundle yang
 * sudah diperkecil, dan tidak boleh mengubah nominal yang dikirim browser:
 * server yang berwenang atas harga. Pemeriksaan ini menjaga kedua hal itu
 * dan menjaga add-on tetap terdaftar di halaman aplikasi serta service worker.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$check = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$addon = $read('assets/wholesale-contract-addon.js');
$index = $read('index.html');
$sw = $read('sw.js');

$loader = $read('assets/runtime-addon-loader.js');
$check(!str_contains($index, 'assets/wholesale-contract-addon.js'), 'Add-on tarif kontrak tidak dimuat eager di halaman aplikasi (batas aset awal)');
$check(str_contains($loader, "loadScript('assets/wholesale-contract-addon.js'"), 'Add-on tarif kontrak dimuat lewat runtime addon loader');
$check(str_contains($loader, "loadWholesaleContract"), 'Runtime loader punya pemuat tarif kontrak');
$check(str_contains($loader, "['rooms','reservations'].includes(current)") || str_contains($loader, "['rooms', 'reservations'].includes(current)"), 'Add-on tarif kontrak dimuat pada rute tempat pemesanan dibuat');
$check(!str_contains($sw, 'assets/wholesale-contract-addon.js'), 'Add-on tarif kontrak tidak di-precache service worker (dimuat sesuai kebutuhan)');
$check(str_contains($addon, 'window.fetch = async function'), 'Add-on membungkus fetch untuk menambahkan paket kontrak ke pemesanan');
$check(str_contains($addon, "action === 'bookings'"), 'Penambahan paket kontrak hanya pada pembuatan pemesanan');
$check(str_contains($addon, "method !== 'POST'"), 'Penambahan paket kontrak hanya pada metode POST');
$check(str_contains($addon, 'wholesalePlanId'), 'Permintaan pemesanan membawa paket kontrak terpilih');
$check(str_contains($addon, 'wholesaleCompanyName'), 'Permintaan pemesanan membawa perusahaan pemilik kontrak');
$check(!preg_match('/totalAmount\s*[:=]\s*/', $addon), 'Add-on tidak pernah menulis nominal total sendiri');
$check(str_contains($addon, 'sameStay') && str_contains($addon, 'sameRoom'), 'Paket hanya dipakai bila inap dan tipe kamar cocok');
$check(str_contains($addon, "hotel_session_token"), 'Add-on memakai token sesi yang sama dengan add-on lain');
$check(str_contains($addon, "tamasya_active_api_url"), 'Add-on memakai basis API aktif yang sama dengan add-on lain');
$check(str_contains($addon, 'wholesale-rate-lookup'), 'Add-on memakai endpoint kuotasi tarif kontrak');
$check(str_contains($addon, 'wholesale-rate-save'), 'Add-on memakai endpoint simpan paket kontrak');
$check(str_contains($addon, 'wholesale-rate-bookings'), 'Add-on memakai endpoint daftar reservasi kontrak');
$check(str_contains($addon, 'sessionStorage.setItem(SELECTION_KEY'), 'Paket terpilih disimpan di sesi, bukan di memori halaman');
$check(str_contains($addon, 'tamasya_wholesale_contract_selection'), 'Kunci penyimpanan paket terpilih bernama jelas');
$check(!preg_match('/[\x{4e00}-\x{9fff}]/u', $addon), 'Teks add-on bebas karakter asing');
$check(!str_contains($addon, '2f4straight'), 'Gaya panel add-on tidak memakai warna placeholder');

echo 'Wholesale contract add-on: ' . $checks . " passed; 0 failed.\n";
