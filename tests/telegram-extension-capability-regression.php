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

$finance = file_get_contents(dirname(__DIR__).'/api/modules/finance/030_booking_finance.php');
extensionCheck(str_contains($finance,"hasCapability(\$actor,'manage_stay_access',['admin','manager','receptionist'])"),'Commit biaya canonical 030 ikut capability, bukan daftar role keras');
extensionCheck(str_contains($finance,"throw new DomainException('Peran ini tidak berhak menetapkan harga nego.',403)"),'Pesan tolak nego 030 dipertahankan untuk pemanggil lama');
$frontOffice = file_get_contents(dirname(__DIR__).'/api/modules/front_office/040_rooms_checkout_housekeeping.php');
extensionCheck(str_contains($frontOffice,"hasCapability(\$staff,'manage_stay_access',['admin','manager','receptionist'])"),'Pindah kamar 040 ikut capability, bukan daftar role keras');
extensionCheck(str_contains($frontOffice,"throw new RuntimeException('Role ini tidak diizinkan memindahkan kamar tamu.')"),'Pesan tolak pindah kamar 040 dipertahankan');
$bookingRoute = file_get_contents(dirname(__DIR__).'/api/routes/020_hotel_booking.php');
extensionCheck(str_contains($bookingRoute,"hasCapability(\$loggedInStaff,'manage_stay_access',['admin','manager','receptionist'])"),'Route harga nego web 020 ikut capability, bukan daftar role keras');

// Batch statis->centangan (Oktober 2026): Telegram, multi-room, operasional, cluster.
extensionCheck(str_contains($webhook,"hasCapability(\$staff,'sell_room_access',['admin','manager','receptionist'])&&tamasyaHasRoomsWorkspaceAccess(\$staff)"),'Tombol reservasi multi-kamar Telegram memakai sell_room_access + workspace kamar');
extensionCheck(str_contains($webhook,"tamasyaCanOperateShift(\$staff)||hasCapability(\$staff,'receive_payment_access',[])"),'Tombol Kas & Shift Telegram memakai receive_payment_access');
extensionCheck(substr_count($webhook,"hasCapability(\$staff,'perform_housekeeping',['admin','manager'])")>=1,'Tombol Mulai layanan tamu memakai perform_housekeeping (baseline pengawas)');
extensionCheck(substr_count($webhook,"hasCapability(\$staff,'perform_housekeeping',['admin','manager','receptionist'])")>=1 && substr_count($webhook,"hasCapability(\$loggedInStaff,'perform_housekeeping',['admin','manager','receptionist'])")>=2,'Tutup layanan tamu (tombol, guard, catatan) memakai perform_housekeeping');
extensionCheck(str_contains($webhook,"\$callbackRole==='owner'||hasCapability(\$loggedInStaff,'manage_room_access',['admin','manager','receptionist','finance'])"),'Gerbang baca kamar Telegram memakai manage_room_access dengan owner read-only');
extensionCheck(str_contains($webhook,"\$allowedCallback = tamasyaCanOperateShift(\$loggedInStaff)||hasCapability(\$loggedInStaff,'receive_payment_access',[])"),'Gerbang callback Kas & Shift memakai receive_payment_access');
extensionCheck(substr_count($webhook,"hasCapability(\$loggedInStaff,'approve_sensitive_actions',['admin','manager'])")>=2,'Selisih kas Telegram memakai approve_sensitive_actions (deteksi + guard override)');
extensionCheck(str_contains($webhook,"if(!hasCapability(\$loggedInStaff,'manage_room_access',['admin','manager','receptionist']))"),'Guard /update_status Telegram memakai manage_room_access');
extensionCheck(str_contains($webhook,"if(!tamasyaCanOperateShift(\$staff)&&!hasCapability(\$staff,'receive_payment_access',[]))return ["),'Menu tutup shift Telegram memakai receive_payment_access');
extensionCheck(str_contains($webhook,"\$requiredDirectCap = ['receive_payment_access', tamasyaShiftOperatorRoles()];"),'Command /buka_shift dan /tutup_shift memakai receive_payment_access');
extensionCheck(substr_count($webhook,"in_array(\$role,['admin','manager','finance','receptionist'],true)")===0,'Tidak ada lagi daftar role shift statis di 080');
extensionCheck(substr_count($webhook,"\$pendingAllowed = in_array(\$pendingRole,tamasyaShiftOperatorRoles(),true)")===0,'Pending state shift tidak lagi memakai daftar statis');
$multiRoom = file_get_contents(dirname(__DIR__).'/api/modules/front_office/044_multi_room_telegram.php');
extensionCheck(str_contains($multiRoom,"hasCapability(\$actor,'sell_room_access',['admin','manager','receptionist'])&&tamasyaHasRoomsWorkspaceAccess(\$actor)"),'Tombol tulis multi-room Telegram memakai sell_room_access + workspace kamar');
$ops090 = file_get_contents(dirname(__DIR__).'/api/routes/090_operations_communications.php');
extensionCheck(substr_count($ops090,"requireCapability(\$loggedInStaff,'manage_room_access',['admin','manager'])")>=4,'Lost-found secure, review maintenance, dan hold kamar memakai manage_room_access');
extensionCheck(str_contains($ops090,"requireCapability(\$loggedInStaff,'override_key_checkout',['admin','manager'])"),'Penyelesaian manual smart-lock memakai override_key_checkout');
extensionCheck(str_contains($ops090,"requireRoles(\$loggedInStaff,['admin']);") && substr_count($ops090,"requireRoles(\$loggedInStaff,['admin']);")===1,'Mode Night Audit tetap admin-only by design (kontrak R16.4)');
extensionCheck(substr_count($ops090,"requireCapability(\$loggedInStaff,'receive_payment_access',tamasyaShiftOperatorRoles())")>=2,'Buka/tutup shift web memakai receive_payment_access');
extensionCheck(str_contains($ops090,"!hasCapability(\$loggedInStaff,'approve_sensitive_actions',['admin','manager']) && \$requestedBlacklist"),'Ubah status blacklist memakai approve_sensitive_actions');
$cluster010 = file_get_contents(dirname(__DIR__).'/api/routes/010_cluster_status.php');
extensionCheck(str_contains($cluster010,"requireCapability(\$loggedInStaff,'resolve_sync_conflict',['admin'])"),'Resolusi konflik sync cluster memakai resolve_sync_conflict');
extensionCheck(str_contains($finance,"hasCapability(\$actor,'approve_sensitive_actions',['admin','manager'])"),'Override booking tamu blacklist (030) memakai approve_sensitive_actions');
$growth110 = file_get_contents(dirname(__DIR__).'/api/routes/110_growth_suite.php');
extensionCheck(substr_count($growth110,"requireCapability(\$loggedInStaff,'approve_sensitive_actions',['admin','manager'])")>=2,'Approve/receive purchase order memakai approve_sensitive_actions (baseline admin/manager)');

echo "Telegram extension capability: {$passed} passed; 0 failed.".PHP_EOL;
