<?php
/** R16.2: immutable catalog attribution, committed in the canonical transaction DB transaction. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaCatalogTransactionIntent(PDO $pdo, array $intent, array $tx): array {
    if (!tamasyaCatalogEnabled()) throw new DomainException('Master tarif nonaktif pada properti ini.');
    if (!function_exists('tamasyaSchemaTableExists') || !tamasyaSchemaTableExists($pdo,'tamasya_catalog_transaction_lines'))
        throw new DomainException('Migrasi snapshot katalog R16.2 belum dipasang.');
    // A catalog item is NOT a booking, system workflow, or historical correction.
    if ((string)($intent['schema']??'')!=='tamasya-catalog-intent-v2'
        || (string)($tx['transactionKind']??'')!=='manual'
        || (string)($tx['recordOrigin']??'')!=='live_operation'
        || !empty($tx['bookingId']) || !empty($tx['sourceEntity']) || !empty($tx['sourceEntityId']))
        throw new DomainException('Item tarif hanya boleh dipakai pada transaksi manual live yang berdiri sendiri.');
    foreach (['itemId','rateId','quantity','serviceDate'] as $key) {
        if (!isset($intent[$key]) || !is_string($intent[$key]) || trim($intent[$key])==='')
            throw new InvalidArgumentException('Identitas versi tarif tidak lengkap.');
    }
    $itemId=trim($intent['itemId']);$rateId=trim($intent['rateId']);$day=$intent['serviceDate'];
    if (strlen($itemId)>50 || strlen($rateId)>50 || !tamasyaCatalogValidDate($day) || $day!==(string)$tx['date'])
        throw new DomainException('Identitas item atau tanggal layanan tidak cocok dengan Log Kas.');
    if (!isset($intent['itemRevision']) || !is_int($intent['itemRevision']) || $intent['itemRevision']<1)
        throw new InvalidArgumentException('Revisi master item wajib dikirim dari quote server.');
    $qty=tamasyaCatalogQuantityMilli($intent['quantity']);
    // Lock item before rate lookup. Rate writer obtains the identical item lock.
    $stmt=$pdo->prepare('SELECT * FROM tamasya_catalog_items WHERE id=? FOR UPDATE');$stmt->execute([$itemId]);
    $item=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if ($item && (int)$item['revision']!==$intent['itemRevision']) throw new DomainException('Master item berubah setelah quote. Muat ulang tarif sebelum mencatat transaksi.');
    if (!$item || (int)$item['is_active']!==1 || (string)$item['price_mode']!=='fixed')
        throw new DomainException('Item tarif tidak aktif atau bukan harga tetap.');
    $category=tamasyaCatalogValidateSelection($pdo,(string)$item['category_id'],$item['subcategory_id'],true);
    if ((string)$category['type']!==(string)$tx['type'] || (string)$item['category_id']!==(string)$tx['categoryId']
        || (string)($item['subcategory_id']??'')!==(string)($tx['subcategoryId']??''))
        throw new DomainException('Kategori atau subkategori transaksi berbeda dari master item.');
    $stmt=$pdo->prepare('SELECT id,amount,valid_from FROM tamasya_catalog_rates WHERE item_id=? AND valid_from<=? ORDER BY valid_from DESC LIMIT 1 FOR UPDATE');
    $stmt->execute([$itemId,$day]);$rate=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$rate || (string)$rate['id']!==$rateId) throw new DomainException('Tarif sudah tidak berlaku pada tanggal transaksi. Ambil ulang harga.');
    $calc=tamasyaCatalogQuoteMoney((string)$rate['amount'],$intent['quantity']);
    if ($calc['quantityMilli']!==$qty || $calc['subtotalCents']%100!==0
        || $calc['subtotalCents']!==((int)$tx['amount']*100))
        throw new DomainException('Total tarif tidak sama dengan transaksi atau masih mengandung pecahan rupiah.');
    return [
        'item_id'=>$itemId,'item_revision'=>(int)$item['revision'],'rate_id'=>$rateId,'category_id'=>(string)$item['category_id'],
        'subcategory_id'=>$item['subcategory_id'],'item_name'=>(string)$item['name'],
        'unit_label'=>(string)$item['unit_label'],'quantity_milli'=>$qty,
        'unit_price_cents'=>$calc['unitPriceCents'],'subtotal_cents'=>$calc['subtotalCents'],
        'service_date'=>$day,'valid_from'=>(string)$rate['valid_from'],
    ];
}

function tamasyaCatalogSaveTransactionSnapshot(PDO $pdo, string $transactionId, ?string $operationId, array $snapshot): void {
    if (!$pdo->inTransaction()) throw new LogicException('Snapshot katalog harus dibuat dalam transaksi finansial yang sama.');
    $stmt=$pdo->prepare('INSERT INTO tamasya_catalog_transaction_lines
        (transaction_id,operation_id,item_id,item_revision,rate_id,category_id,subcategory_id,item_name,unit_label,quantity_milli,unit_price_cents,subtotal_cents,service_date,rate_valid_from)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$transactionId,$operationId,$snapshot['item_id'],$snapshot['item_revision'],$snapshot['rate_id'],$snapshot['category_id'],
        $snapshot['subcategory_id'],$snapshot['item_name'],$snapshot['unit_label'],$snapshot['quantity_milli'],
        $snapshot['unit_price_cents'],$snapshot['subtotal_cents'],$snapshot['service_date'],$snapshot['valid_from']]);
}

function tamasyaCatalogTransactionHasSnapshot(PDO $pdo, string $transactionId): bool {
    if (!function_exists('tamasyaSchemaTableExists') || !tamasyaSchemaTableExists($pdo,'tamasya_catalog_transaction_lines')) return false;
    $q=$pdo->prepare('SELECT 1 FROM tamasya_catalog_transaction_lines WHERE transaction_id=? LIMIT 1');
    $q->execute([$transactionId]);return (bool)$q->fetchColumn();
}

function tamasyaCatalogAssertTransactionReplay(PDO $pdo, string $transactionId, ?array $intent): void {
    if (!function_exists('tamasyaSchemaTableExists')
        || !tamasyaSchemaTableExists($pdo,'tamasya_catalog_transaction_lines')) {
        if ($intent!==null) throw new DomainException('Katalog/snapshot tidak aktif saat replay.');
        return;
    }
    $stmt=$pdo->prepare('SELECT item_id,item_revision,rate_id,quantity_milli,service_date FROM tamasya_catalog_transaction_lines WHERE transaction_id=? FOR UPDATE');
    $stmt->execute([$transactionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if ($row===null && $intent===null) return;
    if ($intent!==null && !tamasyaCatalogEnabled()) throw new DomainException('Master tarif sedang nonaktif.');
    if ($row===null || $intent===null || (string)($intent['schema']??'')!=='tamasya-catalog-intent-v2'
        || (string)($intent['itemId']??'')!==(string)$row['item_id']
        || !is_int($intent['itemRevision']??null) || $intent['itemRevision']!==(int)$row['item_revision']
        || (string)($intent['rateId']??'')!==(string)$row['rate_id']
        || (string)($intent['serviceDate']??'')!==(string)$row['service_date']
        || tamasyaCatalogQuantityMilli($intent['quantity']??'')!==(int)$row['quantity_milli'])
        throw new DomainException('Replay operasi tidak cocok dengan bukti item/tarif yang sudah disimpan.');
}
