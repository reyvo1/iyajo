<?php
declare(strict_types=1);

/**
 * Regression for money precision at the single posting chokepoint.
 *
 * Every canonical transaction passes through tamasyaPostFinancialTransaction. If
 * the amount is not rounded to two decimals there, the drift propagates into the
 * folio, the shift totals and the journal, and none of those surfaces can undo it.
 * A split payment is the sharper case: splitCashAmount + splitTransferAmount must
 * reconcile to the rounded total exactly, or the guest is charged a cent that does
 * not exist.
 *
 * The report-period side of this sweep found nothing to guard: no DATETIME column is
 * filtered with BETWEEN, and the application fails closed when APP_TIMEZONE is unset,
 * so date() cannot silently drift onto the server's own clock. That is recorded here
 * as an assertion rather than a comment so a future change has to notice it.
 */

$root = dirname(__DIR__);
$failures = [];

/**
 * @return array{0: string, 1: array{required: int, rounded: int}} per file
 */
function tamasyaMoneyChokepointCoverage(string $source): array
{
    if (strpos($source, 'function tamasyaPostFinancialTransaction') === false) {
        return ['', ['required' => 0, 'rounded' => 0]];
    }
    $count = substr_count($source, 'tamasyaPostFinancialTransaction');
    $rounded = preg_match_all(
        '/\$amount\s*=\s*(?:max\([^;]{0,80}?,\s*)?round\(\(float\)\([^;]{0,120}?\),\s*2\)/',
        $source,
        $ignored
    );
    // The split legs must reconcile onto the rounded amount, never the reverse.
    $splitRounded = preg_match(
        "/\\\$tx\['amount'\]\s*=\s*round\(\\\$tx\['splitCashAmount'\]\s*\+\s*\\\$tx\['splitTransferAmount'\]\s*,\s*2\)/",
        $source
    ) ? 1 : 0;
    return [$count, ['required' => $count, 'rounded' => max($rounded, $splitRounded)]];
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS)
);
$definitions = 0;
$roundedSites = 0;

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        continue;
    }
    [$calls, $stats] = tamasyaMoneyChokepointCoverage($source);
    $definitions++;
    $roundedSites += $stats['rounded'];
    // "At least one rounding" is too weak: the canonical amount and the split legs are
    // separate promises, and losing either one is a real drift.
    if ($stats['required'] > 0 && $stats['rounded'] < 2) {
        $failures[] = str_replace($root . '/', '', $file->getPathname())
            . ' no longer rounds both the canonical amount and the split legs to two decimals '
            . '(found ' . $stats['rounded'] . ' of 2 expected rounding sites).';
    }
}

// The property clock must never fall back to the server clock, because "today" is an
// operational identity for a hotel and a wrong one shifts a whole day's revenue.
$entry = file_get_contents($root . '/api.php');
if ($entry === false || strpos($entry, 'date_default_timezone_set($appTimezone)') === false) {
    $failures[] = 'api.php no longer pins the PHP clock to APP_TIMEZONE, so date() can drift '
        . 'onto the server timezone and shift a day boundary.';
}
if ($entry === false || strpos($entry, 'APP_TIMEZONE_REQUIRED') === false) {
    $failures[] = 'api.php no longer fails closed when APP_TIMEZONE is missing, so a property '
        . 'can serve with the wrong clock.';
}

// A DATETIME column filtered with BETWEEN silently drops the final day, because the end
// bound is interpreted as midnight. None exists today; this keeps it that way.
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS)
) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = (string)file_get_contents($file->getPathname());
    if (preg_match('/\b[a-zA-Z_]*(?:At|_at|AtTime|At_At)\b\s+BETWEEN\s*\?/i', $source, $m)) {
        $failures[] = str_replace($root . '/', '', $file->getPathname())
            . ' filters a timestamp column with BETWEEN, which drops everything after '
            . 'midnight on the final day.';
    }
}

if ($failures) {
    fwrite(STDERR, "Money precision assertions FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo 'Money precision assertions passed: ' . $roundedSites
    . ' rounding sites across ' . $definitions . " files\n";
