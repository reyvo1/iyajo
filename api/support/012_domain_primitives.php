<?php
/**
 * TAMASYA V137 domain primitives shared by every runtime module.
 *
 * FINAL11 root hardening moves date validation/normalization out of the legacy
 * identity/audit support so tax, biometric, booking, and other modules do not
 * depend on a later unrelated file merely for primitive validation.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 2384: validIsoDate */
function validIsoDate($value) {
    if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

/** TAMASYA V137: normalize a local hotel datetime without UTC conversion. */
function normalizeHotelDateTime($value): ?string {
    if (!is_string($value)) return null;
    $value=trim($value);
    if($value==='')return null;
    $value=str_replace('T',' ',$value);
    if(preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}$/',$value))$value.=':00';
    if(!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/',$value))return null;
    $d=DateTime::createFromFormat('Y-m-d H:i:s',$value);
    return $d&&$d->format('Y-m-d H:i:s')===$value?$value:null;
}

/**
 * Konteks mata uang property.
 *
 * Satu database = satu property, jadi mata uang property adalah sumber
 * kebenaran. Nilai bawaan IDR dipakai bila property belum diatur.
 */
function tamasyaPropertyCurrency(PDO $pdo): string {
    $currency = 'IDR';
    try {
        $stmt = $pdo->query("SELECT `currency` FROM `property_settings` ORDER BY `property_id` LIMIT 1");
        $value = $stmt ? $stmt->fetchColumn() : false;
        if (is_string($value) && trim($value) !== '') { $currency = strtoupper(trim($value)); }
    } catch (Throwable $error) { }
    return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'IDR';
}

function tamasyaPropertyLocale(PDO $pdo): string {
    $locale = 'id-ID';
    try {
        $stmt = $pdo->query("SELECT `locale` FROM `property_settings` ORDER BY `property_id` LIMIT 1");
        $value = $stmt ? $stmt->fetchColumn() : false;
        if (is_string($value) && trim($value) !== '') { $locale = trim($value); }
    } catch (Throwable $error) { }
    return preg_match('/^[a-z]{2}(-[A-Za-z]{2,8})?$/', $locale) === 1 ? $locale : 'id-ID';
}

/**
 * Daftar mata uang yang benar-benar dipakai transaksi pada database ini.
 * Bila lebih dari satu, aplikasi tidak boleh menjumlahkannya diam-diam.
 */
function tamasyaTransactionCurrencies(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT DISTINCT `currency` FROM `transactions` WHERE `currency` IS NOT NULL AND `currency` <> '' ORDER BY `currency`");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $error) { $rows = []; }
    $out = [];
    foreach ((array)$rows as $row) {
        $code = strtoupper(trim((string)$row));
        if ($code !== '') { $out[$code] = true; }
    }
    return array_keys($out);
}

/**
 * Kurs transaksi untuk satu mata uang asing pada satu tanggal.
 *
 * Nilai balik adalah jumlah mata uang property untuk 1 unit mata uang
 * transaksi. Mata uang yang sama dengan property bernilai 1. Bila tidak ada
 * kurs yang berlaku pada atau sebelum tanggal tersebut, fungsi mengembalikan
 * null: pemanggil wajib menolak, bukan memakai asumsi.
 */
function tamasyaTransactionExchangeRate(PDO $pdo, string $propertyCurrency, string $transactionCurrency, string $date): ?float
{
    $base = strtoupper(trim($propertyCurrency));
    $quote = strtoupper(trim($transactionCurrency));
    if ($base === '' || $quote === '') { return null; }
    if ($base === $quote) { return 1.0; }
    $rateDate = validIsoDate($date) ? $date : date('Y-m-d');
    try {
        $stmt = $pdo->prepare("SELECT `rate` FROM `exchange_rates` WHERE `base_currency` = ? AND `quote_currency` = ? AND `rate_date` <= ? ORDER BY `rate_date` DESC LIMIT 1");
        $stmt->execute([$base, $quote, $rateDate]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $error) { return null; }
    if ($value === false || $value === null || !is_numeric($value) || (float)$value <= 0) { return null; }
    return (float)$value;
}

/**
 * Simpan kurs satu hari antara mata uang property dan mata uang asing.
 * Menyimpan ulang hari yang sama memperbarui kurs pada hari itu.
 */
function tamasyaSaveExchangeRate(PDO $pdo, string $baseCurrency, string $quoteCurrency, float $rate, string $rateDate, string $source, string $actorId = ''): string
{
    $base = strtoupper(trim($baseCurrency));
    $quote = strtoupper(trim($quoteCurrency));
    if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote)) {
        throw new InvalidArgumentException('Kode mata uang harus tiga huruf.');
    }
    if ($base === $quote) {
        throw new InvalidArgumentException('Kurs untuk mata uang property sendiri tidak perlu dicatat.');
    }
    if (!is_finite($rate) || $rate <= 0) {
        throw new InvalidArgumentException('Kurs harus lebih besar dari nol.');
    }
    if (!validIsoDate($rateDate)) {
        throw new InvalidArgumentException('Tanggal kurs tidak valid.');
    }
    $id = 'fxr_' . substr(hash('sha256', $base . '|' . $quote . '|' . $rateDate), 0, 32);
    $stmt = $pdo->prepare("INSERT INTO `exchange_rates` (id,`base_currency`,`quote_currency`,`rate`,`rate_date`,`source`,`created_by`) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `rate`=VALUES(`rate`),`source`=VALUES(`source`),`created_by`=VALUES(`created_by`)");
    $stmt->execute([$id, $base, $quote, $rate, $rateDate, trim($source) !== '' ? trim($source) : 'manual', $actorId !== '' ? $actorId : null]);
    return $id;
}

/**
 * Ringkasan keamanan untuk agregasi uang: mata uang property, mata uang yang
 * dipakai, dan apakah ada pencampuran. Laporan yang menjumlahkan nominal
 * harus memeriksa mixedCurrencies sebelum menyajikan satu angka.
 */
function tamasyaTransactionCurrencyGuard(PDO $pdo): array {
    $propertyCurrency = tamasyaPropertyCurrency($pdo);
    $currencies = tamasyaTransactionCurrencies($pdo);
    $foreign = array_values(array_filter($currencies, static fn($code) => $code !== $propertyCurrency));
    $converted = [];
    try {
        $stmt = $pdo->query("SELECT `currency`, COALESCE(SUM(`propertyAmount`),0) AS converted_total, COUNT(*) AS row_count FROM `transactions` WHERE `propertyAmount` IS NOT NULL GROUP BY `currency` ORDER BY `currency`");
        foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $converted[strtoupper(trim((string)$row['currency']))] = [
                'convertedTotal' => (float)$row['converted_total'],
                'rowCount' => (int)$row['row_count'],
            ];
        }
    } catch (Throwable $error) { $converted = []; }
    $propertyTotal = 0.0;
    foreach ($converted as $code => $row) {
        if ($code === $propertyCurrency) { $propertyTotal += (float)$row['convertedTotal']; }
    }
    return [
        'propertyCurrency' => $propertyCurrency,
        'locale' => tamasyaPropertyLocale($pdo),
        'currencies' => $currencies,
        'foreignCurrencies' => $foreign,
        'mixedCurrencies' => count($foreign) > 0,
        'convertedByCurrency' => $converted,
        'propertyCurrencyTotal' => round($propertyTotal, 2),
    ];
}
