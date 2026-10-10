<?php
/** Universal catalog: per-property item/rate master; never bypass financial posting. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaCatalogEnabled(): bool {
    return (string)(getenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED') ?: '') === '1';
}
function tamasyaCatalogPositiveMoneyCents($amount): int {
    if (!is_string($amount) && !is_int($amount)) throw new InvalidArgumentException('Harga harus berupa string desimal.');
    $value=trim((string)$amount);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/D',$value)) throw new InvalidArgumentException('Harga harus desimal positif maksimal 2 angka pecahan.');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    if ((int)$whole>100000000) throw new InvalidArgumentException('Harga melebihi batas aman katalog.');
    $cents=((int)$whole)*100+(int)str_pad($fraction,2,'0');
    if ($cents<=0) throw new InvalidArgumentException('Harga standar harus lebih besar dari nol.');
    return $cents;
}
function tamasyaCatalogQuantityMilli($quantity): int {
    if (!is_string($quantity) && !is_int($quantity)) throw new InvalidArgumentException('Kuantitas harus berupa angka desimal.');
    $value=trim((string)$quantity);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?$/D',$value)) throw new InvalidArgumentException('Kuantitas maksimal 3 desimal.');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    $milli=((int)$whole)*1000+(int)str_pad($fraction,3,'0');
    if ($milli<=0) throw new InvalidArgumentException('Kuantitas harus lebih besar dari nol.');
    return $milli;
}
function tamasyaCatalogQuoteMoney(string $unitPrice, string $quantity): array {
    $cents=tamasyaCatalogPositiveMoneyCents($unitPrice);
    $milli=tamasyaCatalogQuantityMilli($quantity);
    // Bound inputs to ensure exact 64bit arithmetic, half-up cent rounding.
    if ($cents>10000000000 || $milli>10000000) throw new InvalidArgumentException('Nilai tarif/kuantitas melampaui batas aman.');
    $grossCents=intdiv($cents*$milli+500,1000);
    return ['quantityMilli'=>$milli,'unitPriceCents'=>$cents,'subtotalCents'=>$grossCents,
        'subtotal'=>sprintf('%d.%02d',intdiv($grossCents,100),$grossCents%100)];
}
function tamasyaCatalogValidDate(string $date): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $d!==false && $d->format('Y-m-d')===$date;
}
function tamasyaCatalogSafeName($value, int $max, string $label): string {
    $name=trim((string)$value);
    if ($name===''||tamasyaCatalogTextLength($name)>$max || preg_match('/[\x00-\x1F\x7F]/u',$name))
        throw new InvalidArgumentException($label.' wajib berisi 1–'.$max.' karakter teks valid.');
    return $name;
}
function tamasyaCatalogRequireWritable(): void {
    if (function_exists('tamasyaGrowthRequireWriter')) tamasyaGrowthRequireWriter();
    if (function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()
        && function_exists('tamasyaNodeRole') && tamasyaNodeRole() !== 'online_primary')
        throw new RuntimeException('Master tarif hanya dapat diubah di Primary Writer.');
}
function tamasyaCatalogValidateSelection(PDO $pdo, string $categoryId, ?string $subcategoryId, bool $lock=false): array {
    $q=$pdo->prepare('SELECT id,name,type,system_key,is_active,is_system FROM categories WHERE id=? '.($lock?'FOR UPDATE':''));
    $q->execute([$categoryId]); $category=$q->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$category || (int)$category['is_active']!==1) throw new InvalidArgumentException('Kategori aktif tidak ditemukan.');
    if (trim((string)($category['system_key']??''))!=='' || (int)($category['is_system']??0)===1)
        throw new InvalidArgumentException('Kategori terikat ke alur sistem otomatis tidak boleh dipakai master tarif manual.');
    if ($subcategoryId!==null) {
        $q=$pdo->prepare('SELECT id,is_active,system_key,is_system FROM subcategories WHERE id=? AND category_id=? '.($lock?'FOR UPDATE':''));
        $q->execute([$subcategoryId,$categoryId]);$sub=$q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$sub || (int)$sub['is_active']!==1) throw new InvalidArgumentException('Subkategori tidak aktif atau bukan milik kategori yang dipilih.');
        if (trim((string)($sub['system_key']??''))!=='' || (int)($sub['is_system']??0)===1)
            throw new InvalidArgumentException('Subkategori terikat fungsi sistem otomatis.');
    }
    return $category;
}
