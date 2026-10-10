<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('TAMASYA_API_ENTRY',true);
require __DIR__.'/../api/support/090_security_cluster_smartlock.php';
$n=0;
function checkId(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label.PHP_EOL;}
foreach([0,1,123456789012345,2147483648,'9223372036854775807'] as $valid){
    checkId(tamasyaRequireTelegramUpdateId(['update_id'=>$valid])===(string)$valid,'valid update id '.(string)$valid);
}
foreach([[],['update_id'=>null],['update_id'=>''],['update_id'=>'-1'],['update_id'=>'not-an-id'],['update_id'=>true],['update_id'=>1.5],['update_id'=>'1 2'],['update_id'=>'00001'],['update_id'=>'99999999999999999999'],['update_id'=>'9223372036854775808'],['update_id'=>str_repeat('1',21)]] as $invalid){
    $rejected=false;try{tamasyaRequireTelegramUpdateId($invalid);}catch(InvalidArgumentException $ex){$rejected=true;}
    checkId($rejected,'invalid update_id is rejected');
}
$src=file_get_contents(__DIR__.'/../api/routes/080_telegram_webhook.php');
$gate=strpos($src,'tamasyaRequireTelegramUpdateId($update)');
$ack=strpos($src,'$telegramCallbackEarlyAcked = false;');
$lock=strpos($src,'$telegramPrimaryLockHeld = tamasyaAcquirePrimaryMutationLock');
checkId($gate!==false && $gate<$ack && $gate<$lock,'real webhook validates update ID before callback acknowledgement and Primary lock');
checkId(str_contains($src,'$telegramUpdateId = $telegramUpdateIdentity;'),'validated identity always used for durable claim');
echo 'TELEGRAM UPDATE ID GATE PASS '.$n.PHP_EOL;
