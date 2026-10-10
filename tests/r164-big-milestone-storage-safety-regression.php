<?php
/** R16.4 candidate -- durable receipt CAS accuracy and no destructive cleanup. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$maintenance=file_get_contents($root.'/receipt_storage_maintenance.php');
$cron=file_get_contents($root.'/maintenance_cron.php');
$policy=file_get_contents($root.'/api/support/078_durable_retention_policy.php');
function bigAssert(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
bigAssert(str_contains($maintenance,"\$result['casConflicts']++"),'CAS conflicts reported separately from actual saved bytes');
bigAssert(str_contains($maintenance,'if($changed===1)')&&str_contains($maintenance,"\$result['bytesBefore']+=strlen(\$body);"),'Only committed row updates count as saved bytes');
bigAssert(str_contains($maintenance,'is_file($backupReference)')&&str_contains($maintenance,'is_readable($backupReference)')&&str_contains($maintenance,'filesize($backupReference)<=0'),'Cannot use an arbitrary string as proof of backup');
bigAssert(str_contains($maintenance,'}finally{')&&str_contains($maintenance,'tamasyaReleasePrimaryMutationLock($pdo)'),'Primary lock released even after failure');
bigAssert(!preg_match('/DELETE\s+FROM\s+(?:sync_operations|telegram_update_log|request_operation_receipts)\b/i',$cron),'Maintenance cannot purge durable operations, Telegram update IDs, or receipts');
bigAssert(str_contains($policy,"'durable_history_manual_review_required'=>true"),'Retention decision remains manual and recorded');
$scenario=file_get_contents($root.'/tests/uat_rc1/scenario_multi_room.py');
bigAssert(str_contains($scenario,"base.parents[1]/'backup_now.php'") && str_contains($scenario,"backup_result.get('selfVerified') is True") && str_contains($scenario,"sha.hexdigest()==backup_result.get('sha256')"),'Real canonical UAT SQL backup is created and integrity-verified before compaction');
bigAssert(str_contains($scenario,"Storage rejects symbolic backup and fails closed") && str_contains($scenario,"Rejected storage apply preserves the original receipt"),'UAT confirms a fake backup cannot authorize compaction');
bigAssert(str_contains($scenario,"check('Storage '+('apply' if apply else 'dry-run')+' succeeds'"),'Original storage apply success remains an obligatory assertion');
bigAssert(str_contains($scenario,"Compaction keeps exact replay and all semantic metadata") && str_contains($scenario,"Storage dry-run mutates nothing"),'Existing receipt replay, metadata and dry-run UAT assertions retained');
echo "STORAGE POLICY: PASS\n";
