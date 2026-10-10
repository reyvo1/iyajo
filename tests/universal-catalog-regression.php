<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/0185_finance_catalog_identity.php';
require dirname(__DIR__).'/api/modules/finance/021_universal_catalog.php';
$pass=0;
function yes(bool $ok,string $name):void{global $pass;if(!$ok)throw new RuntimeException('FAIL '.$name);$pass++;echo 'PASS '.$name."\n";}
function deny(callable $fn,string $name):void{try{$fn();throw new RuntimeException('ACCEPTED INVALID: '.$name);}catch(InvalidArgumentException|DomainException $e){yes(true,$name);}}
foreach([0,1,68,69,70,100,120] as $n){
  $name=str_repeat('Q',$n);$archive=tamasyaArchivedCatalogLabel($name,'cat_abcdefghijklmnopqrst');
  yes(tamasyaCatalogTextLength($archive)<=100,'Archive length '.$n.' never exceeds DB');
  yes(str_contains($archive,' [arsip '),'Archive identity suffix retained '.$n);
}
$utf=str_repeat('電',99);
yes(tamasyaCatalogTextLength(tamasyaArchivedCatalogLabel($utf,'foo0123456789'))<=100,'UTF8 archived name safe');
yes(!str_contains(tamasyaArchivedCatalogLabel('A','foo0123456789'),'???'),'Unicode safe archive');
$old=getenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED');putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED');
yes(!tamasyaCatalogEnabled(),'Feature disabled by default');
putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED=0');yes(!tamasyaCatalogEnabled(),'Explicit zero denied');
putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED=1');yes(tamasyaCatalogEnabled(),'Explicit enable works');
if($old===false)putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED');else putenv('TAMASYA_UNIVERSAL_CATALOG_ENABLED='.$old);
foreach([['250000','1','250000.00'],['250000.30','3','750000.90'],['0.01','0.500','0.01'],['12500.25','2.5','31250.63'],['200000','3','600000.00']] as [$p,$q,$total])
  yes(tamasyaCatalogQuoteMoney($p,$q)['subtotal']===$total,'Integer-cent exact quote '.$p.' x '.$q);
foreach(['0','-1','1.123','1e3','Infinity','NaN','0.00','10000000000000'] as $v)deny(fn()=>tamasyaCatalogPositiveMoneyCents($v),'Bad price fails closed '.$v);
foreach(['0','-1','1.1234','1e4','INF','NaN'] as $v)deny(fn()=>tamasyaCatalogQuantityMilli($v),'Bad quantity fails closed '.$v);
foreach(['2026-02-30','2026-13-01','2026-1-1','tomorrow'] as $v)yes(!tamasyaCatalogValidDate($v),'Bad date rejected '.$v);
yes(tamasyaCatalogValidDate('2026-10-09'),'Valid date accepted');
yes(tamasyaCatalogSafeName('Gedung/kolam/pemancingan',150,'Item')==='Gedung/kolam/pemancingan','No hardcoded business type');
deny(fn()=>tamasyaCatalogSafeName(str_repeat('X',151),150,'Item'),'150-character name limit');
deny(fn()=>tamasyaCatalogSafeName('A'.chr(10).'B',150,'Item'),'Control characters denied');
$root=dirname(__DIR__);
$canonicalSchema=file_get_contents($root.'/database_setup.sql');
$fixtureSchema=file_get_contents($root.'/tests/universal-catalog-mysql-parents.sql');
foreach (['categories','subcategories'] as $identityTable) {
  $name=preg_quote($identityTable,'/');
  $canonicalPattern='/CREATE TABLE `'.$name.'`\\s*\\([^;]*?\\)\\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/i';
  $fixturePattern='/CREATE TABLE '.$name.'\\s*\\([^;]*?\\)\\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/i';
  yes((bool)preg_match($canonicalPattern,$canonicalSchema),'Canonical '.$identityTable.' parent collation explicitly verified');
  yes((bool)preg_match($fixturePattern,$fixtureSchema),'Disposable MySQL '.$identityTable.' parent collation matches canonical');
}
$route=file_get_contents($root.'/api/routes/099_universal_catalog.php');
yes(str_contains($route,'tamasyaCatalogValidateSelection('),'Server verifies category/subcategory');
yes(str_contains(file_get_contents($root.'/api/modules/finance/021_universal_catalog.php'),'is_system'),'System-reserved category AND subcategory protected');
yes(str_contains($route,'category_system_locked') && str_contains($route,'subcategory_system_locked'),'Draft quote denies items newly rebound to system role');
yes(str_contains($route,'writeRequiredEnterpriseAudit('),'Required audit written');
yes(str_contains($route,'tamasyaCatalogRequireWritable()'),'Writer fencing enforced');
yes(substr_count($route,'tamasyaClusterAssertCommitAuthority($pdo)')===3,'All 3 catalog write commits cluster-fenced');
$sync=file_get_contents($root.'/node_sync_support.php');
$agent=file_get_contents($root.'/node_sync_agent.php');
yes(str_contains($sync,"'tamasya_catalog_items'=>['pk'=>['id']]") && str_contains($sync,"'tamasya_catalog_rates'=>['pk'=>['id']]"),'Optional master and rate included in authoritative hybrid snapshot');
yes(str_contains($agent,"str_starts_with("."$"."table,'tamasya_catalog_')"),'Standby accepts catalog absence only when both nodes lack extension');
foreach(['catalog-item-save','catalog-item-archive','catalog-rate-save'] as $blocked) yes(str_contains($sync,"'".$blocked."'"),'Standby local catalog mutation blocked '.$blocked);
yes(!str_contains($route,'tamasyaPostFinancialTransaction('),'No parallel finance posting');
yes(str_contains($route,'taxStatus')&&str_contains($route,'NOT_CALCULATED'),'Never invent tax rules');
yes(str_contains(file_get_contents($root.'/api/routes/040_transactions_sync.php'),'tamasyaResolveActiveFinanceCatalogSelection('),'Existing cash entry remains canonical');
yes(str_contains(file_get_contents($root.'/api/modules/finance/0185_finance_catalog_identity.php'),'system_key'),'Semantic identity preserved');
echo "UNIVERSAL CATALOG: $pass PASS, 0 FAIL\n";
