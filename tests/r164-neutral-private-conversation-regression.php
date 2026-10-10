<?php
/** R16.4: authenticated provider ID is insufficient for a business context. */
if (PHP_SAPI !== 'cli') exit(1);
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/comms/045_communication_core.php';
function ensurePrivate(bool $ok, string $why): void {if(!$ok)throw new RuntimeException($why);}
function rejectedPrivate(array $message, array $identity): bool {
    try {tamasyaCommunicationAssertPrivateOperationalContext($message,$identity);return false;}
    catch(RuntimeException $e) {return true;}
}
$id=['provider_conversation_id'=>'staff-private-123'];
foreach(['private','direct','dm'] as $kind){
    tamasyaCommunicationAssertPrivateOperationalContext(['conversationType'=>$kind,'providerConversationId'=>'staff-private-123'],$id);
}
foreach(['group','supergroup','channel','unknown',''] as $kind){
    ensurePrivate(rejectedPrivate(['conversationType'=>$kind,'providerConversationId'=>'staff-private-123'],$id), 'Untrusted room status allowed in '.$kind);
}
foreach(['other-chat','','staff-private-123\n'] as $wrong){
    ensurePrivate(rejectedPrivate(['conversationType'=>'private','providerConversationId'=>$wrong],$id), 'Other conversation accepted');
}
ensurePrivate(rejectedPrivate(['conversationType'=>'private','providerConversationId'=>'staff-private-123'],['provider_conversation_id'=>'']), 'Missing verified conversation accepted');
$route=file_get_contents(dirname(__DIR__).'/api/routes/076_communication_webhook.php');
$assert=strpos($route,'tamasyaCommunicationAssertPrivateOperationalContext($normalized,$identity);');
$execute=strpos($route,'$result=tamasyaCommunicationExecuteCommand($pdo,$identity,$parsed);');
ensurePrivate($assert!==false&&$execute!==false&&$assert<$execute, 'Private conversation was not verified before command dispatch');
echo "PASS R16.4 provider-neutral private conversation + binding parity\n";
