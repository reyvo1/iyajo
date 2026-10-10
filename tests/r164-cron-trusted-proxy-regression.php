<?php
/** Both optional shared hosting worker endpoints must reject spoofed XFP. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/api/support/092_trusted_transport.php';
function checkTls(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$untrusted=['HTTPS'=>'off','SERVER_PORT'=>'80','REMOTE_ADDR'=>'203.0.113.5','HTTP_X_FORWARDED_PROTO'=>'https'];
checkTls(!tamasyaProtectedEndpointUsesHttps($untrusted,''), 'Untrusted X-Forwarded-Proto accepted');
checkTls(!tamasyaProtectedEndpointUsesHttps($untrusted,'198.51.100.9'), 'Different proxy accepted');
checkTls(tamasyaProtectedEndpointUsesHttps($untrusted,'203.0.113.5'), 'Explicit trusted proxy HTTPS rejected');
foreach(['http','https,http','', 'HtTp'] as $bad){
    $server=$untrusted;$server['HTTP_X_FORWARDED_PROTO']=$bad;
    checkTls(!tamasyaProtectedEndpointUsesHttps($server,'203.0.113.5'),'Spoofed forwarded scheme accepted');
}
checkTls(tamasyaProtectedEndpointUsesHttps(['HTTPS'=>'on','SERVER_PORT'=>'80'],''),'Native TLS rejected');
checkTls(tamasyaProtectedEndpointUsesHttps(['SERVER_PORT'=>'443'],''),'Native TLS port rejected');
foreach(['maintenance_cron.php','communication_worker.php'] as $path){
    $code=file_get_contents(dirname(__DIR__).'/'.$path);
    checkTls(str_contains($code,'tamasyaProtectedEndpointUsesHttps($_SERVER'),$path.' still trusts arbitrary proxy header');
}
echo "PASS R16.4 protected worker TLS trust boundary\n";
