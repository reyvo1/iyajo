<?php
/** A bridge destination must not contain embedded credentials or IP literals. */
if(PHP_SAPI!=='cli')exit(1);
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/channels/CommunicationChannelAdapter.php';
require dirname(__DIR__).'/api/channels/StandardWebhookAdapter.php';
function checkBridge(bool $condition,string $why):void{if(!$condition)throw new RuntimeException($why);}
foreach(['https://bridge.example.test/send','https://delivery.example.org:8443/queue','HTTPS://bridge.example.com/v1'] as $url){
    checkBridge(tamasyaStandardWebhookUrlIsSafe($url),'Valid HTTPS bridge was rejected '.$url);
}
foreach([
    'http://bridge.example.com/send','https://127.0.0.1/send','https://10.0.0.5/send',
    'https://[::1]/send','https://localhost/send','https://printer.local/api',
    'https://service.internal/sync','https://user:secret@bridge.example.com/send',
    'https://bridge.example.com/send#fragment','https://bridge.example.com./api',
    'https://bad..example.com/api','https://bridge.example.com\nX-Injection: 1','https://bridge/send',
] as $url){
    checkBridge(!tamasyaStandardWebhookUrlIsSafe($url),'Unsafe bridge URL accepted '.$url);
}
$adapter=new TamasyaStandardWebhookAdapter();
checkBridge(!$adapter->validateConfiguration(['outboundUrl'=>'https://127.0.0.1/send'])['valid'],'Configuration allows private IP literal');
checkBridge($adapter->validateConfiguration(['outboundUrl'=>'https://bridge.example.test/send'])['valid'],'Valid provider URL blocked');
echo "PASS R16.4 outbound bridge URL validation\n";
