<?php
/** Functional cents and ledger op-id uniqueness for OTA settlement. */
if (PHP_SAPI !== 'cli') exit(1);
define('TAMASYA_API_ENTRY',true);
function testOtaReject(callable $fn,string $type): bool {try{$fn();}catch(Throwable $e){if($e instanceof $type)return true;throw $e;}return false;}
$action='__isolated_unit_test_no_route';
require dirname(__DIR__).'/api/routes/030_ota_cleanup_audit.php';
if (!function_exists('tamasyaOtaLedgerOperationId')||!function_exists('tamasyaOtaAllocateCents'))throw new RuntimeException('Missing OTA canonical helper');
foreach ([1,50,96,97,100] as $length){
    $id=str_repeat('X',$length);
    $out=tamasyaOtaLedgerOperationId($id,'out');$in=tamasyaOtaLedgerOperationId($id,'in');
    if($out===$in||strlen($out)>100||strlen($in)>100)throw new RuntimeException('Ledger direction collision for length '.$length);
}
if(!testOtaReject(static fn()=>tamasyaOtaLedgerOperationId(str_repeat('a',101),'out'),InvalidArgumentException::class))throw new RuntimeException('Overlong operation id accepted');
if(!testOtaReject(static fn()=>tamasyaOtaLedgerOperationId('abcd','other'),InvalidArgumentException::class))throw new RuntimeException('Unknown ledger direction accepted');
$state=[
 ['transactionId'=>'t1','bookingId'=>'b1','availableAmount'=>0.01],
 ['transactionId'=>'t2','bookingId'=>'b2','availableAmount'=>0.03],
 ['transactionId'=>'t3','bookingId'=>'b3','availableAmount'=>0.07],
];
$items=tamasyaOtaAllocateCents($state,0.11);
if(count($items)!==3||(int)array_sum(array_map(static fn(array $r)=>(int)round($r['amount']*100),$items))!==11)throw new RuntimeException('Cent allocations do not reconcile');
$one=tamasyaOtaAllocateCents($state,0.01);
if(count($one)!==1||$one[0]['amount']!==0.01)throw new RuntimeException('One cent lost');
foreach ([0,-1,INF,NAN] as $invalid)if(!testOtaReject(static fn()=>tamasyaOtaAllocateCents($state,$invalid),InvalidArgumentException::class))throw new RuntimeException('Invalid amount accepted');
if(!testOtaReject(static fn()=>tamasyaOtaAllocateCents($state,0.12),RuntimeException::class))throw new RuntimeException('Insufficient balance accepted');
echo "PASS P1 OTA cent-perfect allocation and stable distinct ledger operations\n";
