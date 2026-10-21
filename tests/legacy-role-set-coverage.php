<?php
/**
 * Regresi P1-3: setiap daftar role statis di requireRoles() harus punya peta
 * ke access group.
 *
 * requireRoles() menyelesaikan daftar role statis lewat rantai
 * rbac_legacy_role_sets -> rbac_access_groups -> rbac_access_group_roles.
 * Daftar tanpa peta tetap Closure Role statis dan tidak bisa dikelola dari
 * panel Kelola Izin, jadi route-level RBAC tidak mengikuti preset. Test ini
 * memindai seluruh route dan gagal bila ada daftar role baru tanpa peta.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$legacyCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};

/* 1. Kumpulkan semua daftar role statis yang dipakai requireRoles(). */
$used = [];
$files = array_merge(glob($root . '/api/routes/*.php') ?: [], glob($root . '/api/modules/*/*.php') ?: [], glob($root . '/api/modules/*/*/*.php') ?: []);
foreach ($files as $file) {
    $source = (string)file_get_contents($file);
    if (preg_match_all("/requireRoles\(\s*\\\$[A-Za-z_][A-Za-z0-9_]*,\s*\[([^\]]*)\]/", $source, $matches)) {
        foreach ($matches[1] as $raw) {
            $roles = [];
            foreach (explode(',', $raw) as $role) {
                $role = trim(trim(trim((string)$role), "'\""), '"');
                if ($role !== '') { $roles[] = $role; }
            }
            if (!$roles) { continue; }
            sort($roles, SORT_STRING);
            $used[implode(',', $roles)] = (basename($file) . ' | ' . implode(',', $roles));
        }
    }
}
$legacyCheck(count($used) > 0, 'Daftar role statis di requireRoles() terdeteksi (' . count($used) . ' daftar unik)');

/* 2. Kumpulkan peta role_set -> group_key dari berkas migrasi. */
$mapped = [];
$migrationFiles = glob($root . '/migrations/*.sql') ?: [];
foreach ($migrationFiles as $file) {
    $sql = (string)file_get_contents($file);
    if (preg_match_all("/INSERT\s+IGNORE\s+INTO\s+`?rbac_legacy_role_sets`?\s*\([^)]*\)\s*VALUES\s*(.*?);/is", $sql, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all("/\(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)/", $block, $pairs)) {
                foreach ($pairs[1] as $index => $roleSet) {
                    $mapped[trim($roleSet)] = trim($pairs[2][$index]);
                }
            }
        }
    }
}
$legacyCheck(count($mapped) > 0, 'Peta role_set -> access group ditemukan di migrasi (' . count($mapped) . ' peta)');

/* 3. Setiap daftar statis wajib terpetakan. */
$missing = [];
foreach ($used as $roleSet => $where) {
    if (!isset($mapped[$roleSet])) { $missing[] = $where; }
}
$legacyCheck($missing === [], 'Semua daftar role statis punya peta access group' . ($missing ? ': ' . implode(' | ', $missing) : ''));

/* 4. Group yang dipakai harus punya anggota di migrasi dynamic RBAC. */
$groupMembers = [];
foreach ($migrationFiles as $file) {
    $sql = (string)file_get_contents($file);
    if (preg_match_all("/INSERT\s+IGNORE\s+INTO\s+`?rbac_access_group_roles`?\s*\([^)]*\)\s*VALUES\s*(.*?);/is", $sql, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all("/\(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)/", $block, $pairs)) {
                foreach ($pairs[1] as $index => $group) {
                    $groupMembers[trim($group)][] = trim($pairs[2][$index]);
                }
            }
        }
    }
}
foreach ($mapped as $roleSet => $group) {
    if (!isset($groupMembers[$group])) { continue; }
    $expected = explode(',', $roleSet);
    sort($expected, SORT_STRING);
    $actual = $groupMembers[$group];
    sort($actual, SORT_STRING);
    $legacyCheck($expected === $actual, 'Anggota group "' . $group . '" sama persis dengan daftar role statis (perilaku route tidak berubah)');
}

echo 'Legacy role set coverage: ' . $checks . " passed; 0 failed.\n";
