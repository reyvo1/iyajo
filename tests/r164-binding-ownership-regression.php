<?php
/** Regression: binding code cannot silently move provider <-> staff ownership. */
if(PHP_SAPI!=='cli')exit(1);
define('TAMASYA_API_ENTRY',true);
require_once dirname(__DIR__).'/api/modules/comms/045_communication_core.php';
function expectBinding(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function expectConflict(callable $fn,string $why):void{try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException($why);}
$user='provider_A';$staff='staff_A';$good=['id'=>'b1','provider_user_id'=>$user,'staff_id'=>$staff];
expectBinding(tamasyaCommunicationAssertBindingOwnership(null,null,$user,$staff)===null,'No prior mapping');
expectBinding(tamasyaCommunicationAssertBindingOwnership($good,null,$user,$staff)===$good,'Same provider accepted');
expectBinding(tamasyaCommunicationAssertBindingOwnership(null,$good,$user,$staff)===$good,'Same staff accepted');
expectBinding(tamasyaCommunicationAssertBindingOwnership($good,$good,$user,$staff)===$good,'Same mapping accepted');
expectConflict(fn()=>tamasyaCommunicationAssertBindingOwnership(['id'=>'b1','provider_user_id'=>$user,'staff_id'=>'another'],null,$user,$staff),'Provider takeover accepted');
expectConflict(fn()=>tamasyaCommunicationAssertBindingOwnership(null,['id'=>'b1','provider_user_id'=>'other','staff_id'=>$staff],$user,$staff),'Staff account takeover accepted');
expectConflict(fn()=>tamasyaCommunicationAssertBindingOwnership($good,['id'=>'b2','provider_user_id'=>$user,'staff_id'=>$staff],$user,$staff),'Conflicting rows accepted');
expectBinding((tamasyaCommunicationAssertBindingOwnership(['id'=>'p-old','provider_user_id'=>$user,'staff_id'=>'another','status'=>'revoked'],null,$user,$staff)['id']??'')==='p-old','Explicitly revoked provider mapping can be rebound using new code');
expectBinding((tamasyaCommunicationAssertBindingOwnership(null,['id'=>'s-old','provider_user_id'=>'former','staff_id'=>$staff,'status'=>'revoked'],$user,$staff)['id']??'')==='s-old','Explicitly revoked staff mapping can be rebound using new code');
expectConflict(fn()=>tamasyaCommunicationAssertBindingOwnership(['id'=>'p-old','provider_user_id'=>$user,'staff_id'=>'another','status'=>'revoked'],['id'=>'s-old','provider_user_id'=>'former','staff_id'=>$staff,'status'=>'revoked'],$user,$staff),'Two revoked rows may not be silently merged');
$core=file_get_contents(dirname(__DIR__).'/api/modules/comms/045_communication_core.php');
expectBinding(str_contains($core,'provider_user_id=? LIMIT 1 FOR UPDATE')&&str_contains($core,'staff_id=? LIMIT 1 FOR UPDATE'),'Missing independent row locks');
expectBinding(!str_contains($core,'ON DUPLICATE KEY UPDATE provider_user_id=VALUES(provider_user_id)'),'Unsafe binding upsert was restored');
expectBinding(str_contains($core,"if($".'consumeCode->rowCount()!==1)'), 'One-use binding code must be atomically consumed');
echo "PASS R16.4 bind ownership conflict prevention\n";
