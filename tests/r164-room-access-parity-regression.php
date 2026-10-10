<?php
/** Static wiring checks supplement, not replace, behavioral state tests. */
if (PHP_SAPI !== 'cli') exit(2);
$telegram=file_get_contents(__DIR__.'/../api/routes/080_telegram_webhook.php');
$operations=file_get_contents(__DIR__.'/../api/routes/090_operations_communications.php');
$canonical=file_get_contents(__DIR__.'/../api/support/091_room_access_issue.php');
function parityAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException('FAIL: '.$message);
    echo 'PASS '.$message.PHP_EOL;
}
parityAssert(str_contains($canonical,'tamasyaAssertRoomAccessIssueState($booking,$bookingId);'),'Canonical issue invokes state/custody guard after booking lock');
parityAssert(str_contains($telegram,'tamasyaAssertRoomAccessIssueState($keyCustody,$keyBookingId)'),'Telegram status uses canonical availability');
parityAssert(str_contains($telegram,'tamasyaAssertRoomAccessIssueState($keyReview,$bookingId)'),'Telegram second-step preview rechecks canonical custody');
parityAssert(str_contains($telegram,'tamasyaIssueRoomAccess($pdo,$loggedInStaff,$bookingId'),'Telegram confirmation uses shared transactional service');
parityAssert(str_contains($operations,"'key-issue'") && str_contains($operations,'tamasyaIssueRoomAccess($pdo,$loggedInStaff,$bookingId'),'Web shares same transactional service');
parityAssert(str_contains($operations,"$".'keyOutstanding=$before') && str_contains($operations,"'physical_key_status'"),'Settings change checks current custody state');
parityAssert(str_contains($canonical,'$checkoutTimestamp===false || $checkoutTimestamp<=time()'),'Smart-lock PIN has validated unexpired checkout timestamp');
parityAssert(str_contains($canonical,'tamasyaAssertRoomAccessReturnState('),'Key return guard remains present');
echo 'PASS: Web/Telegram key parity wiring regression'.PHP_EOL;
