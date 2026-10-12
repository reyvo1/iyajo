<?php
declare(strict_types=1);

/**
 * Regression for the defect class behind the booking-audit-correction outage.
 *
 * tamasyaAssertLiveTaxSnapshotIntegrity() rejects any live income transaction
 * whose kind is tied to a booking or POS sale unless it carries taxSource,
 * taxRuleId and taxSnapshotStatus. A posting path that omits those fields writes
 * a poison row: the whole-ledger gate then fails on every later posting in the
 * hotel, so an unrelated correction or deposit can never be saved.
 *
 * Two paths did exactly that, and the UAT suite stayed green because neither
 * route had a test. This check is deliberately static: it inspects the source so
 * the class cannot come back quietly on a new or edited posting path.
 */

$root = dirname(__DIR__);
$failures = [];
$postingCalls = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if ($source === false || strpos($source, 'tamasyaPostFinancialTransaction') === false) {
        continue;
    }

    // Balance-aware scan: locate each call and take the payload array up to the
    // matching closing bracket, so nested arrays are not truncated.
    $offset = 0;
    while (($pos = strpos($source, 'tamasyaPostFinancialTransaction', $offset)) !== false) {
        $bracket = strpos($source, '[', $pos);
        if ($bracket === false) {
            break;
        }
        $depth = 0;
        $end = null;
        for ($i = $bracket, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '[') {
                $depth++;
            } elseif ($source[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end === null) {
            $offset = $pos + 1;
            continue;
        }
        $payload = substr($source, $bracket, $end - $bracket + 1);
        $line = substr_count(substr($source, 0, $pos), "\n") + 1;
        $offset = $end;
        $postingCalls++;

        if (strpos($payload, "'type'=>'income'") === false) {
            continue;
        }
        // Only the kinds the integrity gate actually polices.
        $guarded = strpos($payload, "'room_rental'") !== false
            || strpos($payload, "'pos_sale'") !== false
            || strpos($payload, "'booking_charge'") !== false
            || strpos($payload, "'settlement'") !== false
            || strpos($payload, "'down_payment'") !== false
            || strpos($payload, "'booking_payment'") !== false;
        if (!$guarded) {
            continue;
        }
        foreach (['taxRuleId', 'taxSource', 'taxSnapshotStatus'] as $required) {
            if (strpos($payload, $required) === false) {
                $failures[] = sprintf(
                    '%s:%d posts guarded live booking income without %s. It will be written as a '
                    . 'poison row and block every later live posting.',
                    str_replace($root . '/', '', $path),
                    $line,
                    $required
                );
            }
        }
    }
}

// The label/row mix-up that produced the original 500: casting the resolved
// master row to string raises "Array to string conversion".
$files->rewind();
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if ($source === false) {
        continue;
    }
    $relative = str_replace($root . '/', '', $path);
    foreach (preg_split('/\R/', $source) as $index => $line) {
        if (preg_match('/\(string\)\s*\$[A-Za-z_]*[Cc]atalog\[\'(category|subcategory)\'\]/', $line, $m)) {
            $failures[] = sprintf(
                '%s:%d casts the resolved master row %s to string. Use categoryName/subcategoryName.',
                $relative,
                $index + 1,
                $m[1]
            );
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Live tax snapshot contract assertions FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo 'Live tax snapshot contract assertions passed: ' . $postingCalls . " posting paths scanned\n";