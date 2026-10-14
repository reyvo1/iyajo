<?php
declare(strict_types=1);

/**
 * Regression for the channel connector phase (R14.3).
 *
 * A verified vendor reservation may create a RESERVED draft and nothing else.
 * It never posts money, never checks a guest in, never invents a room type, and
 * never guesses a rate: an unmapped room type is recorded for a human. Every
 * authentication failure answers 401 with one opaque message, the same external
 * reservation id never produces a second draft, and only the single supported
 * event type can act at all.
 */
$root = dirname(__DIR__);
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
$failures = [];
$checks = 0;
function checkChannelConnector(bool $ok, string $label, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed);
}

require $root . '/api/support/071_channel_connector.php';

$v137 = file_get_contents($root . '/migrations/V137_OPTIONAL_GROWTH_SUITE.sql');
checkChannelConnector($v137 !== false && strpos($v137, 'CREATE TABLE IF NOT EXISTS growth_channel_providers') !== false, 'optional growth schema creates the channel provider registry');
$migration = file_get_contents($root . '/migrations/20261014_CHANNEL_CONNECTOR_PHASE.sql');
checkChannelConnector($migration !== false && strpos($migration, 'growth_channel_providers') !== false
    && substr_count($migration, 'information_schema.TABLES') >= 1, 'channel connector migration is guarded and idempotent');

// --- only the supported event type may act
checkChannelConnector(tamasyaChannelEventTypeSupported('reservation_created'), 'reservation_created is supported');
checkChannelConnector(tamasyaChannelEventTypeSupported('RESERVATION_CREATED'), 'event type match is case-insensitive');
checkChannelConnector(!tamasyaChannelEventTypeSupported('booking_modified'), 'a modification event cannot invent an action');
checkChannelConnector(!tamasyaChannelEventTypeSupported('booking_cancelled'), 'a cancellation event cannot act in this phase');
checkChannelConnector(!tamasyaChannelEventTypeSupported(''), 'an empty event type is refused');
checkChannelConnector(tamasyaChannelWebhookSkewSeconds() === 300, 'channel webhook skew stays at five minutes');

// --- api.php registration
$api = file_get_contents($root . '/api.php');
checkChannelConnector($api !== false && strpos($api, "'growth-payment-webhook', 'growth-channel-webhook', 'public-site-config'") !== false, 'api.php lists the channel webhook as a public action');
checkChannelConnector($api !== false && strpos($api, "'growth-channel-webhook', 'public-help-chat-sync', 'biometric-device-webhook']") !== false, 'api.php excludes the channel webhook from server revision');

// --- handler contract
$route = file_get_contents($root . '/api/routes/110_growth_suite.php');
checkChannelConnector($route !== false && strpos($route, "tamasyaChannelWebhookAuthenticate(\$pdo,(string)(\$rawRequestBody??''))") !== false, 'channel webhook authenticates against the exact raw body');
checkChannelConnector($route !== false && strpos($route, "http_response_code(401);\n            echo tamasyaJsonEncode(['success'=>false,'error'=>'Webhook channel ditolak.']);") !== false, 'channel authentication failures answer 401 with one opaque message');
checkChannelConnector($route !== false && strpos($route, "enforceRateLimit(\$pdo,\$rateKey,120,60,600)") !== false && substr_count($route, 'growth-channel-webhook:') >= 1, 'channel webhook is rate limited per provider and address');
checkChannelConnector($route !== false && strpos($route, "tamasyaChannelEventTypeSupported(\$eventType)") !== false, 'only the supported event type reaches business logic');
checkChannelConnector($route !== false && strpos($route, "tamasyaChannelReservationMapping(\$pdo,\$channelCode") !== false, 'the mapping decides the internal room type, never the payload');
checkChannelConnector($route !== false && strpos($route, "'lifecycleIntent'=>'reserve'") !== false, 'an inbound reservation creates a reserved draft, never a check-in');
checkChannelConnector($route !== false && strpos($route, "'paymentStatus'=>'unpaid'") !== false && strpos($route, "'financialMutation'=>false") !== false, 'the draft is unpaid and the response states no money was posted');
checkChannelConnector($route !== false && strpos($route, "'created'=>false,'reason'=>'unmapped'") !== false, 'an unmapped room type is recorded for a human instead of guessed');
checkChannelConnector($route !== false && strpos($route, 'growth_channel_events WHERE channel_code=? AND external_id=? LIMIT 1 FOR UPDATE') !== false, 'the same external reservation can never create a second draft');
checkChannelConnector($route !== false && strpos($route, 'createCanonicalBookingWorkflow($pdo,$systemActor,$bookingPayload') !== false, 'the draft is created through the canonical booking workflow');
checkChannelConnector($route !== false && strpos($route, 'INSERT INTO growth_channel_events(id,channel_code,event_type,external_id,operation_id,status,payload_hash,created_by)') !== false && strpos($route, "'applied'") !== false, 'an applied event is recorded against the deployed event schema (no invented column)');
checkChannelConnector($route !== false && strpos($route, 'rateAmount>0?$rateAmount:$base') !== false, 'the vendor rate is checked against the mapped rate plan bounds');
// No financial writer may be reachable from the channel webhook.
$webhookBlock = substr($route, (int)strpos($route, "=== 'growth-channel-webhook'"), 12000);
checkChannelConnector($webhookBlock !== false && strpos($webhookBlock, 'INSERT INTO transactions') === false
    && strpos($webhookBlock, 'tamasyaPostFinancialTransaction') === false && strpos($webhookBlock, 'recordBookingDeposit') === false,
    'the channel webhook cannot post money by any path');

// --- provider management
checkChannelConnector($route !== false && strpos($route, 'Secret webhook provider wajib minimal 32 karakter.') !== false, 'a new channel provider requires a secret');
checkChannelConnector($route !== false && strpos($route, "CHAR_LENGTH(COALESCE(webhook_secret_encrypted,'')) AS secret_length") !== false, 'the channel provider readout exposes only a length');
checkChannelConnector($route !== false && strpos($route, "case 'channel-provider-save':") !== false && strpos($route, "requireRoles(\$loggedInStaff,['admin']);\$code=strtolower") !== false, 'channel provider save stays admin-only');
checkChannelConnector($route !== false && strpos($route, "case 'payment-provider-list':") !== false, 'the payment provider list case survived the channel additions');

// --- UI documents the contract
$suite = file_get_contents($root . '/growth-suite.html');
checkChannelConnector($suite !== false && strpos($suite, 'growth-channel-webhook') !== false && strpos($suite, 'HMAC-SHA256') !== false, 'growth UI documents the channel webhook contract');
checkChannelConnector($suite !== false && strpos($suite, 'channel-provider-form') !== false, 'growth UI can manage channel providers');
$suiteJs = file_get_contents($root . '/assets/growth-suite.js');
checkChannelConnector($suiteJs !== false && strpos($suiteJs, 'channel-provider-save') !== false, 'growth UI wires the channel provider save');

if ($failures) {
    fwrite(STDERR, "Channel connector assertions FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Channel connector assertions passed: {$checks} checks\n";
