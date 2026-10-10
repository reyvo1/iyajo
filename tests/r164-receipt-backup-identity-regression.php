<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require __DIR__.'/../backup_support.php';
$n=0;
function backupAssert(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label.PHP_EOL;}
$path=tempnam(sys_get_temp_dir(),'r164-backup-test-');
if($path===false)throw new RuntimeException('Cannot create temporary fixture');
try{
    chmod($path,0600);
    file_put_contents($path,'THIS IS NOT A DATABASE BACKUP');
    $blocked=false;try{tamasyaBackupVerifySqlFile($path);}catch(RuntimeException $e){$blocked=true;}
    backupAssert($blocked,'arbitrary text backup is not restore-grade evidence');
    file_put_contents($path,"-- Objects: 0 base tables, 0 views, 0 routines, 0 events, 0 triggers\n-- TAMASYA_BACKUP_COMPLETE\n");
    $blocked=false;try{tamasyaBackupVerifySqlFile($path);}catch(RuntimeException $e){$blocked=true;}
    backupAssert($blocked,'SQL without canonical manifest is rejected');
    file_put_contents($path,"-- Objects: 0 base tables, 0 views, 0 routines, 0 events, 0 triggers\n-- TAMASYA_BACKUP_MANIFEST_JSON: {\"formatVersion\":2,\"tables\":0,\"views\":0,\"routines\":0,\"events\":0,\"triggers\":0}\n-- TAMASYA_BACKUP_COMPLETE\n");
    $good=tamasyaBackupVerifySqlFile($path);
    backupAssert($good['valid']===true && $good['completionMarkerCount']===1,'canonical marker parser validates consistent fixture');
    file_put_contents($path,"-- Objects: 0 base tables, 0 views, 0 routines, 0 events, 0 triggers\n-- TAMASYA_BACKUP_MANIFEST_JSON: {\"formatVersion\":2,\"tables\":0,\"views\":0,\"routines\":0,\"events\":0,\"triggers\":0}\n-- TAMASYA_BACKUP_COMPLETE\n-- TAMASYA_BACKUP_COMPLETE\n");
    $blocked=false;try{tamasyaBackupVerifySqlFile($path);}catch(RuntimeException $e){$blocked=true;}
    backupAssert($blocked,'duplicate completion marker is rejected');
}finally{unlink($path);}
$maint=file_get_contents(__DIR__.'/../receipt_storage_maintenance.php');
backupAssert(str_contains($maint,'is_link($backupReference)'),'reject symlinks');
backupAssert(str_contains($maint,"getenv('BACKUP_DIR')"),'backup evidence restricted to configured private directory');
backupAssert(str_contains($maint,'$receiptBackupVerification=tamasyaBackupVerifySqlFile($backupResolved)'),'compaction CLI enforces restore-grade parser');
backupAssert(str_contains(file_get_contents(__DIR__.'/../backup_support.php'), "'database'=>\$database"), 'backup exporter embeds database identity in SQL manifest');
backupAssert(str_contains($maint,'hash_equals($liveDbName,$backupDbName)') && str_contains($maint,"\$pdo->query('SELECT DATABASE()')"),'rollback evidence must match the actual database');
backupAssert(str_contains($maint,"require_once __DIR__.'/database_bootstrap.php'"),'document root boundary helper loaded before use');
echo 'RECEIPT BACKUP IDENTITY PASS '.$n.PHP_EOL;
