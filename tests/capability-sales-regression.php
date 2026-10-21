<?php
// Regresi capability penjualan: centangan per akun mengalahkan baseline role,
// owner tetap read-only apa pun centangannya. Murni fungsi, tanpa DB/server.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/support/017_authorization_policy.php';
$passed=0;
function salesCheck(bool $ok,string $name): void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
$mk=function(string $role,?array $caps=null): array{
    $u=['id'=>'u1','role'=>$role];
    if($caps!==null)$u['permissions']=json_encode(['capabilities'=>$caps]);
    return $u;
};
// Baseline lama tidak berubah tanpa centangan.
salesCheck(hasCapability($mk('receptionist'),'sell_room_access',['admin','manager','receptionist'])===true,'Baseline resepsionis bisa jual');
salesCheck(hasCapability($mk('receptionist'),'manage_stay_access',['admin','manager','receptionist'])===true,'Baseline resepsionis bisa inap');
salesCheck(hasCapability($mk('finance'),'receive_payment_access',['admin','manager','receptionist','finance'])===true,'Baseline finance bisa panjar');
salesCheck(hasCapability($mk('finance'),'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])===true,'Baseline finance bisa housekeeping');
salesCheck(hasCapability($mk('finance'),'sell_room_access',['admin','manager','receptionist'])===false,'Finance tanpa centang tetap tidak bisa jual');
salesCheck(hasCapability($mk('finance'),'manage_stay_access',['admin','manager','receptionist'])===false,'Finance tanpa centang tetap tidak bisa inap');
salesCheck(hasCapability($mk('cleaning_service'),'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])===true,'Baseline housekeeping tidak berubah');
// Centangan menambah akses di luar role.
salesCheck(hasCapability($mk('finance',['sell_room_access'=>true]),'sell_room_access',['admin','manager','receptionist'])===true,'Finance dicentang jual langsung bisa');
salesCheck(hasCapability($mk('finance',['manage_stay_access'=>true]),'manage_stay_access',['admin','manager','receptionist'])===true,'Finance dicentang inap langsung bisa');
salesCheck(hasCapability($mk('keamanan',['sell_room_access'=>true,'manage_stay_access'=>true]),'manage_stay_access',['admin','manager','receptionist'])===true,'Peran lain dicentang inap langsung bisa');
salesCheck(hasCapability($mk('cleaning_service',['receive_payment_access'=>true]),'receive_payment_access',['admin','manager','receptionist','finance'])===true,'Housekeeping dicentang panjar langsung bisa');
// Centangan false eksplisit mencabut akses baseline.
salesCheck(hasCapability($mk('receptionist',['sell_room_access'=>false]),'sell_room_access',['admin','manager','receptionist'])===false,'Centang mati mencabut jual resepsionis');
salesCheck(hasCapability($mk('admin',['manage_stay_access'=>false]),'manage_stay_access',['admin','manager','receptionist'])===false,'Centang mati mencabut inap admin');
salesCheck(hasCapability($mk('finance',['perform_housekeeping'=>false]),'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])===false,'Centang mati mencabut housekeeping finance');
// Owner membaca seperti Admin (capability baca yang sama), tetapi TIDAK PERNAH
// bisa menulis. Larangan menulis berasal dari penjaga metode: setiap metode
// selain GET/HEAD/OPTIONS ditolak untuk Owner. Centangan di akun Owner tidak
// pernah mengubah kemampuan itu.
$ownerAll=$mk('owner',['sell_room_access'=>true,'manage_stay_access'=>true,'receive_payment_access'=>true,'perform_housekeeping'=>true,'view_guest_identity'=>true]);
salesCheck(hasCapability($ownerAll,'sell_room_access',['admin','manager','receptionist','finance','owner'])===true,'Owner memakai capability baca Admin (jual)');
salesCheck(hasCapability($ownerAll,'manage_stay_access',['admin','manager','receptionist','finance','owner'])===true,'Owner memakai capability baca Admin (inap)');
salesCheck(hasCapability($ownerAll,'receive_payment_access',['admin','manager','receptionist','finance'])===true,'Owner memakai capability baca Admin (panjar)');
salesCheck(hasCapability($ownerAll,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])===true,'Owner memakai capability baca Admin (housekeeping)');
salesCheck(hasCapability($ownerAll,'view_guest_identity',['admin','manager','receptionist','keamanan'])===true,'Owner tetap bisa lihat KTP');
$ownerWriteMethod=$_SERVER['REQUEST_METHOD']??'GET';
foreach(['POST','PUT','PATCH','DELETE'] as $ownerBlockedMethod){
  $_SERVER['REQUEST_METHOD']=$ownerBlockedMethod;
  salesCheck(tamasyaOwnerMutationDenied($ownerAll)===true,'Owner ditolak pada metode '.$ownerBlockedMethod.' walau capability baca Admin aktif');
}
$_SERVER['REQUEST_METHOD']=$ownerWriteMethod;
salesCheck(tamasyaOwnerMutationDenied($ownerAll)===false,'Owner tetap boleh membaca');
// Sesi Owner selalu readOnly, apa pun capability baca yang diwarisi.
$p=tamasyaSessionPermissions($ownerAll);
salesCheck(($p['readOnly']??false)===true,'Preset sesi Owner tetap readOnly');
salesCheck(($p['capabilities']['view_guest_identity']??null)===true,'Capability view Owner tetap tersedia');
// Workspace Kamar: centangan sell/stay tembus apa pun menu Kamar; peran lain tetap seperti semula.
$roomsAccess=function(string $role,?array $caps=null,?array $tabs=null): bool{
    $u=['id'=>'u1','role'=>$role];$p=[];
    if($caps!==null)$p['capabilities']=$caps;
    if($tabs!==null)$p['desktopTabs']=$tabs;
    if($p)$u['permissions']=json_encode($p);
    return tamasyaHasRoomsWorkspaceAccess($u);
};
salesCheck($roomsAccess('finance',['sell_room_access'=>true])===true,'Finance centang jual bisa buka Kamar');
salesCheck($roomsAccess('finance',['manage_stay_access'=>true])===true,'Finance centang inap bisa buka Kamar');
salesCheck($roomsAccess('finance')===false,'Finance tanpa centangan tetap terkunci');
salesCheck($roomsAccess('finance',['sell_room_access'=>false])===false,'Finance centang jual dicabut tetap terkunci');
salesCheck($roomsAccess('koki',['sell_room_access'=>true])===true,'Koki centang jual bisa buka Kamar');
salesCheck($roomsAccess('koki')===false,'Koki tanpa centangan terkunci');
salesCheck($roomsAccess('receptionist',null,['rooms'=>false])===false,'Resepsionis menu Kamar dimatikan tetap terkunci');
salesCheck($roomsAccess('receptionist')===true,'Resepsionis baseline tetap bisa');
salesCheck($roomsAccess('owner')===true,'Owner tetap bisa membaca Kamar');
$telegramSource=file_get_contents(dirname(__DIR__).'/api/routes/080_telegram_webhook.php');
salesCheck(strpos($telegramSource,"hasCapability(\$loggedInStaff,'sell_room_access',['admin','manager','receptionist']);")!==false,'Telegram jual: fallback peran bersih');
salesCheck(strpos($telegramSource,"hasCapability(\$loggedInStaff,'manage_stay_access',['admin','manager','receptionist']);")!==false,'Telegram inap: fallback peran bersih');
salesCheck(strpos($telegramSource,"'sell_room_access',['admin','manager','receptionist','finance','owner']")===false&&strpos($telegramSource,"'manage_stay_access',['admin','manager','receptionist','finance','owner']")===false,'Telegram jual/inap: finance-owner dihapus dari fallback');
echo "$passed passed; 0 failed\n";
