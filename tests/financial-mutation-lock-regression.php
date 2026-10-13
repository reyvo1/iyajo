<?php
declare(strict_types=1);

/**
 * Regression for concurrent-mutation safety on financial rows.
 *
 * tamasyaMutateFinancialTransactions can take a FOR UPDATE lock on the rows it
 * changes. Call sites that delete a row's journal after mutating it must ask for
 * that lock: reading first without it leaves a window where a concurrent posting
 * reads a journal that is then deleted underneath it, or has its amount
 * overwritten by a reduction.
 *
 * The OTA disbursement cleanup did exactly this. It reduced a pair of transactions
 * and deleted their journal entries while holding no row lock on either.
 *
 * This check is static so the class cannot come back quietly on a new call site.
 */

$root = dirname(__DIR__);
$failures = [];
$scanned = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if ($source === false || strpos($source, 'tamasyaMutateFinancialTransaction') === false) {
        continue;
    }
    $relative = str_replace($root . '/', '', $path);

    $offset = 0;
    while (($pos = strpos($source, 'tamasyaMutateFinancialTransaction', $offset)) !== false) {
        // Skip the declarations themselves.
        $line = substr_count(substr($source, 0, $pos), "\n") + 1;
        $tail = substr($source, $pos, 90);
        if (preg_match('/^tamasyaMutateFinancialTransactions?\s*\(\s*(PDO|string|array)/', $tail)) {
            $offset = $pos + 1;
            continue;
        }

        // Take the call up to its closing semicolon.
        $seg = substr($source, $pos, 900);
        $end = strpos($seg, ');');
        $args = $end > 0 ? substr($seg, 0, $end) : $seg;
        $offset = $pos + 1;
        $scanned++;

        if (strpos($args, 'lockRows') !== false) {
            continue;
        }
        // The journal removal can sit before or after the mutation inside the same
        // block, so both sides of the call are searched.
        $window = substr($source, max(0, $pos - 2600), 5200);
        $deletesJournal = strpos($window, 'DELETE FROM journal_entries') !== false
            || strpos($window, 'DELETE FROM journal_lines') !== false;
        if ($deletesJournal) {
            $failures[] = sprintf(
                '%s:%d mutates financial rows without lockRows and then deletes their journal. '
                . 'Hold the row lock so a concurrent posting cannot read a journal this call removes.',
                $relative,
                $line
            );
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Financial mutation lock assertions FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo 'Financial mutation lock assertions passed: ' . $scanned . " call sites scanned\n";
