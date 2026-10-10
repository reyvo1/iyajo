<?php
/** R16.4 key-return lifecycle and bridge pending contract without live provider. */
if(PHP_SAPI!=='cli')exit(1);
define('TAMASYA_API_ENTRY',true);
require __DIR__.'/../api/support/091_room_access_issue.php';
function keyReturnAssert(bool $c,string $m):void {if(!$c)throw new RuntimeException('FAIL '.$m);echo 'PASS '.$m.PHP_EOL;}
$booking=['keyControlStatus'=>'issued','physical_key_status'=>'issued','current_booking_id'=>'book-1'];
tamasyaAssertRoomAccessReturnState($booking,'book-1');
keyReturnAssert(true,'Valid issued booking may return its own key');
foreach(['not_issued','returned','pending_smart_revoke'] as $bad){
 try {tamasyaAssertRoomAccessReturnState(array_merge($booking,['keyControlStatus'=>$bad]),'book-1');throw new RuntimeException('State accepted '.$bad);}catch(RuntimeException $e){keyReturnAssert(!str_starts_with($e->getMessage(),'State accepted'),'Reject invalid key return '.$bad);}
}
try {tamasyaAssertRoomAccessReturnState(array_merge($booking,['current_booking_id'=>'other']), 'book-1');throw new RuntimeException('Conflict accepted');}
catch(RuntimeException $e){keyReturnAssert(str_contains($e->getMessage(),'booking lain'),'Cannot return a key owned by another active booking');}
class ProviderTestPDO extends PDO {public function __construct(){}}
$GLOBALS['providerThrows']=true;
function processSmartLockBridgeJobById($pdo,$id,$actor,$source){if($GLOBALS['providerThrows'])throw new RuntimeException('provider offline');return ['status'=>'completed','jobId'=>$id];}
function clientExceptionMessage($message,$error){return $message;}
$pdo=new ProviderTestPDO();$actor=['id'=>'staff-1'];
$r=tamasyaSafelyProcessSmartLockJob($pdo,'job-1',$actor);
keyReturnAssert($r['status']==='pending'&&$r['jobId']==='job-1','External provider failure remains pending, not falsely completed');
$GLOBALS['providerThrows']=false;
$r=tamasyaSafelyProcessSmartLockJob($pdo,'job-1',$actor);
keyReturnAssert($r['status']==='completed','Only provider completion returns confirmed result');
$web=file_get_contents(__DIR__.'/../api/routes/090_operations_communications.php');
keyReturnAssert(str_contains($web,'tamasyaAssertRoomAccessReturnState($booking,$bookingId)')&&str_contains($web,'tamasyaSafelyProcessSmartLockJob($pdo,$jobId,$loggedInStaff'), 'Web key return wired to shared guards and safe pending semantics');
echo "ROOM KEY RETURN REGRESSION: PASS\n";
