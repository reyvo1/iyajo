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
// Owner tetap read-only: capability baru selalu false, view tetap true.
$ownerAll=$mk('owner',['sell_room_access'=>true,'manage_stay_access'=>true,'receive_payment_access'=>true,'perform_housekeeping'=>true,'view_guest_identity'=>true]);
salesCheck(hasCapability($ownerAll,'sell_room_access',['admin','manager','receptionist','finance','owner'])===false,'Owner dicentang jual tetap ditolak');
salesCheck(hasCapability($ownerAll,'manage_stay_access',['admin','manager','receptionist','finance','owner'])===false,'Owner dicentang inap tetap ditolak');
salesCheck(hasCapability($ownerAll,'receive_payment_access',['admin','manager','receptionist','finance'])===false,'Owner dicentang panjar tetap ditolak');
salesCheck(hasCapability($ownerAll,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])===false,'Owner dicentang housekeeping tetap ditolak');
salesCheck(hasCapability($ownerAll,'view_guest_identity',['admin','manager','receptionist','keamanan'])===true,'Owner tetap bisa lihat KTP');
// Preset sesi owner memaksa capability baru menjadi false.
$p=tamasyaSessionPermissions($ownerAll);
salesCheck(($p['capabilities']['sell_room_access']??null)===false,'Preset owner memaksa sell false');
salesCheck(($p['capabilities']['manage_stay_access']??null)===false,'Preset owner memaksa stay false');
salesCheck(($p['capabilities']['receive_payment_access']??null)===false,'Preset owner memaksa panjar false');
salesCheck(($p['capabilities']['perform_housekeeping']??null)===false,'Preset owner memaksa housekeeping false');
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
