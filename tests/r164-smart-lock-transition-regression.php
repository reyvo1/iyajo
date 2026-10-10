<?php
/** R16.4 root-fix: no revoke while grant is unconfirmed; all key modes enforce custody. */
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
require_once __DIR__.'/../api/support/091_room_access_issue.php';
function shouldRejectState(array $b, string $id, string $message): void {
    try { tamasyaAssertRoomAccessReturnState($b, $id); }
    catch (RuntimeException $e) {
        if (strpos($e->getMessage(), $message) === false) throw new RuntimeException('Wrong refusal: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Unsafe room access transition was allowed: '.json_encode($b));
}
shouldRejectState(['keyControlStatus'=>'pending_smart_issue','current_booking_id'=>'B1','physical_key_status'=>'secured'],'B1','grant');
shouldRejectState(['keyControlStatus'=>'pending_smart_revoke'],'B1','diproses');
shouldRejectState(['keyControlStatus'=>'returned'],'B1','dikembalikan');
shouldRejectState(['keyControlStatus'=>'not_issued'],'B1','belum pernah');
shouldRejectState(['keyControlStatus'=>'issued','current_booking_id'=>'B2','physical_key_status'=>'secured'],'B1','booking lain');
shouldRejectState(['keyControlStatus'=>'issued','current_booking_id'=>'B2','physical_key_status'=>'issued'],'B1','booking lain');
tamasyaAssertRoomAccessReturnState(['keyControlStatus'=>'issued','current_booking_id'=>'B1','physical_key_status'=>'secured'],'B1');
tamasyaAssertRoomAccessReturnState(['keyControlStatus'=>'issued','current_booking_id'=>'B1','physical_key_status'=>'issued'],'B1');
tamasyaAssertRoomAccessReturnState(['keyControlStatus'=>'issued','current_booking_id'=>'','physical_key_status'=>'secured'],'B1');
echo "PASS: 9 smart-lock return states/custody invariants\n";
