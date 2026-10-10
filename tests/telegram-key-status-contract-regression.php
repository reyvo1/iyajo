<?php
/** Verify read-only status remains intact and issuance uses shared canonical gate. */
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
$source=file_get_contents($root.'/api/routes/080_telegram_webhook.php');
$canonical=file_get_contents($root.'/api/routes/090_operations_communications.php');
$shared=file_get_contents($root.'/api/support/091_room_access_issue.php');
$checks=[
    'Key status button is offered only for active booking and authorized room access actor'=>str_contains($source,"if(\$activeBookingId!=='' && hasCapability(\$loggedInStaff,'manage_room_access'"),
    'Callback is registered under the existing reservation role gate'=>str_contains($source,"'room_select:','room_set:','key_status:','key_issue_review:','key_issue_confirm:','r_sell:'"),
    'Callback rechecks room-access capability on every tap'=>str_contains($source,"if(!hasCapability(\$loggedInStaff,'manage_room_access'"),
    'Callback refreshes booking state and rejects stale/non-active booking'=>str_contains($source,"WHERE id=? AND status='active' LIMIT 1") && str_contains($source,"if(!\$keyBooking)"),
    'Key-control status comes from canonical booking row'=>str_contains($source,"SELECT id,roomNumber,keyControlStatus,accessMode,status FROM bookings"),
    'Physical custody conflict consults room access table'=>str_contains($source,'SELECT physical_key_status,current_booking_id FROM room_access_control'),
    'Smart-lock queued status is not presented as issued'=>str_contains($source,"'pending_smart_issue'=>'⏳ Menunggu konfirmasi smart-lock'"),
    'Status callback links back to room diagnosis without mutating booking'=>str_contains($source,"'callback_data'=>'room_select:'.\$keyRoom"),
    'Explicit instruction preserves canonical Web issuance controls'=>str_contains($source,'validasi pembayaran, shift, dan smart-lock tidak dilewati'),
    'Web still enforces booking/payment/open-shift access policies'=>str_contains($canonical,"} elseif (\$command === 'key-issue')") && str_contains($canonical,'tamasyaIssueRoomAccess') && str_contains($shared,'tamasyaRequireOpenShiftForRoomAccessIssue') && str_contains($shared,'require_payment_before_key_issue') && str_contains($shared,'LIMIT 1 FOR UPDATE'),
];
$passed=0;
foreach($checks as $name=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;$passed++;}
// Status handler contains no INSERT/UPDATE/DELETE/authorization bypass.
$start=strpos($source,"} elseif (str_starts_with(\$callbackData, 'key_status:')) {");
$end=strpos($source,"} elseif (str_starts_with(\$callbackData,'key_issue_review:'))", $start);
if($start===false||$end===false)throw new RuntimeException('FAIL key-status handler boundaries');
$handler=substr($source,$start,$end-$start);
if(preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE)\b/i',$handler))throw new RuntimeException('FAIL key status handler has a mutation verb');
if(str_contains($handler,'processSmartLockBridgeJob') || str_contains($handler,'queueSmartLockBridgeJob'))throw new RuntimeException('FAIL key status handler issues a smart-lock credential');
echo 'PASS Key status callback read-only with no smart-lock mutation'.PHP_EOL;
echo 'Telegram key status: '.($passed+1).' PASS, 0 FAIL'.PHP_EOL;
