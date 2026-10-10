<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('TAMASYA_API_ENTRY',true);
require __DIR__.'/../api/modules/hr_staff/058_employee_self_service.php';
$n=0;
function verifyLeave(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL: '.$label);$n++;echo 'PASS '.$label.PHP_EOL;}
$original=['id'=>'leave-1','staff_id'=>'staff-A','leave_type'=>'permission','start_date'=>'2026-10-12','end_date'=>'2026-10-13','reason'=>'Izin keluarga'];
tamasyaEmployeeAssertLeaveReplay($original,'staff-A','permission','2026-10-12','2026-10-13','Izin keluarga');verifyLeave(true,'exact replay for same employee passes');
foreach(['staff_id'=>'staff-B','leave_type'=>'sick','start_date'=>'2026-10-14','end_date'=>'2026-10-14','reason'=>'Izin lain'] as $column=>$wrong){
    $params=['staff_id'=>'staff-A','leave_type'=>'permission','start_date'=>'2026-10-12','end_date'=>'2026-10-13','reason'=>'Izin keluarga'];$params[$column]=$wrong;
    $blocked=false;try{tamasyaEmployeeAssertLeaveReplay($original,$params['staff_id'],$params['leave_type'],$params['start_date'],$params['end_date'],$params['reason']);}catch(RuntimeException $e){$blocked=true;}
    verifyLeave($blocked,'reject reused operationId with different '.$column);
}
$source=file_get_contents(__DIR__.'/../api/modules/hr_staff/058_employee_self_service.php');
verifyLeave(str_contains($source,'SELECT id,status,staff_id,leave_type,start_date,end_date,reason FROM staff_leave_requests'),'load row identity and complete intent under locking read');
verifyLeave(str_contains($source,'tamasyaEmployeeAssertLeaveReplay($existing,$staffId,$type,$start,$end,$reason)'),'replay comparison wired before duplicate return');
echo 'LEAVE IDEMPOTENCY PASS '.$n.PHP_EOL;
