<?php
/** TAMASYA R14.2 payment connector phase helpers. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Any content more than five minutes old is a replay candidate, not an event. */
function tamasyaPaymentWebhookSkewSeconds(): int {
    return 300;
}

/**
 * Resolve an ACTIVE provider by code. Inactive or unknown providers are
 * indistinguishable to the caller (both fail closed as 401) and must not be
 * hinted apart.
 */
function tamasyaPaymentProviderResolve(PDO $pdo, string $code): ?array {
    $code = strtolower(trim($code));
    if ($code === '' || !preg_match('/^[a-z0-9._-]{2,50}$/', $code)) return null;
    $stmt = $pdo->prepare("SELECT * FROM growth_payment_providers WHERE code=? AND active=1 LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
}

/**
 * Authenticate an inbound provider webhook from the raw request body.
 *
 * Signature covers the exact bytes the provider signed (timestamp + '.' + body),
 * so a body rewritten by a proxy invalidates it. Comparison is constant time and
 * every failure mode is the same generic error: an attacker must not be able to
 * distinguish "unknown provider" from "bad signature" from "stale timestamp".
 */
function tamasyaPaymentWebhookAuthenticate(PDO $pdo, string $rawBody): array {
    $code = trim((string)($_SERVER['HTTP_X_TAMASYA_PROVIDER'] ?? ''));
    $timestamp = trim((string)($_SERVER['HTTP_X_TAMASYA_TIMESTAMP'] ?? ''));
    $signature = strtolower(trim((string)($_SERVER['HTTP_X_TAMASYA_SIGNATURE'] ?? '')));
    if ($code === '' || !ctype_digit($timestamp) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
        throw new RuntimeException('Autentikasi webhook pembayaran tidak lengkap.');
    }
    if (abs(time() - (int)$timestamp) > tamasyaPaymentWebhookSkewSeconds()) {
        throw new RuntimeException('Timestamp webhook pembayaran di luar toleransi.');
    }
    $provider = tamasyaPaymentProviderResolve($pdo, $code);
    if (!$provider) throw new RuntimeException('Provider pembayaran tidak terdaftar atau tidak aktif.');
    $secret = decryptStoredSecret((string)($provider['webhook_secret_encrypted'] ?? ''));
    if (strlen($secret) < 32) throw new RuntimeException('Secret webhook provider belum dikonfigurasi.');
    if (strtolower((string)($provider['signature_mode'] ?? 'hmac_sha256')) !== 'hmac_sha256') {
        throw new RuntimeException('Mode tanda tangan provider belum didukung.');
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Tanda tangan webhook pembayaran tidak sah.');
    return $provider;
}

/**
 * Map a provider event status to the canonical intent status. Unknown statuses
 * are refused rather than guessed, so a new provider vocabulary can never move
 * money-relevant state by accident.
 */
function tamasyaPaymentProviderStatusMap(string $providerStatus): ?string {
    $status = strtolower(trim($providerStatus));
    $map = [
        'paid' => 'provider_paid',
        'settled' => 'provider_paid',
        'captured' => 'provider_paid',
        'failed' => 'failed',
        'denied' => 'failed',
        'expired' => 'expired',
        'cancelled' => 'expired',
        'canceled' => 'expired',
        'refunded' => 'refunded',
        'refund' => 'refunded',
    ];
    return $map[$status] ?? null;
}

/** Statuses a verified provider event may move an intent into, keyed by current status. */
function tamasyaPaymentIntentTransitionAllowed(string $fromStatus, string $toStatus): bool {
    $from = strtolower(trim($fromStatus));
    $allowed = [
        'created' => ['provider_paid', 'failed', 'expired', 'refunded'],
        'awaiting' => ['provider_paid', 'failed', 'expired', 'refunded'],
        'provider_paid' => ['refunded'],
        'failed' => [],
        'expired' => [],
        'refunded' => [],
    ];
    return in_array($toStatus, $allowed[$from] ?? [], true);
}
