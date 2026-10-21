<?php
/**
 * Tarif wholesale / kontrak (B2B).
 *
 * Paket tarif menyimpan harga per tanggal untuk satu perusahaan, channel, atau
 * grup. Pemakaiannya: saat reservasi dibuat, tarif paket yang berlaku diambil
 * dan dikunci di tabel wholesale_rate_bookings supaya harga kontrak tidak
 * berubah diam-diam quando tarif paket diperbarui.
 *
 * Semua aksi tulis memakai capability; aksi baca tarif memakai permission
 * yang sama dengan penjualan kamar.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
$action = (string)($action ?? '');
if (!in_array($action, ['wholesale-rates'], true)) return;
$routeHandled = true;

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $propertyId = strtolower(trim((string)($GLOBALS['tamasya_property_id'] ?? '')));
    $command = trim((string)($input['command'] ?? ($_GET['command'] ?? '')));

    if ($method === 'GET') {
        // Daftar paket + harga per tanggal. Pembacaan mengikuti hak akses
        // penjualan kamar supaya kasir bisa melihat tarif kontrak yang berlaku.
        requireCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'receptionist', 'finance']);
        $rows = [];
        try {
            $stmt = $pdo->prepare("SELECT id,code,name,company_name,room_type,currency,valid_from,valid_to,min_nights,max_nights,cut_off_days,include_tax,breakfast_included,status,notes,updated_at FROM `wholesale_rate_plans` WHERE property_id = ? ORDER BY status, code");
            $stmt->execute([$propertyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $error) {
            http_response_code(409);
            echo tamasyaJsonEncode(['success' => false, 'code' => 'WHOLESALE_SCHEMA_MISSING', 'error' => 'Skema tarif wholesale belum tersedia di database ini. Jalankan migrasi 20261021_WHOLESALE_RATES.sql.']);
            return;
        }
        $planIds = [];
        foreach ($rows as $row) { $planIds[(string)$row['id']] = true; }
        $nights = [];
        if ($planIds) {
            $in = implode(',', array_fill(0, count($planIds), '?'));
            $stmt = $pdo->prepare("SELECT plan_id,stay_date,price,min_available,closed FROM `wholesale_rate_nights` WHERE plan_id IN ($in) ORDER BY stay_date");
            $stmt->execute(array_keys($planIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $night) {
                $nights[(string)$night['plan_id']][] = [
                    'stayDate' => (string)$night['stay_date'],
                    'price' => (float)$night['price'],
                    'minAvailable' => (int)$night['min_available'],
                    'closed' => (int)$night['closed'] === 1,
                ];
            }
        }
        foreach ($rows as &$row) {
            $row['nights'] = $nights[(string)$row['id']] ?? [];
        }
        unset($row);
        echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => ['rows' => $rows]]);
        return;
    }

    // Pencarian tarif untuk kasir: paket mana yang berlaku untuk satu rentang
    // inap, lengkap dengan harga per tanggal dan totalnya. Hasilnya dipakai
    // staf untuk menawarkan harga kontrak ke tamu, dan harga yang sama
    // dikunci server saat reservasi dibuat.
    if ($command === 'wholesale-rate-lookup') {
        if ($method !== 'POST') {
            http_response_code(405);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Pencarian tarif kontrak hanya dapat dilakukan dengan POST.']);
            return;
        }
        requireCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'receptionist', 'finance']);
        $checkIn = trim((string)($input['checkIn'] ?? ''));
        $checkOut = trim((string)($input['checkOut'] ?? ''));
        if (!validIsoDate($checkIn) || !validIsoDate($checkOut) || $checkOut <= $checkIn) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Rentang tanggal pencarian tarif kontrak tidak valid.']);
            return;
        }
        $criteria = [
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'roomType' => trim((string)($input['roomType'] ?? '')),
            'companyName' => trim((string)($input['companyName'] ?? '')),
        ];
        $planId = trim((string)($input['planId'] ?? ''));
        if ($planId !== '') {
            $criteria['planId'] = $planId;
            $quote = tamasyaWholesaleContractRateQuote($pdo, $propertyId, $criteria);
            $quote['propertyId'] = $propertyId;
            echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => ['quote' => $quote]]);
            return;
        }
        $candidates = tamasyaWholesaleContractRateCandidates($pdo, $propertyId, $criteria);
        $applicable = array_values(array_filter($candidates, function ($row) { return (bool)($row['applicable'] ?? false); }));
        echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => [
            'candidates' => $candidates,
            'applicable' => $applicable,
            'currency' => tamasyaPropertyCurrency($pdo),
        ]]);
        return;
    }

    // Reservasi yang memakai tarif kontrak, untuk rekonsiliasi.
    if ($command === 'wholesale-rate-bookings') {
        requireCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'receptionist', 'finance']);
        $rows = [];
        try {
            $stmt = $pdo->prepare("SELECT b.id,b.booking_id,b.plan_id,b.plan_code,b.company_name,b.stay_from,b.stay_to,b.nightly_price,b.nights,b.total,b.currency,b.created_at FROM `wholesale_rate_bookings` b WHERE b.property_id = ? ORDER BY b.created_at DESC LIMIT 200");
            $stmt->execute([$propertyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $error) {
            http_response_code(409);
            echo tamasyaJsonEncode(['success' => false, 'code' => 'WHOLESALE_SCHEMA_MISSING', 'error' => 'Skema tarif wholesale belum tersedia di database ini. Jalankan migrasi 20261021_WHOLESALE_RATES.sql dan 20261021_WHOLESALE_RATE_LOCK.sql.']);
            return;
        }
        echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => ['rows' => $rows]]);
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo tamasyaJsonEncode(['success' => false, 'error' => 'Aksi tarif wholesale hanya dapat ditulis dengan POST.']);
        return;
    }

    // Tulis: hanya admin atau manajer property.
    requireCapability($loggedInStaff, 'manage_operational_settings', ['admin', 'manager']);

    if ($command === 'wholesale-rate-save') {
        $code = strtolower(trim((string)($input['code'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        $from = trim((string)($input['validFrom'] ?? ''));
        $to = trim((string)($input['validTo'] ?? ''));
        if ($code === '' || $name === '') {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Kode dan nama paket wajib diisi.']);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{2,40}$/', $code)) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Kode paket hanya boleh huruf, angka, titik, garis, dan garis bawah.']);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Rentang tanggal paket tidak valid.']);
            return;
        }
        $nights = $input['nights'] ?? [];
        if (!is_array($nights) || !$nights) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Minimal satu harga per tanggal wajib diisi.']);
            return;
        }
        $planId = trim((string)($input['planId'] ?? ''));
        if ($planId === '') { $planId = generateServerId('wsp'); }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO `wholesale_rate_plans` (id,property_id,code,name,company_name,room_type,currency,valid_from,valid_to,min_nights,max_nights,cut_off_days,include_tax,breakfast_included,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`company_name`=VALUES(`company_name`),`room_type`=VALUES(`room_type`),`currency`=VALUES(`currency`),`valid_from`=VALUES(`valid_from`),`valid_to`=VALUES(`valid_to`),`min_nights`=VALUES(`min_nights`),`max_nights`=VALUES(`max_nights`),`cut_off_days`=VALUES(`cut_off_days`),`include_tax`=VALUES(`include_tax`),`breakfast_included`=VALUES(`breakfast_included`),`status`=VALUES(`status`),`notes`=VALUES(`notes`),`updated_at`=CURRENT_TIMESTAMP");
            $stmt->execute([$planId, $propertyId, $code, $name, trim((string)($input['companyName'] ?? '')) ?: null, trim((string)($input['roomType'] ?? '')) ?: null, strtoupper(trim((string)($input['currency'] ?? 'IDR'))) ?: 'IDR', $from, $to, max(1, (int)($input['minNights'] ?? 1)), max(1, (int)($input['maxNights'] ?? 30)), max(0, (int)($input['cutOffDays'] ?? 0)), (int)($input['includeTax'] ?? 1) === 1 ? 1 : 0, (int)($input['breakfastIncluded'] ?? 0) === 1 ? 1 : 0, trim((string)($input['status'] ?? 'active')) ?: 'active', trim((string)($input['notes'] ?? '')) ?: null, (string)($loggedInStaff['id'] ?? '')]);
            $nightStmt = $pdo->prepare("INSERT INTO `wholesale_rate_nights` (plan_id,stay_date,price,min_available,closed) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE `price`=VALUES(`price`),`min_available`=VALUES(`min_available`),`closed`=VALUES(`closed`)");
            foreach ($nights as $night) {
                if (!is_array($night)) { continue; }
                $date = trim((string)($night['stayDate'] ?? ''));
                $price = (float)($night['price'] ?? 0);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $price < 0) { continue; }
                $nightStmt->execute([$planId, $date, $price, max(0, (int)($night['minAvailable'] ?? 0)), (int)($night['closed'] ?? 0) === 1 ? 1 : 0]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        }
        writeRequiredEnterpriseAudit($pdo, $loggedInStaff, 'Menyimpan paket tarif wholesale', 'wholesale_rate_plan', $planId, null, ['code' => $code, 'validFrom' => $from, 'validTo' => $to], 'wholesale_rate');
        echo tamasyaJsonEncode(['success' => true, 'data' => ['planId' => $planId]]);
        return;
    }

    if ($command === 'wholesale-rate-status') {
        $planId = trim((string)($input['planId'] ?? ''));
        $status = trim((string)($input['status'] ?? 'inactive'));
        if ($planId === '' || !in_array($status, ['active', 'inactive'], true)) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Paket dan status tidak valid.']);
            return;
        }
        $stmt = $pdo->prepare("UPDATE `wholesale_rate_plans` SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND property_id=?");
        $stmt->execute([$status, $planId, $propertyId]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Paket tidak ditemukan di property ini.']);
            return;
        }
        writeRequiredEnterpriseAudit($pdo, $loggedInStaff, 'Mengubah status paket tarif wholesale', 'wholesale_rate_plan', $planId, null, ['status' => $status], 'wholesale_rate');
        echo tamasyaJsonEncode(['success' => true]);
        return;
    }

    http_response_code(400);
    echo tamasyaJsonEncode(['success' => false, 'error' => 'Command tarif wholesale tidak dikenal.']);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('wholesale_rates:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'WHOLESALE_RATE_FAILED', 'requestId' => tamasyaRuntimeRequestId()]);
}
