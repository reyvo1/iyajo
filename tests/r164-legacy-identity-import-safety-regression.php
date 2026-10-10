<?php
/** R16.4 legacy Telegram migration must never resurrect/reassign channel identities. */
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
$src=file_get_contents($root.'/api/modules/comms/045_communication_core.php');
$start=strpos($src,'function tamasyaCommunicationSyncLegacyChannels(');
$end=strpos($src,'function tamasyaCommunicationResolveIdentity(', $start);
if($start===false||$end===false)throw new RuntimeException('Legacy identity import unavailable');
$section=substr($src,$start,$end-$start);
function demandImport(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
demandImport(str_contains($section,"FROM telegram_bindings tb\n                WHERE tb.status='active'"),'Inactive legacy binding must never import');
demandImport(str_contains($section,'AND ci.provider_user_id=tb.telegram_user_id'),'Provider-side exclusion required');
demandImport(str_contains($section,'AND ci.staff_id=tb.staff_id'),'Staff-side exclusion required');
demandImport(str_contains($section,'ON DUPLICATE KEY UPDATE id=communication_identities.id'),'Concurrent unique conflict must be non-destructive');
foreach (['staff_id=VALUES(staff_id)','status=VALUES(status)','provider_conversation_id=VALUES(provider_conversation_id)','metadata_json=VALUES(metadata_json)'] as $unsafe)
    demandImport(!str_contains($section,$unsafe),'Legacy importer may overwrite live/revoked binding: '.$unsafe);
// Preserve separate explicit, one-time-code binding command as the *only* path
// allowed to reactivate a revoked neutral-channel identity.
$bindStart=strpos($src,'function tamasyaCommunicationBindIdentityWithCode(');
$bindEnd=strpos($src,'function tamasyaCommunicationUnbindIdentity(', $bindStart);
demandImport($bindStart!==false&&$bindEnd!==false,'Canonical explicit binding command missing');
$bind=substr($src,$bindStart,$bindEnd-$bindStart);
demandImport(str_contains($bind,'SELECT * FROM communication_binding_codes')&&str_contains($bind,'used_at IS NULL')&&str_contains($bind,'FOR UPDATE'),'Fresh one-time binding proof required');
demandImport(str_contains($bind,"status='active'"),'Explicit user-approved reactivation must remain possible');
echo "PASS R16.4 legacy Telegram identity import is non-destructive\n";
