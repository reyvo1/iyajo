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
$check(str_contains($js, 'contract:true'), 'Tab kontrak punya ketersediaan sendiri, tidak terikat modul rate Growth');
$check(str_contains($server, "modules']['contract']"), 'Server menentukan sendiri apakah tab kontrak tampil');
$check(str_contains($js, 'data-contract-use='), 'Ada tombol pakai paket ini');
$check(str_contains($js, 'tamasya_wholesale_contract_selection'), 'Paket terpilih memakai kunci yang sama dengan add-on');
$check(str_contains($addon, 'tamasya_wholesale_contract_selection'), 'Add-on tarif kontrak memakai kunci yang sama');
$check(str_contains($js, 'contractMoney('), 'Nominal kontrak diformat mengikuti mata uang paket');

$check(str_contains($server, "'wholesalePlans'"), 'Server Growth Suite mengirim paket kontrak');
$check(str_contains($server, "'wholesaleBookings'"), 'Server Growth Suite mengirim reservasi kontrak');
$check(str_contains($server, "case 'contract-quote':"), 'Server Growth Suite punya aksi kuotasi kontrak');
$postGate = strpos($server, "if(\$method!=='POST')");
$quoteCase = strpos($server, "case 'contract-quote':");
$rateCase = strpos($server, "case 'exchange-rate-save':");
$getGate = strpos($server, "if(\$method==='GET')");
$check($postGate !== false && $quoteCase > $postGate, 'Aksi kuotasi kontrak berada di cabang POST (UI mengirim POST)');
$check($postGate !== false && $rateCase > $postGate, 'Aksi simpan kurs berada di cabang POST (UI mengirim POST)');
$check($getGate === false || $quoteCase < $getGate || $quoteCase > $postGate, 'Aksi kuotasi kontrak tidak tertinggal di cabang GET');
$check(str_contains($server, 'tamasyaWholesaleContractRateQuote('), 'Kuotasi memakai helper tarif kontrak yang sama dengan server');
$check(str_contains($server, 'catch(Throwable $wholesaleSchemaMissing)'), 'Skema kontrak yang belum ada tidak menggagalkan Growth Suite');

/* Kurs mata uang: API, server, dan UI harus lengkap. */
$check(str_contains($html, 'id="exchange-rate-list"'), 'Growth Suite punya tabel kurs mata uang');
$check(str_contains($html, 'id="exchange-rate-save"'), 'Growth Suite punya tombol catat kurs');
$check(str_contains($html, 'id="exchange-rate-currency"'), 'Growth Suite punya pilihan mata uang');
$check(str_contains($js, 'function renderExchangeRates('), 'Growth Suite merender kurs mata uang');
$check(str_contains($js, "api('exchange-rate-save'"), 'Penyimpanan kurs memakai endpoint growth suite');
$check(str_contains($server, "case 'exchange-rate-save':"), 'Server Growth Suite punya aksi simpan kurs');
$check(str_contains($server, "'exchangeRates'"), 'Server Growth Suite mengirim kurs mata uang');
$check(str_contains($server, "modules']['contract']=\$canRoomRead"), 'Tab kontrak tetap tampil walau skema belum ada');
$check(str_contains($server, "'wholesaleSchemaReady'"), 'Server memberi tahu apakah skema tarif kontrak sudah siap');
$check(str_contains($js, 'wholesaleSchemaReady'), 'Growth Suite memeriksa kesiapan skema');
$check(str_contains($js, 'contract:true'), 'Tab kontrak tidak pernah disembunyikan peta fitur');
$check(str_contains($js, 'IMPORT-RBAC-PHPMYADMIN.sql'), 'Growth Suite memberi instruksi impor bila skema belum ada');
$check(str_contains($server, 'tamasyaSaveExchangeRate('), 'Penyimpanan kurs memakai helper yang sama dengan API kurs');
$check(str_contains($read('api/support/012_domain_primitives.php'), 'function tamasyaSaveExchangeRate('), 'Helper simpan kurs tersedia');
$check(str_contains($read('api/routes/089_exchange_rates.php'), 'tamasyaSaveExchangeRate('), 'API kurs memakai helper yang sama');

echo 'Growth contract tab: ' . $checks . " passed; 0 failed.\n";
