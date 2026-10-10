<?php
/** R16.2/R16.3 real MySQL exercise on 2 disposable property schemas ONLY. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/0185_finance_catalog_identity.php';
require dirname(__DIR__).'/api/modules/finance/021_universal_catalog.php';
require dirname(__DIR__).'/api/modules/finance/022_catalog_transaction_snapshots.php';
$dsn=getenv('TAMASYA_CATALOG_UAT_DSN');
$dsnB=getenv('TAMASYA_CATALOG_UAT_OTHER_DSN');
if(!is_string($dsn)||!str_contains($dsn,'dbname=tamasya_catalog_uat;')
  || !is_string($dsnB)||!str_contains($dsnB,'dbname=tamasya_catalog_uat_other;'))
  throw new RuntimeException('Only exact disposable property databases are accepted');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo=new PDO($dsn,getenv('TAMASYA_CATALOG_UAT_USER'),getenv('TAMASYA_CATALOG_UAT_PASS'),$opts);
$other=new PDO($dsnB,getenv('TAMASYA_CATALOG_UAT_USER'),getenv('TAMASYA_CATALOG_UAT_PASS'),$opts);
function tamasyaSchemaTableExists(PDO $db,string $table):bool {
 $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $q->execute([$table]);return (int)$q->fetchColumn()>0;
}
$tests=0;
function verify(bool $ok,string $label):void{global $tests;if(!$ok)throw new RuntimeException('FAIL '.$label);$tests++;echo 'PASS '.$label."\n";}
function mustReject(callable $fn,string $label):void{
 try{$fn();}catch(DomainException|InvalidArgumentException|PDOException $e){verify(true,$label);return;}
 throw new RuntimeException('FAIL unexpected success '.$label);
}
putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED=1');
foreach([$pdo,$other] as $db) {
 foreach(['tamasya_catalog_items','tamasya_catalog_rates','tamasya_catalog_transaction_lines'] as $table)
  verify(tamasyaSchemaTableExists($db,$table),'Per-property migrated '.$table);
 $db->exec("INSERT INTO categories(id,name,type,is_active) VALUES ('category_1','Pendapatan Universal','income',1)");
 $db->exec("INSERT INTO subcategories(id,category_id,category_name,name) VALUES ('subcategory_1','category_1','Pendapatan Universal','Unit Bebas')");
 $db->exec("INSERT INTO tamasya_catalog_items(id,category_id,subcategory_id,name,unit_label,price_mode) VALUES ('item_1','category_1','subcategory_1','Layanan Bebas','unit','fixed')");
}
$pdo->exec("INSERT INTO tamasya_catalog_rates(id,item_id,valid_from,amount) VALUES ('rate_old','item_1','2026-10-01',250000.30),('rate_new','item_1','2026-10-09',275000.00)");
$other->exec("INSERT INTO tamasya_catalog_rates(id,item_id,valid_from,amount) VALUES ('rate_other','item_1','2026-10-01',300000.00)");
$tx=['transactionKind'=>'manual','recordOrigin'=>'live_operation','bookingId'=>null,'sourceEntity'=>null,'sourceEntityId'=>null,
 'categoryId'=>'category_1','subcategoryId'=>'subcategory_1','type'=>'income','date'=>'2026-10-09','amount'=>550000];
$intent=['schema'=>'tamasya-catalog-intent-v2','itemId'=>'item_1','itemRevision'=>1,'rateId'=>'rate_new','quantity'=>'2','serviceDate'=>'2026-10-09'];
$pdo->exec("CREATE TABLE IF NOT EXISTS catalog_uat_transactions(id VARCHAR(50) PRIMARY KEY,amount DECIMAL(15,2)) ENGINE=InnoDB");
$pdo->beginTransaction();
$snapshot=tamasyaCatalogTransactionIntent($pdo,$intent,$tx);
verify($snapshot['subtotal_cents']===55000000 && $snapshot['unit_price_cents']===27500000,'Server recalculates exact quote using current rate');
$pdo->exec("INSERT INTO catalog_uat_transactions(id,amount) VALUES ('tx_1',550000.00)");
tamasyaCatalogSaveTransactionSnapshot($pdo,'tx_1','op_1',$snapshot);
$pdo->commit();
verify((int)$pdo->query('SELECT COUNT(*) FROM tamasya_catalog_transaction_lines')->fetchColumn()===1,'Attribution persisted atomically');
$pdo->beginTransaction();tamasyaCatalogAssertTransactionReplay($pdo,'tx_1',$intent);$pdo->commit();verify(true,'Identical operation replay accepted without second line');
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogAssertTransactionReplay($pdo,'tx_1',null),'Replay without catalog identity rejected');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogAssertTransactionReplay($pdo,'tx_1',array_merge($intent,['quantity'=>'3'])),'Conflicting operation replay rejected');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,array_merge($intent,['rateId'=>'rate_old']),$tx),'Stale rate rejected after new effective rate');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,
 array_merge($intent,['rateId'=>'rate_old','quantity'=>'1','serviceDate'=>'2026-10-08']),
 array_merge($tx,['date'=>'2026-10-08','amount'=>250000])),'Rp250000.30 rate cannot be silently booked as Rp250000');$pdo->rollBack();
$pdo->exec("UPDATE categories SET system_key='extra_service' WHERE id='category_1'");
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,$tx),'Later binding to automated category rejects new sale');$pdo->rollBack();
$pdo->exec("UPDATE categories SET system_key=NULL WHERE id='category_1'");

$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,array_merge($intent,['serviceDate'=>'2026-10-08']),$tx),'Service date manipulation rejected');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,array_merge($tx,['amount'=>550001])),'Server denies mismatched money');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,array_merge($tx,['categoryId'=>'different'])),'Server denies unrelated category');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,array_merge($tx,['recordOrigin'=>'historical_import'])),'Historical backfill cannot masquerade as live catalog transaction');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,array_merge($tx,['bookingId'=>'booking_x'])),'Booking payment cannot bypass canonical folio');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,array_merge($tx,['transactionKind'=>'booking_payment'])),'System posting kind denied');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,['schema'=>'v1']+$intent,$tx),'Wrong contract denied');$pdo->rollBack();
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogSaveTransactionSnapshot($pdo,'tx_1','op_1',$snapshot),'Unique canonical transaction attribution enforced');$pdo->rollBack();
$pdo->beginTransaction();$pdo->exec("INSERT INTO catalog_uat_transactions(id,amount) VALUES ('tx_rollback',550000.00)");
tamasyaCatalogSaveTransactionSnapshot($pdo,'tx_rollback','op_rollback',$snapshot);$pdo->rollBack();
verify((int)$pdo->query("SELECT COUNT(*) FROM tamasya_catalog_transaction_lines WHERE transaction_id='tx_rollback'")->fetchColumn()===0,'Rollback removes snapshot with failed transaction');
verify((int)$pdo->query("SELECT COUNT(*) FROM catalog_uat_transactions WHERE id='tx_rollback'")->fetchColumn()===0,'Rollback removes canonical receipt in same transaction');
verify((int)$other->query('SELECT COUNT(*) FROM tamasya_catalog_transaction_lines')->fetchColumn()===0,'Other property contains NO transaction attribution');
$otherTx=array_merge($tx,['amount'=>300000,'date'=>'2026-10-09']);$otherIntent=array_merge($intent,['rateId'=>'rate_other','quantity'=>'1']);
$other->beginTransaction();$otherSnap=tamasyaCatalogTransactionIntent($other,$otherIntent,$otherTx);
tamasyaCatalogSaveTransactionSnapshot($other,'tx_other','op_other',$otherSnap);$other->commit();
verify($otherSnap['subtotal_cents']===30000000,'Identical item ID resolves OTHER property price in its own database');
verify((int)$pdo->query('SELECT COUNT(*) FROM tamasya_catalog_transaction_lines')->fetchColumn()===1,'Property A snapshot unaffected by property B');
$pdo->exec("UPDATE tamasya_catalog_items SET name='Renamed after payment',revision=revision+1 WHERE id='item_1'");
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,$tx),'Item revision changed since quote');$pdo->rollBack();
$historic=$pdo->query("SELECT item_name,subtotal_cents FROM tamasya_catalog_transaction_lines WHERE transaction_id='tx_1'")->fetch(PDO::FETCH_ASSOC);
verify($historic['item_name']==='Layanan Bebas' && (int)$historic['subtotal_cents']===55000000,'Master rename cannot mutate immutable historical item snapshot');
putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED=0');
$pdo->beginTransaction();mustReject(fn()=>tamasyaCatalogTransactionIntent($pdo,$intent,$tx),'Feature disabled blocks NEW catalog transaction');
mustReject(fn()=>tamasyaCatalogAssertTransactionReplay($pdo,'tx_1',null),'Disabled feature cannot erase replay catalog identity');$pdo->rollBack();
putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED');
echo "CATALOG R16.2/R16.3 MYSQL: $tests PASS, 0 FAIL\n";
