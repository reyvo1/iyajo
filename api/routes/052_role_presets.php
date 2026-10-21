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
$command=trim((string)($method==='GET'?($_GET['command']??'role-preset-list'):($input['command']??'')));
$readOnlyCommands=['role-preset-list','rbac-catalog-list','role-preset-preview'];
$isMutationCommand=!in_array($command,$readOnlyCommands,true);
tamasyaEnforceOwnerReadOnly($loggedInStaff,(string)$action,$isMutationCommand);
if($isMutationCommand&&$method!=='POST'){
    http_response_code(405);
    echo tamasyaJsonEncode(['success'=>false,'error'=>'Command '.$command.' wajib memakai POST.']);
    return;
}

try {
    requireRoles($loggedInStaff,['admin']);
    $roleInput=strtolower(trim((string)($input['role']??($_GET['role']??''))));
    $jobTitles=tamasyaJobTitleMap($pdo);
    $titleExpr=tamasyaStaffJobTitleColumnExists($pdo)?"COALESCE(NULLIF(job_title,''),role)":"role";
    $requireKnownRole=static function(string $role) use ($jobTitles): string {
        if($role===''||!isset($jobTitles[$role]))throw new InvalidArgumentException('Jabatan tidak dikenal atau tidak dapat diubah (owner dikecualikan).');
        return $role;
    };
    if ($command === 'role-preset-apply') {
        // Paket preset dari HQ. Hanya admin property yang boleh menerapkan,
        // dan hanya jabatan kanonik yang diterima.
        $presets = $input['presets'] ?? null;
        if (!is_array($presets) || !$presets) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success' => false, 'error' => 'Payload preset wajib diisi.']);
            return;
        }
        $result = tamasyaRolePresetApplyPayload($pdo, $presets, 'hq', (string)($loggedInStaff['id'] ?? 'hq'));
        writeRequiredEnterpriseAudit($pdo, $loggedInStaff, 'Menerapkan paket preset jabatan dari HQ', 'role_preset', null, null, ['applied' => $result['applied'], 'roles' => $result['roles']], 'role_preset_hq');
        echo tamasyaJsonEncode(['success' => true, 'data' => $result]);
        return;
    }
    if ($command === 'role-preset-state') {
        // Status penerapan paket HQ di property ini.
        $rows = [];
        try {
            $stmt = $pdo->query("SELECT `role`,`updated_by`,`updated_at` FROM `role_capability_presets` ORDER BY `role`");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $error) { $rows = []; }
        echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => ['rows' => $rows]]);
        return;
    }
    switch ($command) {
        case 'role-preset-list':
            $presets=[];$meta=[];
            foreach(tamasyaRolePresetRows($pdo) as $row){
                $role=(string)$row['role'];
                $presets[$role]=tamasyaRolePresetCoerce(json_decode((string)$row['permissions'],true));
                $meta[$role]=['updatedBy'=>(string)($row['updated_by']??''),'updatedAt'=>(string)($row['updated_at']??''),'label'=>(string)($row['label']??$role),'baseRole'=>(string)($row['baseRole']??$role),'isSystem'=>(bool)($row['isSystem']??false)];
            }
            $labels=[];$bases=[];$systems=[];
            foreach($jobTitles as $key=>$titleRow){
                $labels[(string)$key]=(string)$titleRow['label'];
                $bases[(string)$key]=(string)$titleRow['base_role'];
                $systems[(string)$key]=((int)$titleRow['is_system']===1);
            }
            $counts=$pdo->query("SELECT {$titleExpr} AS title_key,COUNT(*) AS total FROM staff WHERE role<>'owner' GROUP BY {$titleExpr}")->fetchAll(PDO::FETCH_KEY_PAIR)?:[];
            echo tamasyaJsonEncode(['success'=>true,'roles'=>array_keys($jobTitles),'labels'=>$labels,'bases'=>$bases,'system'=>$systems,'presets'=>$presets,'meta'=>$meta,'staffCounts'=>$counts,'catalog'=>tamasyaRbacCatalogPayload($pdo),'accessGroups'=>tamasyaRbacAccessGroupRows($pdo)]);
            break;

        case 'rbac-catalog-list':
            echo tamasyaJsonEncode(['success'=>true,'catalog'=>tamasyaRbacCatalogPayload($pdo),'accessGroups'=>tamasyaRbacAccessGroupRows($pdo),'roles'=>tamasyaRbacRoleKeys($pdo)]);
            break;

        case 'rbac-catalog-save':
            if($method!=='POST')throw new InvalidArgumentException('Simpan katalog RBAC harus POST.');
            $group=strtolower(trim((string)($input['group']??'')));
            $definition=tamasyaRbacCatalogDefinition($group);
            if($definition===null)throw new InvalidArgumentException('Grup katalog RBAC tidak dikenal.');
            $key=strtolower(trim((string)($input['key']??'')));
            if(!preg_match('/^[a-z][a-z0-9_]{1,63}$/',$key))throw new InvalidArgumentException('Key katalog harus 2-64 karakter: huruf kecil, angka, atau garis bawah, diawali huruf.');
            $label=trim((string)($input['label']??''));
            if($label==='')throw new InvalidArgumentException('Label katalog wajib diisi.');
            if((function_exists('mb_strlen')?mb_strlen($label):strlen($label))>160)throw new InvalidArgumentException('Label katalog maksimal 160 karakter.');
            $sortOrder=(int)($input['sortOrder']??100);
            if($sortOrder<0||$sortOrder>100000)throw new InvalidArgumentException('Urutan katalog tidak valid.');
            $active=array_key_exists('active',$input)?($input['active']===true||$input['active']===1||$input['active']==='1'||$input['active']==='true'?1:0):1;
            $pdo->beginTransaction();
            try{
                $before=$pdo->prepare("SELECT * FROM `{$definition['table']}` WHERE `{$definition['column']}`=? FOR UPDATE");
                $before->execute([$key]);
                $beforeRow=$before->fetch(PDO::FETCH_ASSOC)?:null;
                $pdo->prepare("INSERT INTO `{$definition['table']}` (`{$definition['column']}`,label,is_system,sort_order,active) VALUES (?,?,0,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),sort_order=VALUES(sort_order),active=VALUES(active)")
                    ->execute([$key,$label,$sortOrder,$active]);
                if($beforeRow===null)tamasyaRbacCatalogAddToPresets($pdo,$group,$key,false);
                $after=$pdo->prepare("SELECT * FROM `{$definition['table']}` WHERE `{$definition['column']}`=?");
                $after->execute([$key]);
                $afterRow=$after->fetch(PDO::FETCH_ASSOC)?:null;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,($beforeRow===null?'Menambah':'Mengubah').' katalog RBAC '.$group.'/'.$key,'rbac_catalog',$group.'/'.$key,$beforeRow,$afterRow,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                tamasyaRbacFlushCache();
                echo tamasyaJsonEncode(['success'=>true,'group'=>$group,'key'=>$key,'created'=>$beforeRow===null]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        case 'rbac-catalog-delete':
            if($method!=='POST')throw new InvalidArgumentException('Hapus katalog RBAC harus POST.');
            $group=strtolower(trim((string)($input['group']??'')));
            $definition=tamasyaRbacCatalogDefinition($group);
            if($definition===null)throw new InvalidArgumentException('Grup katalog RBAC tidak dikenal.');
            $key=strtolower(trim((string)($input['key']??'')));
            $pdo->beginTransaction();
            try{
                $before=$pdo->prepare("SELECT * FROM `{$definition['table']}` WHERE `{$definition['column']}`=? FOR UPDATE");
                $before->execute([$key]);
                $beforeRow=$before->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$beforeRow)throw new InvalidArgumentException('Key katalog tidak ditemukan.');
                if((int)$beforeRow['is_system']===1)throw new DomainException('Key katalog sistem tidak dapat dihapus.');
                $pdo->prepare("DELETE FROM `{$definition['table']}` WHERE `{$definition['column']}`=?")->execute([$key]);
                tamasyaRbacCatalogRemoveFromPresets($pdo,$group,$key);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus katalog RBAC '.$group.'/'.$key,'rbac_catalog',$group.'/'.$key,$beforeRow,null,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                tamasyaRbacFlushCache();
                echo tamasyaJsonEncode(['success'=>true,'group'=>$group,'key'=>$key]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        case 'rbac-access-group-save':
            if($method!=='POST')throw new InvalidArgumentException('Simpan access group RBAC harus POST.');
            $label=trim((string)($input['label']??''));
            if($label==='')throw new InvalidArgumentException('Label access group wajib diisi.');
            if((function_exists('mb_strlen')?mb_strlen($label):strlen($label))>120)throw new InvalidArgumentException('Label access group maksimal 120 karakter.');
            $groupKey=strtolower(trim((string)($input['groupKey']??'')));
            if($groupKey==='')$groupKey=tamasyaJobTitleSlug($label);
            if(!preg_match('/^[a-z][a-z0-9_]{1,63}$/',$groupKey))throw new InvalidArgumentException('Key access group harus 2-64 karakter: huruf kecil, angka, atau garis bawah, diawali huruf.');
            $rolesInput=$input['roles']??[];
            if(!is_array($rolesInput))throw new InvalidArgumentException('Daftar role access group tidak valid.');
            $knownRoles=array_fill_keys(array_map(static fn(array $row): string=>(string)$row['key'],tamasyaRbacRoleRows($pdo,true)),true);
            $roles=[];
            foreach($rolesInput as $roleKey){
                $roleKey=strtolower(trim((string)$roleKey));
                if($roleKey==='')continue;
                if(!isset($knownRoles[$roleKey]))throw new InvalidArgumentException('Role access group tidak dikenal: '.$roleKey.'.');
                $roles[$roleKey]=$roleKey;
            }
            $roles=array_values($roles);
            sort($roles,SORT_STRING);
            if($roles===[])throw new InvalidArgumentException('Access group minimal memiliki satu role.');
            if($groupKey==='admin_only'&&!in_array('admin',$roles,true))throw new InvalidArgumentException('Grup admin_only wajib memuat role admin agar panel RBAC tidak terkunci.');
            $active=array_key_exists('active',$input)?($input['active']===true||$input['active']===1||$input['active']==='1'||$input['active']==='true'?1:0):1;
            $pdo->beginTransaction();
            try{
                $before=$pdo->prepare("SELECT group_key,label,is_system,active FROM rbac_access_groups WHERE group_key=? FOR UPDATE");
                $before->execute([$groupKey]);
                $beforeRow=$before->fetch(PDO::FETCH_ASSOC)?:null;
                $stmtRolesOld=$pdo->prepare("SELECT role_key FROM rbac_access_group_roles WHERE group_key=? ORDER BY role_key");
                $stmtRolesOld->execute([$groupKey]);
                $beforeRoles=array_map('strval',$stmtRolesOld->fetchAll(PDO::FETCH_COLUMN)?:[]);
                if($beforeRow===null){
                    $pdo->prepare("INSERT INTO rbac_access_groups (group_key,label,is_system,active) VALUES (?,?,0,?)")->execute([$groupKey,$label,$active]);
                }else{
                    $pdo->prepare("UPDATE rbac_access_groups SET label=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE group_key=?")->execute([$label,$active,$groupKey]);
                }
                $pdo->prepare("DELETE FROM rbac_access_group_roles WHERE group_key=?")->execute([$groupKey]);
                $insertRole=$pdo->prepare("INSERT INTO rbac_access_group_roles (group_key,role_key) VALUES (?,?)");
                foreach($roles as $roleKey)$insertRole->execute([$groupKey,$roleKey]);
                $after=$pdo->prepare("SELECT group_key,label,is_system,active FROM rbac_access_groups WHERE group_key=?");
                $after->execute([$groupKey]);
                $afterRow=$after->fetch(PDO::FETCH_ASSOC)?:null;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,($beforeRow===null?'Menambah':'Mengubah').' access group RBAC '.$groupKey,'rbac_access_group',$groupKey,['group'=>$beforeRow,'roles'=>$beforeRoles],['group'=>$afterRow,'roles'=>$roles],'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                tamasyaRbacFlushCache();
                echo tamasyaJsonEncode(['success'=>true,'groupKey'=>$groupKey,'roles'=>$roles,'created'=>$beforeRow===null]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        case 'rbac-access-group-delete':
            if($method!=='POST')throw new InvalidArgumentException('Hapus access group RBAC harus POST.');
            $groupKey=strtolower(trim((string)($input['groupKey']??'')));
            if($groupKey==='')throw new InvalidArgumentException('Key access group wajib diisi.');
            $pdo->beginTransaction();
            try{
                $before=$pdo->prepare("SELECT group_key,label,is_system,active FROM rbac_access_groups WHERE group_key=? FOR UPDATE");
                $before->execute([$groupKey]);
                $beforeRow=$before->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$beforeRow)throw new InvalidArgumentException('Access group tidak ditemukan.');
                if((int)$beforeRow['is_system']===1)throw new DomainException('Access group sistem tidak dapat dihapus.');
                $used=$pdo->prepare("SELECT COUNT(*) FROM rbac_legacy_role_sets WHERE group_key=?");
                $used->execute([$groupKey]);
                if((int)$used->fetchColumn()>0)throw new DomainException('Access group masih dipakai aturan route; ubah pemetaan atau nonaktifkan grup terlebih dahulu.');
                $roles=$pdo->prepare("SELECT role_key FROM rbac_access_group_roles WHERE group_key=? ORDER BY role_key");
                $roles->execute([$groupKey]);
                $beforeRoles=array_map('strval',$roles->fetchAll(PDO::FETCH_COLUMN)?:[]);
                $pdo->prepare("DELETE FROM rbac_access_groups WHERE group_key=?")->execute([$groupKey]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus access group RBAC '.$groupKey,'rbac_access_group',$groupKey,['group'=>$beforeRow,'roles'=>$beforeRoles],null,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                tamasyaRbacFlushCache();
                echo tamasyaJsonEncode(['success'=>true,'groupKey'=>$groupKey]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        case 'job-title-save':
            if($method!=='POST')throw new InvalidArgumentException('Simpan jabatan harus POST.');
            $label=trim((string)($input['label']??''));
            if($label==='')throw new InvalidArgumentException('Nama jabatan wajib diisi.');
            if((function_exists('mb_strlen')?mb_strlen($label):strlen($label))>60)throw new InvalidArgumentException('Nama jabatan maksimal 60 karakter.');
            $key=strtolower(trim((string)($input['key']??'')));
            $pdo->beginTransaction();
            try{
                if($key===''){
                    $baseRole=strtolower(trim((string)($input['baseRole']??'')));
                    if($baseRole==='')$baseRole='lain_lain';
                    if(!in_array($baseRole,tamasyaRolePresetRoles(),true))throw new InvalidArgumentException('Role basis tidak dikenal. Pilih salah satu 9 jabatan sistem.');
                    $candidate=tamasyaJobTitleSlug($label);
                    $existing=array_map('strval',$pdo->query("SELECT title_key FROM staff_job_titles")->fetchAll(PDO::FETCH_COLUMN)?:[]);
                    $taken=array_fill_keys($existing,true);
                    $taken['owner']=true;
                    $unique=$candidate;$counter=2;
                    while(isset($taken[$unique])){ $suffix='_'.$counter; $unique=substr($candidate,0,max(1,30-strlen($suffix))).$suffix; $counter++; }
                    $sortOrder=(int)($pdo->query("SELECT COALESCE(MAX(sort_order),90) FROM staff_job_titles")->fetchColumn())+10;
                    $pdo->prepare("INSERT INTO staff_job_titles (title_key,label,base_role,is_system,sort_order,active) VALUES (?,?,?,0,?,1)")
                        ->execute([$unique,$label,$baseRole,$sortOrder]);
                    $default=tamasyaRolePresetDefaultFor($unique,$baseRole);
                    $pdo->prepare("INSERT IGNORE INTO role_capability_presets (role,permissions,updated_by) VALUES (?,?,NULL)")
                        ->execute([$unique,json_encode($default,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menambah jabatan '.$label.' (basis '.$baseRole.')','staff_job_title',$unique,null,['label'=>$label,'base_role'=>$baseRole,'is_system'=>0],'web');
                    bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                    echo tamasyaJsonEncode(['success'=>true,'created'=>true,'key'=>$unique,'label'=>$label,'baseRole'=>$baseRole]);
                }else{
                    if(!isset($jobTitles[$key]))throw new InvalidArgumentException('Jabatan tidak ditemukan atau sudah dihapus.');
                    $current=$jobTitles[$key];
                    if((int)$current['is_system']===1)throw new InvalidArgumentException('Jabatan sistem tidak dapat diganti dari panel ini. Ubah labelnya lewat rilis aplikasi.');
                    $newBase=strtolower(trim((string)($input['baseRole']??'')));
                    if($newBase!==''&&$newBase!==(string)$current['base_role'])throw new InvalidArgumentException('Role basis jabatan tidak dapat diubah. Hapus jabatan (bila belum dipakai akun) lalu buat ulang.');
                    $pdo->prepare("UPDATE staff_job_titles SET label=?, updated_at=CURRENT_TIMESTAMP WHERE title_key=?")->execute([$label,$key]);
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengganti nama jabatan '.$current['label'].' menjadi '.$label,'staff_job_title',$key,['label'=>(string)$current['label']],['label'=>$label],'web');
                    bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                    echo tamasyaJsonEncode(['success'=>true,'created'=>false,'key'=>$key,'label'=>$label,'baseRole'=>(string)$current['base_role']]);
                }
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;

        case 'job-title-delete':
            if($method!=='POST')throw new InvalidArgumentException('Hapus jabatan harus POST.');
            $key=strtolower(trim((string)($input['key']??'')));
            if(!isset($jobTitles[$key]))throw new InvalidArgumentException('Jabatan tidak ditemukan atau sudah dihapus.');
            if((int)$jobTitles[$key]['is_system']===1)throw new InvalidArgumentException('Jabatan sistem tidak dapat dihapus.');
            $used=$pdo->prepare("SELECT COUNT(*) FROM staff WHERE role<>'owner' AND ({$titleExpr}=? OR role=?)");
            $used->execute([$key,$key]);
            $usedCount=(int)$used->fetchColumn();
            if($usedCount>0)throw new DomainException('Jabatan '.$jobTitles[$key]['label'].' masih dipakai '.$usedCount.' akun. Pindahkan akun ke jabatan lain lebih dulu.');
            $pdo->beginTransaction();
            try{
                $pdo->prepare("DELETE FROM role_capability_presets WHERE role=?")->execute([$key]);
                $pdo->prepare("DELETE FROM staff_job_titles WHERE title_key=?")->execute([$key]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus jabatan '.$jobTitles[$key]['label'],'staff_job_title',$key,['label'=>(string)$jobTitles[$key]['label'],'base_role'=>(string)$jobTitles[$key]['base_role']],null,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'key'=>$key]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
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
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyimpan paket jabatan '.$jobTitles[$role]['label'],'role_capability_preset',$role,$beforeRow,$afterRow,'web');
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
            $accounts=$pdo->prepare("SELECT id,name,username,permissions FROM staff WHERE {$titleExpr}=? AND role<>'owner' ORDER BY name");
            $accounts->execute([$role]);
            $affected=[];$unchanged=0;
            foreach(($accounts->fetchAll(PDO::FETCH_ASSOC)?:[]) as $account){
                $changes=tamasyaRolePresetAccountDiff($preset,$role,$account['permissions'],(string)$jobTitles[$role]['base_role']);
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
                $accounts=$pdo->prepare("SELECT id,name,permissions FROM staff WHERE {$titleExpr}=? AND role<>'owner' FOR UPDATE");
                $accounts->execute([$role]);
                $written=0;$skipped=0;$applied=[];
                foreach(($accounts->fetchAll(PDO::FETCH_ASSOC)?:[]) as $account){
                    $changes=tamasyaRolePresetAccountDiff($preset,$role,$account['permissions'],(string)$jobTitles[$role]['base_role']);
                    if($changes===[]){$skipped++;continue;}
                    $override=tamasyaRolePresetBuildOverride($preset,$account['permissions']);
                    $pdo->prepare("UPDATE staff SET permissions=? WHERE id=?")->execute([json_encode($override,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)$account['id']]);
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menerapkan paket jabatan '.$role.' ke akun staf','staff',(string)$account['id'],['permissions'=>$account['permissions']],['permissions'=>json_encode($override,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],'web');
                    $applied[]=['id'=>(string)$account['id'],'name'=>(string)$account['name'],'changes'=>$changes];
                    $written++;
                }
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menerapkan paket jabatan '.$jobTitles[$role]['label'].' ke '.$written.' akun ('.$skipped.' tidak berubah)','role_capability_preset',$role,null,['written'=>$written,'skipped'=>$skipped],'web');
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
