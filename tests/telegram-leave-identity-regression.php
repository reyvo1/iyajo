<?php
/** No hotel DB needed: Telegram leave wizard idempotency under reused message ID. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY',true);
require __DIR__.'/../api/modules/hr_staff/058_employee_self_service.php';
$n=0;
function checkLeave(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL: '.$label);$n++;echo 'PASS '.$label."\n";}
$a=['id'=>'staff_a'];$b=['id'=>'staff_b'];$old='tgcb_same_chat_same_message_leave_confirm';
$ctx=['leaveType'=>'permission','startDate'=>'2026-10-12','endDate'=>'2026-10-13','reason'=>'izin keluarga','requestNonce'=>str_repeat('a',24)];
$op1=tamasyaEmployeeTelegramLeaveOperationId($a,$ctx,$old);
checkLeave($op1===tamasyaEmployeeTelegramLeaveOperationId($a,$ctx,$old),'same wizard retry is idempotent');
checkLeave(strlen($op1)<=100,'operation ID fits MariaDB varchar(100)');
$ctx2=$ctx;$ctx2['requestNonce']=str_repeat('b',24);
checkLeave($op1!==tamasyaEmployeeTelegramLeaveOperationId($a,$ctx2,$old),'second wizard on same Telegram message gets different ID');
checkLeave($op1!==tamasyaEmployeeTelegramLeaveOperationId($b,$ctx,$old),'other staff gets different ID');
checkLeave(tamasyaEmployeeTelegramLeaveOperationId($a,[], $old)==='tg_leave_'.$old,'already-in-flight legacy wizard remains compatible');
foreach(['invalid',str_repeat('a',23),str_repeat('Z',24)] as $bad){$ctx2['requestNonce']=$bad;$failed=false;try{tamasyaEmployeeTelegramLeaveOperationId($a,$ctx2,$old);}catch(InvalidArgumentException $ignored){$failed=true;}checkLeave($failed,'invalid wizard nonce denied');}
$src=file_get_contents(__DIR__.'/../api/routes/080_telegram_webhook.php');
checkLeave(str_contains($src,"'requestNonce'=>bin2hex(random_bytes(12))"),'fresh wizard nonce generated at type selection');
checkLeave(str_contains($src,'tamasyaEmployeeTelegramLeaveOperationId($loggedInStaff,$ctx,$telegramCallbackOperationId)'),'Telegram submit uses session-safe identity');
checkLeave(str_contains($src,"'callback_data'=>'leave_type:permission'") && str_contains($src,"'callback_data'=>'leave_team'"),'leave and supervisor menu still present');
checkLeave(str_contains($src,"str_starts_with($".'callbackData'.",'leave_approve_confirm:')"),'manager approval handler still present');
echo "TOTAL {$n} PASS; 0 FAIL\n";
