<?php
/** Safe read-only receipt health: test runtime with synthetic rows, never a production DB. */
if (PHP_SAPI !== 'cli') exit(1);
define('TAMASYA_API_ENTRY', true);
ob_start(); // Preserve headers while simulating the live route after earlier assertions.
class ReceiptHealthMockStatement extends PDOStatement {
    private array $row;
    protected function __construct(array $row) { $this->row = $row; }
    public static function fromRow(array $row): self { return new self($row); }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
class ReceiptHealthMockPDO extends PDO {
    public array $queries = [];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queries[] = $query;
        if (str_contains($query, 'OCTET_LENGTH(response_body)')) {
            return ReceiptHealthMockStatement::fromRow(['receipt_count'=>1640,'response_bytes'=>90576021,'processing_count'=>2,'uncertain_count'=>1,'failed_count'=>8,'bodies_over_30_days'=>11]);
        }
        if (str_contains($query, 'information_schema.TABLES')) return ReceiptHealthMockStatement::fromRow(['data_bytes'=>96000000,'index_bytes'=>5242880]);
        throw new RuntimeException('Unexpected query: '.$query);
    }
}
require dirname(__DIR__).'/api/support/077_receipt_storage_health.php';
$passed=0;
function receiptHealthCheck(bool $condition,string $label):void {global $passed;if(!$condition)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label.PHP_EOL;}
$pdo=new ReceiptHealthMockPDO();
$health=tamasyaReceiptStorageHealth($pdo);
receiptHealthCheck($health['receiptCount']===1640 && $health['logicalResponseBytes']===90576021,'Correct logical receipt size and count');
receiptHealthCheck($health['processingCount']===2 && $health['uncertainCount']===1 && $health['failedCount']===8,'Uncertain/processing/failed statuses remain visible');
receiptHealthCheck($health['bodiesOlderThan30Days']===11 && $health['estimatedTableIndexBytes']===5242880,'Aging and InnoDB estimates are reported separately');
receiptHealthCheck($health['cronRequired']===false && $health['canCompactFromThisEndpoint']===false,'Shared-hosting and VPS support without exposing a write action');
receiptHealthCheck(count($pdo->queries)===2 && !preg_grep('/\b(?:DELETE|UPDATE|INSERT|REPLACE|DROP|ALTER|TRUNCATE|OPTIMIZE)\b/i',$pdo->queries),'Inspection never mutates data or schema');
receiptHealthCheck(!str_contains(json_encode($health),'guestName')&&!str_contains(json_encode($health),'operation_id'),'No payloads, booking IDs or staff IDs in response');
$route=file_get_contents(dirname(__DIR__).'/api/routes/091_receipt_storage_health.php');
receiptHealthCheck(str_contains($route,"requireRoles(\$loggedInStaff, ['admin'])"),'Admin role is enforced by backend');
receiptHealthCheck(str_contains($route,"!== 'GET'") && str_contains($route,'405'),'Mutation methods denied explicitly');
receiptHealthCheck(str_contains($route,"tamasyaTableExists(\$pdo, 'request_operation_receipts')") && str_contains($route,'409'),'Missing schema handled fail-closed');
receiptHealthCheck(!str_contains($route,'echo $error->getMessage()'),'Exceptions never expose SQL/payload to browser');
$router=file_get_contents(dirname(__DIR__).'/api/router.php');
receiptHealthCheck(str_contains($router,"require __DIR__ . '/routes/091_receipt_storage_health.php'"),'Canonical router wires exact action');
$ui=file_get_contents(dirname(__DIR__).'/assets/system-health-addon.js');
receiptHealthCheck(str_contains($ui,'receiptButton.hidden=role()!==\'admin\'') && str_contains($ui,'loadReceiptStorageHealth'),'Button admin-only and opt-in');
receiptHealthCheck(str_contains($ui,"method:'GET'") && str_contains($ui,"headers.Authorization='Bearer '+token") && str_contains($ui,"headers['X-Tamasya-Hotel-Scope']=scope"),'Secure same-origin read, bearer and property scope preserved');
receiptHealthCheck(!str_contains($ui,"action=receipt-storage-compact")&&!str_contains($ui,"method:'POST'"),'No dangerous write button');
$index=file_get_contents(dirname(__DIR__).'/index.html');$sw=file_get_contents(dirname(__DIR__).'/sw.js');$loader=file_get_contents(dirname(__DIR__).'/assets/runtime-addon-loader.js');
$version='20261009-r164-receipt-health';
receiptHealthCheck(str_contains($index,'runtime-addon-loader.js?v='.$version) && str_contains($sw,'runtime-addon-loader.js?v='.$version) && str_contains($loader,"'assets/system-health-addon.js', '".$version."'"),'Loader, precache and health addon versioned coherently');
// Execute the actual route on mock PDO, not just string-check its content.
function requireRoles($staff, $roles): void {
    if (!in_array((string)($staff['role']??''), $roles, true)) throw new DomainException('denied');
}
function tamasyaTableExists(PDO $pdo, string $table): bool { return $GLOBALS['tableAvailable']; }
function tamasyaJsonEncode(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE); }
function tamasyaRuntimeSetStage(string $stage): void { $GLOBALS['lastStage']=$stage; }
function tamasyaRuntimeCaptureThrowable(Throwable $e): void { $GLOBALS['capturedError']=get_class($e); }
function tamasyaRuntimeRequestId(): string { return 'req_synthetic_only'; }
function invokeReceiptHealthRoute(string $method, string $role, bool $tableExists=true): array {
    global $action,$pdo,$loggedInStaff,$routeHandled;
    $action='receipt-storage-health';
    $loggedInStaff=['role'=>$role];
    $pdo=new ReceiptHealthMockPDO();
    $GLOBALS['tableAvailable']=$tableExists;
    $_SERVER['REQUEST_METHOD']=$method;
    http_response_code(200);
    ob_start();
    try {
        include dirname(__DIR__).'/api/routes/091_receipt_storage_health.php';
        return [http_response_code(), ob_get_clean(),count($pdo->queries)];
    } catch(Throwable $e) {
        ob_end_clean();
        return [403, $e->getMessage(),count($pdo->queries)];
    }
}
[$status,$response,$qcount]=invokeReceiptHealthRoute('GET','admin');
$decoded=json_decode($response,true);
receiptHealthCheck($status===200&&$qcount===2&&($decoded['success']??false)===true&&($decoded['readOnly']??false)===true,'Actual admin GET produces expected safe envelope');
[$status,$response,$qcount]=invokeReceiptHealthRoute('GET','owner');
receiptHealthCheck($status===403&&$qcount===0,'Actual owner GET denied before any database inspection');
[$status,$response,$qcount]=invokeReceiptHealthRoute('POST','admin');
receiptHealthCheck($status===405&&$qcount===0,'Actual POST denied and no DB query issued');
[$status,$response,$qcount]=invokeReceiptHealthRoute('GET','admin',false);
receiptHealthCheck($status===409&&$qcount===0,'Actual missing receipt schema returns 409 without querying storage');
echo 'Receipt health regression: '.$passed.' PASS, 0 FAIL'.PHP_EOL;
ob_end_flush();
