<?php
/** P1 integration-contract exercise of canonical Web/Telegram room-access mutation.
 * No external DB or real Telegram token required. Deliberately fail-closed on mocks.
 */
if(PHP_SAPI!=='cli')exit(1);
define('TAMASYA_API_ENTRY',true);
require_once __DIR__.'/../api/support/091_room_access_issue.php';
$GLOBALS['keyTest']=['ledger'=>10.0,'policy'=>1,'cap'=>true,'bookingMode'=>'physical','bookingStatus'=>'not_issued','conflict'=>false,'duplicate'=>false,'shift'=>true,'events'=>[],'audits'=>0,'revision'=>0];
function requireCapability(array $staff,string $ability,array $roles): void {if(!$GLOBALS['keyTest']['cap'] || !in_array($staff['role']??'', $roles,true))throw new RuntimeException('Forbidden');}
function claimTelegramMutation($pdo,$op,$staff,$type,$entity,$payload,$action): bool {if($GLOBALS['keyTest']['duplicate'])throw new TelegramDuplicateOperationException('Duplicate');$GLOBALS['keyTest']['events'][]='claim';return true;}
class TelegramDuplicateOperationException extends RuntimeException {}
function completeTelegramMutation($pdo,$op,$result): void {$GLOBALS['keyTest']['events'][]='complete';}
function tamasyaRequireOpenShiftForRoomAccessIssue($pdo,array $staff,?array $settings=null): ?string {if(!$GLOBALS['keyTest']['shift'])throw new RuntimeException('Open shift required');$GLOBALS['keyTest']['events'][]='shift';return 'shift-1';}
function bookingLedgerTotals($pdo,$bookingId): array {$GLOBALS['keyTest']['events'][]='ledger';return ['net'=>$GLOBALS['keyTest']['ledger']];}
function insertRoomKeyEvent($pdo,array $actor,string $room,?string $bookingId,string $eventType,string $mode,?string $keyRef,?string $last4,?string $status,string $reason=''): string {$GLOBALS['keyTest']['events'][]='keyevent';return 'evt1';}
function writeRequiredEnterpriseAudit($pdo,array $actor,string $action,string $table,string $id,$before,$after,$source='web',$extra=[]): void {$GLOBALS['keyTest']['audits']++;$GLOBALS['keyTest']['events'][]='audit';}
function bumpServerRevision($pdo): void {$GLOBALS['keyTest']['revision']++;}
function tamasyaFinancialCommit($pdo): void {$pdo->commit();}
function currentStaffLabel($staff): string {return 'Operator';}
class FakeKeyStmt extends PDOStatement {
    private string $sql;
    public function __construct(string $sql){$this->sql=$sql;}
    public function execute(?array $args=null): bool {
        if(preg_match('/\b(?:UPDATE|INSERT|DELETE)\b/i',$this->sql))$GLOBALS['keyTest']['events'][]='sql_write';
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
        $t=$GLOBALS['keyTest'];
        if(str_contains($this->sql,'FROM bookings b JOIN rooms r'))return [
            'id'=>'b1','roomNumber'=>'101','guestName'=>'Simulated Guest','status'=>'active',
            'access_mode'=>$t['bookingMode'],'keyControlStatus'=>$t['bookingStatus'],
            'physical_key_status'=>$t['conflict']?'issued':'secured',
            'current_booking_id'=>$t['conflict']?'b-other':null,'physical_key_ref'=>'KEY-101'
        ];
        if(str_contains($this->sql,'FROM hotel_operational_settings'))return ['require_payment_before_key_issue'=>$t['policy'],'require_open_shift_for_sale'=>1];
        if(str_contains($this->sql,'FROM bookings WHERE id='))return ['id'=>'b1','keyControlStatus'=>'issued'];
        return false;
    }
}
class FakeKeyPDO extends PDO {
    private bool $tx=false;
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new FakeKeyStmt($query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {return new FakeKeyStmt($query);}
    public function inTransaction(): bool {return $this->tx;}
    public function beginTransaction(): bool {$this->tx=true;$GLOBALS['keyTest']['events'][]='begin';return true;}
    public function commit(): bool {$this->tx=false;$GLOBALS['keyTest']['events'][]='commit';return true;}
    public function rollBack(): bool {$this->tx=false;$GLOBALS['keyTest']['events'][]='rollback';return true;}
}
function assertKey(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo 'PASS: '.$message.PHP_EOL;}
function resetKeyTest(): void {$GLOBALS['keyTest']=array_merge($GLOBALS['keyTest'],['ledger'=>10.0,'policy'=>1,'cap'=>true,'bookingMode'=>'physical','bookingStatus'=>'not_issued','conflict'=>false,'duplicate'=>false,'shift'=>true,'events'=>[],'audits'=>0,'revision'=>0]);}
$staff=['id'=>'s1','role'=>'receptionist'];
resetKeyTest();$r=tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','Handover','telegram','op1',true);
$e=$GLOBALS['keyTest']['events'];
assertKey($r['mode']==='physical'&&$r['confirmed']&&$r['code']===null,'Telegram physical handover creates no PIN');
assertKey($GLOBALS['keyTest']['audits']===1&&$GLOBALS['keyTest']['revision']===1,'Audit and revision committed exactly once');
assertKey(array_search('claim',$e)<array_search('sql_write',$e)&&array_search('complete',$e)<array_search('commit',$e),'Idempotency journal and custody mutation are atomic');
resetKeyTest();$GLOBALS['keyTest']['ledger']=0;
try{tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','x','telegram','op2',true);throw new RuntimeException('Payment guard bypassed');}catch(RuntimeException $e){assertKey(str_contains($e->getMessage(),'pembayaran')&&$GLOBALS['keyTest']['audits']===0,'Payment guard blocks handover without receipt');}
resetKeyTest();$GLOBALS['keyTest']['shift']=false;
try{tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','x','telegram','op3',true);throw new RuntimeException('Shift guard bypassed');}catch(RuntimeException $e){assertKey(str_contains($e->getMessage(),'shift')&&$GLOBALS['keyTest']['audits']===0,'Open-shift requirement blocks receptionist');}
resetKeyTest();$GLOBALS['keyTest']['bookingMode']='smart';
try{tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','x','telegram','op4',true);throw new RuntimeException('Smart mode bypassed');}catch(RuntimeException $e){assertKey(str_contains($e->getMessage(),'smart-lock')&&!in_array('keyevent',$GLOBALS['keyTest']['events'],true),'Telegram cannot issue smart PIN without secure bridge');}
resetKeyTest();$GLOBALS['keyTest']['conflict']=true;
try{tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','x','telegram','op5',true);throw new RuntimeException('Custody guard bypassed');}catch(RuntimeException $e){assertKey(str_contains($e->getMessage(),'booking lain'),'Custody conflict blocks handover');}
resetKeyTest();$GLOBALS['keyTest']['duplicate']=true;
try{tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','x','telegram','op6',true);throw new RuntimeException('Duplicate guard bypassed');}catch(TelegramDuplicateOperationException $e){assertKey(!in_array('sql_write',$GLOBALS['keyTest']['events'],true),'Replay cannot write a second key issuance');}
resetKeyTest();$GLOBALS['keyTest']['cap']=false;
try{tamasyaIssueRoomAccess(new FakeKeyPDO(),$staff,'b1','x','telegram','op7',true);throw new RuntimeException('Auth guard bypassed');}catch(RuntimeException $e){assertKey(!in_array('begin',$GLOBALS['keyTest']['events'],true),'Unauthorized staff denied before transaction begins');}
