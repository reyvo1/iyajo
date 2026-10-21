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
 * Ringkasan keamanan untuk agregasi uang: mata uang property, mata uang yang
 * dipakai, dan apakah ada pencampuran. Laporan yang menjumlahkan nominal
 * harus memeriksa mixedCurrencies sebelum menyajikan satu angka.
 */
function tamasyaTransactionCurrencyGuard(PDO $pdo): array {
    $propertyCurrency = tamasyaPropertyCurrency($pdo);
    $currencies = tamasyaTransactionCurrencies($pdo);
    $foreign = array_values(array_filter($currencies, static fn($code) => $code !== $propertyCurrency));
    return [
        'propertyCurrency' => $propertyCurrency,
        'locale' => tamasyaPropertyLocale($pdo),
        'currencies' => $currencies,
        'foreignCurrencies' => $foreign,
        'mixedCurrencies' => count($foreign) > 0,
    ];
}
