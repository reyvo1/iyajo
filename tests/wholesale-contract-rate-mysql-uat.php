<?php
/**
 * Uji fungsional tarif kontrak B2B terhadap MariaDB.
 *
 * Menguji modul tarif kontrak dengan database sungguhan: kuotasi harga paket
 * per tanggal, seluruh aturan penolakan (fail-closed), dan penguncian tarif ke
 * reservasi. Bila tidak ada DSN, bagian database dilewati dan pemeriksaan
 * statis tetap berjalan.
 */
$root = dirname(__DIR__);
$read = static function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};
$checks = 0;
$rateCheck = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};

// Modul aplikasi dijaga agar tidak bisa dipanggil langsung sebagai skrip.
if (!defined('TAMASYA_API_ENTRY')) { define('TAMASYA_API_ENTRY', true); }
require_once $root . '/api/support/012_domain_primitives.php';
require_once $root . '/api/modules/finance/031_wholesale_contract_rates.php';

/* ===== Pemeriksaan statis: integrasi ke alur pemesanan ===== */
$workflow = $read('api/modules/finance/030_booking_finance.php');
$rateCheck(str_contains($workflow, 'tamasyaWholesaleContractRateQuote('), 'Alur pemesanan menghitung kuotasi tarif kontrak');
$rateCheck(str_contains($workflow, 'tamasyaWholesaleContractRateLock('), 'Alur pemesanan mengunci tarif kontrak ke reservasi');
$rateCheck(str_contains($workflow, "'\$payload['wholesalePlanId']") || str_contains($workflow, "\$payload['wholesalePlanId']"), 'Paket kontrak dibaca dari payload pemesanan');
$rateCheck(str_contains($workflow, 'Tarif kontrak tidak dapat dipakai'), 'Paket kontrak yang tidak berlaku menolak reservasi (fail-closed)');
$rateCheck(str_contains($workflow, "\$totalAmount=round((float)\$wholesaleQuote['total'],2)"), 'Total reservasi memakai harga paket dari server, bukan angka browser');
$rateCheck(str_contains($workflow, 'Panjar booking melebihi total tarif kontrak'), 'Panjar divalidasi ulang terhadap total tarif kontrak');
$rateCheck(strpos($workflow, 'tamasyaWholesaleContractRateQuote(') < strpos($workflow, 'INSERT INTO bookings'), 'Kuotasi tarif dihitung sebelum reservasi disimpan');

$route = $read('api/routes/088_wholesale_rates.php');
$rateCheck(str_contains($route, "wholesale-rate-lookup"), 'Endpoint pencarian tarif kontrak untuk kasir tersedia');
$rateCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'sell_room_access'"), 'Pencarian tarif kontrak mengikuti permission penjualan kamar');
$rateCheck(str_contains($route, "wholesale-rate-bookings"), 'Daftar reservasi kontrak tersedia untuk rekonsiliasi');

$module = $read('api/modules/finance/031_wholesale_contract_rates.php');
$rateCheck(str_contains($module, 'di luar masa berlaku paket'), 'Masa berlaku paket diperiksa');
$rateCheck(str_contains($module, 'hanya berlaku untuk tipe kamar'), 'Tipe kamar paket diperiksa');
$rateCheck(str_contains($module, 'terikat perusahaan'), 'Paket yang terikat perusahaan diperiksa');
$rateCheck(str_contains($module, 'minimal'), 'Jumlah malam minimal dan maksimal diperiksa');
$rateCheck(str_contains($module, 'cut off'), 'Aturan cut off diperiksa');
$rateCheck(str_contains($module, 'berbeda dari mata uang property'), 'Mata uang paket dibandingkan mata uang property');
$rateCheck(str_contains($module, 'belum lengkap pada tanggal'), 'Tanggal tanpa harga ditolak');
$rateCheck(str_contains($module, 'ditutup pada tanggal'), 'Tanggal yang ditutup ditolak');
$rateCheck(str_contains($module, 'nightly_breakdown'), 'Kunci tarif menyimpan rincian harga per tanggal');

/* ===== Pemeriksaan fungsional terhadap database ===== */
$dsn = trim((string)getenv('TAMASYA_CATALOG_UAT_DSN'));
if ($dsn === '') {
    echo 'SKIP pemeriksaan fungsional tarif kontrak: TAMASYA_CATALOG_UAT_DSN belum diisi (' . $checks . " pemeriksaan statis lolos)\n";
    exit(0);
}
$pdo = new PDO($dsn, (string)getenv('TAMASYA_CATALOG_UAT_USER'), (string)getenv('TAMASYA_CATALOG_UAT_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$propertyId = 'uat-wholesale-contract';
$prefix = 'UATWH' . substr(hash('sha256', $dsn), 0, 6);
$currency = tamasyaPropertyCurrency($pdo);
$checkIn = date('Y-m-d', strtotime('+30 day'));
$checkOut = date('Y-m-d', strtotime('+33 day'));

$cleanup = static function () use ($pdo, $propertyId, $prefix): void {
    $stmt = $pdo->prepare("SELECT `id` FROM `wholesale_rate_plans` WHERE `property_id` = ? AND `code` LIKE ?");
    $stmt->execute([$propertyId, $prefix . '%']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pdo->prepare("DELETE FROM `wholesale_rate_nights` WHERE `plan_id` = ?")->execute([(string)$row['id']]);
        $pdo->prepare("DELETE FROM `wholesale_rate_bookings` WHERE `plan_id` = ?")->execute([(string)$row['id']]);
        $pdo->prepare("DELETE FROM `wholesale_rate_plans` WHERE `id` = ?")->execute([(string)$row['id']]);
    }
};
$cleanup();

$planId = 'uat_plan_' . substr(hash('sha256', $prefix), 0, 12);
$stmt = $pdo->prepare("INSERT INTO `wholesale_rate_plans` (id,property_id,code,name,company_name,room_type,currency,valid_from,valid_to,min_nights,max_nights,cut_off_days,include_tax,breakfast_included,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')");
$stmt->execute([$planId, $propertyId, $prefix . 'ACME', 'Paket kontrak ACME', 'ACME Corp', 'Deluxe', $currency, date('Y-m-d', strtotime('+10 day')), date('Y-m-d', strtotime('+90 day')), 2, 10, 0, 1, 0]);
$nightStmt = $pdo->prepare("INSERT INTO `wholesale_rate_nights` (plan_id,stay_date,price,min_available,closed) VALUES (?,?,?,1,0)");
$nightStmt->execute([$planId, $checkIn, 700000]);
$nightStmt->execute([$planId, date('Y-m-d', strtotime('+31 day')), 800000]);
$nightStmt->execute([$planId, date('Y-m-d', strtotime('+32 day')), 900000]);

$quote = tamasyaWholesaleContractRateQuote($pdo, $propertyId, [
    'planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut,
]);
$rateCheck($quote['applicable'] === true, 'Kuotasi paket kontrak berlaku untuk rentang inap yang benar');
$rateCheck(abs((float)$quote['total'] - 2400000.0) < 0.01, 'Total kuotasi menjumlahkan harga per tanggal (700.000 + 800.000 + 900.000)');
$rateCheck($quote['nightsCount'] === 3, 'Jumlah malam dihitung dari tanggal inap');
$rateCheck(abs((float)$quote['nightlyPrice'] - 800000.0) < 0.01, 'Harga per malam rata-rata dihitung dari rincian');
$rateCheck(count($quote['nights']) === 3, 'Rincian harga per tanggal tersedia untuk kasir');

$reject = static function (array $criteria, string $label) use ($pdo, $propertyId, $rateCheck): void {
    $result = tamasyaWholesaleContractRateQuote($pdo, $propertyId, $criteria);
    $rateCheck($result['applicable'] === false, $label);
};
$reject(['planId' => $planId, 'roomType' => 'Superior', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut], 'Tipe kamar yang berbeda ditolak');
$reject(['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'Lain Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut], 'Perusahaan yang berbeda ditolak');
$reject(['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => date('Y-m-d', strtotime('+120 day')), 'checkOut' => date('Y-m-d', strtotime('+123 day'))], 'Tanggal di luar masa berlaku ditolak');
$reject(['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => date('Y-m-d', strtotime('+34 day'))], 'Tanggal tanpa harga ditolak');
$reject(['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => date('Y-m-d', strtotime('+31 day'))], 'Jumlah malam di bawah minimal paket ditolak');
$reject(['planId' => 'paket_tidak_ada', 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut], 'Paket yang tidak ada ditolak');

$pdo->prepare("UPDATE `wholesale_rate_nights` SET `closed` = 1 WHERE `plan_id` = ? AND `stay_date` = ?")->execute([$planId, $checkIn]);
$reject(['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut], 'Tanggal yang ditutup ditolak');
$pdo->prepare("UPDATE `wholesale_rate_nights` SET `closed` = 0 WHERE `plan_id` = ? AND `stay_date` = ?")->execute([$planId, $checkIn]);

$otherCurrency = $currency === 'IDR' ? 'USD' : 'IDR';
$otherPlanId = 'uat_plan_other_' . substr(hash('sha256', $prefix . 'x'), 0, 10);
$pdo->prepare("INSERT INTO `wholesale_rate_plans` (id,property_id,code,name,company_name,room_type,currency,valid_from,valid_to,min_nights,max_nights,cut_off_days,include_tax,breakfast_included,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')")->execute([$otherPlanId, $propertyId, $prefix . 'OTHER', 'Paket mata uang lain', 'ACME Corp', 'Deluxe', $otherCurrency, date('Y-m-d', strtotime('+10 day')), date('Y-m-d', strtotime('+90 day')), 1, 10, 0, 1, 0]);
$pdo->prepare("INSERT INTO `wholesale_rate_nights` (plan_id,stay_date,price,min_available,closed) VALUES (?,?,?,1,0)")->execute([$otherPlanId, $checkIn, 500000]);
$reject(['planId' => $otherPlanId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut], 'Mata uang paket yang berbeda dari property ditolak');

$pdo->prepare("UPDATE `wholesale_rate_plans` SET `status` = 'inactive' WHERE `id` = ?")->execute([$planId]);
$reject(['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut], 'Paket tidak aktif ditolak');
$pdo->prepare("UPDATE `wholesale_rate_plans` SET `status` = 'active' WHERE `id` = ?")->execute([$planId]);

/* Kunci tarif ke reservasi */
$bookingId = 'uat_booking_' . substr(hash('sha256', $prefix . 'b'), 0, 10);
$lockQuote = tamasyaWholesaleContractRateQuote($pdo, $propertyId, [
    'planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut,
]);
$lockQuote['propertyId'] = $propertyId;
tamasyaWholesaleContractRateLock($pdo, $lockQuote, $bookingId, 'uat_staff');
$row = $pdo->query("SELECT * FROM `wholesale_rate_bookings` WHERE `booking_id` = " . $pdo->quote($bookingId))->fetch(PDO::FETCH_ASSOC);
$rateCheck(is_array($row), 'Kunci tarif kontrak tersimpan pada reservasi');
$rateCheck(abs((float)$row['total'] - 2400000.0) < 0.01, 'Total terkunci sama dengan harga paket');
$rateCheck((int)$row['nights'] === 3, 'Jumlah malam terkunci');
$rateCheck((string)$row['stay_from'] === $checkIn && (string)$row['stay_to'] === $checkOut, 'Rentang inap terkunci');
$breakdown = json_decode((string)$row['nightly_breakdown'], true);
$rateCheck(is_array($breakdown) && count($breakdown) === 3, 'Rincian harga per tanggal terkunci');
$rateCheck(abs((float)($breakdown[1]['price'] ?? 0) - 800000.0) < 0.01, 'Rincian terkunci memuat harga tiap tanggal');

// Perubahan paket tidak boleh mengubah tarif yang sudah terkunci.
$pdo->prepare("UPDATE `wholesale_rate_nights` SET `price` = 111 WHERE `plan_id` = ?")->execute([$planId]);
$rowAfter = $pdo->query("SELECT `total` FROM `wholesale_rate_bookings` WHERE `booking_id` = " . $pdo->quote($bookingId))->fetch(PDO::FETCH_ASSOC);
$rateCheck(abs((float)$rowAfter['total'] - 2400000.0) < 0.01, 'Perubahan harga paket tidak mengubah tarif yang sudah terkunci');
$reQuote = tamasyaWholesaleContractRateQuote($pdo, $propertyId, ['planId' => $planId, 'roomType' => 'Deluxe', 'companyName' => 'ACME Corp', 'checkIn' => $checkIn, 'checkOut' => $checkOut]);
$rateCheck(abs((float)$reQuote['total'] - 333.0) < 0.01, 'Kuotasi baru memakai harga paket terbaru');

$cleanup();
echo 'Wholesale contract rate: ' . $checks . " passed; 0 failed.\n";
