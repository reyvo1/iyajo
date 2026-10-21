<?php
/**
 * Regresi P0-1: viewer log audit.
 *
 * Jejak audit sudah dikumpulkan hundreds call site, tetapi belum bisa dibaca.
 * Route ini harus tetap read-only, dikunci capability, discope ke property,
 * menyamarkan nilai sensitif, dan mencatat pembacaan log audit itu sendiri.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$auditCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$route = $read('api/routes/013_audit_logs.php');
$router = $read('api/router.php');
$manifest = json_decode($read('api/MODULARIZATION_MANIFEST.json'), true);
$policy = $read('api/support/017_authorization_policy.php');

/* Registrasi route: router dan manifest harus sinkron. */
$auditCheck(str_contains($router, "require __DIR__ . '/routes/013_audit_logs.php';"), 'Router memuat route log audit');
$auditCheck(in_array('013_audit_logs.php', (array)($manifest['routeModules'] ?? []), true), 'Manifest mendaftarkan route log audit');
$auditCheck(str_contains($route, "if ((\$action ?? '') !== 'audit-logs') return;"), 'Route hanya menangani action audit-logs');

/* Read-only: tidak ada jalur mutasi. */
$auditCheck(str_contains($route, "REQUEST_METHOD") && str_contains($route, "'GET'"), 'Route menolak metode selain GET');
$auditCheck(!preg_match('/\b(INSERT INTO|UPDATE `|DELETE FROM)\b/i', $route), 'Route tidak pernah menulis ke tabel lain');
$auditCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'view_audit_log'"), 'Pembacaan dikunci capability view_audit_log');
$auditCheck(str_contains($policy, "'view_audit_log'"), 'Capability view_audit_log terdaftar di policy');

/* Scope property: tanpa scope, log properti lain bisa terbaca. */
$auditCheck(str_contains($route, 'tamasya_property_id'), 'Route memakai scope property aktif');
$auditCheck(str_contains($route, "property_id = ?"), 'Query difilter property_id');
$auditCheck(str_contains($route, 'PROPERTY_SCOPE_UNRESOLVED'), 'Scope property yang tidak ter-resolve ditolak (fail-closed)');

/* Batas & paginasi: route tidak boleh menjadi vektor berat. */
$auditCheck(str_contains($route, 'min(200'), 'Batas jumlah baris dibatasi');
$auditCheck(str_contains($route, "LIMIT ' . \$limit . ' OFFSET ' . \$offset"), 'Paginasi memakai LIMIT/OFFSET terikat (bukan input mentah)');
$auditCheck(str_contains($route, 'COUNT(*)'), 'Jumlah total dihitung untuk paginasi');

/* Penyamaran nilai sensitif. */
$auditCheck(str_contains($route, '[REDACTED]'), 'Snapshot disamarkan untuk kunci sensitif');
foreach (['password', 'secret', 'token', 'signature', 'pin', 'otp', 'cvv'] as $needle) {
    $auditCheck(str_contains($route, "'" . $needle . "'"), 'Kunci sensitif disamar: ' . $needle);
}

/* Jejak pembacaan log audit sendiri. */
$auditCheck(str_contains($route, "'Membaca log audit'"), 'Pembacaan log audit dicatat sebagai jejak');
$auditCheck(str_contains($route, "'audit_view'"), 'Sumber jejak pembacaan log audit ditandai');

/* Filter yang disediakan UI. */
foreach ([
    'staffId' => 'filter staf',
    'entityType' => 'filter jenis entitas',
    'entityId' => 'filter id entitas',
    'auditAction' => 'filter aksi',
    'outcome' => 'filter hasil',
    'from' => 'filter tanggal mulai',
    'to' => 'filter tanggal akhir',
] as $param => $label) {
    $auditCheck(str_contains($route, "\$_GET['" . $param . "']"), 'UI dapat memfilter ' . $label);
}
$auditCheck(str_contains($route, "'facets'"), 'Route menyediakan facet untuk filter UI');

/* ===== UI: panel governance (log audit + inspector) ===== */
$governance = $read('assets/chunks/governance.js');
$appCore = $read('assets/app-core.js');
$appShell = $read('assets/chunks/app-shell.js');
$auditCheck(str_contains($governance, 'audit-logs'), 'Panel UI memanggil endpoint log audit');
$auditCheck(!preg_match('/action=audit-logs[^\n]{0,160}method:\s*"(POST|PUT|PATCH|DELETE)"/i', $governance), 'Panel log audit tidak pernah mengirim metode tulis');
$auditCheck(!preg_match('/<form|type:\s*"submit"/i', $governance), 'Panel UI tidak memakai form atau tombol submit');
$staffChunk = $read('assets/chunks/staff.js');
$auditCheck(str_contains($staffChunk, 'from "./governance.js?v='), 'Chunk staf mengimpor panel governance dengan token versi');
$auditCheck(str_contains($staffChunk, 't.jsx(TamasyaGovernancePanel,{})'), 'Panel governance dirender di tab staf');
$auditCheck(!str_contains($appCore, 'governance.js'), 'Panel governance tidak masuk graph eager (tetap lazy)');
$auditCheck(!str_contains($appShell, 'TamasyaGovernancePanel'), 'App-shell tidak perlu perubahan: panel terpasang di chunk staf');

echo 'Audit log viewer: ' . $checks . " passed; 0 failed.\n";
