<?php
/** Regression: provider-neutral webhook only acknowledges durable atomic processing. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$route=file_get_contents($root.'/api/routes/076_communication_webhook.php');
$adapter=file_get_contents($root.'/api/channels/StandardWebhookAdapter.php');
function requireGate(bool $c,string $msg):void{if(!$c)throw new RuntimeException($msg);}
requireGate(str_contains($adapter,"'conversationType'=>trim((string)($"."payload['conversationType']??'unknown'))"),'Missing conversation type cannot imply private');
requireGate(str_contains($route,"(string)($"."normalized['conversationType']??'unknown')"),'Missing conversation type cannot be used for account binding');
requireGate(str_contains($route,"!in_array($"."conversationType,['private','direct','dm'],true)"),'Group binding guard missing');
$a=strpos($route,'$pdo->beginTransaction();',$mark=strpos($route,'$parsed=tamasyaCommunicationParseCommand($normalized);'));
$b=strpos($route,'$result=tamasyaCommunicationExecuteCommand(', $mark);
$c=strpos($route,'tamasyaCommunicationCompleteEvent($pdo,$channelId,$eventId,true,null,true);',$mark);
$d=strpos($route,'tamasyaFinancialCommit($pdo);',$mark);
requireGate($a!==false&&$b!==false&&$c!==false&&$d!==false&&$a<$b&&$b<$c&&$c<$d,'Business mutation and webhook acknowledgement must be atomic');
requireGate(str_contains($route,'if($pdo->inTransaction())$pdo->rollBack();'),'Transaction failure must rollback');
echo "PASS R16.4 atomic webhook and private binding\n";
