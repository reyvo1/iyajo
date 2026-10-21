<?php
/**
 * Tarif kontrak B2B (wholesale).
 *
 * Paket tarif menyimpan harga per tanggal untuk satu perusahaan, channel, atau
 * grup. Modul ini melakukan dua hal:
 *
 *  1. Menghitung kuotasi tarif kontrak untuk satu rentang tanggal. Server yang
 *     berwenang atas nominal: harga yang dipakai saat reservasi dibuat berasal
 *     dari paket, bukan dari angka yang diketik staf di browser.
 *  2. Mengunci tarif tersebut ke reservasi. Yang dikunci adalah harga per
 *     tanggal, sehingga perubahan paket di kemudian hari tidak mengubah
 *     reservasi yang sudah ada.
 *
 * Semua aturan berlaku fail-closed. Paket yang tidak cocok membuat reservasi
 * ditolak dengan alasan yang jelas, bukan diam-diam kembali ke tarif rak.
 */

/**
 * Muat satu paket tarif milik property tertentu.
 */
function tamasyaWholesaleContractRatePlan(PDO $pdo, string $propertyId, string $planId): ?array
{
    $planId = trim($planId);
    if ($planId === '') {
        return null;
    }
    $sql = "SELECT * FROM `wholesale_rate_plans` WHERE `id` = ?";
    $params = [$planId];
    if ($propertyId !== '') {
        $sql .= " AND `property_id` = ?";
        $params[] = $propertyId;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Daftar tanggal inap pada satu stay. Tanggal keluar tidak termasuk malam
 * yang dibayar, jadi daftar malam adalah check-in sampai check-out - 1 hari.
 */
function tamasyaWholesaleContractRateStayDates(string $checkIn, string $checkOut): array
{
    $dates = [];
    $start = strtotime($checkIn . ' 00:00:00');
    $end = strtotime($checkOut . ' 00:00:00');
    if ($start === false || $end === false || $end <= $start) {
        return $dates;
    }
    for ($cursor = $start; $cursor < $end; $cursor = strtotime('+1 day', $cursor)) {
        $dates[] = date('Y-m-d', $cursor);
    }
    return $dates;
}

/**
 * Harga paket untuk daftar tanggal inap.
 *
 * Mengembalikan ['nights'=>[...], 'missing'=>[...], 'closed'=>[...]].
 */
function tamasyaWholesaleContractRateNights(PDO $pdo, string $planId, array $stayDates): array
{
    $prices = [];
    $missing = [];
    $closed = [];
    if (!$stayDates) {
        return ['nights' => [], 'missing' => [], 'closed' => []];
    }
    $in = implode(',', array_fill(0, count($stayDates), '?'));
    $stmt = $pdo->prepare("SELECT `stay_date`,`price`,`min_available`,`closed` FROM `wholesale_rate_nights` WHERE `plan_id` = ? AND `stay_date` IN ($in)");
    $stmt->execute(array_merge([$planId], $stayDates));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $date = (string)$row['stay_date'];
        $prices[$date] = [
            'stayDate' => $date,
            'price' => round((float)$row['price'], 2),
            'minAvailable' => (int)$row['min_available'],
            'closed' => (int)$row['closed'] === 1,
        ];
    }
    $nights = [];
    foreach ($stayDates as $date) {
        if (!isset($prices[$date])) {
            $missing[] = $date;
            continue;
        }
        if ($prices[$date]['closed']) {
            $closed[] = $date;
            continue;
        }
        $nights[] = $prices[$date];
    }
    return ['nights' => $nights, 'missing' => $missing, 'closed' => $closed];
}

/**
 * Hitung kuotasi tarif kontrak.
 *
 * $criteria: planId (wajib bila mengunci harga), roomType, companyName,
 * checkIn, checkOut. Nilai balik selalu punya bentuk yang sama; 'applicable'
 * menandai apakah paket boleh dipakai.
 */
function tamasyaWholesaleContractRateQuote(PDO $pdo, string $propertyId, array $criteria): array
{
    $planId = trim((string)($criteria['planId'] ?? ''));
    $roomType = trim((string)($criteria['roomType'] ?? ''));
    $companyName = trim((string)($criteria['companyName'] ?? ''));
    $checkIn = trim((string)($criteria['checkIn'] ?? ''));
    $checkOut = trim((string)($criteria['checkOut'] ?? ''));

    $quote = [
        'applicable' => false,
        'reason' => '',
        'planId' => $planId,
        'planCode' => '',
        'planName' => '',
        'companyName' => '',
        'roomType' => '',
        'currency' => '',
        'includeTax' => true,
        'breakfastIncluded' => false,
        'validFrom' => '',
        'validTo' => '',
        'minNights' => 0,
        'maxNights' => 0,
        'cutOffDays' => 0,
        'nights' => [],
        'nightsCount' => 0,
        'nightlyPrice' => 0.0,
        'total' => 0.0,
    ];

    $plan = tamasyaWholesaleContractRatePlan($pdo, $propertyId, $planId);
    if ($plan === null) {
        $quote['reason'] = 'Paket tarif kontrak tidak ditemukan pada property ini.';
        return $quote;
    }
    $quote['planId'] = (string)$plan['id'];
    $quote['planCode'] = (string)$plan['code'];
    $quote['planName'] = (string)$plan['name'];
    $quote['companyName'] = (string)($plan['company_name'] ?? '');
    $quote['roomType'] = (string)($plan['room_type'] ?? '');
    $quote['currency'] = strtoupper((string)($plan['currency'] ?? 'IDR'));
    $quote['includeTax'] = (int)($plan['include_tax'] ?? 1) === 1;
    $quote['breakfastIncluded'] = (int)($plan['breakfast_included'] ?? 0) === 1;
    $quote['validFrom'] = (string)$plan['valid_from'];
    $quote['validTo'] = (string)$plan['valid_to'];
    $quote['minNights'] = (int)($plan['min_nights'] ?? 1);
    $quote['maxNights'] = (int)($plan['max_nights'] ?? 30);
    $quote['cutOffDays'] = (int)($plan['cut_off_days'] ?? 0);

    if (strtolower(trim((string)($plan['status'] ?? ''))) !== 'active') {
        $quote['reason'] = 'Paket tarif kontrak tidak aktif.';
        return $quote;
    }
    if (!validIsoDate($checkIn) || !validIsoDate($checkOut)) {
        $quote['reason'] = 'Tanggal inap paket tarif kontrak tidak valid.';
        return $quote;
    }
    $stayDates = tamasyaWholesaleContractRateStayDates($checkIn, $checkOut);
    if (!$stayDates) {
        $quote['reason'] = 'Rentang inap paket tarif kontrak tidak valid.';
        return $quote;
    }
    $quote['nightsCount'] = count($stayDates);

    // Tipe kamar paket harus cocok dengan kamar yang dipesan bila paket
    // dibatasi ke satu tipe kamar.
    $planRoomType = trim((string)($plan['room_type'] ?? ''));
    if ($planRoomType !== '' && $roomType !== '' && strcasecmp($planRoomType, $roomType) !== 0) {
        $quote['reason'] = 'Paket tarif kontrak ini hanya berlaku untuk tipe kamar ' . $planRoomType . '.';
        return $quote;
    }

    // Paket kontrak mengikat perusahaan. Nama perusahaan pada pemesanan wajib
    // cocok, supaya kontrak satu perusahaan tidak terpakai untuk tamu lain.
    $planCompany = trim((string)($plan['company_name'] ?? ''));
    if ($planCompany !== '') {
        if ($companyName === '') {
            $quote['reason'] = 'Paket tarif kontrak terikat perusahaan "' . $planCompany . '"; nama perusahaan wajib dicantumkan.';
            return $quote;
        }
        if (strcasecmp($planCompany, $companyName) !== 0) {
            $quote['reason'] = 'Paket tarif kontrak ini milik perusahaan "' . $planCompany . '".';
            return $quote;
        }
    }

    // Masa berlaku paket: malam terakhir yang dibayar harus masih di dalam
    // masa berlaku.
    $lastNight = $stayDates[count($stayDates) - 1];
    if ($checkIn < (string)$plan['valid_from'] || $lastNight > (string)$plan['valid_to']) {
        $quote['reason'] = 'Tanggal inap berada di luar masa berlaku paket tarif kontrak.';
        return $quote;
    }

    // Jumlah malam harus sesuai minimal dan maksimal paket.
    if ($quote['nightsCount'] < max(1, $quote['minNights']) || $quote['nightsCount'] > max(1, $quote['maxNights'])) {
        $quote['reason'] = 'Jumlah malam tidak sesuai aturan paket tarif kontrak (minimal ' . $quote['minNights'] . ', maksimal ' . $quote['maxNights'] . ').';
        return $quote;
    }

    // Cut off: pemesanan harus dilakukan sejumlah hari sebelum tanggal inap.
    if ($quote['cutOffDays'] > 0) {
        $today = date('Y-m-d');
        $earliest = date('Y-m-d', strtotime($today . ' +' . $quote['cutOffDays'] . ' day'));
        if ($checkIn < $earliest) {
            $quote['reason'] = 'Paket tarif kontrak ini memiliki cut off ' . $quote['cutOffDays'] . ' hari; pemesanan hanya boleh paling lambat ' . $earliest . '.';
            return $quote;
        }
    }

    // Mata uang paket harus sama dengan mata uang property, karena nominal
    // reservasi disimpan tanpa kolom mata uang.
    $propertyCurrency = function_exists('tamasyaPropertyCurrency') ? tamasyaPropertyCurrency($pdo) : 'IDR';
    if ($quote['currency'] !== $propertyCurrency) {
        $quote['reason'] = 'Mata uang paket tarif kontrak (' . $quote['currency'] . ') berbeda dari mata uang property (' . $propertyCurrency . ').';
        return $quote;
    }

    $nightly = tamasyaWholesaleContractRateNights($pdo, $planId, $stayDates);
    if ($nightly['missing']) {
        $quote['reason'] = 'Harga paket tarif kontrak belum lengkap pada tanggal: ' . implode(', ', array_slice($nightly['missing'], 0, 5)) . '.';
        return $quote;
    }
    if ($nightly['closed']) {
        $quote['reason'] = 'Paket tarif kontrak ditutup pada tanggal: ' . implode(', ', array_slice($nightly['closed'], 0, 5)) . '.';
        return $quote;
    }

    $total = 0.0;
    foreach ($nightly['nights'] as $night) {
        $total += (float)$night['price'];
    }
    $total = round($total, 2);
    if ($total <= 0) {
        $quote['reason'] = 'Total tarif kontrak bernilai nol.';
        return $quote;
    }
    $quote['nights'] = $nightly['nights'];
    $quote['total'] = $total;
    $quote['nightlyPrice'] = round($total / max(1, count($nightly['nights'])), 2);
    $quote['applicable'] = true;
    $quote['reason'] = 'Paket tarif kontrak berlaku.';
    return $quote;
}

/**
 * Kunci tarif kontrak ke sebuah reservasi.
 *
 * Nilai yang dikunci adalah harga per tanggal, jadi perubahan paket di
 * kemudian hari tidak mengubah reservasi ini.
 */
function tamasyaWholesaleContractRateLock(PDO $pdo, array $quote, string $bookingId, string $actorId): void
{
    $planId = trim((string)($quote['planId'] ?? ''));
    if ($planId === '' || $bookingId === '') {
        throw new RuntimeException('Kunci tarif kontrak membutuhkan paket dan reservasi yang valid.');
    }
    $plan = tamasyaWholesaleContractRatePlan($pdo, (string)($quote['propertyId'] ?? ''), $planId);
    $propertyId = (string)($plan['property_id'] ?? ($quote['propertyId'] ?? ''));
    $nights = [];
    foreach ((array)($quote['nights'] ?? []) as $night) {
        $nights[] = ['stayDate' => (string)($night['stayDate'] ?? ''), 'price' => round((float)($night['price'] ?? 0), 2)];
    }
    $stayFrom = (string)($nights[0]['stayDate'] ?? '');
    $stayTo = '';
    if ($nights) {
        $last = $nights[count($nights) - 1]['stayDate'];
        $stayTo = date('Y-m-d', strtotime($last . ' +1 day'));
    }
    $id = 'wrb_' . substr(hash('sha256', $bookingId . '|' . $planId), 0, 32);
    $stmt = $pdo->prepare("INSERT INTO `wholesale_rate_bookings` (id,property_id,booking_id,plan_id,plan_code,company_name,stay_from,stay_to,nightly_price,nights,nightly_breakdown,total,currency,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `plan_id`=VALUES(`plan_id`),`plan_code`=VALUES(`plan_code`),`company_name`=VALUES(`company_name`),`stay_from`=VALUES(`stay_from`),`stay_to`=VALUES(`stay_to`),`nightly_price`=VALUES(`nightly_price`),`nights`=VALUES(`nights`),`nightly_breakdown`=VALUES(`nightly_breakdown`),`total`=VALUES(`total`),`currency`=VALUES(`currency`)");
    $stmt->execute([
        $id,
        $propertyId,
        $bookingId,
        $planId,
        (string)($quote['planCode'] ?? ''),
        (string)($quote['companyName'] ?? ''),
        $stayFrom,
        $stayTo,
        round((float)($quote['nightlyPrice'] ?? 0), 2),
        (int)($quote['nightsCount'] ?? 0),
        (string)json_encode($nights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        round((float)($quote['total'] ?? 0), 2),
        (string)($quote['currency'] ?? 'IDR'),
        $actorId !== '' ? $actorId : null,
    ]);
}

/**
 * Kandidat paket tarif yang bisa dipakai untuk satu rentang inap.
 *
 * Dipakai kasir untuk mencari paket kontrak yang tepat sebelum membuat
 * reservasi, sehingga harga yang ditawarkan ke tamu berasal dari server.
 */
function tamasyaWholesaleContractRateCandidates(PDO $pdo, string $propertyId, array $criteria): array
{
    $checkIn = trim((string)($criteria['checkIn'] ?? ''));
    $checkOut = trim((string)($criteria['checkOut'] ?? ''));
    $roomType = trim((string)($criteria['roomType'] ?? ''));
    $companyName = trim((string)($criteria['companyName'] ?? ''));
    $sql = "SELECT `id` FROM `wholesale_rate_plans` WHERE `status` = 'active'";
    $params = [];
    if ($propertyId !== '') {
        $sql .= " AND `property_id` = ?";
        $params[] = $propertyId;
    }
    $sql .= " ORDER BY `code`";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $quote = tamasyaWholesaleContractRateQuote($pdo, $propertyId, [
            'planId' => (string)$row['id'],
            'roomType' => $roomType,
            'companyName' => $companyName,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
        ]);
        $quote['propertyId'] = (string)($row['property_id'] ?? $propertyId);
        $candidates[] = $quote;
    }
    return $candidates;
}
