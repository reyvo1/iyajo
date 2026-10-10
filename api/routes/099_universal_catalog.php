<?php
/** Optional universal item/rate master, per-property, no independent finance posting. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action??''),[
    'catalog-items','catalog-item-save','catalog-item-archive','catalog-rate-save','catalog-quote'
],true)) return;
$routeHandled=true;
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
// Fail closed even when UI calls API directly.
requireRoles($loggedInStaff, $method==='GET' ? ['admin','manager','finance','owner'] : ['admin','manager']);
if (!tamasyaCatalogEnabled()) {
    http_response_code(403);
    echo tamasyaJsonEncode(['success'=>false,'code'=>'CATALOG_DISABLED','message'=>'Master Item & Tarif belum diaktifkan pada properti ini.']);
    return;
}
if (!tamasyaSchemaTableExists($pdo,'tamasya_catalog_items') || !tamasyaSchemaTableExists($pdo,'tamasya_catalog_rates')) {
    http_response_code(409);
    echo tamasyaJsonEncode(['success'=>false,'code'=>'CATALOG_SCHEMA_REQUIRED','message'=>'Skema Master Item & Tarif belum terpasang. Lakukan migrasi resmi setelah backup.']);
    return;
}
if ($method !== (in_array($action,['catalog-items','catalog-quote'],true)?'GET':'POST')) {
    http_response_code(405);
    echo tamasyaJsonEncode(['success'=>false,'message'=>'Metode HTTP tidak diizinkan.']);
    return;
}
try {
    if ($action==='catalog-items') {
        $rows=$pdo->query("SELECT i.*, c.name category_name,c.type category_type,c.system_key category_system_key,
            s.name subcategory_name,
            (SELECT r.amount FROM tamasya_catalog_rates r WHERE r.item_id=i.id AND r.valid_from<=CURRENT_DATE ORDER BY r.valid_from DESC LIMIT 1) current_rate,
            (SELECT r.valid_from FROM tamasya_catalog_rates r WHERE r.item_id=i.id AND r.valid_from<=CURRENT_DATE ORDER BY r.valid_from DESC LIMIT 1) rate_from
            FROM tamasya_catalog_items i JOIN categories c ON c.id=i.category_id
            LEFT JOIN subcategories s ON s.id=i.subcategory_id ORDER BY i.name,i.id LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo tamasyaJsonEncode(['success'=>true,'data'=>$rows,'readOnly'=>tamasyaIsOwnerRole($loggedInStaff),'taxMode'=>'canonical-finance-only']);
        return;
    }
    if ($action==='catalog-quote') {
        $itemId=trim((string)($_GET['itemId']??''));
        $qty=trim((string)($_GET['quantity']??'1'));
        $date=trim((string)($_GET['date']??date('Y-m-d')));
        if ($itemId===''||!tamasyaCatalogValidDate($date)) throw new InvalidArgumentException('Item atau tanggal tidak valid.');
        $q=$pdo->prepare("SELECT i.*,c.type category_type,c.system_key category_system_key,c.is_active category_active,c.is_system category_system_locked,
            c.name category_name,s.name subcategory_name,
            s.is_active subcategory_active,s.system_key subcategory_system_key,s.is_system subcategory_system_locked,
            (SELECT r.id FROM tamasya_catalog_rates r WHERE r.item_id=i.id AND r.valid_from<=? ORDER BY r.valid_from DESC LIMIT 1) rate_id,
            (SELECT r.valid_from FROM tamasya_catalog_rates r WHERE r.item_id=i.id AND r.valid_from<=? ORDER BY r.valid_from DESC LIMIT 1) rate_valid_from,
            (SELECT r.amount FROM tamasya_catalog_rates r WHERE r.item_id=i.id AND r.valid_from<=? ORDER BY r.valid_from DESC LIMIT 1) price
            FROM tamasya_catalog_items i JOIN categories c ON c.id=i.category_id
            LEFT JOIN subcategories s ON s.id=i.subcategory_id WHERE i.id=? LIMIT 1");
        $q->execute([$date,$date,$date,$itemId]);$row=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if (!$row||(int)$row['is_active']!==1||(int)$row['category_active']!==1
            ||($row['subcategory_id']!==null && (int)$row['subcategory_active']!==1)
            ||trim((string)($row['category_system_key']??''))!==''
            ||(int)($row['category_system_locked']??0)===1
            ||trim((string)($row['subcategory_system_key']??''))!==''
            ||(int)($row['subcategory_system_locked']??0)===1) throw new InvalidArgumentException('Item/kategori tidak aktif atau terikat alur otomatis.');
        if ($row['price_mode']!=='fixed' || $row['price']===null) throw new InvalidArgumentException('Item ini memakai harga manual/belum memiliki tarif berlaku.');
        $calculation=tamasyaCatalogQuoteMoney((string)$row['price'],$qty);
        // Read-only snapshot to prefill canonical Log Kas. NEVER write cash/journals here.
        // Log Kas R15 rounds manual receipts to whole rupiah: forbid sub-rupiah
        // catalog drafts rather than silently lose 0.30 (or any other cents).
        $canPrefillCash = $calculation['subtotalCents'] % 100 === 0;
        echo tamasyaJsonEncode(['success'=>true,'itemId'=>$itemId,'unit'=>$row['unit_label'],'date'=>$date,'quote'=>$calculation,
            'itemSnapshot'=>[
                'itemId'=>(string)$row['id'],'itemRevision'=>(int)$row['revision'],'itemName'=>(string)$row['name'],'unit'=>(string)$row['unit_label'],
                'categoryId'=>(string)$row['category_id'],'categoryName'=>(string)$row['category_name'],'categoryType'=>(string)$row['category_type'],
                'subcategoryId'=>$row['subcategory_id'],'subcategoryName'=>$row['subcategory_name'],
                'rateId'=>(string)$row['rate_id'],'validFrom'=>(string)$row['rate_valid_from'],
                'unitPrice'=>(string)$row['price'],
            ],
            'cashDraftEligible'=>$canPrefillCash,'cashDraftStatus'=>$canPrefillCash?'PREFILL_ONLY':'FRACTIONAL_RUPIAH_UNSUPPORTED',
            'taxStatus'=>'NOT_CALCULATED','postingStatus'=>'DRAFT_ONLY',
            'message'=>'Belum termasuk pajak. Posting hanya melalui mesin keuangan kanonis.']);
        return;
    }
    tamasyaCatalogRequireWritable();
    if ($action==='catalog-item-save') {
        $id=trim((string)($input['id']??''));
        $categoryId=trim((string)($input['categoryId']??''));
        $subcategoryId=trim((string)($input['subcategoryId']??'')) ?: null;
        $name=tamasyaCatalogSafeName($input['name']??'',150,'Nama item');
        $unit=tamasyaCatalogSafeName($input['unit']??'',40,'Satuan');
        $mode=trim((string)($input['priceMode']??''));
        if (!in_array($mode,['fixed','manual'],true)) throw new InvalidArgumentException('Mode harga tidak valid.');
        $isActive=array_key_exists('isActive',$input)?(!empty($input['isActive'])?1:0):1;
        $revision=(int)($input['revision']??0);
        // Optional initial rate is accepted only for a NEW active fixed-price item.
        // Old two-step callers remain compatible, while UI can save item + rate atomically.
        $initialAmountRaw=trim((string)($input['initialAmount']??''));
        $initialDate=trim((string)($input['initialValidFrom']??''));
        $hasInitialRate=$initialAmountRaw!=='' || $initialDate!=='';
        $initialRateAmount=null;
        if ($hasInitialRate) {
            if ($id!=='' || $mode!=='fixed' || $isActive!==1)
                throw new InvalidArgumentException('Tarif awal hanya boleh diisi saat membuat item tarif tetap yang aktif.');
            if (!tamasyaCatalogValidDate($initialDate))
                throw new InvalidArgumentException('Tanggal berlaku tarif awal tidak valid.');
            $initialCents=tamasyaCatalogPositiveMoneyCents($initialAmountRaw);
            if ($initialCents>10000000000) throw new InvalidArgumentException('Tarif awal melebihi batas aman.');
            $initialRateAmount=sprintf('%d.%02d',intdiv($initialCents,100),$initialCents%100);
        }
        $initialRateId=null;
        $pdo->beginTransaction();
        tamasyaCatalogValidateSelection($pdo,$categoryId,$subcategoryId,true);
        if ($id==='') {
            $id=generateServerId('catalog_item');
            $pdo->prepare('INSERT INTO tamasya_catalog_items (id,category_id,subcategory_id,name,unit_label,price_mode,is_active) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id,$categoryId,$subcategoryId,$name,$unit,$mode,$isActive]);
            $before=null;
            if ($hasInitialRate) {
                $initialRateId=generateServerId('catalog_rate');
                $pdo->prepare('INSERT INTO tamasya_catalog_rates (id,item_id,valid_from,amount,recorded_by) VALUES (?,?,?,?,?)')
                    ->execute([$initialRateId,$id,$initialDate,$initialRateAmount,(string)($loggedInStaff['id']??'')]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menambahkan tarif awal master universal','catalog_rate',$initialRateId,null,
                    ['itemId'=>$id,'validFrom'=>$initialDate,'amount'=>$initialRateAmount],'web');
            }
        } else {
            $q=$pdo->prepare('SELECT * FROM tamasya_catalog_items WHERE id=? FOR UPDATE');$q->execute([$id]);
            $before=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if (!$before) throw new InvalidArgumentException('Item tidak ditemukan.');
            if ((int)$before['revision']!==$revision) throw new DomainException('Konflik versi master item. Segarkan daftar sebelum menyimpan.');
            // Immutable category identity protects historical categorization and reports.
            if ((string)$before['category_id']!==$categoryId || (string)($before['subcategory_id']??'')!==(string)($subcategoryId??''))
                throw new DomainException('Kategori/subkategori item yang sudah dibuat tidak dapat dipindahkan. Buat item baru.');
            $pdo->prepare('UPDATE tamasya_catalog_items SET name=?,unit_label=?,price_mode=?,is_active=?,revision=revision+1 WHERE id=?')
                ->execute([$name,$unit,$mode,$isActive,$id]);
        }
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$before?'Mengubah master item universal':'Menambah master item universal',
            'catalog_item',$id,$before,['name'=>$name,'unit'=>$unit,'priceMode'=>$mode,'isActive'=>$isActive,'categoryId'=>$categoryId,'subcategoryId'=>$subcategoryId],'web');
        // Hybrid leadership fencing must be verified inside THIS transaction.
        if (function_exists('tamasyaClusterAssertCommitAuthority')) tamasyaClusterAssertCommitAuthority($pdo);
        $pdo->commit();
        echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'initialRateId'=>$initialRateId,'message'=>'Master item tersimpan.']);
        return;
    }
    if ($action==='catalog-item-archive') {
        $id=trim((string)($input['id']??''));$revision=(int)($input['revision']??0);
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT * FROM tamasya_catalog_items WHERE id=? FOR UPDATE');$q->execute([$id]);
        $before=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if (!$before) throw new InvalidArgumentException('Item tidak ditemukan.');
        if ((int)$before['revision']!==$revision) throw new DomainException('Konflik versi item.');
        if ((int)$before['is_active']!==1) throw new DomainException('Item sudah diarsipkan.');
        $pdo->prepare('UPDATE tamasya_catalog_items SET is_active=0,revision=revision+1 WHERE id=?')->execute([$id]);
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengarsipkan master item universal','catalog_item',$id,$before,['is_active'=>0],'web');
        // Hybrid leadership fencing must be verified inside THIS transaction.
        if (function_exists('tamasyaClusterAssertCommitAuthority')) tamasyaClusterAssertCommitAuthority($pdo);
        $pdo->commit();
        echo tamasyaJsonEncode(['success'=>true,'message'=>'Item diarsipkan, tarif dan audit historis tetap tersimpan.']);
        return;
    }
    if ($action==='catalog-rate-save') {
        $itemId=trim((string)($input['itemId']??''));
        $start=trim((string)($input['validFrom']??''));
        if (!tamasyaCatalogValidDate($start)) throw new InvalidArgumentException('Tanggal berlaku tarif wajib YYYY-MM-DD.');
        $cents=tamasyaCatalogPositiveMoneyCents($input['amount']??null);
        if ($cents>10000000000) throw new InvalidArgumentException('Tarif melebihi batas aman.');
        $amount=sprintf('%d.%02d',intdiv($cents,100),$cents%100);
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT * FROM tamasya_catalog_items WHERE id=? FOR UPDATE');$q->execute([$itemId]);
        $item=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if (!$item || (int)$item['is_active']!==1 || $item['price_mode']!=='fixed') throw new InvalidArgumentException('Item tidak aktif atau menggunakan harga manual.');
        tamasyaCatalogValidateSelection($pdo,(string)$item['category_id'],$item['subcategory_id'],true);
        // No overwrites of existing tariffs, including future effective periods.
        $rateId=generateServerId('catalog_rate');
        $pdo->prepare('INSERT INTO tamasya_catalog_rates (id,item_id,valid_from,amount,recorded_by) VALUES (?,?,?,?,?)')
            ->execute([$rateId,$itemId,$start,$amount,(string)($loggedInStaff['id']??'')]);
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menambahkan revisi tarif master universal','catalog_rate',$rateId,null,
            ['itemId'=>$itemId,'validFrom'=>$start,'amount'=>$amount],'web');
        // Hybrid leadership fencing must be verified inside THIS transaction.
        if (function_exists('tamasyaClusterAssertCommitAuthority')) tamasyaClusterAssertCommitAuthority($pdo);
        $pdo->commit();
        echo tamasyaJsonEncode(['success'=>true,'id'=>$rateId,'message'=>'Versi tarif tersimpan dan tidak menimpa periode lama.']);
        return;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($e instanceof DomainException?409:($e instanceof InvalidArgumentException?422:500));
    error_log('[universal-catalog] '.get_class($e).': '.$e->getMessage());
    echo tamasyaJsonEncode(['success'=>false,'message'=>$e instanceof DomainException || $e instanceof InvalidArgumentException
        ?$e->getMessage():'Master Item & Tarif gagal. Periksa log server.']);
}
