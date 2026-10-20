<?php
declare(strict_types=1);

/**
 * Regression for the payment connector phase (R14.2).
 *
 * The payment foundation records intents and events without ever touching money.
 * This phase adds authenticated provider webhooks. The contract that must not
 * regress: a provider event can only move an intent to a provider-reported status
 * and record the event; the money is still posted by the canonical settlement
 * flow. Unknown statuses are refused, transitions are one-way, an inactive or
 * secret-less provider can never authenticate, and every authentication failure
 * looks identical to the caller.
 */
$root = dirname(__DIR__);
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
$failures = [];
$checks = 0;
function checkPaymentConnector(bool $ok, string $label, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed);
}

require $root . '/api/support/070_payment_connector.php';

// --- schema: provider registry exists in the optional migration and the additive migration
$v137 = file_get_contents($root . '/migrations/V137_OPTIONAL_GROWTH_SUITE.sql');
checkPaymentConnector($v137 !== false && strpos($v137, 'CREATE TABLE IF NOT EXISTS growth_payment_providers') !== false, 'optional growth schema creates the provider registry');
$migration = file_get_contents($root . '/migrations/20261014_PAYMENT_CONNECTOR_PHASE.sql');
checkPaymentConnector($migration !== false && strpos($migration, 'growth_payment_providers') !== false
    && substr_count($migration, 'information_schema.TABLES') >= 1, 'connector migration is guarded and idempotent');

// --- provider status mapping is explicit
checkPaymentConnector(tamasyaPaymentProviderStatusMap('paid') === 'provider_paid', 'paid maps to provider_paid');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('settled') === 'provider_paid', 'settled maps to provider_paid');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('captured') === 'provider_paid', 'captured maps to provider_paid');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('failed') === 'failed', 'failed maps to failed');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('denied') === 'failed', 'denied maps to failed');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('expired') === 'expired', 'expired maps to expired');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('canceled') === 'expired', 'canceled maps to expired');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('refunded') === 'refunded', 'refunded maps to refunded');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('pending_confirmation_new_vocabulary') === null, 'unknown provider status is refused, never guessed');
checkPaymentConnector(tamasyaPaymentProviderStatusMap('') === null, 'empty status is refused');

// --- transitions are one-way
checkPaymentConnector(tamasyaPaymentIntentTransitionAllowed('created', 'provider_paid'), 'created can become provider_paid');
checkPaymentConnector(tamasyaPaymentIntentTransitionAllowed('awaiting', 'failed'), 'awaiting can fail');
checkPaymentConnector(tamasyaPaymentIntentTransitionAllowed('provider_paid', 'refunded'), 'provider_paid can be refunded');
checkPaymentConnector(!tamasyaPaymentIntentTransitionAllowed('provider_paid', 'provider_paid'), 'a settled intent cannot settle twice');
checkPaymentConnector(!tamasyaPaymentIntentTransitionAllowed('failed', 'provider_paid'), 'a failed intent cannot be paid');
checkPaymentConnector(!tamasyaPaymentIntentTransitionAllowed('expired', 'provider_paid'), 'an expired intent cannot be paid');
checkPaymentConnector(!tamasyaPaymentIntentTransitionAllowed('refunded', 'provider_paid'), 'a refunded intent cannot be paid again');
checkPaymentConnector(!tamasyaPaymentIntentTransitionAllowed('created', 'nonexistent'), 'unknown target status is refused');

// --- provider code validation runs before any database lookup
checkPaymentConnector(tamasyaPaymentWebhookSkewSeconds() === 300, 'webhook skew stays at five minutes');

// --- api.php registers the webhook as public and revision-neutral
$api = file_get_contents($root . '/api.php');
checkPaymentConnector($api !== false && strpos($api, "'growth-payment-webhook', 'growth-channel-webhook', 'public-site-config'") !== false, 'api.php lists the webhook as a public action');
checkPaymentConnector($api !== false && strpos($api, "'growth-payment-webhook', 'growth-channel-webhook', 'public-help-chat-sync', 'biometric-device-webhook']") !== false, 'api.php excludes the webhook from server revision');

// --- route: authentication before parsing, no money, events carry a verified signature
$route = file_get_contents($root . '/api/routes/110_growth_suite.php');
checkPaymentConnector($route !== false && strpos($route, "tamasyaPaymentWebhookAuthenticate(\$pdo,(string)(\$rawRequestBody??''))") !== false, 'webhook authenticates against the exact raw body');
checkPaymentConnector($route !== false && strpos($route, 'signature_valid,event_at) VALUES (?,?,?,?,?,?,1,?)') !== false, 'webhook event is stored with signature_valid=1');
checkPaymentConnector($route !== false && strpos($route, "'financialMutation'=>false") !== false, 'webhook response states no financial mutation');
checkPaymentConnector($route !== false && strpos($route, "'nextAction'=>'settle_canonical_payment'") !== false, 'webhook directs staff to the canonical settlement flow');
checkPaymentConnector($route !== false && strpos($route, 'UPDATE growth_payment_intents SET status=?,updated_at=CURRENT_TIMESTAMP') !== false, 'webhook only moves intent status, never money rows');
checkPaymentConnector($route !== false && strpos($route, "tamasyaPaymentProviderStatusMap(\$providerStatus)") !== false, 'webhook refuses unknown provider statuses');
checkPaymentConnector($route !== false && strpos($route, 'tamasyaPaymentIntentTransitionAllowed') !== false, 'webhook enforces one-way intent transitions');
checkPaymentConnector($route !== false && strpos($route, "enforceRateLimit(\$pdo,\$rateKey,120,60,600)") !== false, 'webhook is rate limited per provider and address');
checkPaymentConnector($route !== false && strpos($route, 'provider_event_id=? LIMIT 1 FOR UPDATE') !== false, 'webhook deduplicates by provider_event_id');
checkPaymentConnector($route !== false && strpos($route, 'Provider webhook tidak sesuai dengan intent.') !== false, 'webhook rejects an intent that belongs to a different provider');
// Every authentication failure answers 401 with one fixed message, so a caller cannot
// tell an unknown provider from a bad signature or a stale timestamp.
checkPaymentConnector($route !== false && strpos($route, "\$provider=tamasyaPaymentWebhookAuthenticate") !== false
    && strpos($route, "http_response_code(401);\n            echo tamasyaJsonEncode(['success'=>false,'error'=>'Webhook pembayaran ditolak.']);") !== false,
    'authentication failures answer 401 with a single opaque message');
checkPaymentConnector($route !== false && strpos($route, 'tamasyaApplyExceptionHttpStatus($e,422)') !== false, 'business rejections keep the standard status mapping, never 401');

// --- provider management refuses secret-less providers and hides stored secrets
checkPaymentConnector($route !== false && strpos($route, 'Secret webhook provider wajib minimal 32 karakter.') !== false, 'a new provider requires a secret');
checkPaymentConnector($route !== false && strpos($route, "CHAR_LENGTH(COALESCE(webhook_secret_encrypted,'')) AS secret_length") !== false, 'provider readout exposes only a length, never the secret');
checkPaymentConnector($route !== false && strpos($route, "case 'payment-provider-save':") !== false && strpos($route, "requireRoles(\$loggedInStaff,['admin']);\$code=strtolower") !== false, 'provider save stays admin-only');
checkPaymentConnector($route !== false && strpos($route, "'payment-provider-list'=>['rooms','finance','config']") !== false, 'provider list is gated to real tabs');
checkPaymentConnector($route !== false && strpos($route, 'tamasyaGrowthModuleEnabled(\'payment\')&&$canPaymentRead){$data[\'paymentProviders\']') !== false, 'provider rows follow the same payment read gate as intents, so Finance sees them too');

// --- UI documents the authenticated contract
$suite = file_get_contents($root . '/growth-suite.html');
checkPaymentConnector($suite !== false && strpos($suite, 'X-Tamasya-Signature') !== false && strpos($suite, 'HMAC-SHA256') !== false, 'growth UI documents the webhook signature contract');
$suiteJs = file_get_contents($root . '/assets/growth-suite.js');
checkPaymentConnector($suiteJs !== false && strpos($suiteJs, 'payment-provider-save') !== false, 'growth UI can manage providers');
checkPaymentConnector($suiteJs !== false && strpos($suiteJs, 'providerForm.classList.toggle(\'role-hidden\',!ma(\'manage_operational_settings\', [\'admin\']))') !== false, 'provider management is admin-only in the UI');

if ($failures) {
    fwrite(STDERR, "Payment connector assertions FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Payment connector assertions passed: {$checks} checks\n";
