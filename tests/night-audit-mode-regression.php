<?php
/** R16.4 source policy contract; no production DB calls. */
if (PHP_SAPI!=='cli') exit(1);
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/support/090_security_cluster_smartlock.php';
$tests=0;
function verify(bool $ok,string $label):void { global $tests; if(!$ok)throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label."\n"; $tests++; }
verify(tamasyaNightAuditIsEnabled([]),'Legacy property without migration remains ON');
verify(tamasyaNightAuditIsEnabled(['night_audit_enabled'=>1]),'Explicit ON permitted');
verify(!tamasyaNightAuditIsEnabled(['night_audit_enabled'=>0]),'Explicit OFF denied');
$base=dirname(__DIR__);
$route=file_get_contents($base.'/api/routes/090_operations_communications.php');
$shift=file_get_contents($base.'/api/support/060_shift_receipts.php');
$cron=file_get_contents($base.'/maintenance_cron.php');
$ui=file_get_contents($base.'/assets/chunks/operations.js');
$migration=file_get_contents($base.'/migrations/R16_4_NIGHT_AUDIT_MODE.sql');
verify(str_contains($route,"requireRoles(\$loggedInStaff,['admin']);"),'Admin-only toggle enforced server-side');
verify(str_contains($route,"tamasyaStringLength(\$reason)<12"),'Audit reason required');
verify(str_contains($route,"SELECT COUNT(*) FROM night_audit_runs WHERE status='open'"),'Cannot disable unfinished audits');
verify(str_contains($route,"writeRequiredEnterpriseAudit(\$pdo,\$loggedInStaff,\$enabled?"),'Mode changes leave required audit trail');
verify(substr_count($route,'tamasyaRequireNightAuditEnabled($pdo);')===5,'All five Night Audit mutation commands guarded');
foreach (['night-audit-start','night-audit-refresh','night-audit-check-room','night-audit-resolve','night-audit-finalize'] as $command) {
    $pos=strpos($route,"} elseif (\$command === '".$command."')");
    $tx=$pos===false?false:strpos($route,'$pdo->beginTransaction();',$pos);
    verify($tx!==false && $tx-$pos<2000 && preg_match('/^\$pdo->beginTransaction\(\);\s*tamasyaRequireNightAuditEnabled\(\$pdo\);/',substr($route,$tx,140)), 'Night Audit '.$command.' locks mode BEFORE all business mutations');
}
verify(str_contains($shift,'!tamasyaNightAuditIsEnabled($settings)'),'Mode OFF independently releases Night Audit shift-only requirement');
verify(str_contains($cron,"['night_audit_enabled']??1"),'Monitoring alarm understands OFF mode');
verify(str_contains($ui,'night-audit-mode-save') && str_contains($ui,'Nonaktifkan Night Audit'),'Visible admin toggle sends dedicated operation');
verify(str_contains($ui,'disabled:!sa||m||Number(i.operationalSettings?.night_audit_enabled??1)!==1'),'UI blocks start while OFF');
verify(str_contains($migration,'information_schema.COLUMNS') && !str_contains($migration,'DROP TABLE'),'Migration idempotent and additive');
verify(str_contains(file_get_contents($base.'/node_sync_support.php'),"'night-audit-mode-save','backup-record'"),'Standby cannot mutate Night Audit policy locally');
verify(str_contains(file_get_contents($base.'/node_sync_support.php'),"'hotel_operational_settings'=>"),'Hybrid includes property operational settings');
echo "NIGHT AUDIT MODE SOURCE: {$tests} PASS, 0 FAIL\n";
