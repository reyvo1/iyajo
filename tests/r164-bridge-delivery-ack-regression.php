<?php
/** Only an explicit success=true acknowledgement marks a delivery sent. */
if (PHP_SAPI !== 'cli')exit(1);
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/channels/CommunicationChannelAdapter.php';
function tamasyaCommunicationChannelConfig(array $channel): array {return ['outboundUrl'=>'https://bridge.example.test/send','timeoutSeconds'=>5];}
function tamasyaCommunicationChannelSecret(array $channel,string $kind='credential'): string {return 'test-only-secret';}
function sendHttpPost(string $url,string $body,array $headers,array $options=[]): string|false {return $GLOBALS['bridge_response'];}
require dirname(__DIR__).'/api/channels/StandardWebhookAdapter.php';
$dummy=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$adapter=new TamasyaStandardWebhookAdapter();
foreach ([
    [false,false],['<html>OK</html>',false],['{}',false],['{"success":false}',false],['{"success":1}',false],
    ['{"success":true,"providerMessageId":"r101"}',true],
] as [$payload,$expected]) {
    $GLOBALS['bridge_response']=$payload;
    $result=$adapter->send($dummy,[],['providerUserId'=>'person1'],['text'=>'hello']);
    if($result['success']!==$expected||$result['status']!==($expected?'sent':'failed'))throw new RuntimeException('Bridge false ACK: '.var_export($payload,true));
}
echo "PASS P1 bridge explicit delivery acknowledgement\n";
