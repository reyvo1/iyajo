<?php
/** Regression: provider response is not counted as delivery until DB audit commits. */
if(PHP_SAPI!=='cli')exit(1);
$src=file_get_contents(dirname(__DIR__).'/api/modules/comms/045_communication_core.php');
function assertCounter(bool $v,string $desc):void{if(!$v)throw new RuntimeException($desc);}
$start=strpos($src,'function tamasyaCommunicationProcessOutbox(');
$end=strpos($src,'function tamasyaCommunicationCreateBindingCode(', $start);
assertCounter($start!==false&&$end!==false,'Outbox worker unavailable');
$block=substr($src,$start,$end-$start);
assertCounter(str_contains($block,'if(!empty($result[\'success\'])){$delivered=true;break;}'),'Provider success should not increment committed count');
assertCounter(str_contains($block,'tamasyaFinancialCommit($pdo);' . "\n" . '            if($delivered)$sent++;else $failed++;'),'Delivery counter must follow audit commit');
assertCounter(!str_contains($block,'if(!$delivered)$failed++;'),'Failure must not be counted before DB audit');
assertCounter(str_contains($block,'$attentionRequired++;'),'Indeterminate finalization must be tracked');
echo "PASS R16.4 outbox finalization counters\n";
