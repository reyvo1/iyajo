<?php
declare(strict_types=1);

/**
 * Regression for configurable shift boundaries (R14.1).
 *
 * The pagi/siang/malam slot must resolve from property-configurable boundaries,
 * with the historical 07:00/15:00/23:00 defaults preserved so existing shift rows
 * keep their meaning. The reader fails safe to defaults when the stored values
 * are corrupt, because the slot drives the Night Audit gate. The web form and
 * the server must share one resolution: the boundaries that used to be hardcoded
 * twice (07:00-15:00-23:00 in the browser vs 18:00-05:00 in SQL) are gone.
 */
$root = dirname(__DIR__);
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
$failures = [];
$checks = 0;
function checkShiftHours(bool $ok, string $label, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed);
}

require $root . '/api/support/060_shift_receipts.php';

// --- schema baseline and migration are additive and idempotent
$migration = file_get_contents($root . '/migrations/20261014_SHIFT_HOURS_CONFIG.sql');
checkShiftHours($migration !== false && strpos($migration, 'shift_day_start') !== false, 'migration installs shift_day_start');
checkShiftHours($migration !== false && strpos($migration, 'shift_siang_start') !== false, 'migration installs shift_siang_start');
checkShiftHours($migration !== false && strpos($migration, 'shift_malam_start') !== false, 'migration installs shift_malam_start');
checkShiftHours($migration !== false && substr_count($migration, 'information_schema.COLUMNS') >= 3, 'migration guards every column with information_schema (idempotent)');
$baseline = file_get_contents($root . '/database_setup.sql');
checkShiftHours($baseline !== false
    && strpos($baseline, "`shift_day_start` time NOT NULL DEFAULT '07:00:00'") !== false
    && strpos($baseline, "`shift_siang_start` time NOT NULL DEFAULT '15:00:00'") !== false
    && strpos($baseline, "`shift_malam_start` time NOT NULL DEFAULT '23:00:00'") !== false,
    'baseline schema carries the three boundaries with historical defaults');

// --- resolution semantics with the default boundaries
$defaults = tamasyaShiftBoundariesMinutes([]);
checkShiftHours($defaults === ['day_start' => 420, 'siang_start' => 900, 'malam_start' => 1380], 'absent settings fall back to 07:00/15:00/23:00', $defaults);
$dates = [
    ['minute' => 6 * 60 + 59, 'expected' => 'malam'],
    ['minute' => 7 * 60, 'expected' => 'pagi'],
    ['minute' => 14 * 60 + 59, 'expected' => 'pagi'],
    ['minute' => 15 * 60, 'expected' => 'siang'],
    ['minute' => 22 * 60 + 59, 'expected' => 'siang'],
    ['minute' => 23 * 60, 'expected' => 'malam'],
    ['minute' => 2 * 60, 'expected' => 'malam'],
];
foreach ($dates as $case) {
    checkShiftHours(tamasyaShiftSlotForMinutes([], $case['minute']) === $case['expected'],
        'default boundaries map ' . $case['minute'] . ' to ' . $case['expected'],
        ['got' => tamasyaShiftSlotForMinutes([], $case['minute'])]);
}

// --- hotel-specific boundaries are honoured exactly
$custom = ['shift_day_start' => '05:00:00', 'shift_siang_start' => '13:00:00', 'shift_malam_start' => '21:00:00'];
$customDates = [
    ['minute' => 4 * 60 + 59, 'expected' => 'malam'],
    ['minute' => 5 * 60, 'expected' => 'pagi'],
    ['minute' => 12 * 60 + 59, 'expected' => 'pagi'],
    ['minute' => 13 * 60, 'expected' => 'siang'],
    ['minute' => 20 * 60 + 59, 'expected' => 'siang'],
    ['minute' => 21 * 60, 'expected' => 'malam'],
    ['minute' => 23 * 60 + 30, 'expected' => 'malam'],
];
foreach ($customDates as $case) {
    checkShiftHours(tamasyaShiftSlotForMinutes($custom, $case['minute']) === $case['expected'],
        'custom boundaries map ' . $case['minute'] . ' to ' . $case['expected'],
        ['got' => tamasyaShiftSlotForMinutes($custom, $case['minute'])]);
}

// --- corrupt or unordered values fail safe, never to an arbitrary slot
foreach ([
    'reversed' => ['shift_day_start' => '15:00:00', 'shift_siang_start' => '07:00:00', 'shift_malam_start' => '23:00:00'],
    'garbage' => ['shift_day_start' => 'not-a-time', 'shift_siang_start' => '99:99', 'shift_malam_start' => ''],
] as $label => $settings) {
    checkShiftHours(tamasyaShiftBoundariesMinutes($settings) === ['day_start' => 420, 'siang_start' => 900, 'malam_start' => 1380],
        'corrupt boundaries (' . $label . ') fail safe to defaults',
        tamasyaShiftBoundariesMinutes($settings));
}
// 00:00/08:00/16:00 is ordered and legal: it must NOT be overridden.
$legal = tamasyaShiftBoundariesMinutes(['shift_day_start' => '00:00:00', 'shift_siang_start' => '08:00:00', 'shift_malam_start' => '16:00:00']);
checkShiftHours($legal === ['day_start' => 0, 'siang_start' => 480, 'malam_start' => 960], 'legal early-day boundaries are honoured', $legal);

// --- seconds are tolerated, e.g. a TIME column read back as HH:MM:SS
checkShiftHours(tamasyaShiftSlotForMinutes(['shift_day_start' => '07:30:00', 'shift_siang_start' => '15:30:00', 'shift_malam_start' => '23:30:00'], 7 * 60 + 29) === 'malam'
    && tamasyaShiftSlotForMinutes(['shift_day_start' => '07:30:00', 'shift_siang_start' => '15:30:00', 'shift_malam_start' => '23:30:00'], 7 * 60 + 30) === 'pagi',
    'HH:MM:SS values resolve to the minute');

// --- server and web share one resolution: no hardcoded slot arithmetic left
$ops = file_get_contents($root . '/api/routes/090_operations_communications.php');
checkShiftHours($ops !== false && strpos($ops, "date('G')>=7 && (int)date('G')<15") === false, 'shift-open no longer hardcodes 07:00-15:00-23:00');
checkShiftHours($ops !== false && strpos($ops, 'tamasyaDefaultShiftSlotForNow($pdo)') !== false, 'shift-open resolves the default slot from settings');
checkShiftHours($ops !== false && strpos($ops, "HOUR(opened_at)>=18 OR HOUR(opened_at)<=5") === false, 'legacy night window 18:00-05:00 removed');
checkShiftHours($ops !== false && strpos($ops, 'HOUR(opened_at)*60+MINUTE(opened_at)>=') !== false, 'legacy night classification uses configurable boundaries');
$smartlock = file_get_contents($root . '/api/support/090_security_cluster_smartlock.php');
checkShiftHours($smartlock !== false && strpos($smartlock, '$hour>=18 || $hour<=5') === false, 'isNightShiftRow no longer hardcodes the night window');
checkShiftHours($smartlock !== false && strpos($smartlock, 'isNightShiftRow(array $shift,?array $boundaries=null)') !== false, 'isNightShiftRow accepts the configured boundaries');
$receipts = file_get_contents($root . '/api/support/060_shift_receipts.php');
checkShiftHours($receipts !== false && strpos($receipts, 'isNightShiftRow($shift,tamasyaShiftBoundariesMinutes($settings))') !== false, 'night-audit gate passes the same boundaries');

// --- settings save validates ordering and persists all three boundaries
checkShiftHours($ops !== false && strpos($ops, "'shiftDayStart'") !== false
    && strpos($ops, "'shiftSiangStart'") !== false
    && strpos($ops, "'shiftMalamStart'") !== false, 'settings save accepts the three boundary fields');
checkShiftHours($ops !== false && strpos($ops, 'Urutan jam shift wajib pagi < siang < malam') !== false, 'settings save rejects unordered boundaries');
checkShiftHours($ops !== false && strpos($ops, 'shift_day_start=?,shift_siang_start=?,shift_malam_start=?') !== false, 'settings save persists all three boundaries');

// --- web form computes its default from the same boundaries
$opsJs = file_get_contents($root . '/assets/chunks/operations.js');
checkShiftHours($opsJs !== false && strpos($opsJs, 'function tamasyaShiftSlotBoundaries(') !== false, 'web form resolves the slot from settings');
checkShiftHours($opsJs !== false && strpos($opsJs, '_P>=_b.malam||_P<_b.day?"malam":_P>=_b.siang?"siang":"pagi"') !== false, 'web form default matches the server resolution');
checkShiftHours($opsJs !== false && strpos($opsJs, 'Y>=7&&Y<15?"pagi":Y>=15&&Y<23?"siang":"malam"') === false, 'web form no longer hardcodes the slot');
checkShiftHours($opsJs !== false && strpos($opsJs, 'shiftDayStart:_dayStart') !== false, 'web settings flow sends the three boundaries');

if ($failures) {
    fwrite(STDERR, "Shift hours config assertions FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Shift hours config assertions passed: {$checks} checks\n";
