<?php
/** No automatic loss of operation-ID, replay body, or Hybrid history. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/support/078_durable_retention_policy.php';
function dhAssert(bool $ok,string $why):void { if (!$ok) throw new RuntimeException('FAIL '.$why); echo 'PASS '.$why.PHP_EOL; }
$cron=file_get_contents(dirname(__DIR__).'/maintenance_cron.php');
dhAssert($cron!==false,'Maintenance file readable');
$writePattern='/\b(?:DELETE\s+FROM|UPDATE|TRUNCATE\s+(?:TABLE\s+)?|REPLACE\s+INTO)\s+`?(?:sync_operations|request_operation_receipts)`?\b/i';
// Never permit direct destructive maintenance against either canonical operation table.
dhAssert(!preg_match($writePattern,$cron),'No direct age-based DELETE/UPDATE against durable receipts or sync operations');
dhAssert(str_contains($cron, 'tamasyaDurableRetentionPolicySummary()'), 'Cron must invoke protected durable retention policy');
dhAssert(str_contains($cron, "require_once __DIR__ . DIRECTORY_SEPARATOR . 'api/support/078_durable_retention_policy.php'"), 'Durable policy is loaded by cron');
dhAssert(!str_contains($cron,"$"."results['sync_operations_cleaned'] = true"), 'No misleading completed cleanup report');
$oldBody=getenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS');$oldMeta=getenv('REQUEST_RECEIPT_RETENTION_DAYS');
foreach ([[null,null,30,120],['1','1',7,7],['500','500',120,365],['45','5',45,45]] as [$b,$m,$wantBody,$wantMeta]) {
    $b===null?putenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS'):putenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS='.$b);
    $m===null?putenv('REQUEST_RECEIPT_RETENTION_DAYS'):putenv('REQUEST_RECEIPT_RETENTION_DAYS='.$m);
    $p=tamasyaDurableRetentionPolicySummary();
    dhAssert($p['request_receipt_body_retention_days']===$wantBody && $p['request_receipt_metadata_retention_days']===$wantMeta,'Legacy day limits remain clamped as diagnostics');
    dhAssert($p['request_receipt_body_pruned']===false && $p['request_receipt_metadata_pruned']===false && $p['sync_operations_cleaned']===false && $p['request_receipt_retention_config_only']===true,'All replay/metadata retention stays non-destructive irrespective of env');
}
$oldBody===false?putenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS'):putenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS='.$oldBody);
$oldMeta===false?putenv('REQUEST_RECEIPT_RETENTION_DAYS'):putenv('REQUEST_RECEIPT_RETENTION_DAYS='.$oldMeta);
// Mutation checks: prove this regression would catch re-introducing the original bug.
foreach (["DELETE FROM sync_operations WHERE created_at < NOW()", "UPDATE request_operation_receipts SET response_body=NULL", "DELETE FROM request_operation_receipts WHERE status='processing'"] as $badSql) {
    dhAssert((bool)preg_match($writePattern,$cron."\n".$badSql),'Unsafe SQL injection into cron would fail retention guard');
}
$policy=tamasyaDurableRetentionPolicySummary();
dhAssert($policy['durable_history_cleanup_mode']==='preserve_metadata_and_replay' && $policy['durable_history_manual_review_required']===true, 'Explicit and honest retention state');
echo 'DURABLE OPERATION HISTORY REGRESSION: PASS'.PHP_EOL;
