<?php
// Regresi Dynamic RBAC: registry DB (role/capability/tab/notifikasi), access-group
// bridge untuk requireRoles(), fallback aman tanpa PDO, wiring route/UI, dan
// kelengkapan migrasi/schema. Tidak membutuhkan DB/server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
$root = dirname(__DIR__);
require $root.'/api/support/017_authorization_policy.php';
require $root.'/api/support/021_role_presets.php';

$passed = 0;
function rbacCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}

$rbac = file_get_contents($root.'/api/support/022_dynamic_rbac.php');
$policy = file_get_contents($root.'/api/support/017_authorization_policy.php');
$presets = file_get_contents($root.'/api/support/021_role_presets.php');
$route = file_get_contents($root.'/api/routes/052_role_presets.php');
$setup = file_get_contents($root.'/database_setup.sql');
$migration = file_get_contents($root.'/migrations/20261021_DYNAMIC_RBAC.sql');
$staffJs = file_get_contents($root.'/assets/chunks/staff.js');
$appCore = file_get_contents($root.'/assets/app-core.js');
$appShell = file_get_contents($root.'/assets/chunks/app-shell.js');
$navigation = file_get_contents($root.'/assets/navigation-registry.js');

rbacCheck(!tamasyaRbacEnabled(), 'Tanpa PDO registry dinamis nonaktif (fallback aman)');
rbacCheck(tamasyaRbacPdo() === null, 'Tanpa PDO helper tidak membuka koneksi sendiri');
rbacCheck(tamasyaRbacRoleKeys() === [], 'Tanpa PDO role DB kosong, bukan daftar palsu');
rbacCheck(tamasyaRbacCatalogPayload() === ['roles'=>[], 'capabilities'=>[], 'desktopTabs'=>[], 'telegramNotifications'=>[]], 'Tanpa PDO katalog kosong');
rbacCheck(tamasyaRbacRolePreset('admin') === null, 'Tanpa PDO preset role null');
rbacCheck(tamasyaRbacRolePermission('admin','capabilities','manage_backup') === null, 'Tanpa PDO permission role null');
rbacCheck(tamasyaRbacEffectivePermissions(['role'=>'finance']) === null, 'Tanpa PDO effective permissions null');
rbacCheck(tamasyaRbacAccessGroupRoles(['admin','finance']) === null, 'Tanpa PDO access group null (fallback code roles)');
rbacCheck(tamasyaRbacAccessGroupRows() === [], 'Tanpa PDO daftar access group kosong');

foreach (['rbac_roles','rbac_capabilities','rbac_desktop_tabs','rbac_notification_groups','rbac_access_groups','rbac_access_group_roles','rbac_legacy_role_sets'] as $table) {
    rbacCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `'.$table.'`'), 'Migrasi membuat tabel '.$table);
    rbacCheck(str_contains($setup, 'CREATE TABLE IF NOT EXISTS `'.$table.'`'), 'Baseline membuat tabel '.$table);
}
rbacCheck(substr_count($migration, 'INSERT IGNORE INTO') === 7, 'Seed migrasi memakai 7 INSERT IGNORE (idempoten)');
rbacCheck(!str_contains($migration, 'rbac_role_permissions'), 'Tidak ada tabel permission role paralel; role_capability_presets tetap sumbernya');
rbacCheck(str_contains($migration, "('owner','Owner',1,1,0)"), 'Owner di-seed sebagai role read-only');
rbacCheck(str_contains($migration, "('daily_summary','Lihat Ringkasan Harian Operasional',1,230)"), 'Capability daily_summary ikut di-seed');
rbacCheck(str_contains($migration, "('admin,cleaning_service,finance,manager','finance_ops_cleaning')"), 'Pemetaan role_set finance_ops_cleaning tepat');
rbacCheck(str_contains($migration, "('admin,finance,manager,owner','finance_ops_owner')"), 'Pemetaan role_set finance_ops_owner tepat');
rbacCheck(str_contains($migration, "('admin,finance,owner','admin_finance_owner')"), 'Pemetaan role_set admin_finance_owner tepat');

rbacCheck(str_contains($rbac, 'function tamasyaRbacAccessGroupRoles'), 'Helper access-group tersedia');
rbacCheck(str_contains($rbac, 'function tamasyaRbacRolePermission'), 'Helper permission role tersedia');
rbacCheck(str_contains($rbac, 'function tamasyaRbacEffectivePermissions'), 'Helper effective permissions tersedia');
rbacCheck(str_contains($rbac, 'function tamasyaRbacCatalogKeyState') && str_contains($rbac, "if (\$state === 'inactive') return false;") && str_contains($rbac, "if (\$state !== 'active') return null;") && str_contains($rbac, "tamasyaRbacCatalogKeyState(\$group, (string)\$key, \$pdo) === 'inactive'"), 'Katalog nonaktif = deny; key lama/asing tetap fallback legacy');
rbacCheck(str_contains($rbac, 'tamasyaRbacCacheSet($key, []);') && str_contains($rbac, 'Grup yang sengaja dinonaktifkan harus menutup route'), 'Access group nonaktif menutup route (bukan fallback role statis)');
rbacCheck(str_contains($policy, "tamasyaRbacCatalogKeyState('capabilities',(string)\$capability) === 'inactive'") && str_contains($policy, "tamasyaRbacCatalogKeyState('desktopTabs',(string)\$tab) === 'inactive'"), 'Deaktivasi capability/tab menang atas override akun dan fallback role');
rbacCheck(str_contains($rbac, 'tamasyaRbacCatalogAddToPresets'), 'Catalog baru ditambal false ke semua preset');
rbacCheck(str_contains($rbac, 'tamasyaRbacCatalogRemoveFromPresets'), 'Catalog yang dihapus dibersihkan dari preset');
rbacCheck(str_contains($policy, 'function tamasyaSessionPermissions(array $user, ?PDO $pdo = null)'), 'Session permissions menerima PDO');
rbacCheck(str_contains($policy, 'tamasyaRbacAccessGroupRoles((array)$roles)'), 'requireRoles memakai access-group bridge');
rbacCheck(str_contains($policy, "tamasyaRbacRolePermission(\$roleKey,'capabilities'"), 'hasCapability membaca permission role DB');
rbacCheck(str_contains($policy, "tamasyaRbacRolePermission(\$roleKey,'desktopTabs'"), 'hasDesktopTabAccess membaca tab DB');
rbacCheck(str_contains($presets, "require_once __DIR__ . '/022_dynamic_rbac.php'"), '021 memuat helper 022');
rbacCheck(str_contains($presets, "tamasyaRbacCatalogKeys('capabilities')"), 'Registry capability membaca DB');
rbacCheck(str_contains($route, 'case \'rbac-catalog-save\''), 'Route 052 punya simpan katalog');
rbacCheck(str_contains($route, 'case \'rbac-access-group-save\''), 'Route 052 punya simpan access group');
rbacCheck(str_contains($route, "'catalog'=>tamasyaRbacCatalogPayload"), 'role-preset-list mengirim katalog dinamis');
rbacCheck(str_contains($staffJs, 'let rpTabLabels='), 'UI memakai label tab mutable');
rbacCheck(str_contains($staffJs, 'rpCatalogPairs(rpCatalog.capabilities)'), 'UI mengganti label capability dari katalog');
rbacCheck(str_contains($staffJs, 'rpTabLabels.map(([rpKey,rpLabel])') && str_contains($staffJs, 'rpCapLabels.map(([rpKey,rpLabel])') && str_contains($staffJs, 'rpNotifLabels.map(([rpKey,rpLabel])'), 'Grid izin akun dirender dari katalog tab/capability/notifikasi');
rbacCheck(str_contains($staffJs, 'X.desktopTabs[rpKey]') && str_contains($staffJs, 'X.capabilities[rpKey]') && str_contains($staffJs, 'X.telegramNotifications[rpKey]'), 'Grid izin akun membaca state permission akun');
rbacCheck(str_contains($staffJs, 'rpWebsiteLocked') && str_contains($staffJs, 'manage_public_website') && str_contains($staffJs, 'Uo(b,rpKey)'), 'Grid izin akun mempertahankan lock role + website');
rbacCheck(str_contains($appCore, '_K=(e,a,n)=>{') && str_contains($appCore, 'a[l]===!0:s.has(l)'), 'app-core memakai desktopTabs eksplisit lalu fallback role');
rbacCheck(str_contains($appCore, 'n.manage_public_website') && str_contains($appCore, 'l==="website"'), 'app-core memblokir website tanpa capability');
rbacCheck(str_contains($appShell, '_K(k,Z,O==null?void 0:O.capabilities)') && str_contains($appShell, 'sell_room_access===!0') && str_contains($appShell, 'manage_stay_access===!0'), 'app-shell memakai tab efektif + capability akses kamar');
rbacCheck(str_contains($appShell, 'AUDIT11-REACT-DOMAIN-NAV') && str_contains($appShell, 'window.TAMASYA_NAVIGATION'), 'app-shell mengekspos navigasi kanonik');
rbacCheck(str_contains($navigation, 'const TAMASYA_DOMAIN_NAV = Object.freeze') && str_contains($navigation, 'tamasyaVisibleDomainMembers') && str_contains($navigation, 'allowed.includes(permission)'), 'Registry navigasi tetap batas menu statis + filter permission');
rbacCheck(substr_count($rbac, '$sql .= " AND is_owner=0"') === 1, 'Filter owner tidak terduplikasi di role rows');
rbacCheck(str_contains($route, 'Access group minimal memiliki satu role.') && str_contains($route, 'sort($roles,SORT_STRING)') && str_contains($route, 'Role access group tidak dikenal'), 'Simpan access group: validasi role + dedupe/sort');
rbacCheck(str_contains($route, "\$groupKey==='admin_only'") && str_contains($route, "in_array('admin',\$roles,true)"), 'Grup admin_only wajib memuat admin');
rbacCheck(str_contains($route, "Access group sistem tidak dapat dihapus.") && str_contains($route, 'FROM rbac_legacy_role_sets WHERE group_key=?'), 'Hapus access group: guard sistem + pemakaian route');

echo 'Dynamic RBAC: '.$passed.' passed; 0 failed.'.PHP_EOL;
