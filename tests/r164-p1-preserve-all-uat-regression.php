<?php
/** Audit all historical UAT command names as well as the new P1 security suites. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$historical=json_decode(file_get_contents($root.'/tests/uat-r164-required-commands.json'),true,512,JSON_THROW_ON_ERROR)['required'];
$workflow=file_get_contents($root.'/.github/workflows/tamasya-enterprise-rc1-uat.yml');
if(count($historical)!==54||count(array_unique($historical))!==54)throw new RuntimeException('Frozen 54 historic suites changed');
$must=array_merge($historical,[
 'php tests/r164-communication-inbound-identity-regression.php',
 'php tests/r164-bridge-delivery-ack-regression.php',
 'php tests/r164-ota-allocation-ledger-regression.php',
 'php tests/r164-p1-preserve-all-uat-regression.php',
 'php tests/r164-binding-ownership-regression.php',
 'php tests/r164-webhook-atomic-private-regression.php',
 'php tests/r164-outbox-finalization-counters-regression.php',
 'php tests/r164-legacy-identity-import-safety-regression.php',
 'php tests/r164-hybrid-lease-transport-regression.php',
 'php tests/r164-neutral-webhook-durable-history-regression.php',
 'php tests/r164-neutral-private-conversation-regression.php',
 'php tests/r164-cron-trusted-proxy-regression.php',
 'php tests/r164-bridge-url-safety-regression.php',
 'php tests/r164-neutral-private-conversation-regression.php',
 'php tests/r164-cron-trusted-proxy-regression.php',
 'php tests/r164-bridge-url-safety-regression.php',
]);
foreach($must as $cmd){
 $needle='          '.$cmd;
 if(strpos($workflow,$needle)===false||!is_file($root.'/'.array_slice(explode(' ',trim($cmd)),-1)[0]))throw new RuntimeException('Required UAT command/file missing: '.$cmd);
}
foreach (['source-gate:', 'mysql-comprehensive-uat:', 'mariadb-1011-compatibility:', 'prd-deployment-profile-simulation:' ] as $gate) {
 // The source workflow may refer to the VPS job through a longer name;
 // preserve the named gate check while tolerating its existing spelling.
 if(!str_contains($workflow,$gate))throw new RuntimeException('Existing UAT gate missing: '.$gate);
}
echo 'PASS preserved '.count($historical).' historic suites and 13 additive P1 suites'.PHP_EOL;
