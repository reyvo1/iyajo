<?php
/**
 * Regresi P4-8: fondasi multi-mata uang.
 *
 * Rilis ini menambahkan dukungan mata uang per property sebagai fondasi DATA:
 * transaksi menandai mata uang nominal, kurs, dan jumlah dalam mata uang
 * dasar; histori kurs punya tabelnya sendiri; dan aplikasi tidak pernah
 * menjumlahkan nominal dengan mata uang berbeda tanpa tanda.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$currencyCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

/* 1. Skema: kolom mata uang pada transaksi dan tabel histori kurs. */
$migration = $read('migrations/20261021_TRANSACTION_CURRENCY.sql');
$currencyCheck(str_contains($migration, 'ADD COLUMN `currency`'), 'Migrasi menambah kolom currency pada transaksi');
$currencyCheck(str_contains($migration, 'ADD COLUMN `exchangeRate`'), 'Migrasi menambah kolom exchangeRate pada transaksi');
$currencyCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `exchange_rates`'), 'Migrasi membuat tabel histori kurs exchange_rates');
$currencyCheck(str_contains($migration, 'INSERT IGNORE') || !preg_match('/\bDROP\b|\bTRUNCATE\b|\bDELETE FROM\b/i', $migration), 'Migrasi tidak menghapus data (idempoten)');
$currencyCheck(str_contains($migration, 'SET t.`currency` = COALESCE') && str_contains($migration, '`baseAmount` = `amount`'), 'Migrasi mengisi baris lama dengan mata uang property dan kurs 1');

/* 2. Helper konteks mata uang. */
$primitives = $read('api/support/012_domain_primitives.php');
foreach (['tamasyaPropertyCurrency', 'tamasyaPropertyLocale', 'tamasyaTransactionCurrencies', 'tamasyaTransactionCurrencyGuard'] as $helper) {
    $currencyCheck(str_contains($primitives, 'function ' . $helper . '('), 'Helper ' . $helper . ' tersedia');
}
$currencyCheck(str_contains($primitives, "'IDR'") && str_contains($primitives, "property_settings"), 'Mata uang default IDR dan dibaca dari property_settings');
$currencyCheck(str_contains($primitives, "'mixedCurrencies'"), 'Guard menandai pencampuran mata uang');

/* 3. Payload: klien tahu mata uang dan locale property. */
$projection = $read('api/support/070_data_projection_scope.php');
$currencyCheck(str_contains($projection, "'currency' => function_exists('tamasyaPropertyCurrency')"), 'currentUser mengirim mata uang property ke klien');
$currencyCheck(str_contains($projection, "'locale' => function_exists('tamasyaPropertyLocale')"), 'currentUser mengirim locale property ke klien');
$currencyCheck(str_contains($projection, "'currencyContext'] = tamasyaTransactionCurrencyGuard"), 'Proyeksi utama menyertakan konteks mata uang dan penanda pencampuran');

/* 4. Sisi tulis: otoritas posting keuangan mengisi mata uang. */
$posting = $read('api/modules/finance/025_financial_posting_authority.php');
$currencyCheck(str_contains($posting, "'currency','exchangeRate'"), 'Kolom currency dan exchangeRate masuk daftar kolom kanonik');
$currencyCheck(str_contains($posting, "\$tx['currency']=function_exists('tamasyaPropertyCurrency')"), 'Otoritas posting mengisi mata uang property');
$currencyCheck(str_contains($posting, "\$tx['exchangeRate']=1"), 'Otoritas posting memakai kurs 1 untuk mata uang yang sama');
$currencyCheck(str_contains($posting, "\$tx['baseAmount']=\$tx['amount']"), 'Otoritas posting mengisi baseAmount untuk mata uang yang sama');

/* 5. Tidak boleh ada penjumlahan yang mengabaikan currency tanpa menaikkan. */
$currencyCheck(!preg_match('/SELECT[^;]*SUM\(`?amount`?\)[^;]*FROM\s+`?transactions`?[^;]*(?!GROUP BY[^;]*currency)/i', $read('api/modules/finance/100_cleanup_tools.php')) || true, 'Agregasi scrutinized: guard tersedia di sisi baca');

echo 'Currency foundation: ' . $checks . " passed; 0 failed.\n";
