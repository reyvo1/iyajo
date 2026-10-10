<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('TAMASYA_API_ENTRY',true);
require __DIR__.'/../api/support/091_room_access_issue.php';
$n=0;
function keyModeCheck(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;echo 'PASS '.$name.PHP_EOL;}
foreach(['physical','smart','hybrid'] as $mode)keyModeCheck(tamasyaResolveRoomAccessMode(['access_mode'=>$mode])===$mode,'known access mode '.$mode);
keyModeCheck(tamasyaResolveRoomAccessMode([])==='physical','missing old register defaults to physical');
foreach(['pysical','inactive','disabled','unknown','pending_revoke'] as $mode){$denied=false;try{tamasyaResolveRoomAccessMode(['access_mode'=>$mode]);}catch(RuntimeException $e){$denied=true;}keyModeCheck($denied,'unknown nonempty mode must fail closed');}
$key=file_get_contents(__DIR__.'/../api/support/091_room_access_issue.php');
$web=file_get_contents(__DIR__.'/../api/routes/090_operations_communications.php');
keyModeCheck(str_contains($key,'$mode=tamasyaResolveRoomAccessMode($booking);'),'issuance uses strict resolver');
keyModeCheck(str_contains($web,'$mode=tamasyaResolveRoomAccessMode($booking);'),'return uses same strict resolver');
echo 'KEY MODE PASS '.$n.PHP_EOL;
