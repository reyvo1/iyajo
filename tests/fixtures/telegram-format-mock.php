<?php
// Local-only mock server; never uses a real token or production URL.
$payload=json_decode(file_get_contents('php://input'),true)?:[];
$chat=(string)($payload['chat_id']??'');
$method=(string)basename((string)($_SERVER['REQUEST_URI']??''));
file_put_contents((string)getenv('TAMASYA_TEST_LOG_FILE'),json_encode(['chat'=>$chat,'method'=>$method,'payload'=>$payload],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
$code=200;$description='';
if (in_array($chat,['1001','1010','1011'],true) && isset($payload['parse_mode'])) {
    $code=400;$description="Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 112";
} elseif ($chat==='1002') { $code=401;$description='Unauthorized'; }
elseif ($chat==='1003') {
    $code=isset($payload['parse_mode'])?400:503;
    $description=isset($payload['parse_mode'])?"Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 112":'Service Unavailable';
} elseif ($chat==='1005' || ($chat==='1006' && !isset($payload['parse_mode']))) {
    $code=400;$description='Bad Request: message is not modified: specified new message content and reply markup are exactly the same';
} elseif ($chat==='1006' || $chat==='1007') {
    $code=400;$description="Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 112";
} elseif ($chat==='1008') {$code=400;$description='Bad Request: BUTTON_DATA_INVALID';}
header('Content-Type: application/json');http_response_code($code);
echo json_encode($code>=400?['ok'=>false,'error_code'=>$code,'description'=>$description]:['ok'=>true,'result'=>['message_id'=>$payload['message_id']??11]]);
