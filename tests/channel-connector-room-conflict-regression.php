<?php
declare(strict_types=1);

/**
 * Regression for the R14.3 channel-connector room-conflict fix.
 *
 * The growth-channel-webhook room-availability query must use a proper
 * half-open overlap predicate (b.checkIn < checkOut AND b.checkOut > checkIn),
 * not the anti-predicate that always evaluated to false and therefore never
 * excluded rooms already held by an active/reserved booking.
 *
 * Statically verified here (no DB needed): the route file must (a) validate
 * checkIn/checkOut dates BEFORE querying room availability, and (b) pass the
 * validated $checkOut/$checkIn values as the two overlap parameters, in that
 * order.
 */
$root = dirname(__DIR__);
$failures = [];
$checks = 0;
function check(string $label, bool $ok, array $observed = []): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $label . ' observed=' . json_encode($observed, JSON_UNESCAPED_SLASHES);
}

$route = (string)@file_get_contents($root . '/api/routes/110_growth_suite.php');
check('growth suite route is readable', $route !== '', ['len' => strlen($route)]);

// The anti-predicate constants must no longer be present in the room query.
check('anti-predicate 9999-12-31 default removed from channel webhook room query',
    !preg_match("/9999-12-31[^\"]*checkIn.*?9999-12-31/s", $route) && !str_contains($route, "(string)(\$payload['checkIn']??'9999-12-31')"),
    ['still_present' => str_contains($route, "(string)(\$payload['checkIn']??'9999-12-31')")]);

// Dates must be validated before the room query runs.
$checkOutValidate = strpos($route, "if(\$checkIn===''||\$checkOut===''||\$guestName==='')throw new InvalidArgumentException('checkIn, checkOut dan guestName wajib.');");
$roomQueryStart = strpos($route, "NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active') AND b.checkIn<? AND b.checkOut>?)");
check('checkIn/checkOut validation precedes the room-availability query',
    $checkOutValidate !== false && $roomQueryStart !== false && $checkOutValidate < $roomQueryStart,
    ['validateAt' => $checkOutValidate, 'queryAt' => $roomQueryStart]);

// The overlap parameters must be the validated variables, in the correct order
// (checkOut first as the upper bound of b.checkIn, checkIn second as the lower
// bound of b.checkOut).
check('room query uses validated $checkOut then $checkIn as the two overlap parameters',
    (bool)preg_match("/NOT EXISTS \(SELECT 1 FROM bookings b WHERE b\.roomNumber=r\.number AND b\.status IN \('reserved','active'\) AND b\.checkIn<\? AND b\.checkOut>\?\)[\s\S]{0,140}\[\\\$internalRoomType,\\\$checkOut,\\\$checkIn\]/", $route),
    ['block' => substr($route, (int)$roomQueryStart, 300)]);

if ($failures) {
    fwrite(STDERR, "Channel connector room-conflict regression FAILED:\n");
    foreach ($failures as $f) fwrite(STDERR, '  - ' . $f . "\n");
    exit(1);
}
echo "Channel connector room-conflict regression passed: {$checks} checks\n";
