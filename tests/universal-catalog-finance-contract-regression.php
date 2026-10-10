<?php
/** R16.2/R16.3 mandatory source-contract regression, runs PHP 8.2-8.5. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/0185_finance_catalog_identity.php';
require dirname(__DIR__).'/api/modules/finance/021_universal_catalog.php';
require dirname(__DIR__).'/api/modules/finance/022_catalog_transaction_snapshots.php';
$root=dirname(__DIR__);$passed=0;
function ck(bool $p,string $label):void{global $passed;if(!$p)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function blocked(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException $e){ck(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);}
ck(tamasyaCatalogQuoteMoney('250000.30','3')['subtotal']==='750000.90','Fraction retained for explicit rejection, not hidden');
ck(tamasyaCatalogQuoteMoney('275000.00','2')['subtotalCents']===55000000,'Whole rupiah exact total');
$tx=file_get_contents($root.'/api/routes/040_transactions_sync.php');
ck(str_contains($tx,'tamasyaCatalogTransactionIntent($pdo,$catalogIntent'),'Canonical transaction validates catalog inside financial transaction');
ck(strpos($tx,"$".'pdo->beginTransaction()')<strpos($tx,'tamasyaCatalogTransactionIntent($pdo,$catalogIntent'),'Catalog verification happens after transaction starts');
ck(str_contains($tx,'tamasyaCatalogSaveTransactionSnapshot($pdo,$txId,$operationId,$catalogSnapshot)'),'Canonical transaction saves attribution atomically');
ck(strpos($tx,'tamasyaCatalogSaveTransactionSnapshot($pdo,$txId')<strpos($tx,'tamasyaFinancialCommit($pdo)',strpos($tx,'tamasyaCatalogSaveTransactionSnapshot($pdo,$txId')),'Snapshot is persisted before canonical commit');
ck(str_contains($tx,'tamasyaCatalogAssertTransactionReplay($pdo'),'Idempotency compares immutable item identity');
ck(str_contains($tx,'tamasyaCatalogTransactionHasSnapshot($pdo'),'Direct edits of attributed transactions blocked');
$finance=file_get_contents($root.'/assets/chunks/finance.js');
$catalog=file_get_contents($root.'/assets/universal-catalog-workspace.js');
ck(str_contains($finance,"schema:'tamasya-catalog-intent-v2'") && str_contains($finance,'catalogIntent:!qt&&!st&&!pa?catalogIntentRef.current'),'Cash form transmits explicit versioned item intent');
ck(str_contains($catalog,'quantity:String(quantity)'),'Draft carries precise quantity, not inferred from description');
ck(!str_contains($catalog,'tamasyaPostFinancialTransaction(') && !str_contains($catalog,"request('transactions'"),'Master catalog still cannot post directly');
$sync=file_get_contents($root.'/node_sync_support.php');$agent=file_get_contents($root.'/node_sync_agent.php');
ck(str_contains($sync,"'tamasya_catalog_transaction_lines'=>['pk'=>['transaction_id']]"),'Hybrid snapshot mirrors catalog receipt attribution');
ck(str_contains($agent,"str_starts_with(".'$'."table,'tamasya_catalog_')"),'Optional schema parity fails closed across nodes');
$schema=file_get_contents($root.'/migrations/20261009_R16_2_CATALOG_TRANSACTION_SNAPSHOTS.sql');
ck(str_contains($schema,'UNIQUE KEY uq_catalog_snapshot_operation') && str_contains($schema,'PRIMARY KEY'),'Transaction and operation duplicate keys enforced');
ck(!preg_match('/(?:ALTER|DROP|TRUNCATE|DELETE|REPLACE)\s+(?:TABLE|FROM)\s+(?:transactions|categories|subcategories)\b/i',$schema),'No destructive DDL on canonical data');
ck(str_contains($schema,'item_name VARCHAR(150)') && str_contains($schema,'unit_price_cents BIGINT'),'Snapshot stores immutable item label and price');
ck(str_contains($schema,'item_revision INT UNSIGNED'),'Snapshot records item revision to prevent stale metadata');
ck(str_contains($root.('/migrations/20261009_R16_2_CATALOG_TRANSACTION_SNAPSHOTS.sql'),'20261009_R16_2'),'Migrator release identity exists');
blocked(fn()=>tamasyaCatalogQuantityMilli('0.0001'),'Malformed precision fails closed');
blocked(fn()=>tamasyaCatalogPositiveMoneyCents('250000.300'),'Floating precision fails closed');
echo "CATALOG R16.2/16.3 SOURCE: $passed PASS, 0 FAIL\n";
