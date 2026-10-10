<?php
/** Test real SQL-driven cleanup safety with deliberately hostile fake PDO rows. */
if(PHP_SAPI!=='cli')exit(2);
define('TAMASYA_API_ENTRY',true);
function tamasyaTableExists(PDO $pdo,string $table):bool {return in_array($table,['room_access_control','smart_lock_jobs'],true);}
require dirname(__DIR__).'/api/modules/finance/100_cleanup_tools.php';
class CustodySQL extends PDOStatement {
    private array $rows=[];
    public function __construct(private CustodyFakeDb $db,private string $sql){}
    public function execute(?array $params=null):bool {
        if(str_contains($this->sql,'FROM room_access_control'))$this->rows=$this->db->roomRows;
        elseif(str_contains($this->sql,'FROM smart_lock_jobs'))$this->rows=$this->db->jobRows;
        elseif(str_contains($this->sql,'FROM bookings'))$this->rows=$this->db->bookingRows;
        else throw new RuntimeException('Unexpected SQL '.$this->sql);
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->rows[0]??false;}
}
class CustodyFakeDb extends PDO {
    public array $roomRows=[],$jobRows=[],$bookingRows=[];
    public array $sql=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {$this->sql[]=$query;return new CustodySQL($this,$query);}
}
function checkCustodyCase(array $rooms,array $jobs,array $bookings,bool $allow,string $name):void{
    $db=new CustodyFakeDb();$db->roomRows=$rooms;$db->jobRows=$jobs;$db->bookingRows=$bookings;
    $denied=false;try{tamasyaR164AssertCleanupCustodySafe($db,['bk1'],true);}catch(RuntimeException $e){$denied=true;}
    if($denied===$allow)throw new RuntimeException('FAIL '.$name);
    if(!str_contains(implode(' ',$db->sql),'FOR UPDATE'))throw new RuntimeException('Missing transaction row lock');
    echo 'PASS '.$name.PHP_EOL;
}
checkCustodyCase([['room_number'=>'101','physical_key_status'=>'secured','current_booking_id'=>null]],[],[],true,'Unbound secured key can be purged');
checkCustodyCase([['room_number'=>'101','physical_key_status'=>'issued','current_booking_id'=>'bk1']],[],[],false,'Issued key cannot be force-purged');
checkCustodyCase([['room_number'=>'101','physical_key_status'=>'missing','current_booking_id'=>null]],[],[],false,'Missing key never silently restored');
checkCustodyCase([['room_number'=>'101','physical_key_status'=>'secured','current_booking_id'=>'bk1']],[],[],false,'Pre-bound legacy key blocks destructive cleanup');
checkCustodyCase([], [['id'=>'job1','status'=>'uncertain']], [], false,'Unknown or uncertain smart-lock job blocks deletion');
checkCustodyCase([], [], [['id'=>'bk1','keyControlStatus'=>'pending_smart_revoke']], false,'Pending revoke booking cannot be purged');
$src=file_get_contents(dirname(__DIR__).'/api/routes/030_ota_cleanup_audit.php');
if(substr_count($src,'tamasyaR164AssertCleanupCustodySafe($pdo,')!==2)throw new RuntimeException('Both destructive cleanup paths must enforce hard custody gate');
if(str_contains($src,"physical_key_status=IF(physical_key_status='issued','secured',physical_key_status)"))throw new RuntimeException('Cleanup must not invent physical key return');
echo 'PASS cleanup both APIs fail closed'.PHP_EOL;
