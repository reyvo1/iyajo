<?php
/**
 * TAMASYA Dynamic RBAC runtime.
 *
 * Registry role/capability/tab/notifikasi dan access group dibaca dari tabel
 * rbac_* (migrasi 20261021_DYNAMIC_RBAC.sql). File ini sengaja tidak memuat
 * daftar role/capability aktif: fallback statis hanya dipakai 021/017 ketika
 * tabel belum ada (instalasi lama atau unit test tanpa PDO), sehingga install
 * yang sudah dimigrasikan sepenuhnya mengikuti data database.
 *
 * Semua lookup dibungkus cache per-request dan gagal-tertutup: tabel yang tidak
 * ada/rusak mengembalikan null agar pemanggil memakai fallback lama, bukan
 * membuka akses baru.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** PDO aktif: argumen eksplisit, lalu koneksi runtime api.php. */
function tamasyaRbacPdo(?PDO $pdo = null): ?PDO {
    if ($pdo instanceof PDO) return $pdo;
    $runtime = $GLOBALS['tamasya_runtime_pdo'] ?? ($GLOBALS['pdo'] ?? null);
    return $runtime instanceof PDO ? $runtime : null;
}

function tamasyaRbacCacheKey(PDO $pdo, string $suffix): string {
    return (function_exists('spl_object_id') ? spl_object_id($pdo) : 'pdo') . ':' . $suffix;
}

function tamasyaRbacFlushCache(): void {
    $GLOBALS['tamasya_rbac_cache'] = [];
}

function tamasyaRbacCacheGet(string $key) {
    $cache = $GLOBALS['tamasya_rbac_cache'] ?? [];
    return array_key_exists($key, $cache) ? $cache[$key] : null;
}

function tamasyaRbacCacheHas(string $key): bool {
    $cache = $GLOBALS['tamasya_rbac_cache'] ?? [];
    return array_key_exists($key, $cache);
}

function tamasyaRbacCacheSet(string $key, $value): void {
    if (!isset($GLOBALS['tamasya_rbac_cache']) || !is_array($GLOBALS['tamasya_rbac_cache'])) $GLOBALS['tamasya_rbac_cache'] = [];
    $GLOBALS['tamasya_rbac_cache'][$key] = $value;
}

/** Cek tabel informasi_schema dengan cache; false bila query gagal. */
function tamasyaRbacTableExists(PDO $pdo, string $table): bool {
    $key = tamasyaRbacCacheKey($pdo, 'table:' . $table);
    if (tamasyaRbacCacheHas($key)) return (bool)tamasyaRbacCacheGet($key);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        $exists = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $exists = false;
    }
    tamasyaRbacCacheSet($key, $exists);
    return $exists;
}

/** Registry dianggap aktif hanya bila seluruh tabel inti sudah ada. */
function tamasyaRbacEnabled(?PDO $pdo = null): bool {
    $pdo = tamasyaRbacPdo($pdo);
    if (!$pdo) return false;
    foreach (['rbac_roles', 'rbac_capabilities', 'rbac_desktop_tabs', 'rbac_notification_groups', 'rbac_access_groups', 'rbac_access_group_roles', 'rbac_legacy_role_sets'] as $table) {
        if (!tamasyaRbacTableExists($pdo, $table)) return false;
    }
    return true;
}

/** Semua role aktif (termasuk owner bila diminta), urut sort_order. */
function tamasyaRbacRoleRows(?PDO $pdo = null, bool $includeOwner = false): array {
    $pdo = tamasyaRbacPdo($pdo);
    if (!$pdo || !tamasyaRbacTableExists($pdo, 'rbac_roles')) return [];
    $key = tamasyaRbacCacheKey($pdo, 'roles:' . ($includeOwner ? 'all' : 'nonowner'));
    if (tamasyaRbacCacheHas($key)) return (array)tamasyaRbacCacheGet($key);
    try {
        $sql = "SELECT role_key,label,is_owner,is_system,sort_order,active FROM rbac_roles WHERE active=1";
        if (!$includeOwner) $sql .= " AND is_owner=0";
        $sql .= " ORDER BY sort_order ASC, role_key ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'key' => (string)$row['role_key'],
            'label' => (string)$row['label'],
            'isOwner' => (int)$row['is_owner'] === 1,
            'isSystem' => (int)$row['is_system'] === 1,
            'sortOrder' => (int)$row['sort_order'],
            'active' => (int)$row['active'] === 1,
        ];
    }
    tamasyaRbacCacheSet($key, $result);
    return $result;
}

/** Role non-owner untuk registry paket jabatan (urutan kanonik DB). */
function tamasyaRbacRoleKeys(?PDO $pdo = null): array {
    return array_map(static fn(array $row): string => (string)$row['key'], tamasyaRbacRoleRows($pdo, false));
}

/** Definisi tabel untuk tiga grup registry. */
function tamasyaRbacCatalogDefinition(string $group): ?array {
    return [
        'capabilities' => ['table' => 'rbac_capabilities', 'column' => 'capability_key'],
        'desktopTabs' => ['table' => 'rbac_desktop_tabs', 'column' => 'tab_key'],
        'telegramNotifications' => ['table' => 'rbac_notification_groups', 'column' => 'group_key'],
    ][$group] ?? null;
}

/** Baris registry aktif satu grup. */
function tamasyaRbacCatalogRows(string $group, ?PDO $pdo = null): array {
    $def = tamasyaRbacCatalogDefinition($group);
    $pdo = tamasyaRbacPdo($pdo);
    if (!$def || !$pdo || !tamasyaRbacTableExists($pdo, $def['table'])) return [];
    $key = tamasyaRbacCacheKey($pdo, 'catalog:' . $group);
    if (tamasyaRbacCacheHas($key)) return (array)tamasyaRbacCacheGet($key);
    try {
        $rows = $pdo->query("SELECT `{$def['column']}` AS item_key,label,is_system,sort_order,active FROM `{$def['table']}` WHERE active=1 ORDER BY sort_order ASC, item_key ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'key' => (string)$row['item_key'],
            'label' => (string)$row['label'],
            'isSystem' => (int)$row['is_system'] === 1,
            'sortOrder' => (int)$row['sort_order'],
            'active' => (int)$row['active'] === 1,
        ];
    }
    tamasyaRbacCacheSet($key, $result);
    return $result;
}

/** Kunci registry satu grup; [] bila tabel belum ada. */
function tamasyaRbacCatalogKeys(string $group, ?PDO $pdo = null): array {
    return array_map(static fn(array $row): string => (string)$row['key'], tamasyaRbacCatalogRows($group, $pdo));
}

/** Payload katalog lengkap untuk UI/API. */
function tamasyaRbacCatalogPayload(?PDO $pdo = null): array {
    return [
        'roles' => tamasyaRbacRoleRows($pdo, true),
        'capabilities' => tamasyaRbacCatalogRows('capabilities', $pdo),
        'desktopTabs' => tamasyaRbacCatalogRows('desktopTabs', $pdo),
        'telegramNotifications' => tamasyaRbacCatalogRows('telegramNotifications', $pdo),
    ];
}

/** Paket satu role/jabatan dari role_capability_presets.
 *  null = baris belum ada (pemanggil boleh fallback ke base role);
 *  array = paket (JSON rusak menghasilkan semua grup kosong = deny). */
function tamasyaRbacRolePreset(string $roleKey, ?PDO $pdo = null): ?array {
    $pdo = tamasyaRbacPdo($pdo);
    $roleKey = strtolower(trim($roleKey));
    if ($roleKey === '' || !$pdo || !tamasyaRbacTableExists($pdo, 'role_capability_presets')) return null;
    $key = tamasyaRbacCacheKey($pdo, 'role-preset:' . $roleKey);
    if (tamasyaRbacCacheHas($key)) return tamasyaRbacCacheGet($key);
    try {
        $stmt = $pdo->prepare("SELECT permissions FROM role_capability_presets WHERE role=? LIMIT 1");
        $stmt->execute([$roleKey]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    if ($raw === false) {
        tamasyaRbacCacheSet($key, null);
        return null;
    }
    $decoded = is_string($raw) ? json_decode((string)$raw, true) : null;
    if (!is_array($decoded)) {
        $preset = ['desktopTabs' => [], 'telegramNotifications' => [], 'capabilities' => []];
    } elseif (function_exists('tamasyaRolePresetCoerce')) {
        $preset = tamasyaRolePresetCoerce($decoded);
    } else {
        $preset = $decoded;
    }
    tamasyaRbacCacheSet($key, $preset);
    return $preset;
}

/** Role efektif akun: job_title dinamis bila punya paket, selain itu base role. */
function tamasyaRbacRoleKeyForUser(array $user, ?PDO $pdo = null): string {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $job = strtolower(trim((string)($user['job_title'] ?? ($user['jobTitle'] ?? ''))));
    if ($job !== '' && $job !== 'owner' && tamasyaRbacRolePreset($job, $pdo) !== null) return $job;
    return $role;
}

/** Nilai satu izin dari paket role. null = role/paket tidak ada. */
function tamasyaRbacRolePermission(string $roleKey, string $group, string $permissionKey, ?PDO $pdo = null): ?bool {
    $preset = tamasyaRbacRolePreset($roleKey, $pdo);
    if ($preset === null) return null;
    $known = function_exists('tamasyaRbacCatalogKeys') ? tamasyaRbacCatalogKeys($group, $pdo) : [];
    if ($known === [] || !in_array($permissionKey, $known, true)) return null;
    $values = $preset[$group] ?? null;
    if (!is_array($values)) return false;
    if (!array_key_exists($permissionKey, $values)) return false;
    $value = $values[$permissionKey];
    if (is_bool($value)) return $value;
    if ($value === 1 || $value === '1') return true;
    if (is_string($value) && strtolower(trim($value)) === 'true') return true;
    return false;
}

/** Permission efektif akun: paket role + override akun (override menang per kunci). */
function tamasyaRbacEffectivePermissions(array $user, ?PDO $pdo = null): ?array {
    if (function_exists('tamasyaIsOwnerRole') && tamasyaIsOwnerRole($user)) return null;
    $roleKey = tamasyaRbacRoleKeyForUser($user, $pdo);
    $preset = tamasyaRbacRolePreset($roleKey, $pdo);
    if ($preset === null) return null;
    $override = $user['permissions'] ?? null;
    if (is_string($override)) $override = trim($override) === '' ? null : json_decode($override, true);
    if (!is_array($override)) $override = [];
    $merged = $preset;
    foreach (['desktopTabs', 'telegramNotifications', 'capabilities'] as $group) {
        $values = isset($merged[$group]) && is_array($merged[$group]) ? $merged[$group] : [];
        $incoming = isset($override[$group]) && is_array($override[$group]) ? $override[$group] : [];
        foreach ($incoming as $key => $value) {
            if (is_bool($value)) $values[$key] = $value;
            elseif ($value === 1 || $value === '1') $values[$key] = true;
            elseif ($value === 0 || $value === '0') $values[$key] = false;
            elseif (is_string($value) && strtolower(trim($value)) === 'true') $values[$key] = true;
            elseif (is_string($value) && strtolower(trim($value)) === 'false') $values[$key] = false;
            else $values[$key] = false;
        }
        $merged[$group] = $values;
    }
    foreach ($override as $key => $value) {
        if (!in_array($key, ['desktopTabs', 'telegramNotifications', 'capabilities'], true)) $merged[$key] = $value;
    }
    return $merged;
}

/** Access-group efektif untuk satu set role kode. null = belum dimapping,
 *  [] = group aktif tetapi tidak punya role (deny), array = role pengganti. */
function tamasyaRbacAccessGroupRoles(array $roles, ?PDO $pdo = null): ?array {
    $pdo = tamasyaRbacPdo($pdo);
    if (!$pdo || !tamasyaRbacEnabled($pdo)) return null;
    $normalized = [];
    foreach ($roles as $role) {
        $role = strtolower(trim((string)$role));
        if ($role !== '') $normalized[$role] = true;
    }
    $normalized = array_keys($normalized);
    sort($normalized, SORT_STRING);
    $roleSet = implode(',', $normalized);
    if ($roleSet === '') return null;
    $key = tamasyaRbacCacheKey($pdo, 'access-group:' . $roleSet);
    if (tamasyaRbacCacheHas($key)) return tamasyaRbacCacheGet($key);
    try {
        $stmt = $pdo->prepare("SELECT g.group_key,g.label,g.active FROM rbac_legacy_role_sets m JOIN rbac_access_groups g ON g.group_key=m.group_key WHERE m.role_set=? LIMIT 1");
        $stmt->execute([$roleSet]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            tamasyaRbacCacheSet($key, null);
            return null;
        }
        if ((int)$group['active'] !== 1) {
            tamasyaRbacCacheSet($key, null);
            return null;
        }
        $stmtRoles = $pdo->prepare("SELECT role_key FROM rbac_access_group_roles WHERE group_key=? ORDER BY role_key ASC");
        $stmtRoles->execute([(string)$group['group_key']]);
        $effective = array_values(array_unique(array_map('strval', $stmtRoles->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) {
        tamasyaRbacCacheSet($key, null);
        return null;
    }
    tamasyaRbacCacheSet($key, $effective);
    return $effective;
}

/** Daftar access group (+ anggota) untuk API/UI. */
function tamasyaRbacAccessGroupRows(?PDO $pdo = null): array {
    $pdo = tamasyaRbacPdo($pdo);
    if (!$pdo || !tamasyaRbacTableExists($pdo, 'rbac_access_groups')) return [];
    $key = tamasyaRbacCacheKey($pdo, 'access-groups');
    if (tamasyaRbacCacheHas($key)) return (array)tamasyaRbacCacheGet($key);
    try {
        $rows = $pdo->query("SELECT group_key,label,is_system,active FROM rbac_access_groups ORDER BY group_key ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $roleStmt = $pdo->prepare("SELECT role_key FROM rbac_access_group_roles WHERE group_key=? ORDER BY role_key ASC");
        $result = [];
        foreach ($rows as $row) {
            $roleStmt->execute([(string)$row['group_key']]);
            $result[] = [
                'key' => (string)$row['group_key'],
                'label' => (string)$row['label'],
                'isSystem' => (int)$row['is_system'] === 1,
                'active' => (int)$row['active'] === 1,
                'roles' => array_values(array_map('strval', $roleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])),
            ];
        }
    } catch (Throwable $e) {
        $result = [];
    }
    tamasyaRbacCacheSet($key, $result);
    return $result;
}

/** Tambah kunci registry baru ke semua paket role dengan nilai default.
 *  Kunci yang sudah ada tidak pernah ditimpa. */
function tamasyaRbacCatalogAddToPresets(PDO $pdo, string $group, string $key, bool $default = false): void {
    if (!tamasyaRbacTableExists($pdo, 'role_capability_presets')) return;
    $rows = $pdo->query("SELECT role,permissions FROM role_capability_presets")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $update = $pdo->prepare("UPDATE role_capability_presets SET permissions=? WHERE role=?");
    foreach ($rows as $row) {
        $decoded = is_string($row['permissions'] ?? null) ? json_decode((string)$row['permissions'], true) : null;
        if (!is_array($decoded)) continue;
        if (!isset($decoded[$group]) || !is_array($decoded[$group])) $decoded[$group] = [];
        if (array_key_exists($key, $decoded[$group])) continue;
        $decoded[$group][$key] = $default;
        $update->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)$row['role']]);
    }
    tamasyaRbacFlushCache();
}

/** Hapus kunci registry dari semua paket role. */
function tamasyaRbacCatalogRemoveFromPresets(PDO $pdo, string $group, string $key): void {
    if (!tamasyaRbacTableExists($pdo, 'role_capability_presets')) return;
    $rows = $pdo->query("SELECT role,permissions FROM role_capability_presets")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $update = $pdo->prepare("UPDATE role_capability_presets SET permissions=? WHERE role=?");
    foreach ($rows as $row) {
        $decoded = is_string($row['permissions'] ?? null) ? json_decode((string)$row['permissions'], true) : null;
        if (!is_array($decoded) || !isset($decoded[$group]) || !is_array($decoded[$group]) || !array_key_exists($key, $decoded[$group])) continue;
        unset($decoded[$group][$key]);
        $update->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)$row['role']]);
    }
    tamasyaRbacFlushCache();
}
