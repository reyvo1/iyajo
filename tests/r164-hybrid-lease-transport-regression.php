<?php
/** Fail-closed Hybrid: stale lease or missing HTTP status cannot confirm delivery. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/node_sync_support.php';
function expectHybridProof(bool $okay,string $why):void{if(!$okay)throw new RuntimeException($why);}
foreach([0,-1,99,600,999] as $invalid){
  $r=tamasyaNodeClassifyResponse($invalid,'{"success":true}');
  expectHybridProof($r['completed']===false,'Invalid transport status '.$invalid.' acknowledged business replay');
  expectHybridProof($r['httpStatus']>=500,'Invalid transport status must be retryable server error');
}
$success=tamasyaNodeClassifyResponse(200,'{"success":true}');
expectHybridProof($success['completed']===true,'HTTP 200 with valid JSON success must still work');
$failure=tamasyaNodeClassifyResponse(200,'{"success":false,"error":"cannot commit"}');
expectHybridProof($failure['completed']===false,'Application error in HTTP 200 may not complete replay');
$empty=tamasyaNodeClassifyResponse(200,'');
expectHybridProof($empty['completed']===false,'HTTP 200 without JSON proof may not complete replay');
function tamasyaClusterEnabled():bool{return true;}
function tamasyaClusterLeaseIsValid(array $state):bool{return true;}
putenv('TAMASYA_NODE_ID=primary-test');
$GLOBALS['tamasya_cluster_state']=['current_primary_node_id'=>'primary-test','transfer_state'=>'active'];
unset($GLOBALS['tamasya_runtime_pdo']);
expectHybridProof(tamasyaExternalSideEffectsAllowed()===false,'Cached lease without fresh PDO may authorize external side effect');
echo "PASS R16.4 Hybrid needs HTTP response and fresh primary lease\n";
