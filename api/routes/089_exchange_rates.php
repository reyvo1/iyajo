<?php
/**
 * Kurs transaksi (exchange_rates).
 *
 * Menyimpan histori kurs antara mata uang property dan mata uang asing, satu
 * baris per hari. Kurs dipakai saat transaksi dicatat: nominal dalam mata uang
 * asing disimpan apa adanya, dan hasil konversinya ke mata uang property
 * disimpan terpisah sehingga laporan bisa menjumlahkan semua transaksi dalam
 * satu angka mata uang property.
 *
 * Membaca daftar kurs mengikuti hak akses keuangan; menulis kurs memakai
 * capability pengaturan operasional.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
$action = (string)($action ?? '');
if (!in_array($action, ['exchange-rates'], true)) return;
$routeHandled = true;

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $propertyCurrency = tamasyaPropertyCurrency($pdo);
    $command = trim((string)($input['command'] ?? ($_GET['command'] ?? '')));

    if ($method === 'GET') {
        requireCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'finance']);
        $rows = [];
        $summary = [];
        try {
            $stmt = $pdo->prepare("SELECT `id`,`base_currency`,`quote_currency`,`rate`,`rate_date`,`source`,`created_by`,`created_at` FROM `exchange_rates` WHERE `base_currency` = ? ORDER BY `quote_currency`,`rate_date` DESC LIMIT 500");
            $stmt->execute([$propertyCurrency]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $error) {
            http_response_code(409);
            echo tamasyaJsonEncode(['success' => false, 'code' => 'EXCHANGE_RATE_SCHEMA_MISSING', 'error' => 'Tabel kurs belum tersedia di database ini. Jalankan migrasi 20261021_TRANSACTION_CURRENCY.sql.']);
            return;
        }
        foreach ($rows as $row) {
            $quote = strtoupper(trim((string)$row['quote_currency']));
            if (!isset($summary[$quote])) {
                $summary[$quote] = ['quoteCurrency' => $quote, 'latestRate' => (float)$row['rate'], 'latestDate' => (string)$row['rate_date'], 'rows' => 0];
            }
            $summary[$quote]['rows']++;
        }
        echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => [
            'baseCurrency' => $propertyCurrency,
            'rows' => $rows,
            'summary' => array_values($summary),
        ]]);
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo tamasyaJsonEncode(['success' => false, 'error' => 'Kurs hanya dapat ditulis dengan POST.']);
        return;
    }

    requireCapability($loggedInStaff, 'manage_operational_settings', ['admin', 'manager']);

    if ($command === 'exchange-rate-save') {
        $quoteCurrency = strtoupper(trim((string)($input['quoteCurrency'] ?? '')));
        $rate = (float)($input['rate'] ?? 0);
        $rateDate = trim((string)($input['rateDate'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $quoteCurrency)) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Kode mata uang harus tiga huruf.']);
            return;
        }
        if ($quoteCurrency === $propertyCurrency) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Kurs untuk mata uang property sendiri tidak perlu dicatat.']);
            return;
        }
        if (!is_finite($rate) || $rate <= 0) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Kurs harus lebih besar dari nol.']);
            return;
        }
        if (!validIsoDate($rateDate)) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Tanggal kurs tidak valid.']);
            return;
        }
        $source = trim((string)($input['source'] ?? 'manual')) ?: 'manual';
        $id = tamasyaSaveExchangeRate($pdo, $propertyCurrency, $quoteCurrency, $rate, $rateDate, $source, (string)($loggedInStaff['id'] ?? ''));
        writeRequiredEnterpriseAudit($pdo, $loggedInStaff, 'Mencatat kurs transaksi', 'exchange_rate', $id, null, [
            'baseCurrency' => $propertyCurrency, 'quoteCurrency' => $quoteCurrency, 'rate' => $rate, 'rateDate' => $rateDate, 'source' => $source,
        ], 'exchange_rate');
        echo tamasyaJsonEncode(['success' => true, 'data' => ['id' => $id]]);
        return;
    }

    http_response_code(400);
    echo tamasyaJsonEncode(['success' => false, 'error' => 'Command kurs tidak dikenal.']);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('exchange_rates:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'EXCHANGE_RATE_FAILED', 'requestId' => tamasyaRuntimeRequestId()]);
}
