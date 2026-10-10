<?php
declare(strict_types=1);
// No hotel DB, no real Telegram: local mock only, must run under CLI.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
function tamasyaExternalSideEffectsAllowed(): bool {return true;}
function decryptStoredSecret($token) {return (string)$token;}
require dirname(__DIR__).'/api/modules/comms/050_integrations_telegram_mail.php';
require dirname(__DIR__).'/api/channels/CommunicationChannelAdapter.php';
require dirname(__DIR__).'/api/channels/TelegramCompatibilityAdapter.php';
$n=0;
function chk(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('FAILED: '.$label);$n++;echo 'PASS '.$label."\n";}
function calls(string $chat):array {
    $lines=file((string)getenv('TAMASYA_TEST_LOG_FILE'),FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
    return array_values(array_filter(array_map(static fn($line)=>json_decode($line,true),$lines),static fn($row)=>($row['chat']??'')===$chat));
}
function payload(string $chat):array {
    return ['chat_id'=>$chat,'message_id'=>991,'text'=>"*MENU*\nTamu: punya_underscore\n`kode",'parse_mode'=>'Markdown','reply_markup'=>['inline_keyboard'=>[[['text'=>'🔑 Konfirmasi Kunci','callback_data'=>'key:test:1']]]]];
}
$r=telegramApiCallRequired('LOCAL','editMessageText',payload('1001'),true);
chk(!empty($r['ok']) && !empty($r['plain_text_format_recovery']),'editMessageText recovers only malformed Markdown');
$c=calls('1001');chk(count($c)===2,'exactly two Telegram API attempts, no hotel operation replay');
chk(($c[1]['payload']['text']??'')===payload('1001')['text'],'original message not changed');
chk(($c[1]['payload']['reply_markup']??null)===payload('1001')['reply_markup'],'inline buttons and callback payload preserved');
chk(($c[1]['payload']['chat_id']??null)==='1001' && ($c[1]['payload']['message_id']??null)===991,'message and chat identity preserved');
chk(!isset($c[1]['payload']['parse_mode'])&&!isset($c[1]['payload']['entities']),'invalid formatting removed for recovery only');
$r=telegramApiCallRequired('LOCAL','sendMessage',payload('1001'));
chk(!empty($r['plain_text_format_recovery'])&&count(calls('1001'))===4,'sendMessage callback response also recovers');
$r=telegramApiCallRequired('LOCAL','editMessageText',payload('1004'));
chk(!empty($r['ok'])&&!isset($r['plain_text_format_recovery'])&&count(calls('1004'))===1,'normal valid Telegram request is not replayed');
foreach(['1002','1003','1008'] as $chat){
    $failed=false;try{telegramApiCallRequired('LOCAL','editMessageText',payload($chat));}catch(RuntimeException $e){$failed=true;}
    chk($failed,'non-parse/auth/recovery failures stay fail-closed: '.$chat);
}
chk(count(calls('1002'))===1&&count(calls('1008'))===1&&count(calls('1003'))===2,'auth and button errors not retried; format retry once only');
$r=telegramApiCallRequired('LOCAL','editMessageText',payload('1005'),true);
chk(!empty($r['harmless'])&&count(calls('1005'))===1,'unchanged edit allowed only for explicit edit case');
$r=telegramApiCallRequired('LOCAL','editMessageText',payload('1006'),true);
chk(!empty($r['harmless'])&&count(calls('1006'))===2,'unchanged edit after safe plain-text recovery');
$failed=false;try{telegramApiCallRequired('LOCAL','answerCallbackQuery',payload('1007'));}catch(RuntimeException $e){$failed=true;}
chk($failed&&count(calls('1007'))===1,'no fallback for callback acknowledgement');
chk(tamasyaTelegramHasMalformedEntitiesResponse(['httpCode'=>400,'data'=>['description'=>"Bad Request: can't parse entities"]]),'matches real error class');
chk(!tamasyaTelegramHasMalformedEntitiesResponse(['httpCode'=>500,'data'=>['description'=>"Bad Request: can't parse entities"]]),'must have explicit HTTP 400 to retry');
$r=tamasyaTelegramApiCallWithFormatRecovery('LOCAL','sendMessage',payload('1010'),4);
chk(!empty($r['plain_text_format_recovery'])&&count(calls('1010'))===2,'legacy broadcast transport recovers without duplicates');
final class TelegramMockStatement extends PDOStatement {
    public function __construct() {}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return ['telegram_bot_token'=>'LOCAL'];}
}
final class TelegramMockPDO extends PDO {
    public function __construct() {}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {return new TelegramMockStatement();}
}
$adapter=new TamasyaTelegramCompatibilityAdapter();
$send=$adapter->send(new TelegramMockPDO(),[],['providerConversationId'=>'1011'],['text'=>payload('1011')['text'],'parseMode'=>'Markdown','replyMarkup'=>payload('1011')['reply_markup']]);
chk(!empty($send['success'])&&($send['status']??'')==='sent'&&count(calls('1011'))===2,'communication outbox adapter recovers outbound key/room notifications');
chk((calls('1011')[1]['payload']['reply_markup']??null)===payload('1011')['reply_markup'],'adapter keeps outbound inline buttons intact');
$webhook=file_get_contents(dirname(__DIR__).'/api/routes/080_telegram_webhook.php');
chk(str_contains($webhook,"if (\$callbackData === 'main_menu') unset(\$editPayload['parse_mode']);"),'main menu sends property name as plain text by design');
chk(str_contains($webhook,'tamasyaTelegramCompactReplyMarkup')&&str_contains($webhook,'claimTelegramUpdate'),'callback markup and idempotent update claim still intact');
$webRoutes=file_get_contents(dirname(__DIR__).'/api/routes/090_operations_communications.php');
chk(str_contains($webRoutes,"$"."command === 'key-issue'")&&str_contains($webRoutes,'require_payment_before_key_issue'),'key issue payment authorization remains enforced by the web API');
echo "TOTAL: {$n} PASS; 0 FAIL\n";
