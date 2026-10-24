<?php
/** Regression: Telegram expense input is button/state-driven and supports photo proof. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$webhook = file_get_contents(__DIR__.'/../api/routes/080_telegram_webhook.php');
function assertAudit(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$name);
    echo "PASS ".$name."\n";
}
assertAudit(str_contains($webhook, "waiting_for_expense_input"), 'expense input state exists');
assertAudit(str_contains($webhook, "telegram_file_id:"), 'telegram photo proof reference supported');
assertAudit(str_contains($webhook, "if (\$subId === '')"), 'quick expense supports category without subcategory');
assertAudit(str_contains($webhook, "'proofUrl' => \$proofUrl") || str_contains($webhook, "\"proofUrl\" => \$proofUrl") || str_contains($webhook, "'proofUrl'=>\$proofUrl"), 'proofUrl passed to transaction');
assertAudit(str_contains($webhook, "Nominal & Foto (Kustom)"), 'custom expense button is button-driven');
echo "TOTAL: 5 PASS; 0 FAIL\n";