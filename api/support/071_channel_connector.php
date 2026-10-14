<?php
/** TAMASYA R14.3 channel connector phase helpers. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Any content more than five minutes old is a replay candidate, not an event. */
function tamasyaChannelWebhookSkewSeconds(): int {
    return 300;
}

/**
 * Resolve an ACTIVE channel provider by code. Inactive or unknown providers must
 * look identical to the caller, so the webhook cannot be used as an existence oracle.
 */
function tamasyaChannelProviderResolve(PDO $pdo, string $code): ?array {
    $code = strtolower(trim($code));
    if ($code === '' || !preg_match('/^[a-z0-9._-]{2,50}$/', $code)) return null;
    $stmt = $pdo->prepare("SELECT * FROM growth_channel_providers WHERE code=? AND active=1 LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
}

/**
 * Authenticate an inbound channel webhook from the raw request body. The signature
 * covers the exact signed bytes (timestamp + '.' + body), the comparison is
 * constant time, and every failure is the same generic error.
 */
function tamasyaChannelWebhookAuthenticate(PDO $pdo, string $rawBody): array {
    $code = trim((string)($_SERVER['HTTP_X_TAMASYA_PROVIDER'] ?? ''));
    $timestamp = trim((string)($_SERVER['HTTP_X_TAMASYA_TIMESTAMP'] ?? ''));
    $signature = strtolower(trim((string)($_SERVER['HTTP_X_TAMASYA_SIGNATURE'] ?? '')));
    if ($code === '' || !ctype_digit($timestamp) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
        throw new RuntimeException('Autentikasi webhook channel tidak lengkap.');
    }
    if (abs(time() - (int)$timestamp) > tamasyaChannelWebhookSkewSeconds()) {
        throw new RuntimeException('Timestamp webhook channel di luar toleransi.');
    }
    $provider = tamasyaChannelProviderResolve($pdo, $code);
    if (!$provider) throw new RuntimeException('Provider channel tidak terdaftar atau tidak aktif.');
    $secret = decryptStoredSecret((string)($provider['webhook_secret_encrypted'] ?? ''));
    if (strlen($secret) < 32) throw new RuntimeException('Secret webhook provider belum dikonfigurasi.');
    if (strtolower((string)($provider['signature_mode'] ?? 'hmac_sha256')) !== 'hmac_sha256') {
        throw new RuntimeException('Mode tanda tangan provider belum didukung.');
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Tanda tangan webhook channel tidak sah.');
    return $provider;
}

/**
 * The only event type this phase acts on. Anything else is recorded and refused,
 * so a vendor vocabulary can never invent a booking path.
 */
function tamasyaChannelEventTypeSupported(string $eventType): bool {
    return strtolower(trim($eventType)) === 'reservation_created';
}

/**
 * Resolve the internal room type and rate for an inbound reservation from the
 * mapping the administrator configured. Returns null when the mapping is missing
 * or inactive; the caller records the event for a human instead of guessing.
 */
function tamasyaChannelReservationMapping(PDO $pdo, string $channelCode, string $externalRoomType, string $externalRatePlan, string $externalPropertyId): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM growth_channel_mappings WHERE channel_code=? AND external_room_type=? AND active=1"
        . " AND (external_rate_plan=? OR external_rate_plan='') ORDER BY (external_rate_plan='') ASC, updated_at DESC LIMIT 1"
    );
    $stmt->execute([$channelCode, $externalRoomType, $externalRatePlan]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;
    $internalRoomType = trim((string)($row['internal_room_type'] ?? ''));
    if ($internalRoomType === '') return null;
    // The mapping's external property must match the payload's property when both exist.
    $mappedProperty = trim((string)($row['external_property_id'] ?? ''));
    if ($mappedProperty !== '' && $externalPropertyId !== '' && strcasecmp($mappedProperty, $externalPropertyId) !== 0) return null;
    return $row;
}
