<?php
/** Neutral provider callbacks must remain deduped after arbitrary elapsed time. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$worker=file_get_contents($root.'/communication_worker.php');
$core=file_get_contents($root.'/api/modules/comms/045_communication_core.php');
function checkWebhookHistory(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
foreach([$worker,$core,file_get_contents($root.'/maintenance_cron.php')] as $source){
    checkWebhookHistory(!preg_match('/(?:DELETE\s+FROM|TRUNCATE\s+(?:TABLE\s+)?)\s+[`]?communication_webhook_events\b/i',$source),'Destructive neutral webhook history cleanup must not exist');
}
checkWebhookHistory(str_contains($core,"if((string)$".'row[\'status\']===\'completed\')'),'Existing completed events must be detected');
checkWebhookHistory(str_contains($core,'Event ID provider dipakai ulang dengan payload berbeda'),'Changed duplicate payload must be rejected');
checkWebhookHistory(str_contains($core,'FOR UPDATE'),'Replay claims need row locking');
checkWebhookHistory(str_contains($worker,'communication_webhook_events is a durable idempotency ledger'),'Durability contract comment missing');
echo "PASS R16.4 neutral communication webhook receipts remain durable\n";
