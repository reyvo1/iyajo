<?php
/**
 * Ulasan tamu: undangan (staf) -> penilaian (tamu) -> moderasi (staf).
 *
 * - Undangan dibuat staf untuk kamar yang sudah checkout; token acak jadi
 *   satu-satunya kunci akses tamu, tanpa membuka data tamu lain.
 * - Tamu membuka tautan, memberi rating (1..5) dan komentar, lalu
 *   ditutup sehingga tidak bisa dinilai ulang.
 * - Staf melihat daftar, membalas, dan memutuskan tampil/sembunyi.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
$action = (string)($action ?? '');
if (!in_array($action, ['guest-reviews', 'review-invite', 'public-review'], true)) return;
$routeHandled = true;
require_once dirname(__DIR__) . '/support/012_domain_primitives.php';

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $propertyId = strtolower(trim((string)($GLOBALS['tamasya_property_id'] ?? '')));
    $now = gmdate('Y-m-d H:i:s');

    // ---------- Tamu: membuka undangan & menilai ----------
    if ($action === 'public-review') {
        $token = trim((string)($_GET['token'] ?? ($input['token'] ?? '')));
        if ($token === '') {
            http_response_code(400);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Token ulasan wajib diisi.']);
            return;
        }
        $stmt = $pdo->prepare("SELECT i.*, r.id AS review_id, r.status AS review_status, r.rating AS rating, r.comment AS comment, r.guest_name AS review_guest FROM `guest_review_invites` i LEFT JOIN `guest_reviews` r ON r.token = i.token WHERE i.token = ? LIMIT 1");
        $stmt->execute([$token]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($invite)) {
            http_response_code(404);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Tautan ulasan tidak ditemukan.']);
            return;
        }
        if (!empty($invite['expires_at']) && strtotime((string)$invite['expires_at']) < time()) {
            http_response_code(410);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Tautan ulasan sudah kedaluwarsa.']);
            return;
        }
        if ($method === 'GET') {
            echo tamasyaJsonEncode(['success' => true, 'data' => [
                'propertyId' => (string)$invite['property_id'],
                'roomNumber' => (string)($invite['room_number'] ?? ''),
                'guestName' => (string)($invite['guest_name'] ?? ''),
                'status' => (string)$invite['status'],
                'rating' => isset($invite['rating']) ? (int)$invite['rating'] : null,
                'comment' => (string)($invite['comment'] ?? ''),
            ]]);
            return;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Penilaian hanya dapat dikirim dengan POST.']);
            return;
        }
        if ((string)$invite['status'] !== 'pending') {
            http_response_code(409);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Ulasan untuk-tautan ini sudah terkirim.']);
            return;
        }
        // Rate limit per token: token sekali pakai sudah membatasi, tapi
        // percobaan berulang dari satu token tidak boleh membanjiri.
        if (function_exists('enforceRateLimit')) {
            $rateKey = 'guest-review:' . substr(hash('sha256', $token), 0, 32);
            if (!enforceRateLimit($pdo, $rateKey, 10, 600, 900)) {
                http_response_code(429);
                echo tamasyaJsonEncode(['success' => false, 'error' => 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.']);
                return;
            }
        }
        $rating = (int)($input['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Rating harus antara 1 sampai 5.']);
            return;
        }
        $comment = trim((string)($input['comment'] ?? ''));
        if (mb_strlen($comment) > 2000) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Komentar terlalu panjang (maksimal 2000 karakter).']);
            return;
        }
        $pdo->beginTransaction();
        $reviewId = generateServerId('rev');
        $pdo->prepare("INSERT INTO `guest_reviews` (id,property_id,booking_id,token,room_number,guest_name,rating,comment,status,published,created_at) VALUES (?,?,?,?,?,?,?,?, 'received', 0, ?)")
            ->execute([$reviewId, (string)$invite['property_id'], (string)$invite['booking_id'], $token, $invite['room_number'] ?? null, $invite['guest_name'] ?? null, $rating, $comment !== '' ? $comment : null, $now]);
        $pdo->prepare("UPDATE `guest_review_invites` SET status='responded' WHERE token=?")->execute([$token]);
        tamasyaFinancialCommit($pdo);
        echo tamasyaJsonEncode(['success' => true, 'data' => ['id' => $reviewId]]);
        return;
    }

    // ---------- Staf: daftar, moderasi, undangan ----------
    requireCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'receptionist']);
    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? ''));
        $sql = "SELECT id,booking_id,room_number,guest_name,rating,comment,reply,status,published,created_at,responded_at,published_at FROM `guest_reviews` WHERE property_id = ?";
        $args = [$propertyId];
        if ($status !== '') { $sql .= ' AND status = ?'; $args[] = $status; }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = ['total' => count($rows), 'published' => 0, 'hidden' => 0, 'average' => 0.0];
        $sum = 0;$rated = 0;
        foreach ($rows as $row) {
            if ((int)$row['published'] === 1) { $summary['published']++; } else { $summary['hidden']++; }
            if ($row['rating'] !== null) { $sum += (int)$row['rating']; $rated++; }
        }
        $summary['average'] = $rated > 0 ? round($sum / $rated, 2) : 0.0;
        echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => ['rows' => $rows, 'summary' => $summary]]);
        return;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        echo tamasyaJsonEncode(['success' => false, 'error' => 'Aksi ulasan hanya dapat ditulis dengan POST.']);
        return;
    }
    $command = trim((string)($input['command'] ?? ''));
    if ($command === 'review-invite') {
        $bookingId = trim((string)($input['bookingId'] ?? ''));
        if ($bookingId === '') {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'ID reservasi wajib diisi.']);
            return;
        }
        $stmt = $pdo->prepare("SELECT id,roomNumber,guestName,status FROM `bookings` WHERE id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($booking)) {
            http_response_code(404);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Reservasi tidak ditemukan.']);
            return;
        }
        $token = bin2hex(random_bytes(24));
        $inviteId = generateServerId('rvi');
        $pdo->prepare("INSERT INTO `guest_review_invites` (id,property_id,booking_id,token,room_number,guest_name,status,created_by,created_at,expires_at) VALUES (?,?,?,?,?,?, 'pending', ?, ?, ?)")
            ->execute([$inviteId, $propertyId, $bookingId, $token, $booking['roomNumber'] ?? null, $booking['guestName'] ?? null, (string)($loggedInStaff['id'] ?? ''), $now, gmdate('Y-m-d H:i:s', time() + 2592000)]);
        echo tamasyaJsonEncode(['success' => true, 'data' => ['inviteId' => $inviteId, 'token' => $token, 'bookingId' => $bookingId]]);
        return;
    }
    if ($command === 'review-publish' || $command === 'review-hide' || $command === 'review-reply') {
        // ModerasiExpiry: hanya pengelola (admin/manajer). Resepsionis boleh
        // membuat undangan dan melihat daftar, tetapi tidak memutuskan
        // apakah ulasan ditampilkan.
        requireCapability($loggedInStaff, 'manage_operational_settings', ['admin', 'manager']);
        $reviewId = trim((string)($input['reviewId'] ?? ''));
        if ($reviewId === '') {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'ID ulasan wajib diisi.']);
            return;
        }
        $stmt = $pdo->prepare("SELECT id,property_id FROM `guest_reviews` WHERE id = ? LIMIT 1");
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($review)) {
            http_response_code(404);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Ulasan tidak ditemukan.']);
            return;
        }
        if ($propertyId !== '' && strtolower((string)$review['property_id']) !== $propertyId) {
            http_response_code(403);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Ulasan berada di luar scope property aktif.']);
            return;
        }
        if ($command === 'review-publish') {
            $pdo->prepare("UPDATE `guest_reviews` SET published=1, published_at=? WHERE id=?")->execute([$now, $reviewId]);
        } elseif ($command === 'review-hide') {
            $pdo->prepare("UPDATE `guest_reviews` SET published=0 WHERE id=?")->execute([$reviewId]);
        } else {
            $reply = trim((string)($input['reply'] ?? ''));
            if (mb_strlen($reply) > 2000) {
                http_response_code(422);
                echo tamasyaJsonEncode(['success' => false, 'error' => 'Balasan terlalu panjang (maksimal 2000 karakter).']);
                return;
            }
            $pdo->prepare("UPDATE `guest_reviews` SET reply=?, responded_at=? WHERE id=?")->execute([$reply !== '' ? $reply : null, $now, $reviewId]);
        }
        echo tamasyaJsonEncode(['success' => true]);
        return;
    }
    http_response_code(400);
    echo tamasyaJsonEncode(['success' => false, 'error' => 'Command ulasan tidak dikenal.']);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('guest_reviews:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'GUEST_REVIEW_FAILED', 'requestId' => tamasyaRuntimeRequestId()]);
}
