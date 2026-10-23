<?php
/**
 * Regresi P3-7: kesehatan sistem terpadu.
 *
 * Satu tampilan untuk kondisi hotel: identitas & setup, RBAC, keuangan &
 * backup, shift & antrean, klaster, dan integrasi. Setiap bagian harus
 * berdiri sendiri: tabel yang belum ada (modul opsional belum aktif)
 * appeared sebagai "tidak tersedia", bukan membuat seluruh halaman mati.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$healthCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$route = $read('api/routes/086_system_health.php');
$router = $read('api/router.php');
$manifest = json_decode($read('api/MODULARIZATION_MANIFEST.json'), true);
$api = $read('api.php');
$healthUi = $read('assets/system-health-addon.js');

$healthCheck(str_contains($router, "require __DIR__ . '/routes/086_system_health.php';"), 'Router memuat route system-health');
$healthCheck(in_array('086_system_health.php', (array)($manifest['routeModules'] ?? []), true), 'Manifest mendaftarkan route system-health');
$healthCheck(str_contains($route, "if ((\$action ?? '') !== 'system-health') return;"), 'Route hanya menangani action system-health');
$healthCheck(str_contains($route, "'GET'"), 'Route menolak metode selain GET');
$healthCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'view_audit_log'"), 'Health dikunci capability transparansi');
$healthCheck(!preg_match('/\b(INSERT INTO|UPDATE `|DELETE FROM)\b/i', $route), 'Route tidak pernah menulis ke tabel lain');

/* Bagian-bagian health. */
foreach ([
    'identity' => 'identitas & setup property',
    'rbac' => 'izin & katalog RBAC',
    'finance' => 'kesehatan keuangan & backup',
    'operations' => 'shift, antrean & pengiriman',
    'cluster' => 'klaster & sinkronisasi',
    'integrations' => 'konfigurasi integrasi',
] as $key => $label) {
    $healthCheck(str_contains($route, "'" . $key . "'"), 'Bagian health tersedia: ' . $label);
}

/* Setiap bagian harus tahan kegagalan sendiri. */
$healthCheck(str_contains($route, "function (string \$label, callable \$probe)"), 'Setiap bagian dibungkus pelindung kegagalan');
$healthCheck(str_contains($route, "'status' => 'unavailable'"), 'Bagian yang tidak tersedia ditandai, bukan menggagalkan halaman');
$healthCheck(str_contains($route, "'notices'"), 'Ringkasan singkat tersedia');

// Deteksi role tanpa preset (owner dikecualikan secara sadar).
$healthCheck(str_contains($route, "\$role !== 'owner'"), 'Role owner tidak dilaporkan sebagai role tanpa preset');
$healthCheck(str_contains($route, "'rolesWithoutPreset'"), 'Daftar role tanpa preset dilaporkan');

// Campuran mata uang harus terlihat.
$healthCheck(str_contains($route, 'mixedCurrencies'), 'Pencampuran mata uang dimunculkan sebagai peringatan');
$healthCheck(str_contains($api, "'system-health'=>['staff','config','db_config']"), 'Endpoint mengikuti tab staff/config/db_config');

$healthCheck(str_contains($healthUi, 'op.plans?.data?.data?.rows'), 'Ringkasan paket membaca payload API yang benar');
$healthCheck(str_contains($healthUi, 'op.rates?.data?.data?.rows'), 'Ringkasan kurs membaca payload API yang benar');
$healthCheck(str_contains($healthUi, 'Number(r.published||0)!==1'), 'Ulasan menunggu moderasi dihitung dari published, bukan status');
$healthCheck(!str_contains($healthUi, 'op.plans?.json'), 'Panel tidak lagi membaca bentuk respons lama');

echo 'System health: ' . $checks . " passed; 0 failed.\n";
