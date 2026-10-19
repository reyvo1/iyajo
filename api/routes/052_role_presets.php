<?php
/** TAMASYA Fase 2 "Kelola Jabatan": paket capability/tab/notifikasi per jabatan.
 *
 * Data paket disimpan di role_capability_presets; enforcement TIDAK berubah — aksi
 * "role-preset-apply" menulis override staff.permissions per akun (eksplisit, sadar
 * dampak), sehingga aplikasi centangan mengikuti jalur tamasyaPermissionOverride yang
 * sudah ada (017). Owner dikecualikan sepenuhnya: paketnya tetap read-only.
 * Semua command admin-only; owner tercakup lewat tamasyaEnforceOwnerReadOnly.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'role-preset') return;
$routeHandled=true;

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
tamasyaEnforceOwnerReadOnly($loggedInStaff,(string)$action);

try {
    requireRoles($loggedInStaff,['admin']);
    $command=trim((string)($method==='GET'?($_GET['command']??'role-preset-list'):($input['command']??'')));
    $roleInput=strtolower(trim((string)($input['role']??($_GET['role']??''))));
    $requireKnownRole=static function(string $role): string {
        if(!in_array($role,tamasyaRolePresetRoles(),true))throw new InvalidArgumentException('Jabatan tidak dikenal atau tidak dapat diubah (owner dikecualikan).');
        return $role;
    };
    switch ($command) {
        case 'role-preset-list':
            $presets=[];$meta=[];
            foreach(tamasyaRolePresetRows($pdo) as $row){
                $role=(string)$row['role'];
                $presets[$role]=tamasyaRolePresetCoerce(json_decode((string)$row['permissions'],true));
                $meta[$role]=['updatedBy'=>(string)($row['updated_by']??''),'updatedAt'=>(string)($row['updated_at']??'')];
            }
            $counts=$pdo->query("SELECT role,COUNT(*) AS total FROM staff WHERE role<>'owner' GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR)?:[];
            echo tamasyaJsonEncode(['success'=>true,'roles'=>tamasyaRolePresetRoles(),'presets'=>$presets,'meta'=>$meta,'staffCounts'=>$counts]);
            break;

        case 'role-preset-save':
            $role=$requireKnownRole($roleInput);
            $preset=tamasyaRolePresetNormalize($input['preset']??null);
            tamasyaRolePresetRows($pdo);
            $pdo->beginTransaction();
            try{
                $before=$pdo->prepare("SELECT role,permissions,updated_by,updated_at FROM role_capability_presets WHERE role=? FOR UPDATE");
                $before->execute([$role]);
                $beforeRow=$before->fetch(PDO::FETCH_ASSOC)?:null;
                $pdo->prepare("INSERT INTO role_capability_presets (role,permissions,updated_by,updated_at) VALUES (?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE permissions=VALUES(permissions),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$role,json_encode($preset,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($loggedInStaff['id']??'')]);
                $after=$pdo->prepare("SELECT role,permissions,updated_by,updated_at FROM role_capability_presets WHERE role=?");
                $after->execute([$role]);
                $afterRow=$after->fetch(PDO::FETCH_ASSOC)?:null;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyimpan paket jabatan '.$role,'role_capability_preset',$role,$beforeRow,$afterRow,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'role'=>$role,'preset'=>$preset,'updatedAt'=>(string)($afterRow['updated_at']??'')]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        case 'role-preset-preview':
            $role=$requireKnownRole($roleInput);
            $stored=null;
            foreach(tamasyaRolePresetRows($pdo) as $row){if((string)$row['role']===$role){$stored=$row;break;}}
            if($stored===null)throw new RuntimeException('Paket jabatan '.$role.' belum ada di database.');
            $preset=tamasyaRolePresetCoerce(json_decode((string)$stored['permissions'],true));
            $accounts=$pdo->prepare("SELECT id,name,username,permissions FROM staff WHERE role=? AND role<>'owner' ORDER BY name");
            $accounts->execute([$role]);
            $affected=[];$unchanged=0;
            foreach(($accounts->fetchAll(PDO::FETCH_ASSOC)?:[]) as $account){
                $changes=tamasyaRolePresetAccountDiff($preset,$role,$account['permissions']);
                if($changes===[]){$unchanged++;continue;}
                $affected[]=['id'=>(string)$account['id'],'name'=>(string)$account['name'],'username'=>(string)$account['username'],'changes'=>$changes];
            }
            echo tamasyaJsonEncode(['success'=>true,'role'=>$role,'preset'=>$preset,'updatedAt'=>(string)($stored['updated_at']??''),'total'=>count($affected)+$unchanged,'unchanged'=>$unchanged,'affected'=>$affected]);
            break;

        case 'role-preset-apply':
            if($method!=='POST')throw new InvalidArgumentException('Terapkan paket jabatan harus POST.');
            if(($input['confirm']??null)!==true)throw new InvalidArgumentException('Penerapan paket butuh konfirmasi eksplisit (confirm=true) setelah pratinjau.');
            $role=$requireKnownRole($roleInput);
            tamasyaRolePresetRows($pdo);
            $pdo->beginTransaction();
            try{
                $stored=$pdo->prepare("SELECT role,permissions,updated_at FROM role_capability_presets WHERE role=? FOR UPDATE");
                $stored->execute([$role]);
                $storedRow=$stored->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$storedRow)throw new InvalidArgumentException('Paket jabatan '.$role.' belum ada. Simpan paket lebih dulu.');
                $expectedUpdatedAt=trim((string)($input['updatedAt']??''));
                if($expectedUpdatedAt!==''&&$expectedUpdatedAt!==(string)$storedRow['updated_at']){
                    throw new DomainException('Paket jabatan berubah di perangkat lain. Muat ulang pratinjau sebelum menerapkan.');
                }
                $preset=tamasyaRolePresetCoerce(json_decode((string)$storedRow['permissions'],true));
                $accounts=$pdo->prepare("SELECT id,name,permissions FROM staff WHERE role=? AND role<>'owner' FOR UPDATE");
                $accounts->execute([$role]);
                $written=0;$skipped=0;$applied=[];
                foreach(($accounts->fetchAll(PDO::FETCH_ASSOC)?:[]) as $account){
                    $changes=tamasyaRolePresetAccountDiff($preset,$role,$account['permissions']);
                    if($changes===[]){$skipped++;continue;}
                    $override=tamasyaRolePresetBuildOverride($preset,$account['permissions']);
                    $pdo->prepare("UPDATE staff SET permissions=? WHERE id=?")->execute([json_encode($override,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)$account['id']]);
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menerapkan paket jabatan '.$role.' ke akun staf','staff',(string)$account['id'],['permissions'=>$account['permissions']],['permissions'=>json_encode($override,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],'web');
                    $applied[]=['id'=>(string)$account['id'],'name'=>(string)$account['name'],'changes'=>$changes];
                    $written++;
                }
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menerapkan paket jabatan '.$role.' ke '.$written.' akun ('.$skipped.' tidak berubah)','role_capability_preset',$role,null,['written'=>$written,'skipped'=>$skipped],'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'role'=>$role,'written'=>$written,'skipped'=>$skipped,'applied'=>$applied]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        default: throw new InvalidArgumentException('Command paket jabatan tidak dikenal.');
    }
}catch(Throwable $e){
    if($pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
    tamasyaApplyExceptionHttpStatus($e,422);
    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Paket jabatan gagal',$e)]);
}
