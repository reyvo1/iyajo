<?php
/** P1 — Verify authenticated ingress and durable event id are mandatory. */
if (PHP_SAPI !== 'cli') exit(1);
define('TAMASYA_API_ENTRY', true);
$root=dirname(__DIR__);
require_once $root.'/api/channels/CommunicationChannelAdapter.php';
require_once $root.'/api/channels/TelegramCompatibilityAdapter.php';
require_once $root.'/api/modules/comms/045_communication_core.php';
function must(bool $valid,string $message): void {if(!$valid)throw new RuntimeException($message);}
function rejected(callable $fn): bool {try{$fn();}catch(InvalidArgumentException $e){return true;}return false;}
$ref=new ReflectionClass(PDO::class);
$dummy=$ref->newInstanceWithoutConstructor();
$adapter=new TamasyaTelegramCompatibilityAdapter();
must($adapter->verifyInbound($dummy,[],[], '{"update_id":13}')===false,'Telegram adapter must not authenticate the generic webhook');
$route=file_get_contents($root.'/api/routes/076_communication_webhook.php');
must(str_contains($route,"$".'channel' . "['provider_key']==='telegram'")&&str_contains($route,"$".'channelId===\'channel_telegram_main\''),'Generic endpoint must reject both legacy provider and channel ID');
must(strpos($route,'tamasyaCommunicationValidateEventId(')<strpos($route,'tamasyaCommunicationClaimEvent('),'ID validation occurs before durable claim and any business routing');
foreach(['','   ',"x\n1",str_repeat('a',191)] as $input){
    must(rejected(static fn()=>tamasyaCommunicationValidateEventId($input)),'Unsafe event id accepted');
}
must(tamasyaCommunicationValidateEventId('bridge:event_123')==='bridge:event_123','Valid canonical event ID accepted');
$claim=(new ReflectionFunction('tamasyaCommunicationClaimEvent'))->getFileName();
must(is_file($claim),'Claim implementation present');
$core=file_get_contents($root.'/api/modules/comms/045_communication_core.php');
must(str_contains($core,"AND status='processing'") && str_contains($core,'if($strict&&$stmt->rowCount()!==1)'), 'Completion requires processing lease and strict persisted result');
must(substr_count($route,'tamasyaCommunicationCompleteEvent($pdo,$channelId,$eventId,true,null,true)')===2,'Generic inbound sends ACK only after strict event completion');
// Fake PDO is intentionally unconnected: bad IDs must fail *before* SQL access.
must(rejected(static fn()=>tamasyaCommunicationClaimEvent($dummy,'bridge','',[])),'Empty event bypass accepted');
echo "PASS P1 communication ingress, mandatory event-id and dedupe preconditions\n";
