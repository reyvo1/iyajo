<?php
/** CLI-only, lossless storage maintenance; no schema/business/history deletion. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!defined('TAMASYA_API_ENTRY'))define('TAMASYA_API_ENTRY',true);
require_once __DIR__.'/api/modules/hr_staff/020_identity_access_audit.php';
function tamasyaReceiptStorageCandidate(string $body): ?string {
    if(strlen($body)<4096||str_starts_with($body,'@tamasya:gzip-base64:'))return null;
    $encoded=tamasyaEncodeReceiptResponseBody($body);
    if(strlen($encoded)>=strlen($body))return null;
    if(tamasyaDecodeReceiptResponseBody($encoded)!==$body)throw new RuntimeException('Lossless receipt verification failed.');
    return $encoded;
}
/** Explicit logical compaction; replaces only replay UI cache, not business result. */
function tamasyaReceiptProjectionCandidate(string $body,string $action): ?string {
    $decoded=tamasyaDecodeReceiptResponseBody($body);
    if($decoded===''&&$body!=='')throw new RuntimeException('Receipt body cannot be decoded; compaction refused.');
    $compact=tamasyaPrepareDurableReceiptBody($decoded,$action);
    if($compact===$decoded)return null;
    $encoded=tamasyaEncodeReceiptResponseBody($compact);
    if(strlen($encoded)>=strlen($body))return null;
    if(tamasyaDecodeReceiptResponseBody($encoded)!==$compact)throw new RuntimeException('Receipt projection envelope verification failed.');
    return $encoded;
}
/** Cursor-paginated scan prevents already-compacted/noneligible historical rows
 * from permanently shadowing later receipts. Keep the compare-and-swap update,
 * primary-only lock and existing replay codec unchanged.
 */
function tamasyaCompactReceiptStorage(PDO $pdo,bool $apply=false,int $limit=200,bool $omitUiProjection=false,string $after=''): array {
    if($limit<1||$limit>1000)throw new InvalidArgumentException('Limit must be 1–1000.');
    if(strlen($after)>100||preg_match('/[\x00-\x1F\x7F]/',$after))throw new InvalidArgumentException('Invalid receipt cursor.');
    if($apply&&!$pdo->inTransaction())throw new RuntimeException('Apply requires an authority-checked transaction.');
    $projectionFilter=$omitUiProjection?" AND action IN ('transactions','notifications-read')":" AND response_body NOT LIKE '@tamasya:gzip-base64:%'";
    // Always seek past the last inspected ID; never use an OFFSET on mutable receipts.
    $stmt=$pdo->prepare("SELECT operation_id,action,response_body FROM request_operation_receipts WHERE status IN ('completed','failed','rejected') AND OCTET_LENGTH(response_body)>=4096{$projectionFilter} AND operation_id > ? ORDER BY operation_id LIMIT {$limit}");
    $stmt->execute([$after]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $next=$rows ? (string)$rows[count($rows)-1]['operation_id'] : $after;
    $result=['success'=>true,'mode'=>$apply?'apply':'dry-run','omitUiProjection'=>$omitUiProjection,'scanned'=>count($rows),'eligible'=>0,'updated'=>0,'casConflicts'=>0,'bytesBefore'=>0,'bytesAfter'=>0,'bytesSaved'=>0,'after'=>$after,'nextCursor'=>$next,'scanExhausted'=>count($rows)<$limit];
    foreach($rows as $r){
        $body=(string)$r['response_body'];
        $nextBody=$omitUiProjection?tamasyaReceiptProjectionCandidate($body,(string)$r['action']):tamasyaReceiptStorageCandidate($body);
        if($nextBody===null)continue;
        $result['eligible']++;
        if($apply){
            $update=$pdo->prepare("UPDATE request_operation_receipts SET response_body=?,updated_at=updated_at WHERE operation_id=? AND BINARY response_body=BINARY ? AND status IN ('completed','failed','rejected')");
            $update->execute([$nextBody,$r['operation_id'],$body]);
            $changed=$update->rowCount();
            if($changed===1){
                $result['updated']++;
                $result['bytesBefore']+=strlen($body);
                $result['bytesAfter']+=strlen($nextBody);
            }else{
                // Concurrent updates must not be reported as bytes actually saved.
                $result['casConflicts']++;
            }
        }else{
            $result['bytesBefore']+=strlen($body);
            $result['bytesAfter']+=strlen($nextBody);
        }
    }
    $result['bytesSaved']=$result['bytesBefore']-$result['bytesAfter'];
    return $result;
}
if(defined('TAMASYA_RECEIPT_COMPACTION_LIBRARY'))return;
$options=getopt('', ['apply','omit-ui-projection','limit:','after:','backup-confirmed:','help']);
if(isset($options['help'])){echo "php receipt_storage_maintenance.php [--limit=200] [--after=LAST_CURSOR] [--omit-ui-projection] [--apply --backup-confirmed=/private/verified-backup.sql]\nDefault: read-only dry-run. Compact existing replay bodies without deleting metadata or business records. --omit-ui-projection replaces only the redundant UI snapshot with a fresh role-scoped read on replay; business results remain fixed.\n";exit;}
$apply=isset($options['apply']);$omitUiProjection=isset($options['omit-ui-projection']);$limit=(int)($options['limit']??200);$after=(string)($options['after']??'');
try{
    if($apply){
        $backupReference=trim((string)($options['backup-confirmed']??''));
        // A symbolic string is NOT evidence that a recoverable snapshot exists.
        // The caller must supply an actual readable non-empty backup file path.
        if($backupReference==='' || !is_file($backupReference) || !is_readable($backupReference) || filesize($backupReference)<=0)
            throw new RuntimeException('Apply requires an existing readable non-empty private backup file.');
        // Mere file existence is not restore evidence. Verify that the file is
        // regular, not a symlink, private, outside webroot and produced with the
        // canonical SQL exporter (manifest, object markers, completion marker).
        if(is_link($backupReference) || realpath($backupReference)===false)
            throw new RuntimeException('Backup must be a regular node-local SQL file, not a symlink.');
        $backupResolved=(string)realpath($backupReference);
        $backupPermissions=@fileperms($backupResolved);
        if($backupPermissions===false || (($backupPermissions & 0077)!==0))
            throw new RuntimeException('Backup permissions are not private (required 0600).');
        require_once __DIR__.'/backup_support.php';
        require_once __DIR__.'/database_bootstrap.php';
        $backupDirectory=trim((string)(getenv('BACKUP_DIR')?:''));
        $configuredPrivateDirectory=$backupDirectory!==''?realpath($backupDirectory):false;
        if($configuredPrivateDirectory===false || !str_starts_with($backupResolved,rtrim((string)$configuredPrivateDirectory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))
            throw new RuntimeException('Backup confirmation must reference a file inside BACKUP_DIR on this node.');
        if(tamasyaPathIsInsideDocumentRoot($backupResolved,__DIR__))
            throw new RuntimeException('Backup confirmation cannot reference a file in the application webroot.');
        $receiptBackupVerification=tamasyaBackupVerifySqlFile($backupResolved);
    }
    require_once __DIR__.'/release_contract.php';require_once __DIR__.'/database_bootstrap.php';require_once __DIR__.'/node_sync_support.php';require_once __DIR__.'/node_cluster_support.php';
    $config=tamasyaResolveDatabaseConfig(__DIR__);[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
    if(!$pdo instanceof PDO)throw new RuntimeException('Database connection unavailable ('.$stage.').');
    tamasyaAssertDatabaseSafety($pdo,$config);tamasyaDatabasePropertyIdentity($pdo,true);
    if($apply){
        // Verify the backup belongs to the SAME MariaDB database about to be
        // compacted. A valid backup of another property/database is not a safe
        // rollback source for this node.
        $liveDbName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $backupDbName=(string)($receiptBackupVerification['manifest']['database']??'');
        if($liveDbName==='' || $backupDbName==='' || !hash_equals($liveDbName,$backupDbName)
            || (int)($receiptBackupVerification['manifest']['tables']??0)<1)
            throw new RuntimeException('Verified backup is for another/empty database; compaction refused.');
        if(!tamasyaClusterEnabled()&&tamasyaNodeRole()!=='online_primary')throw new RuntimeException('Storage maintenance is primary-only.');
        if(!tamasyaAcquirePrimaryMutationLock($pdo,30))throw new RuntimeException('Primary mutation lock unavailable.');
        $receiptPrimaryLockHeld=true;
        $guard=tamasyaClusterMutationGuard($pdo);
        if($guard!==null)throw new RuntimeException($guard);
        $pdo->beginTransaction();
    }
    $result=tamasyaCompactReceiptStorage($pdo,$apply,$limit,$omitUiProjection,$after);
    if($apply){tamasyaClusterAssertCommitAuthority($pdo);$pdo->commit();}
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$e->getMessage()]).PHP_EOL);
    $receiptExitCode=1;
}finally{
    if(!empty($receiptPrimaryLockHeld) && isset($pdo) && $pdo instanceof PDO)
        tamasyaReleasePrimaryMutationLock($pdo);
}
if(!empty($receiptExitCode))exit($receiptExitCode);
