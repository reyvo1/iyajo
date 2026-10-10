<?php
/** Regression: caught webhook HTTP 500 must retain safe error metadata, not NULL. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY',true);
define('TAMASYA_APP_ROOT',dirname(__DIR__));
function tamasyaAssertTablesExist(PDO $pdo,array $tables,string $scope):void {
    if($tables !== ['runtime_request_events'])throw new RuntimeException('Wrong telemetry schema check');
}
function tamasyaJsonEncode($value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
require __DIR__.'/../api/support/005_runtime_observability.php';
final class TelemetryStatement extends PDOStatement {
    public static array $values = [];
    public function __construct() {}
    public function execute(?array $params = null):bool {self::$values=$params??[];return true;}
}
final class TelemetryPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(!str_contains($query,'INSERT INTO runtime_request_events'))throw new RuntimeException('Unexpected telemetry query');
        return new TelemetryStatement();
    }
}
function assertAudit(bool $condition,string $name):void {if(!$condition)throw new RuntimeException('FAIL: '.$name);echo 'PASS '.$name."\n";}
$webhook=file_get_contents(__DIR__.'/../api/routes/080_telegram_webhook.php');
assertAudit(str_contains($webhook,"tamasyaRuntimeCaptureThrowable(\$e, 'telegram:webhook_processing')"),'caught Telegram errors propagate metadata');
assertAudit(str_contains($webhook,'http_response_code(500)'),'Telegram webhook still fails closed');
assertAudit(str_contains($webhook,"telegramApiCallRequired(\$token,'sendMessage'"),'required Telegram delivery unchanged');
$_SERVER['REQUEST_METHOD']='POST';
tamasyaRuntimeInit();tamasyaRuntimeSetAction('telegram-webhook');
try{throw new RuntimeException('token=secret_password guest_identity=DO_NOT_PERSIST');}catch(Throwable $error){tamasyaRuntimeCaptureThrowable($error,'telegram:webhook_processing');}
assertAudit(($GLOBALS['tamasya_runtime_failed_stage']??'')==='telegram:webhook_processing','failure stage retained');
assertAudit(($GLOBALS['tamasya_runtime_caught_error_class']??'')==='RuntimeException','exception class retained');
assertAudit(($GLOBALS['tamasya_runtime_source_file']??'')==='tests/telegram-webhook-telemetry-regression.php','relative file recorded');
tamasyaRuntimePersist(new TelemetryPDO(),500,null,'Telegram webhook gagal (detail di server log)');
$v=TelemetryStatement::$values;
assertAudit(count($v)===19,'telemetry database parameter count unchanged');
assertAudit($v[2]==='telegram-webhook'&&$v[6]===500&&$v[7]==='server_error','HTTP 500 classification preserved');
assertAudit($v[5]==='telegram:webhook_processing'&&$v[13]==='RuntimeException','stage and class stored');
assertAudit($v[14]==='tests/telegram-webhook-telemetry-regression.php'&&(int)$v[15]>0,'safe source and line stored');
assertAudit(!str_contains(json_encode($v),'secret_password'),'raw exception never enters telemetry');
assertAudit($v[17]===null,'non-database exception does not invent SQLSTATE');
echo "TOTAL: 12 PASS; 0 FAIL\n";
