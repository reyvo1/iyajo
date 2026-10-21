<?php
/**
 * Regresi P0-2: permission inspector.
 *
 * Inspector menjawab "kenapa akun X tidak bisa Y". Agar jawabannya tidak
 * melenceng dari perilaku aplikasi, verdict WAJIB diambil dari fungsi
 * produksi yang sama (hasCapability / hasDesktopTabAccess).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$inspectorCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$route = $read('api/routes/014_permission_inspector.php');
$router = $read('api/router.php');
$manifest = json_decode($read('api/MODULARIZATION_MANIFEST.json'), true);

$inspectorCheck(str_contains($router, "require __DIR__ . '/routes/014_permission_inspector.php';"), 'Router memuat route inspector');
$inspectorCheck(in_array('014_permission_inspector.php', (array)($manifest['routeModules'] ?? []), true), 'Manifest mendaftarkan route inspector');
$inspectorCheck(str_contains($route, "if ((\$action ?? '') !== 'permission-inspector') return;"), 'Route hanya menangani action permission-inspector');
$inspectorCheck(str_contains($route, "'GET'"), 'Route menolak metode selain GET');
$inspectorCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'view_audit_log'"), 'Inspector dikunci capability transparansi');
$inspectorCheck(!preg_match('/\b(INSERT INTO|UPDATE `|DELETE FROM)\b/i', $route), 'Route tidak pernah menulis ke tabel lain');

/* Verdict harus berasal dari fungsi produksi, bukan implementasi ulang. */
$inspectorCheck(str_contains($route, 'hasCapability($target'), 'Verdict capability diambil dari hasCapability() produksi');
$inspectorCheck(str_contains($route, 'hasDesktopTabAccess($target'), 'Verdict tab diambil dari hasDesktopTabAccess() produksi');
$inspectorCheck(!preg_match('/function\s+\w*(Resolve|Effective)\w*\s*\(/', $route), 'Route tidak menduplikasi logika resolusi');

/* Laporan lapisan & alasan. */
$inspectorCheck(str_contains($route, "'layer'"), 'Keputusan melaporkan lapisan yang menentukan');
$inspectorCheck(str_contains($route, "'reason'"), 'Keputusan menyertakan alasan');
$inspectorCheck(str_contains($route, "'catalog'"), 'Lapisan katalog dilaporkan');
$inspectorCheck(str_contains($route, "'preset'"), 'Lapisan preset jabatan dilaporkan');
$inspectorCheck(str_contains($route, "'override'"), 'Lapisan override akun dilaporkan');
$inspectorCheck(str_contains($route, "'fallback'"), 'Lapisan fallback role dilaporkan');
$inspectorCheck(str_contains($route, "'summary'"), 'Ringkasan per lapisan tersedia');
$inspectorCheck(str_contains($route, "'decisions'"), 'Daftar keputusan per kunci tersedia');

/* Peringatan untuk sumber hak akses yang tidak terduga. */
$inspectorCheck(str_contains($route, "'warnings'"), 'Inspector menyediakan daftar peringatan');
$inspectorCheck(str_contains($route, 'belum memiliki paket preset'), 'Peringatan jabatan kustom tanpa paket preset');

/* Konteks akun & jejak akses. */
$inspectorCheck(str_contains($route, "'jobTitle'"), 'Konteks jabatan akun dilaporkan');
$inspectorCheck(str_contains($route, "'roleKeySource'"), 'Sumber role key (jabatan atau role) dilaporkan');
$inspectorCheck(str_contains($route, "'Memeriksa hak akses akun'"), 'Pembacaan hak akses dicatat sebagai jejak');
$inspectorCheck(str_contains($route, "tamasyaRbacEffectivePermissions(\$target"), 'Hak akses efektif diambil dari resolver produksi');

/* Target account wajib ada dan tidak boleh bocor. */
$inspectorCheck(str_contains($route, "ACCOUNT_NOT_FOUND"), 'Akun yang tidak ada ditolak 404');
$inspectorCheck(str_contains($route, "TARGET_REQUIRED"), 'Permintaan tanpa target ditolak');

/* ===== UI: panel governance (log audit + inspector) ===== */
$governance = $read('assets/chunks/governance.js');
$appCore = $read('assets/app-core.js');
$appShell = $read('assets/chunks/app-shell.js');
$inspectorCheck(str_contains($governance, 'permission-inspector'), 'Panel UI memanggil endpoint inspector');
$inspectorCheck(!preg_match('/action=audit-logs[^\n]{0,160}method:\s*"(POST|PUT|PATCH|DELETE)"/i', $governance), 'Panel log audit tidak pernah mengirim metode tulis');
$inspectorCheck(!preg_match('/<form|type:\s*"submit"/i', $governance), 'Panel UI tidak memakai form atau tombol submit');
$staffChunk = $read('assets/chunks/staff.js');
$inspectorCheck(str_contains($staffChunk, 'from "./governance.js?v='), 'Chunk staf mengimpor panel governance dengan token versi');
$inspectorCheck(str_contains($staffChunk, 't.jsx(TamasyaGovernancePanel,{})'), 'Panel governance dirender di tab staf');
$inspectorCheck(!str_contains($appCore, 'governance.js'), 'Panel governance tidak masuk graph eager (tetap lazy)');
$inspectorCheck(!str_contains($appShell, 'TamasyaGovernancePanel'), 'App-shell tidak perlu perubahan: panel terpasang di chunk staf');

$governanceCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$governance = $read('assets/chunks/governance.js');
$governanceCheck(str_contains($governance, 'Audit & Kendali'), 'Panel audit dan kendali punya judul yang terlihat');
$governanceCheck(str_contains($governance, 'Buka Audit & Kendali'), 'Tombol buka panel menyebut apa isinya');
$governanceCheck(str_contains($governance, 'Inspektur Izin'), 'Label tab inspektur izin memakai bahasa yang benar');
echo 'Permission inspector: ' . $checks . " passed; 0 failed.\n";
