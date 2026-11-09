<?php
declare(strict_types=1);

/**
 * Regression for the shift-aware attendance fallback logging.
 *
 * tamasyaAttendanceClockInStatus() falls back to the legacy flat 08:30 rule
 * whenever operational shift settings are unavailable. That fallback must be
 * observable (error_log), not silent, so a property that has never filled
 * hotel_operational_settings does not quietly re-introduce "always late"
 * behaviour for afternoon/night-shift staff without a trace in the log.
 *
 * Verified statically against the helper (no DB needed).
 */
$root = dirname(__DIR__);
$failures = [];
$checks = 0;
function check(string $label, bool $ok, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed, JSON_UNESCAPED_SLASHES);
}

$file = (string)@file_get_contents($root . '/api/support/015_biometric_attendance.php');
check('biometric attendance support is readable', $file !== '', ['len' => strlen($file)]);

check('helper is defined',
    str_contains($file, 'function tamasyaAttendanceClockInStatus(PDO $pdo, string $time): string'),
    ['found' => str_contains($file, 'function tamasyaAttendanceClockInStatus')]);

// The catch branch that falls back to the legacy rule must log the fallback.
check('legacy fallback on getOperationalSettings failure is logged',
    str_contains($file, "error_log('[attendance] getOperationalSettings gagal saat menilai status clock-in; memakai aturan lama: ' . \$e->getMessage())"),
    ['has_log' => str_contains($file, "error_log('[attendance] getOperationalSettings gagal saat menilai status clock-in; memakai aturan lama: ' . \$e->getMessage())")]);

// The legacy path must still be the defined floor (staf shift pagi default
// tidak berubah), jadi check bahwa variabel $legacy tetap dipakai di fallback.
check('fallback still returns the legacy present/late decision, not a new default',
    (bool)preg_match('/\$legacy = \$minute > \$lateAfterMinute \? \'late\' : \'present\';/', $file),
    ['snippet' => substr($file, max(0, strpos($file, '$legacy =')), 80)]);

if ($failures) {
    fwrite(STDERR, "Shift-aware attendance fallback regression FAILED:\n");
    foreach ($failures as $f) fwrite(STDERR, '  - ' . $f . "\n");
    exit(1);
}
echo "Shift-aware attendance fallback regression passed: {$checks} checks\n";
