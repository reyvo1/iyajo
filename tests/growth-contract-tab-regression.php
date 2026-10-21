<?php
/**
 * Regresi tab Kontrak B2B pada Growth Suite.
 *
 * Growth Suite menampilkan paket tarif kontrak, menghitung harga satu inap,
 * dan lettingkan paket terpilih untuk dipakai pemesanan di PMS. Kunci
 * penyimpanan harus sama dengan add-on tarif kontrak, supaya paket yang
 * dipilih di Growth Suite dipakai saat pemesanan dibuat.
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

$html = $read('growth-suite.html');
$js = $read('assets/growth-suite.js');
$server = $read('api/routes/110_growth_suite.php');
$addon = $read('assets/wholesale-contract-addon.js');

$check(str_contains($html, 'data-tab="contract"'), 'Growth Suite punya tombol tab Kontrak B2B');
$check(str_contains($html, 'id="tab-contract"'), 'Growth Suite punya panel tab kontrak');
$check(str_contains($html, 'id="contract-list"'), 'Panel memuat daftar paket kontrak');
$check(str_contains($html, 'id="contract-quote"'), 'Panel memuat hasil pencarian harga');
$check(str_contains($html, 'id="contract-bookings"'), 'Panel memuat daftar reservasi kontrak');
$check(str_contains($html, 'id="contract-quote-btn"'), 'Panel punya tombol cari harga');

$check(str_contains($js, 'function renderContract('), 'Growth Suite merender paket kontrak');
$check(str_contains($js, 'function loadContract('), 'Growth Suite menghitung harga kontrak');
$check(str_contains($js, "api('contract-quote'"), 'Permintaan harga kontrak memakai endpoint growth suite');
$check(str_contains($js, "name==='contract'"), 'Pindah tab memuat data kontrak');
$check(str_contains($js, 'contract:feature('), 'Tab kontrak mengikuti modul rate');
$check(str_contains($js, 'data-contract-use='), 'Ada tombol pakai paket ini');
$check(str_contains($js, 'tamasya_wholesale_contract_selection'), 'Paket terpilih memakai kunci yang sama dengan add-on');
$check(str_contains($addon, 'tamasya_wholesale_contract_selection'), 'Add-on tarif kontrak memakai kunci yang sama');
$check(str_contains($js, 'contractMoney('), 'Nominal kontrak diformat mengikuti mata uang paket');

$check(str_contains($server, "'wholesalePlans'"), 'Server Growth Suite mengirim paket kontrak');
$check(str_contains($server, "'wholesaleBookings'"), 'Server Growth Suite mengirim reservasi kontrak');
$check(str_contains($server, "case 'contract-quote':"), 'Server Growth Suite punya aksi kuotasi kontrak');
$check(str_contains($server, 'tamasyaWholesaleContractRateQuote('), 'Kuotasi memakai helper tarif kontrak yang sama dengan server');
$check(str_contains($server, 'catch(Throwable $wholesaleSchemaMissing)'), 'Skema kontrak yang belum ada tidak menggagalkan Growth Suite');

echo 'Growth contract tab: ' . $checks . " passed; 0 failed.\n";
