<?php
/**
 * Regresi mesin kurs transaksi (multi-mata uang v2).
 *
 * Kurs disimpan per hari antara mata uang property dan mata uang asing. Saat
 * transaksi dicatat, nominal asing disimpan apa adanya dan hasil konversinya
 * disimpan terpisah. Tanpa kurs yang berlaku, transaksi ditolak: nominal
 * asing tidak boleh dicatat seolah-olah sudah menjadi mata uang property.
 */
$root = dirname(__DIR__);
$read = static function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};

if (!defined('TAMASYA_API_ENTRY')) { define('TAMASYA_API_ENTRY', true); }
require_once $root . '/api/support/012_domain_primitives.php';

$posting = $read('api/modules/finance/025_financial_posting_authority.php');
$primitives = $read('api/support/012_domain_primitives.php');
$route = $read('api/routes/089_exchange_rates.php');
$migration = $read('migrations/20261021_TRANSACTION_PROPERTY_AMOUNT.sql');

$check(str_contains($primitives, 'function tamasyaTransactionExchangeRate('), 'Helper kurs transaksi tersedia');
$check(str_contains($posting, 'tamasyaTransactionExchangeRate('), 'Otoritas posting memakai kurs dari exchange_rates');
$check(str_contains($posting, 'belum tersedia'), 'Transaksi tanpa kurs ditolak, bukan diasumsikan');
$check(str_contains($posting, "propertyAmount"), 'Nilai konversi disimpan pada kolom propertyAmount');
$check(str_contains($posting, "'propertyAmount'"), 'Kolom propertyAmount masuk daftar kolom kanonik');
$check(!str_contains($posting, "baseAmount']=round"), 'baseAmount tidak diisi otomatis (tetap berarti pajak belum diketahui)');
$check(str_contains($primitives, "'convertedByCurrency'"), 'Ringkasan keamanan uang melaporkan total per mata uang');
$check(str_contains($primitives, "'propertyCurrencyTotal'"), 'Ringkasan melaporkan total dalam mata uang property');
$check(str_contains($route, "requireCapability(\$loggedInStaff, 'manage_operational_settings'"), 'Menulis kurs memakai capability pengaturan operasional');
$check(str_contains($route, 'exchange-rate-save'), 'Endpoint simpan kurs tersedia');
$check(str_contains($migration, 'propertyAmount'), 'Migrasi menambah kolom propertyAmount');
$check(str_contains($read('database_setup.sql'), '`propertyAmount`'), 'Skema kanonik punya kolom propertyAmount');

$dsn = trim((string)getenv('TAMASYA_CATALOG_UAT_DSN'));
if ($dsn === '') {
    echo 'SKIP pemeriksaan fungsional kurs: TAMASYA_CATALOG_UAT_DSN belum diisi (' . $checks . " pemeriksaan statis lolos)\n";
    exit(0);
}
$pdo = new PDO($dsn, (string)getenv('TAMASYA_CATALOG_UAT_USER'), (string)getenv('TAMASYA_CATALOG_UAT_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$base = tamasyaPropertyCurrency($pdo);
$quote = $base === 'IDR' ? 'USD' : 'IDR';
$rateDate = date('Y-m-d', strtotime('-2 day'));
$earlier = date('Y-m-d', strtotime('-10 day'));
$id = 'uat_fx_' . substr(hash('sha256', $base . $quote), 0, 12);

$pdo->prepare("DELETE FROM `exchange_rates` WHERE `id` = ?")->execute([$id]);
$pdo->prepare("INSERT INTO `exchange_rates` (id,`base_currency`,`quote_currency`,`rate`,`rate_date`,`source`) VALUES (?,?,?,?,?,'uat')")->execute([$id, $base, $quote, 16000, $rateDate]);

$check(tamasyaTransactionExchangeRate($pdo, $base, $base, $rateDate) === 1.0, 'Kurs mata uang yang sama bernilai satu');
$check(abs((float)tamasyaTransactionExchangeRate($pdo, $base, $quote, $rateDate) - 16000.0) < 0.0001, 'Kurs pada tanggal yang sama dipakai');
$check(abs((float)tamasyaTransactionExchangeRate($pdo, $base, $quote, date('Y-m-d')) - 16000.0) < 0.0001, 'Kurs terakhir pada atau sebelum tanggal dipakai');
$check(tamasyaTransactionExchangeRate($pdo, $base, $quote, $earlier) === null, 'Kurs sebelum tanggal kurs pertama tidak dikarang');
$check(tamasyaTransactionExchangeRate($pdo, $base, 'JPY', date('Y-m-d')) === null, 'Mata uang tanpa kurs menghasilkan null, bukan asumsi');

$guard = tamasyaTransactionCurrencyGuard($pdo);
$check(($guard['propertyCurrency'] ?? '') === $base, 'Ringkasan melaporkan mata uang property');
$check(array_key_exists('convertedByCurrency', $guard), 'Ringkasan memuat total hasil konversi per mata uang');
$check(array_key_exists('propertyCurrencyTotal', $guard), 'Ringkasan memuat total dalam mata uang property');

$pdo->prepare("DELETE FROM `exchange_rates` WHERE `id` = ?")->execute([$id]);
echo 'Exchange rate engine: ' . $checks . " passed; 0 failed.\n";
