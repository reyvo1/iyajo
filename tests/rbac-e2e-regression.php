<?php
/**
 * E2E Regression untuk alur RBAC penuh
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
$root = dirname(__DIR__);
require $root.'/api/support/017_authorization_policy.php';
require $root.'/api/support/021_role_presets.php';

$passed = 0;
function e2eCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) {
        echo "DEBUG: Check failed for: $name\n";
        throw new RuntimeException('FAIL E2E: '.$name);
    }
    $passed++;
    echo 'PASS E2E: '.$name.PHP_EOL;
}

$policy = file_get_contents($root.'/api/support/017_authorization_policy.php');
$rbac = file_get_contents($root.'/api/support/022_dynamic_rbac.php');
$presets = file_get_contents($root.'/api/support/021_role_presets.php');
$route52 = file_get_contents($root.'/api/routes/052_role_presets.php');
$staffJs = file_get_contents($root.'/assets/chunks/staff.js');
$appCore = file_get_contents($root.'/assets/app-core.js');
$appShell = file_get_contents($root.'/assets/chunks/app-shell.js');
$operationsJs = file_get_contents($root.'/assets/chunks/operations.js');
$navigation = file_get_contents($root.'/assets/navigation-registry.js');
$authRoutes = file_get_contents($root.'/api/routes/050_auth_staff.php');

/* ===== 1. PRESET -> AKUN ===== */
e2eCheck(str_contains($route52, "case 'role-preset-save'"), '052 command role-preset-save ada');
e2eCheck(str_contains($route52, 'role_capability_presets'), '052 simpan preset ke tabel');
e2eCheck(str_contains($route52, 'tamasyaRolePresetNormalize'), '052 normalisasi preset sebelum simpan');

e2eCheck(str_contains($route52, "case 'role-preset-apply'"), '052 command role-preset-apply ada');
e2eCheck(str_contains($route52, 'tamasyaRolePresetBuildOverride'), '052 pakai buildOverride preset+override');
e2eCheck(str_contains($route52, 'tamasyaRolePresetRows'), '052 baca preset rows dari DB');
e2eCheck(str_contains($route52, 'staff.permissions'), '052 tulis staff.permissions override');

/* ===== 2. CAPABILITY EFEKTIF ===== */
e2eCheck(str_contains($policy, 'tamasyaPermissionOverride($user[\'permissions\']??null,\'capabilities\''), 'hasCapability cek override akun');
e2eCheck(str_contains($policy, 'tamasyaRbacRolePermission($roleKey,\'capabilities\''), 'hasCapability baca preset role');
e2eCheck(str_contains($policy, "in_array(\$user['role'] ?? '', \$fallbackRoles, true)"), 'hasCapability fallback role statis');

e2eCheck(str_contains($policy, 'tamasyaPermissionOverride($user[\'permissions\']??null,\'desktopTabs\''), 'hasDesktopTabAccess cek override akun');
e2eCheck(str_contains($policy, 'tamasyaRbacRolePermission($roleKey,\'desktopTabs\''), 'hasDesktopTabAccess baca preset role');

e2eCheck(str_contains($rbac, 'function tamasyaRbacEffectivePermissions'), 'Helper effective permissions ada');
e2eCheck(str_contains($rbac, 'foreach ($incoming as $key => $value)') && str_contains($rbac, '$values[$key] = $value'), 'Effective permissions merge override ke preset');
e2eCheck(str_contains($rbac, 'tamasyaRbacCatalogKeyState($group, (string)$key, $pdo) === \'inactive\''), 'Effective permissions filter catalog inactive');

/* ===== 3. DEAKTIVASI = DENY (F-02) ===== */
$p1 = "tamasyaRbacCatalogKeyState('capabilities',(string)\$capability) === 'inactive'";
$p2 = "tamasyaRbacCatalogKeyState('desktopTabs',(string)\$tab) === 'inactive'";
$p3 = "if (\$state === 'inactive') return false;";
$p4 = "if (\$state !== 'active') return null;";
$p5 = "tamasyaRbacCatalogKeyState(\$group, (string)\$key, \$pdo) === 'inactive'";

e2eCheck(str_contains($policy, $p1) && str_contains($policy, 'return false;'), 'hasCapability: inactive capability = deny');
e2eCheck(str_contains($policy, "tamasyaRbacCatalogKeyState('desktopTabs',(string)\$tab) === 'inactive'") && str_contains($policy, 'return false;'), 'hasDesktopTabAccess: inactive tab = deny');
e2eCheck(str_contains($rbac, "if (\$state === 'inactive') return false;") && str_contains($rbac, "if (\$state !== 'active') return null;"), 'tamasyaRbacRolePermission: inactive = false, missing = null');
e2eCheck(str_contains($rbac, 'tamasyaRbacCatalogKeyState($group, (string)$key, $pdo) === \'inactive\'') && str_contains($rbac, '$merged[$group][$key] = false;'), 'Effective permissions set inactive = false');

e2eCheck(str_contains($rbac, 'tamasyaRbacCacheSet($key, []);') && str_contains($rbac, 'Grup yang sengaja dinonaktifkan harus menutup route'), 'Access group inactive = [] (deny route)');

/* ===== 4. OWNER READ-ONLY (F-01) ===== */
e2eCheck(str_contains($policy, 'function tamasyaOwnerMutationDenied($user, ?bool $isMutation = null)'), 'Owner mutation guard command-aware');
e2eCheck(str_contains($policy, 'if ($isMutation !== null) return $isMutation;'), 'Owner guard pakai command flag, bukan hanya HTTP method');
e2eCheck(str_contains($route52, '$isMutationCommand=!in_array($command,$readOnlyCommands,true)'), '052 bedakan command read vs mutasi');
e2eCheck(str_contains($route52, '$method!==\'POST\''), '052 mutasi wajib POST');
e2eCheck(str_contains($route52, 'tamasyaEnforceOwnerReadOnly($loggedInStaff,(string)$action,$isMutationCommand)'), '052 command-aware owner guard');

/* ===== 5. OVERRIDE RESET SAAT ROLE/JABATAN BERUBAH (F-03) ===== */
e2eCheck(str_contains($authRoutes, '$titleActuallyChanged') && str_contains($authRoutes, 'Jabatan/role baru adalah baseline baru'), '050 deteksi perubahan jabatan/role aktual');
e2eCheck(str_contains($authRoutes, 'permissions = ?') && str_contains($authRoutes, 'tamasyaRolePresetDefaultFor'), '050 reset permissions ke preset baru saat berubah');

/* ===== 6. STAFF/LEAVES GUARD (F-04) ===== */
e2eCheck(str_contains($authRoutes, 'requireDesktopTabAccess($loggedInStaff, \'staff\', [\'admin\',\'owner\'])'), '050 staff GET wajib tab staff');
e2eCheck(str_contains($authRoutes, 'requireDesktopTabAccess($loggedInStaff, \'leaves\', [\'admin\',\'manager\'])'), '050 leaves scope wajib tab leaves');
e2eCheck(str_contains($authRoutes, '$canSeeSensitiveStaff = in_array($loggedInStaff[\'role\'] ?? \'\', [\'admin\',\'owner\'], true)'), '050 data sensitif hanya admin/owner');

/* ===== 7. UI GATE CAPABILITY-BASED ===== */
e2eCheck(str_contains($appShell, '_K(k,Z,O==null?void 0:O.capabilities)'), 'app-shell _K pakai capability');
e2eCheck(str_contains($appShell, 'sell_room_access===!0') && str_contains($appShell, 'manage_stay_access===!0'), 'app-shell capability akses kamar');

e2eCheck(str_contains($navigation, 'module.permission && !ownerRead') && str_contains($navigation, 'tamasyaExplicitDesktopPermission'), 'Module dock cek explicit desktop permission');
e2eCheck(str_contains($navigation, 'module.requiredAnyTabs') && str_contains($navigation, 'allowedSet.has(tab)'), 'Module dock cek requiredAnyTabs');
e2eCheck(str_contains($navigation, 'tamasyaExplicitDesktopPermission(context.permissions, module.permission)'), 'Module dock cek permission dari context.permissions');

e2eCheck(str_contains($appShell, 'currentPermissions:O') && substr_count($appShell, 'currentPermissions:O') >= 10, 'App-shell forward currentPermissions ke banyak komponen');

e2eCheck(str_contains($rbac, 'tamasyaRbacCacheSet($key, []);') && str_contains($rbac, 'return [];'), 'Access group inactive return []');

e2eCheck(str_contains($route52, 'tamasyaEnforceOwnerReadOnly($loggedInStaff,(string)$action,$isMutationCommand)'), '052 command-aware owner guard');

$opsJs = file_get_contents($root.'/assets/chunks/operations.js');
e2eCheck(str_contains($opsJs, 'taxAllowed=ma("manage_tax_rules"') && str_contains($opsJs, 'Hn=ma("perform_night_audit"'), 'Operations UI pakai capability tax/night audit');

echo 'E2E RBAC Flow: '.$passed.' passed; 0 failed.'.PHP_EOL;
