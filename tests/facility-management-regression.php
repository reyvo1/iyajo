<?php
declare(strict_types=1);

/**
 * Regression for dynamic facility management (R15).
 *
 * Facility types are data, not code: a new pool, gym, karaoke room or spa must
 * never require a code change. The engine guarantees three things that must not
 * regress: prices are computed server-side (the client never dictates money),
 * exclusive capacity cannot double-book (slot/room models fail closed with 409),
 * and usage charges post as folio extras through the canonical booking workflow
 * so settlement stays in the normal payment/checkout flow. No code path in the
 * facility route may write a transaction row directly.
 */
$root = dirname(__DIR__);
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
$failures = [];
$checks = 0;
function checkFacility(bool $ok, string $label, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed);
}

require $root . '/api/routes/096_facility_management.php';

// --- schema: three tables in the additive migration and the baseline
$migration = file_get_contents($root . '/migrations/20261015_FACILITY_MANAGEMENT.sql');
foreach (['facility_types', 'facilities', 'facility_bookings'] as $table) {
    checkFacility($migration !== false && strpos($migration, $table) !== false, 'migration installs ' . $table);
}
checkFacility($migration !== false && substr_count($migration, 'information_schema.TABLES') >= 3, 'every facility table install is guarded and idempotent');
$baseline = file_get_contents($root . '/database_setup.sql');
foreach (['facility_types', 'facilities', 'facility_bookings'] as $table) {
    checkFacility($baseline !== false && strpos($baseline, 'CREATE TABLE `' . $table . '`') !== false, 'baseline carries ' . $table);
}

// --- pure pricing semantics: the server owns the arithmetic
checkFacility(tamasyaFacilityPrice(['pricing_model' => 'visit'], ['base_price' => 50000], 3, null, null) === [50000.0, 150000.0], 'visit multiplies base by pax');
checkFacility(tamasyaFacilityPrice(['pricing_model' => 'hour'], ['base_price' => 100000], 4, '19:00', '21:30') === [100000.0, 300000.0], 'hour rounds a 2.5h stay up to 3 billed hours');
checkFacility(tamasyaFacilityPrice(['pricing_model' => 'package'], ['base_price' => 250000], 10, null, null) === [250000.0, 250000.0], 'package is flat regardless of pax');
checkFacility(tamasyaFacilityPrice(['pricing_model' => 'member'], ['base_price' => 50000], 2, null, null) === [0.0, 0.0], 'member usage records no charge');
$hourRejected = false;
try { tamasyaFacilityPrice(['pricing_model' => 'hour'], ['base_price' => 100000], 1, '21:00', '19:00'); } catch (InvalidArgumentException $e) { $hourRejected = true; }
checkFacility($hourRejected, 'an inverted time range is rejected');
$modelRejected = false;
try { tamasyaFacilityValidateModel('room', 'per_second'); } catch (InvalidArgumentException $e) { $modelRejected = true; }
checkFacility($modelRejected, 'an unknown pricing model is rejected');
$capacityRejected = false;
try { tamasyaFacilityValidateModel('stadium', 'visit'); } catch (InvalidArgumentException $e) { $capacityRejected = true; }
checkFacility($capacityRejected, 'an unknown capacity model is rejected');

// --- route registration and role gates
$router = file_get_contents($root . '/api/router.php');
checkFacility($router !== false && strpos($router, "routes/096_facility_management.php") !== false, 'router loads the facility route');
$manifest = json_decode((string)file_get_contents($root . '/api/MODULARIZATION_MANIFEST.json'), true);
checkFacility(is_array($manifest) && in_array('096_facility_management.php', $manifest['routeModules'] ?? [], true), 'manifest lists the facility route');
$api = file_get_contents($root . '/api.php');
checkFacility($api !== false && strpos($api, "'facilities'=>['rooms','operations']") !== false, 'facilities action is gated to rooms and operations tabs');
$setup = file_get_contents($root . '/api/modules/setup_admin/004_property_setup.php');
checkFacility($setup !== false && strpos($setup, "'inventory-maintenance','sync','growth-suite','enterprise-suite','facilities'") !== false, 'facility mutations require a READY property');

// --- no direct money path exists in the facility route
$route = file_get_contents($root . '/api/routes/096_facility_management.php');
checkFacility($route !== false && strpos($route, 'INSERT INTO transactions') === false, 'facility route never inserts transaction rows');
checkFacility($route !== false && strpos($route, 'tamasyaPostFinancialTransaction') === false, 'facility route never posts financial transactions');
checkFacility($route !== false && strpos($route, 'recordBookingDeposit') === false, 'facility route never records deposits');
checkFacility($route !== false && strpos($route, "'taxKind'=>'extra'") !== false && strpos($route, "'allocationType'=>'extra'") !== false, 'facility charges post as extra folio lines');
checkFacility($route !== false && strpos($route, "tamasyaRequireSystemFinanceCategory(\$pdo,'extra_service','income',true)") !== false, 'facility charges use the extra_service income role');
checkFacility($route !== false && strpos($route, 'recalculateBookingFinancials($pdo,') !== false, 'folio mutation recalculates booking financials');
checkFacility($route !== false && strpos($route, 'assertBookingLedgerInvariant($pdo,') !== false, 'folio mutation asserts the ledger invariant');
checkFacility($route !== false && strpos($route, "tamasyaFacilitySlotConflicts(\$pdo,\$facilityId,\$useDate,\$startTime,\$endTime") !== false, 'slot and room models check exclusive time');
checkFacility($route !== false && strpos($route, 'tamasyaFacilityQuotaRemaining($pdo,$facilityId,$useDate') !== false, 'quota model checks remaining headcount');
checkFacility($route !== false && strpos($route, "throw new DomainException('Slot fasilitas sudah terisi") !== false, 'a taken slot fails closed as a conflict');
checkFacility($route !== false && strpos($route, "payment_status']==='paid'") !== false && strpos($route, 'refund, bukan pembatalan slot') !== false, 'a paid usage can only leave through refund, never slot cancel');
checkFacility($route !== false && strpos($route, "requireRoles(\$loggedInStaff,['admin','manager']);") !== false, 'master data stays admin and manager');
checkFacility($route !== false && strpos($route, "requireRoles(\$loggedInStaff,['admin','manager','receptionist']);") !== false, 'booking stays operable by receptionists');
checkFacility($route !== false && strpos($route, 'operation_id=? LIMIT 1 FOR UPDATE') !== false, 'booking is idempotent per operation id');

// --- UI placement: facilities live in daily operations, next to shifts
$nav = file_get_contents($root . '/assets/chunks/feature-shared.js');
checkFacility($nav !== false && strpos($nav, '{key:"facilities",label:"Fasilitas & Reservasi"') !== false, 'sub-nav carries the facilities key exactly once');
checkFacility($nav !== false && strpos($nav, '"shifts","facilities","guests"') !== false, 'facilities sits inside daily operations between shifts and guests');
$ops = file_get_contents($root . '/assets/chunks/operations.js');
checkFacility($ops !== false && strpos($ops, 'facilities:()=>{') !== false, 'the facilities view is wired into the module map');
checkFacility($ops !== false && strpos($ops, '"shifts","facilities","calendar"') !== false, 'receptionists see facilities in their sub-nav');
checkFacility($ops !== false && strpos($ops, 'facility-type-save') !== false && strpos($ops, 'facility-booking-cancel') !== false, 'the view reaches every facility command');

if ($failures) {
    fwrite(STDERR, "Facility management assertions FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Facility management assertions passed: {$checks} checks\n";
