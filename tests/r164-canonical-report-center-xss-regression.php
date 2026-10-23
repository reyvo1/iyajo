<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/assets/canonical-report-center.js';
$src = file_get_contents($path);
if ($src === false) { fwrite(STDERR, "FAIL cannot read canonical-report-center.js\n"); exit(1); }
$checks = 0;
function ok(bool $cond, string $label): void {
    global $checks;
    if (!$cond) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
    $checks++;
    echo "PASS {$label}\n";
}
ok(str_contains($src, 'function status(message)'), 'status(message) function exists');
ok(str_contains($src, 'el.textContent=message;'), 'plain status uses textContent, not innerHTML');
ok(str_contains($src, 'function statusHtml(html)'), 'statusHtml(html) function exists');
ok(!preg_match('/function status\(message\)\{[^}]*el\.innerHTML/s', $src), 'plain status does not write HTML');
ok(str_contains($src, 'statusHtml(`'), 'HTML callers use statusHtml explicitly');
echo "CANONICAL REPORT CENTER XSS REGRESSION: {$checks} PASS, 0 FAIL\n";