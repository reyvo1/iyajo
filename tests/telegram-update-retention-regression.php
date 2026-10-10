<?php
/** Durable update-id regression: no maintenance retry replay through age-based pruning. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$cron=file_get_contents($root.'/maintenance_cron.php');
$policy=file_get_contents($root.'/api/support/078_durable_retention_policy.php');
$claims=file_get_contents($root.'/api/support/090_security_cluster_smartlock.php');
$webhook=file_get_contents($root.'/api/routes/080_telegram_webhook.php');
$assert=static function(bool $cond,string $msg): void {
    if (!$cond) throw new RuntimeException('FAIL '.$msg);
    echo 'PASS '.$msg.PHP_EOL;
};
$assert($cron!==false&&$policy!==false&&$claims!==false&&$webhook!==false,'All canonical sources exist');
$writePattern='/\b(?:DELETE\s+(?:[a-z_]+\s+)?FROM|TRUNCATE\s+(?:TABLE\s*)?|UPDATE|REPLACE\s+INTO)\s+`?telegram_update_log`?\b/i';
$assert(!preg_match($writePattern,$cron),'Maintenance never mutates durable Telegram update-id table');
$assert(str_contains($cron,'tamasyaDurableRetentionPolicySummary()'),'Cron publishes canonical retention policy');
$assert(str_contains($cron,"\$results['telegram_update_log_cleaned']=false"),'Cron reports no Telegram dedupe cleanup');
$assert(str_contains($policy,"'telegram_update_log_cleaned'=>false") && str_contains($policy,"'telegram_update_log_retention_mode'=>'preserve_update_ids'"),'Policy explicitly preserves update IDs');
$claimStart=strpos($claims,'function claimTelegramUpdate(');
$claimEnd=strpos($claims,'function registerTelegramUpdateCompletion(', $claimStart);
$assert($claimStart!==false && $claimEnd!==false,'Canonical claim/update-completion contract is present');
$claim=substr($claims,$claimStart,$claimEnd-$claimStart);
$assert(str_contains($claim,'FOR UPDATE')&&str_contains($claim,"'completed'")&&str_contains($claim,"'busy'")&&str_contains($claim,"'processing'"),'Update claim protects completed/in-flight duplicates via lock');
$assert(str_contains($webhook,'claimTelegramUpdate($pdo, $telegramUpdateId)')&&str_contains($webhook,'registerTelegramUpdateCompletion($pdo, $telegramUpdateId, true)'),'Webhook participates in durable idempotent claim/completion');
$assert(str_contains($webhook,'duplicate_update_already_completed')&&str_contains($webhook,'http_response_code(503)'),'Webhook dedupe and busy retry are fail-closed');
foreach ([
    "DELETE FROM telegram_update_log WHERE created_at < NOW()",
    "UPDATE telegram_update_log SET status='expired'",
    "TRUNCATE TABLE telegram_update_log",
] as $mutant) $assert((bool)preg_match($writePattern,$cron."\n".$mutant),'Mutation detector rejects a destructive SQL variant');
$assert(str_contains($cron,'telegram_callback_tokens')&&str_contains($cron,'telegram_binding_codes'),'Ephemeral Telegram token cleanup remains intact');
echo "TELEGRAM DURABLE UPDATE REGRESSION: PASS\n";
