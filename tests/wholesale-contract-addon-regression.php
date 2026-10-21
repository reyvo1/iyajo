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
$check(str_contains($loader, "current==='rooms'") || str_contains($loader, "current==='rooms'||current==='reservations'"), 'Add-on tarif kontrak dimuat pada rute tempat pemesanan dibuat');
$wholesaleRouteLine='';
foreach (explode("\n", $loader) as $loaderLine) { if (str_contains($loaderLine, 'loadWholesaleContract')) { $wholesaleRouteLine = $loaderLine; } }
$check(str_contains($wholesaleRouteLine, "'rooms'") && str_contains($wholesaleRouteLine, "'reservations'"), 'Add-on tarif kontrak dimuat di tab Kamar dan tab Reservasi');
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
$check(str_contains($addon, "method: 'POST', command: 'wholesale-rate-bookings'"), 'Daftar reservasi kontrak diambil dengan POST (server hanya melayani perintah ini di jalur tulis)');
$check(str_contains($addon, 'WHOLESALE_SCHEMA_MISSING'), 'Add-on mengenali kondisi skema tarif belum ada');
$check(str_contains($addon, 'IMPORT-RBAC-PHPMYADMIN.sql'), 'Add-on memberi instruksi impor, bukan pesan teknis');
$check(str_contains($addon, 'sessionStorage.setItem(SELECTION_KEY'), 'Paket terpilih disimpan di sesi, bukan di memori halaman');
$check(str_contains($addon, 'tamasya_wholesale_contract_selection'), 'Kunci penyimpanan paket terpilih bernama jelas');
$check(!preg_match('/[\x{4e00}-\x{9fff}]/u', $addon), 'Teks add-on bebas karakter asing');
$check(!str_contains($addon, '2f4straight'), 'Gaya panel add-on tidak memakai warna placeholder');

/* Tidak boleh menutupi kontrol aplikasi yang sudah ada di pojok kanan bawah. */
$reportCenter = $read('assets/canonical-report-center.js');
$reportBottom = 22; // tombol laporan: right:22px;bottom:22px
if (preg_match('/#tamasya-report-center-btn\{[^}]*bottom:(\d+)px/', $reportCenter, $match)) {
    $reportBottom = (int)$match[1];
}
$check(preg_match('/#tamasya-wholesale-button|button\.style\.cssText = \'[^\']*bottom:(\d+)px/', $addon, $match) === 1, 'Tombol tarif kontrak punya posisi tetap yang terukur');
$triggerBottom = isset($match[1]) ? (int)$match[1] : 0;
$check($triggerBottom > $reportBottom, 'Tombol tarif kontrak duduk di atas tombol laporan, tidak menimpanya');
$check(str_contains($addon, 'linear-gradient(135deg,#312e81,#4f46e5)'), 'Tombol tarif kontrak memakai warna tombol aplikasi');
$check(str_contains($addon, 'inset:0') && str_contains($addon, 'backdrop-filter'), 'Panel tarif kontrak memakai overlay modal seperti modal laporan');
$check(!str_contains($addon, 'right:16px;bottom:74px'), 'Panel tidak lagi melayang di pojok sebagai kartu');
$check(!preg_match('/z-index:2147483(?!000)/', $addon), 'Z-index tombol tidak melampaui tombol aplikasi');

echo 'Wholesale contract add-on: ' . $checks . " passed; 0 failed.\n";
