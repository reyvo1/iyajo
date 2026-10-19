<?php
// Read-only unit fixtures: no database connection, SMTP dial-out, or outbound email.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
$GLOBALS['fake_decrypted'] = 'secret';
function decryptStoredSecret(string $value): string { return (string)($GLOBALS['fake_decrypted'] ?? ''); }
require dirname(__DIR__).'/api/support/023_smtp_diagnostics.php';

$passed = 0;
function smtpDiagCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}

smtpDiagCheck(function_exists('tamasyaSmtpExtractQueueId') && function_exists('tamasyaSmtpSanitizeResponse') && function_exists('tamasyaSmtpConfigFindings') && function_exists('tamasyaSmtpVerdictFromSteps') && function_exists('tamasyaSmtpVerdictMessage') && function_exists('tamasyaSmtpProbeExchange') && function_exists('tamasyaSmtpProbeResult') && function_exists('tamasyaSmtpPeerCertificateStep'),'Diagnostics module exposes the full public helper surface');

smtpDiagCheck(tamasyaSmtpExtractQueueId('250 OK id=1a2b-3c4d')==='1a2b-3c4d','Queue id extracted from an Exim style acceptance reply');
smtpDiagCheck(tamasyaSmtpExtractQueueId('250 2.0.0 Ok: queued as 4Wx1YZ2abc')==='4Wx1YZ2abc','Queue id extracted from a Postfix style acceptance reply');
smtpDiagCheck(tamasyaSmtpExtractQueueId('250 2.6.0 <a@b> [InternalId=12345, Hostname=EX01] Queued mail')==='12345','Queue id extracted from an Exchange InternalId');
smtpDiagCheck(tamasyaSmtpExtractQueueId('250 Message accepted')==='','Plain acceptance reply yields no queue id');
smtpDiagCheck(tamasyaSmtpSanitizeResponse("535 auth\r\nfailed\tnow")==='535 auth failed now','Sanitizer collapses control characters into one safe line');
smtpDiagCheck(strlen(tamasyaSmtpSanitizeResponse(str_repeat('x',500)))===300 && str_ends_with(tamasyaSmtpSanitizeResponse(str_repeat('x',500)),'...'),'Sanitizer caps evidence at 300 characters with an ellipsis');

$findings = tamasyaSmtpConfigFindings(['smtp_host'=>''], '');
smtpDiagCheck(count($findings)===1 && $findings[0]['code']==='host_missing' && $findings[0]['severity']==='fail','Empty host produces a single explicit fail finding');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp..uji.test','smtp_from'=>'no-reply@uji.test'], 'secret');
smtpDiagCheck(count($findings)===1 && $findings[0]['code']==='host_invalid','Malformed host names are rejected before probing');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.uji.test'], 'secret');
smtpDiagCheck($findings[0]['code']==='from_missing' && $findings[0]['severity']==='fail' && in_array('no_auth',array_column($findings,'code'),true),'Missing sender address is a hard configuration failure');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.uji.test','smtp_from'=>'no-reply@uji.test','smtp_user'=>'ops@uji.test','smtp_password'=>'cipher'], '');
smtpDiagCheck(count($findings)===1 && $findings[0]['code']==='password_undecryptable' && str_contains($findings[0]['message'],'Simpan ulang password SMTP'),'Stored password that cannot be decrypted points back at the form');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.uji.test','smtp_from'=>'no-reply@uji.test'], '');
smtpDiagCheck(count($findings)===1 && $findings[0]['code']==='no_auth' && $findings[0]['severity']==='warn','Credential-free relay is warned instead of silently trusted');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.uji.test','smtp_from'=>'no-reply@uji.test','smtp_user'=>'ops@uji.test','smtp_password'=>'cipher'], 'secret');
smtpDiagCheck(count($findings)===1 && $findings[0]['code']==='config_ok' && $findings[0]['severity']==='ok','Complete credentials produce a single config_ok finding');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.gmail.com','smtp_from'=>'no-reply@hotel-anda.com','smtp_user'=>'ops@gmail.com','smtp_password'=>'cipher'], 'secret');
smtpDiagCheck(in_array('from_domain_mismatch',array_column($findings,'code'),true),'Sender domain mismatch against the SMTP host is warned before probing');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.uji.test','smtp_from'=>'no-reply@uji.test','smtp_user'=>'ops@uji.test','smtp_password'=>'cipher','smtp_secure'=>'ssl','smtp_port'=>587], 'secret');
smtpDiagCheck(in_array('pair_ssl',array_column($findings,'code'),true),'SSL on a STARTTLS port is warned as a likely misconfiguration');
$findings = tamasyaSmtpConfigFindings(['smtp_host'=>'smtp.uji.test','smtp_from'=>'no-reply@uji.test','smtp_user'=>'ops@uji.test','smtp_password'=>'cipher','smtp_secure'=>'none','smtp_port'=>25], 'secret');
smtpDiagCheck(in_array('insecure',array_column($findings,'code'),true),'Plain mode is flagged as insecure');

$verdict = tamasyaSmtpVerdictFromSteps([['stage'=>'koneksi','status'=>'ok'],['stage'=>'otentikasi','status'=>'fail','code'=>'auth_failed']]);
smtpDiagCheck($verdict['ok']===false && $verdict['verdict']==='auth_failed','First failing step decides the probe verdict');
$verdict = tamasyaSmtpVerdictFromSteps([['stage'=>'koneksi','status'=>'fail','code'=>'']]);
smtpDiagCheck($verdict['ok']===false && $verdict['verdict']==='failed','Failures without a code fall back to a generic verdict');
$verdict = tamasyaSmtpVerdictFromSteps([['stage'=>'konfigurasi','status'=>'warn','code'=>''],['stage'=>'data','status'=>'ok']]);
smtpDiagCheck($verdict['ok']===true && $verdict['verdict']==='smtp_ready_with_warnings','Warnings only keep the probe successful with an explicit verdict');
$verdict = tamasyaSmtpVerdictFromSteps([['stage'=>'otentikasi','status'=>'skip'],['stage'=>'data','status'=>'ok']]);
smtpDiagCheck($verdict['ok']===true && $verdict['verdict']==='smtp_ready','An all-ok probe reports smtp_ready');
smtpDiagCheck(str_contains(tamasyaSmtpVerdictMessage('auth_failed'),'username/password SMTP') && str_contains(tamasyaSmtpVerdictMessage('tls_failed'),'sertifikat'),'Verdict messages map failures to concrete fix hints');
smtpDiagCheck(str_contains(tamasyaSmtpVerdictMessage('unknown_code'),'unknown_code'),'Unknown verdicts still surface their code');

$probe = tamasyaSmtpProbeResult([['stage'=>'data','status'=>'ok']], 'Q123', '250 OK', microtime(true)-0.05);
smtpDiagCheck($probe['ok']===true && $probe['verdict']==='smtp_ready' && $probe['queueId']==='Q123' && $probe['latencyMs']>=1,'Probe result bundles verdict queue id and latency');

$probe = tamasyaSmtpProbeExchange(['smtp_host'=>''], '', false);
smtpDiagCheck($probe['ok']===false && $probe['verdict']==='host_missing' && $probe['steps'][0]['code']==='host_missing' && $probe['latencyMs']>=1,'Probe stops at host_missing without touching the network');
$probe = tamasyaSmtpProbeExchange(['smtp_host'=>'smtp..uji.test','smtp_from'=>'no-reply@uji.test'], '', false);
smtpDiagCheck($probe['ok']===false && $probe['verdict']==='host_invalid','Probe rejects malformed host names before connecting');
$probe = tamasyaSmtpProbeExchange(['smtp_host'=>'smtp.uji.test'], '', false);
smtpDiagCheck($probe['ok']===false && $probe['verdict']==='from_missing','Probe rejects a missing sender before connecting');
$GLOBALS['fake_decrypted'] = '';
$probe = tamasyaSmtpProbeExchange(['smtp_host'=>'smtp.uji.test','smtp_from'=>'no-reply@uji.test','smtp_user'=>'ops@uji.test','smtp_password'=>'cipher'], '', false);
smtpDiagCheck($probe['ok']===false && $probe['verdict']==='password_undecryptable','Probe rejects an undecryptable stored password before connecting');
$GLOBALS['fake_decrypted'] = 'secret';

$root = dirname(__DIR__);
smtpDiagCheck(str_contains(file_get_contents($root.'/api/support/023_smtp_diagnostics.php'),"if (!defined('TAMASYA_API_ENTRY'))"),'Diagnostics module keeps the internal-entry guard');
smtpDiagCheck(str_contains(file_get_contents($root.'/api.php'),"'023_smtp_diagnostics.php'"),'api.php loads the SMTP diagnostics support module');
$adminRoute = file_get_contents($root.'/api/routes/070_config_telegram_admin.php');
smtpDiagCheck(str_contains($adminRoute,"'email-diagnostics'") && str_contains($adminRoute,"'gemini-test'"),'Admin route whitelists email-diagnostics next to gemini-test');
smtpDiagCheck(str_contains($adminRoute,'tamasyaSmtpProbeExchange') && str_contains($adminRoute,'Hanya Admin yang dapat menjalankan tes email SMTP.') && str_contains($adminRoute,'tamasyaExternalSideEffectsAllowed()') && str_contains($adminRoute,"writeRequiredEnterpriseAudit"),'Diagnostics action is guarded by admin plus primary gate and writes an audit row');
smtpDiagCheck(str_contains($adminRoute,"'steps'") && str_contains($adminRoute,"'findings'") && str_contains($adminRoute,"'testEmailSent'") && str_contains($adminRoute,"'queueId'"),'Diagnostics response exposes steps findings queue id and test delivery flag');
$receiptLibSrc = file_get_contents($root.'/api/support/060_shift_receipts.php');
smtpDiagCheck(!str_contains($receiptLibSrc,'tamasyaSmtpProbeExchange') && str_contains($receiptLibSrc,'tamasyaSmtpSanitizeResponse'),'Receipt email reuses the sanitizer without importing the full probe');
$configUi = file_get_contents($root.'/assets/chunks/config.js');
smtpDiagCheck(str_contains($configUi,'/api/email-diagnostics') && str_contains($configUi,'testEmail') && str_contains($configUi,'Tes Pengiriman Email') && str_contains($configUi,'EmailTestStatus'),'Settings UI ships the SMTP test panel wired to the diagnostics action');

echo "SMTP diagnostics: $passed passed; 0 failed.".PHP_EOL;
