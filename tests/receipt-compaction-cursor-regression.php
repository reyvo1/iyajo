<?php
/** Cursor progression + byte-exact CAS in disposable mock PDO; no production DB. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
define('TAMASYA_RECEIPT_COMPACTION_LIBRARY',true);
require dirname(__DIR__).'/receipt_storage_maintenance.php';
final class CursorStatement extends PDOStatement {
    private CursorPDO $db;
    private string $sql;
    private array $results=[];
    private int $changed=0;
    protected function __construct(CursorPDO $db,string $sql){$this->db=$db;$this->sql=$sql;}
    public static function build(CursorPDO $db,string $sql):self{return new self($db,$sql);}
    public function execute(?array $params=null):bool {
        $params??=[];
        if(str_starts_with($this->sql,'SELECT ')){
            if(!str_contains($this->sql,'AND operation_id > ? ORDER BY operation_id LIMIT '))throw new RuntimeException('No stable seek pagination');
            if($params===[]||count($params)!==1)throw new RuntimeException('No bound cursor');
            $after=$params[0];preg_match('/LIMIT (\\d+)$/',$this->sql,$m);$limit=(int)($m[1]??0);
            $this->results=[];
            foreach($this->db->rows as $id=>$r){
                if(strcmp($id,$after)<=0)continue;
                if(!in_array($r['status'],['completed','failed','rejected'],true))continue;
                if(strlen($r['response_body'])<4096)continue;
                if(str_contains($this->sql,"response_body NOT LIKE")&&str_starts_with($r['response_body'],'@tamasya:gzip-base64:'))continue;
                if(str_contains($this->sql,"action IN (")&&!in_array($r['action'],['transactions','notifications-read'],true))continue;
                $this->results[]=['operation_id'=>$id,'action'=>$r['action'],'response_body'=>$r['response_body']];
                if(count($this->results)===$limit)break;
            }
            return true;
        }
        if(!str_contains($this->sql,'BINARY response_body=BINARY ?'))throw new RuntimeException('Compare-and-swap missing');
        [$next,$id,$expected]=$params;
        if(!$this->db->forceCasConflict && isset($this->db->rows[$id])&&$this->db->rows[$id]['response_body']===$expected){
            $this->db->rows[$id]['response_body']=$next;$this->changed=1;
        }
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->results;}
    public function rowCount():int{return $this->changed;}
}
final class CursorPDO extends PDO {
    public array $rows=[];public bool $transaction=false;public bool $forceCasConflict=false;
    public function __construct(){}
    public function inTransaction():bool{return $this->transaction;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return CursorStatement::build($this,$query);}
}
function cursorCheck(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('FAIL '.$msg);echo 'PASS '.$msg.PHP_EOL;}
$pdo=new CursorPDO();
$raw=json_encode(['success'=>true,'payload'=>str_repeat('DEDUPE-RESP-',1000)]);
$already=tamasyaEncodeReceiptResponseBody($raw);
// First two large rows are not eligible, the third needs compression.
$pdo->rows=['op-01'=>['status'=>'completed','action'=>'bookings','response_body'=>$already],
            'op-02'=>['status'=>'completed','action'=>'bookings','response_body'=>random_bytes(6000)],
            'op-03'=>['status'=>'completed','action'=>'bookings','response_body'=>$raw],
            'op-04'=>['status'=>'processing','action'=>'bookings','response_body'=>$raw]];
$baseline=$pdo->rows;
$p1=tamasyaCompactReceiptStorage($pdo,false,1,false,'');
$p2=tamasyaCompactReceiptStorage($pdo,false,1,false,$p1['nextCursor']);
$p3=tamasyaCompactReceiptStorage($pdo,false,1,false,$p2['nextCursor']);
cursorCheck($p1['nextCursor']==='op-02'&&$p1['eligible']===0,'First scanning batch may be ineligible but advances cursor');
cursorCheck($p2['nextCursor']==='op-03'&&$p2['eligible']===1&&$p2['bytesSaved']>0,'Second batch reaches eligible receipt without starvation');
cursorCheck($p3['scanExhausted']===true&&$p3['scanned']===0,'Cursor exhausts finite history');
cursorCheck($pdo->rows===$baseline,'Dry run leaves all response bytes and metadata untouched');
try{tamasyaCompactReceiptStorage($pdo,true,1,false,'');throw new RuntimeException('UNSAFE apply accepted');}catch(RuntimeException $e){cursorCheck($e->getMessage()==='Apply requires an authority-checked transaction.','Apply without a transaction fails closed');}
$pdo->transaction=true;
$applied=tamasyaCompactReceiptStorage($pdo,true,1,false,$p1['nextCursor']);
cursorCheck($applied['updated']===1&&tamasyaDecodeReceiptResponseBody($pdo->rows['op-03']['response_body'])===$raw,'Apply uses CAS and exact decoded replay');
cursorCheck($pdo->rows['op-01']===$baseline['op-01']&&$pdo->rows['op-02']===$baseline['op-02']&&$pdo->rows['op-04']===$baseline['op-04'],'Unrelated, already-compressed and processing rows are unchanged');
$pdo->forceCasConflict=true;
$pdo->rows['op-05']=['status'=>'completed','action'=>'bookings','response_body'=>$raw];
$conflict=tamasyaCompactReceiptStorage($pdo,true,1,false,'op-04');
cursorCheck($conflict['eligible']===1 && $conflict['updated']===0 && $conflict['casConflicts']===1 && $conflict['bytesSaved']===0,
    'Concurrent CAS rejection cannot be counted as bytes actually saved');
cursorCheck($pdo->rows['op-05']['response_body']===$raw,'Concurrent CAS failure leaves business receipt untouched');
$pdo->forceCasConflict=false;
foreach([0,1001] as $bad){try{tamasyaCompactReceiptStorage($pdo,false,$bad);throw new RuntimeException('BAD LIMIT ACCEPTED');}catch(InvalidArgumentException $e){cursorCheck(true,'Invalid scan limit rejected');}}
try{tamasyaCompactReceiptStorage($pdo,false,1,false,"invalid\ncursor");throw new RuntimeException('BAD CURSOR ACCEPTED');}catch(InvalidArgumentException $e){cursorCheck(true,'Control-character cursor rejected');}
echo "CURSOR COMPACTION REGRESSION: PASS\n";
