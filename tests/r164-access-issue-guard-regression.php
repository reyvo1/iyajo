<?php
/** P1 custody state machine: issue only from secured, unbound room access.
 * Pure PHP test; no database or network access.
 */
if (PHP_SAPI !== 'cli') exit(2);
define('TAMASYA_API_ENTRY',true);
require_once __DIR__.'/../api/support/091_room_access_issue.php';
function keyIssueGuardExpect(bool $expected,array $booking,string $label): void {
    $allowed=true;
    try { tamasyaAssertRoomAccessIssueState($booking,'booking-1'); }
    catch (RuntimeException $e) {$allowed=false;}
    if($allowed!==$expected) throw new RuntimeException('FAIL: '.$label.'; allowed='.($allowed?'yes':'no'));
    echo 'PASS '.$label.PHP_EOL;
}
$secured=['keyControlStatus'=>'not_issued','physical_key_status'=>'secured','current_booking_id'=>null];
keyIssueGuardExpect(true,$secured,'Normal booking may issue secured physical key');
keyIssueGuardExpect(true,array_merge($secured,['keyControlStatus'=>'returned']),'Returned and unbound key may be issued again');
foreach(['issued','pending_smart_issue','pending_smart_revoke','missing','override','unknown',''] as $state)
    keyIssueGuardExpect(false,array_merge($secured,['keyControlStatus'=>$state]),'Reject unresolved or unexpected booking state '.$state);
foreach(['issued','missing','override','lost','pending','unknown','returned',''] as $state)
    keyIssueGuardExpect(false,array_merge($secured,['physical_key_status'=>$state]),'Reject unsecured physical-key custody '.$state);
keyIssueGuardExpect(false,array_merge($secured,['current_booking_id'=>'booking-2']),'Reject custody bound to another booking');
keyIssueGuardExpect(false,array_merge($secured,['current_booking_id'=>'booking-1']),'Reject stale custody still bound to same booking');
keyIssueGuardExpect(false,['keyControlStatus'=>'returned','physical_key_status'=>'missing','current_booking_id'=>null],'Returned booking cannot bypass missing key report');
echo 'PASS: canonical issue eligibility is fail-closed for unresolved states'.PHP_EOL;
