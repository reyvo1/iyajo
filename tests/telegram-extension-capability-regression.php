<?php
// Regresi capability alur perpanjangan biaya Telegram: centangan manage_stay_access
// pada peran non-baseline (finance dkk) tidak boleh dilempar assert biaya — inilah
// penyebab sesi "beku" setelah mengetik jumlah malam. Tanpa centangan tetap ditolak,
// owner tetap read-only. Murni fungsi, tanpa DB/server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
require dirname(__DIR__).'/api/support/017_authorization_policy.php';
function tamasyaExternalSideEffectsAllowed(): bool { return true; }
function decryptStoredSecret(string $value): string { return $value; }
require dirname(__DIR__).'/api/modules/comms/050_integrations_telegram_mail.php';

$passed = 0;
function extensionCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}
$actor = function (string $role, ?array $caps = null): array {
    $user = ['id'=>'u1','role'=>$role];
    if ($caps !== null) $user['permissions'] = json_encode(['capabilities'=>$caps]);
    return $user;
};
$allows = function (array $who): bool {
    try { tamasyaTelegramChargeAssertRole($who); return true; }
    catch (Throwable $e) { return false; }
};
extensionCheck($allows($actor('admin'))===true && $allows($actor('manager'))===true && $allows($actor('receptionist'))===true,'Baseline admin manajer resepsionis lolos assert biaya');
extensionCheck($allows($actor('finance',['manage_stay_access'=>true]))===true,'Finance dicentang inap lolos assert biaya (anti-beku Telegram)');
extensionCheck($allows($actor('keamanan',['manage_stay_access'=>true]))===true,'Peran lain dicentang inap lolos assert biaya');
extensionCheck($allows($actor('finance'))===false,'Finance tanpa centangan tetap ditolak');
extensionCheck($allows($actor('finance',['manage_stay_access'=>false]))===false,'Centang mati tetap ditolak');
extensionCheck($allows($actor('manager',['manage_stay_access'=>false]))===false,'Centang mati mencabut akses manajer');
extensionCheck($allows($actor('owner',['manage_stay_access'=>true]))===false,'Owner read-only tetap ditolak');
$module = file_get_contents(dirname(__DIR__).'/api/modules/comms/050_integrations_telegram_mail.php');
extensionCheck(str_contains($module,"hasCapability(\$actor,'manage_stay_access',['admin','manager','receptionist'])"),'Assert biaya memakai hasCapability, bukan daftar role keras');
extensionCheck(str_contains($module,"throw new RuntimeException('Peran akun tidak berhak menambah biaya booking.')"),'Pesan tolak assert biaya dipertahankan untuk pemanggil lama');
$webhook = file_get_contents(dirname(__DIR__).'/api/routes/080_telegram_webhook.php');
extensionCheck(preg_match("/waiting_for_extend_nights'\)\s*\{[\s\S]{0,300}?try\s*\{/",$webhook)===1,'State perpanjangan malam dibungkus try/catch agar tidak pernah beku');
extensionCheck(str_contains($webhook,"clientExceptionMessage('Perpanjangan belum dapat diproses',\$extensionError)"),'Kegagalan perpanjangan selalu membalas pesan yang bisa ditindaklanjuti');

echo "Telegram extension capability: {$passed} passed; 0 failed.".PHP_EOL;
