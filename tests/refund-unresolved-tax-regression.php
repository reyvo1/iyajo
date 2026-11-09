<?php
declare(strict_types=1);

/**
 * Regression for the booking-refund unresolved-tax fix (FIN-03).
 *
 * When a booking refund's income source has taxSnapshotStatus 'unresolved',
 * the refund must not record a definite tax figure: baseAmount, taxAmount and
 * taxRate must all be null/0 so the refund is booked to suspense instead of
 * asserting a confirmed PBJT amount against revenue that was never confirmed.
 *
 * Verified statically against the specific refund-writing block in
 * createBookingRefundTransactions (no DB needed).
 */
$root = dirname(__DIR__);
$failures = [];
$checks = 0;
function check(string $label, bool $ok, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed, JSON_UNESCAPED_SLASHES);
}

$file = (string)@file_get_contents($root . '/api/modules/finance/030_booking_finance.php');
check('booking finance module is readable', $file !== '', ['len' => strlen($file)]);

// Isolate the specific refund posting block.
$start = strpos($file, "'transactionKind'=>'refund','sourceEntity'=>'booking_refund'");
check('refund posting block is present', $start !== false, ['at' => $start]);
$window = substr($file, max(0, $start - 2000), 3000);
$callPos = strpos($window, "tamasyaPostFinancialTransaction(\$pdo,[");
check('refund posting call is found within a wide window around the marker', $callPos !== false, ['window_len' => strlen($window), 'callAt' => $callPos]);
$block = ($callPos !== false) ? substr($window, $callPos) : $window;

// The flag must be defined in the wider region (before the posting call).
$wideRegion = substr($file, max(0, $start - 2000), 5000);
$flagNeedle = '$refundSourceTaxUnresolved = strtolower(trim((string)($income[\'taxSnapshotStatus\'] ?? \'\'))) === \'unresolved\';';
check('refund source unresolved flag is computed from income taxSnapshotStatus',
    str_contains($wideRegion, $flagNeedle),
    ['flag_present' => str_contains($wideRegion, '$refundSourceTaxUnresolved')]);

$baseNeedle = '!$refundSourceTaxUnresolved && array_key_exists(\'baseAmount\',$share)';
check('refund baseAmount is suppressed for an unresolved source',
    str_contains($block, $baseNeedle)
        && !preg_match("/'baseAmount'=>\(array_key_exists\('baseAmount',\$share\)/", $block),
    ['snippet' => substr($block, max(0, strpos($block, "'baseAmount'=>") - 20), 200)]);

$taxAmtNeedle = '\'taxAmount\'=>(!$refundSourceTaxUnresolved ? max(0.0,(float)($share[\'taxAmount\']??0)) : 0.0)';
check('refund taxAmount is zeroed for an unresolved source',
    str_contains($block, $taxAmtNeedle),
    ['snippet' => substr($block, max(0, strpos($block, "'taxAmount'=>") - 20), 200)]);

$taxRateNeedle = '\'taxRate\'=>(!$refundSourceTaxUnresolved ? max(0.0,(float)($share[\'taxRate\']??0)) : 0.0)';
check('refund taxRate is zeroed for an unresolved source',
    str_contains($block, $taxRateNeedle),
    ['snippet' => substr($block, max(0, strpos($block, "'taxRate'=>") - 20), 200)]);

$statusNeedle = '\'taxSnapshotStatus\'=> (!$refundSourceTaxUnresolved && array_key_exists(\'baseAmount\',$share)';
check('refund taxSnapshotStatus follows the unresolved source',
    str_contains($block, $statusNeedle),
    ['snippet' => substr($block, max(0, strpos($block, "'taxSnapshotStatus'=>") - 20), 200)]);

if ($failures) {
    fwrite(STDERR, "Refund unresolved-tax regression FAILED:\n");
    foreach ($failures as $f) fwrite(STDERR, '  - ' . $f . "\n");
    exit(1);
}
echo "Refund unresolved-tax regression passed: {$checks} checks\n";
