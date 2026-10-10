<?php
/** MySQL integration with TWO disposable per-property databases. */
if(PHP_SAPI!=='cli')exit(1);
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/support/090_security_cluster_smartlock.php';
require dirname(__DIR__).'/api/support/060_shift_receipts.php';
$dsnA=getenv('TAMASYA_CATALOG_UAT_DSN');$dsnB=getenv('TAMASYA_CATALOG_UAT_OTHER_DSN');
if(!str_contains((string)$dsnA,'dbname=tamasya_catalog_uat;') || !str_contains((string)$dsnB,'dbname=tamasya_catalog_uat_other;'))throw new RuntimeException('Refusing non-disposable databases');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$a=new PDO($dsnA,getenv('TAMASYA_CATALOG_UAT_USER'),getenv('TAMASYA_CATALOG_UAT_PASS'),$opts);
$b=new PDO($dsnB,getenv('TAMASYA_CATALOG_UAT_USER'),getenv('TAMASYA_CATALOG_UAT_PASS'),$opts);
$tests=0;
function verifyNight(bool $x,string $s):void{global $tests;if(!$x)throw new RuntimeException('FAIL '.$s);$tests++;echo 'PASS '.$s."\n";}
function mustDenyNight(callable $f,string $s):void{try{$f();}catch(RuntimeException $e){verifyNight(true,$s);return;}throw new RuntimeException('FAIL unexpected success '.$s);}
foreach ([$a,$b] as $db) {
 $row=getOperationalSettings($db);verifyNight(tamasyaNightAuditIsEnabled($row),'Default ON after additive migration');
 verifyNight(array_key_exists('night_audit_enabled',$row),'Migration column exists');
}
$a->exec("UPDATE hotel_operational_settings SET night_audit_enabled=0 WHERE id='system_default'");
verifyNight(!tamasyaNightAuditIsEnabled(getOperationalSettings($a)),'Property A disabled');
verifyNight(tamasyaNightAuditIsEnabled(getOperationalSettings($b)),'Property B remains ON');
$a->beginTransaction();mustDenyNight(fn()=>tamasyaRequireNightAuditEnabled($a),'Start denied when property A OFF');$a->rollBack();
$b->beginTransaction();tamasyaRequireNightAuditEnabled($b);$b->commit();verifyNight(true,'Property B can start while ON');
$night=['id'=>'shift_test','shift_time'=>'malam','opened_at'=>'2026-10-09 20:00:00'];
assertShiftNightAuditReady($a,$night,getOperationalSettings($a));verifyNight(true,'Disabled mode does not block night shift close');
mustDenyNight(fn()=>assertShiftNightAuditReady($b,$night,getOperationalSettings($b)),'Enabled mode requires completed audit');
$b->exec("INSERT INTO night_audit_runs(id,shift_session_id,status,completed_at) VALUES ('audit_test','shift_test','completed',NOW())");
assertShiftNightAuditReady($b,$night,getOperationalSettings($b));verifyNight(true,'Completed audit satisfies night shift gate');
$b->exec("INSERT INTO night_audit_items(id,audit_id,discrepancy_type,resolution_status) VALUES ('item_test','audit_test','physical_key_missing','open')");
mustDenyNight(fn()=>assertShiftNightAuditReady($b,$night,getOperationalSettings($b)),'Unresolved discrepancy still blocks closure');
$b->exec("UPDATE night_audit_items SET resolution_status='resolved' WHERE id='item_test'");
assertShiftNightAuditReady($b,$night,getOperationalSettings($b));verifyNight(true,'Resolved discrepancy permits closure');
$b->exec("UPDATE hotel_operational_settings SET night_audit_enabled=0 WHERE id='system_default'");
verifyNight(!tamasyaNightAuditIsEnabled(getOperationalSettings($b)),'Property B mode can change independently');
$b->exec("UPDATE hotel_operational_settings SET night_audit_enabled=1 WHERE id='system_default'");
verifyNight(tamasyaNightAuditIsEnabled(getOperationalSettings($b)),'Re-enable restores requirement');
echo "NIGHT AUDIT MODE MYSQL: {$tests} PASS, 0 FAIL\n";
