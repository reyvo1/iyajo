<?php
/** CI-only disposable MySQL integration gate. No production configuration or data. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/0185_finance_catalog_identity.php';
require dirname(__DIR__).'/api/modules/finance/021_universal_catalog.php';
$dsn=getenv('TAMASYA_CATALOG_UAT_DSN');
if (!$dsn || !str_contains($dsn,'dbname=tamasya_catalog_uat;')) throw new RuntimeException('Only dedicated disposable DB supported');
$pdo=new PDO($dsn,getenv('TAMASYA_CATALOG_UAT_USER'),getenv('TAMASYA_CATALOG_UAT_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$pass=0;
function ok(bool $test,string $label):void{global $pass;if(!$test)throw new RuntimeException('FAIL '.$label);$pass++;echo 'PASS '.$label."\n";}
function rejected(callable $cb,string $label):void{try{$cb();throw new RuntimeException('UNEXPECTED ACCEPT: '.$label);}catch(PDOException|DomainException|InvalidArgumentException $e){ok(true,$label);}}
foreach(['tamasya_catalog_items','tamasya_catalog_rates'] as $t){
 $stmt=$pdo->query("SHOW TABLES LIKE '$t'");ok((bool)$stmt->fetchColumn(),'Optional table migrated '.$t);
}
$pdo->exec("INSERT INTO categories (id,name,type,is_active) VALUES ('cat_uat','Generic Income','income',1),('cat_other','Generic Expense','expense',1),('cat_sys','Protected Room','income',1)");
$pdo->exec("UPDATE categories SET system_key='room_rental' WHERE id='cat_sys'");
$pdo->exec("INSERT INTO subcategories(id,category_id,category_name,name) VALUES ('sub_uat','cat_uat','Generic Income','Any service'),('sub_other','cat_other','Generic Expense','Any service')");
ok(tamasyaCatalogValidateSelection($pdo,'cat_uat','sub_uat')['type']==='income','Valid relationship selected by immutable IDs');
rejected(fn()=>tamasyaCatalogValidateSelection($pdo,'cat_uat','sub_other'),'Cross-category subcategory rejected');
rejected(fn()=>tamasyaCatalogValidateSelection($pdo,'cat_sys',null),'Semantic room category protected');
$pdo->exec("UPDATE categories SET is_system=1 WHERE id='cat_other'");
rejected(fn()=>tamasyaCatalogValidateSelection($pdo,'cat_other',null),'Reserved system category without system_key protected');
$pdo->exec("UPDATE categories SET is_system=0 WHERE id='cat_other'");
$pdo->exec("UPDATE subcategories SET is_system=1 WHERE id='sub_uat'");
rejected(fn()=>tamasyaCatalogValidateSelection($pdo,'cat_uat','sub_uat'),'Reserved system subcategory protected');
$pdo->exec("UPDATE subcategories SET is_system=0 WHERE id='sub_uat'");
$pdo->prepare('INSERT INTO tamasya_catalog_items (id,category_id,subcategory_id,name,unit_label,price_mode) VALUES (?,?,?,?,?,?)')
 ->execute(['item_a','cat_uat','sub_uat','Any Service','day','fixed']);
$pdo->prepare('INSERT INTO tamasya_catalog_items (id,category_id,name,unit_label,price_mode) VALUES (?,?,?,?,?)')
 ->execute(['item_b','cat_other','Other Service','piece','manual']);
ok((int)$pdo->query('SELECT COUNT(*) FROM tamasya_catalog_items')->fetchColumn()===2,'Two independent generic items');
rejected(function()use($pdo){$pdo->exec("INSERT INTO tamasya_catalog_items(id,category_id,subcategory_id,name,unit_label) VALUES('item_invalid','cat_uat','nonexistent','Should Fail','unit')");},'Broken subcategory FK denied');
$ins=$pdo->prepare('INSERT INTO tamasya_catalog_rates(id,item_id,valid_from,amount) VALUES (?,?,?,?)');
$ins->execute(['rate1','item_a','2026-09-01','250000.30']);
$ins->execute(['rate2','item_a','2026-10-09','275000.00']);
rejected(fn()=>$ins->execute(['rate3','item_a','2026-10-09','280000.00']),'Duplicate effective-date rate denied');
$q=$pdo->prepare('SELECT amount FROM tamasya_catalog_rates WHERE item_id=? AND valid_from<=? ORDER BY valid_from DESC LIMIT 1');
$q->execute(['item_a','2026-09-30']);ok((string)$q->fetchColumn()==='250000.30','Old price immutable at past service date');
$q->execute(['item_a','2026-10-10']);ok((string)$q->fetchColumn()==='275000.00','New rate becomes effective on schedule');
ok(tamasyaCatalogQuoteMoney('250000.30','3')['subtotal']==='750000.90','Cent precision preserved (not hidden)');
$pdo->exec("UPDATE tamasya_catalog_items SET is_active=0,revision=revision+1 WHERE id='item_a'");
ok((int)$pdo->query("SELECT COUNT(*) FROM tamasya_catalog_rates WHERE item_id='item_a'")->fetchColumn()===2,'Archive retains historical price versions');
ok((int)$pdo->query("SELECT COUNT(*) FROM categories WHERE system_key='room_rental'")->fetchColumn()===1,'Room semantic binding unchanged');
ok((int)$pdo->query('SELECT COUNT(*) FROM tamasya_catalog_items')->fetchColumn()===2,'All catalog data remains property-local');
echo "UNIVERSAL CATALOG MYSQL: $pass PASS, 0 FAIL\n";
