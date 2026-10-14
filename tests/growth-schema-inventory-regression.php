<?php
declare(strict_types=1);

/**
 * Regression for the optional growth schema inventory.
 *
 * Three places must describe the same table set, or the release breaks long
 * before any scenario runs:
 *   1. migrations/V137_OPTIONAL_GROWTH_SUITE.sql  (source of truth)
 *   2. optional_modules_install.php $growthTables (installer-reported counts)
 *   3. tamasyaGrowthSchemaTables() in 105_growth_suite.php (readiness contract)
 *
 * A table added to the migration but missing from the installer makes the CI
 * inventory gate red (expectedTables mismatch); a table missing from the
 * readiness contract makes modules pass readiness while their tables do not
 * exist, which then fails late as a schema SQLSTATE in production-like UAT.
 */
$root = dirname(__DIR__);
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
$failures = [];
$checks = 0;
function checkGrowthInventory(bool $ok, string $label, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed);
}

function growthCreateTables(string $sql): array {
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
}

$migration = (string)file_get_contents($root . '/migrations/V137_OPTIONAL_GROWTH_SUITE.sql');
checkGrowthInventory($migration !== '', 'migration file is readable');
$migrationTables = growthCreateTables($migration);
checkGrowthInventory(count($migrationTables) > 0, 'migration declares growth tables', ['count' => count($migrationTables)]);
checkGrowthInventory(in_array('growth_payment_providers', $migrationTables, true)
    && in_array('growth_channel_providers', $migrationTables, true), 'migration declares both connector provider registries');

$installer = (string)file_get_contents($root . '/optional_modules_install.php');
$installerTables = [];
if (preg_match("/\\\$growthTables=\[(.*?)\];/s", $installer, $m)) {
    preg_match_all("/'([A-Za-z0-9_]+)'/", $m[1], $names);
    $installerTables = array_values(array_unique(array_map('strtolower', $names[1])));
}
sort($migrationTables);
$sortedInstaller = $installerTables;
sort($sortedInstaller);
checkGrowthInventory($sortedInstaller === $migrationTables, 'installer inventory matches the migration file',
    ['migration' => count($migrationTables), 'installer' => count($installerTables),
     'missing' => array_values(array_diff($migrationTables, $sortedInstaller)),
     'extra' => array_values(array_diff($sortedInstaller, $migrationTables))]);

$contract = (string)file_get_contents($root . '/api/modules/growth_enterprise_locked/105_growth_suite.php');
$contractTables = [];
if (preg_match('/function tamasyaGrowthSchemaTables\(\).*?return\s*\[(.*?)\];/s', $contract, $m)) {
    preg_match_all("/'([A-Za-z0-9_]+)'/", $m[1], $names);
    $contractTables = array_values(array_unique(array_map('strtolower', $names[1])));
}
sort($contractTables);
checkGrowthInventory($contractTables === $migrationTables, 'readiness contract matches the migration file',
    ['contract' => count($contractTables),
     'missing' => array_values(array_diff($migrationTables, $contractTables)),
     'extra' => array_values(array_diff($contractTables, $migrationTables))]);
checkGrowthInventory(in_array('growth_payment_providers', $contractTables, true)
    && in_array('growth_channel_providers', $contractTables, true), 'readiness covers both connector provider registries');

if ($failures) {
    fwrite(STDERR, "Growth schema inventory assertions FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Growth schema inventory assertions passed: {$checks} checks\n";
