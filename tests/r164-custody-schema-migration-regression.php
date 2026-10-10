<?php
/** The legacy repair may not infer key possession from active occupancy. */
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);
$source=file_get_contents($root.'/api/support/080_schema_alignment.php');
$start=strpos($source,'// Custody is not occupancy.');
$end=strpos($source,'// Repair actor identity that legacy allocation', $start===false?0:$start);
if($start===false||$end===false||$end<=$start)throw new RuntimeException('Missing custody repair boundary');
$segment=substr($source,$start,$end-$start);
$assert=static function(bool $ok,string $message){if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;};
$assert(!str_contains($segment,'SET rac.current_booking_id=b.id'),'Migration cannot pre-bind every active guest to room custody');
$assert(!str_contains($segment,"WHEN b.id IS NOT NULL THEN 'secured'"),'Migration cannot silently mark outstanding key secured');
$assert(!str_contains($segment,"WHEN b.id IS NOT NULL AND b.keyControlStatus='issued' THEN 'issued'"),'Migration cannot infer key issue from booking flag alone');
$assert(str_contains($segment,"THEN 'override' ELSE 'secured' END") && str_contains($segment,"NULL,CURRENT_TIMESTAMP"),'New controls stay unbound and unresolved legacy issued keys are blocked');
$assert(str_contains($segment,"pending_smart_revoke") && str_contains($segment,"AND rac.current_booking_id IS NULL"),'Unknown issued access is quarantined rather than reissuable');
$assert(str_contains($segment,"orphan_outstanding_room_key") && str_contains($segment,"AND rac.physical_key_status='secured' AND rac.current_booking_id IS NULL"),'Outstanding orphan keys must be reported, never deleted');
$assert(str_contains($segment,"room_key_custody_mismatch") && str_contains($segment,"data_integrity_issues"),'Disputed historical ownership must be reported for operator reconciliation');
$assert(str_contains($source,'invariant_repair_snapshots'),'Historical repair must retain before state');
echo "PASS R16.4 migration custody root cause\n";
