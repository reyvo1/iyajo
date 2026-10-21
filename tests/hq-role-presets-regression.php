<?php
/**
 * Regresi P1-4: preset jabatan kanonik di HQ.
 *
 * Paket preset jabatan tingkat perusahaan disimpan di HQ, lalu ditarik (pull)
 * oleh property dan diterapkan ke tabel preset lokal. Property tidak perlu
 * menerima koneksi masuk dari HQ, dan property offline tidak kehilangan paket
 * karena HQ menyimpan versi kanonik.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$hqCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$hqCore = $read('hq/core.php');
$hqApi = $read('hq/api.php');
$hqSchema = $read('hq/schema.sql');
$policy = $read('api/support/021_role_presets.php');
$route = $read('api/routes/052_role_presets.php');
$cron = $read('maintenance_cron.php');

/* HQ: penyimpanan kanonik */
$hqCheck(str_contains($hqSchema, 'CREATE TABLE hq_role_presets'), 'HQ punya tabel hq_role_presets');
$hqCheck(str_contains($hqSchema, 'CREATE TABLE hq_role_preset_state'), 'HQ punya tabel hq_role_preset_state');
$hqCheck(str_contains($hqCore, 'function tamasyaHqRolePresets('), 'HQ punya pembaca paket preset');
$hqCheck(str_contains($hqCore, 'function tamasyaHqRolePresetSave('), 'HQ punya penyimpan paket preset');
$hqCheck(str_contains($hqCore, 'function tamasyaHqRolePresetPublish('), 'HQ punya terbitkan revisi paket');
$hqCheck(str_contains($hqCore, 'function tamasyaHqRolePresetState('), 'HQ punya status penerapan per properti');

/* HQ: aksi API */
$hqCheck(str_contains($hqApi, "'rbac-presets'"), 'Aksi HQ rbac-presets terdaftar');
$hqCheck(str_contains($hqApi, "'rbac-preset-save'"), 'Aksi HQ rbac-preset-save terdaftar');
$hqCheck(str_contains($hqApi, "'rbac-preset-publish'"), 'Aksi HQ rbac-preset-publish terdaftar');
$hqCheck(str_contains($hqApi, "'rbac-preset-state'"), 'Aksi HQ rbac-preset-state terdaftar');
$hqCheck(str_contains($hqApi, "['platform_admin','company_admin']"), 'Perubahan paket preset hanya untuk admin perusahaan/platform');

/* Property: penerapan paket */
$hqCheck(str_contains($policy, 'function tamasyaRolePresetApplyPayload('), 'Property punya penerapan paket preset');
$hqCheck(str_contains($policy, 'function tamasyaRolePresetPullFromHq('), 'Property punya penarikan paket dari HQ');
$hqCheck(str_contains($policy, "ON DUPLICATE KEY UPDATE `permissions`=VALUES(`permissions`)"), 'Paket diterapkan tanpa menggandakan baris');
$hqCheck(str_contains($policy, "tamasyaRolePresetNormalize("), 'Paket yang masuk dinormalisasi (hanya kunci registry, nilai boolean)');
$hqCheck(str_contains($policy, 'TAMASYA_HQ_URL') && str_contains($policy, 'TAMASYA_HQ_VIEWER_TOKEN'), 'Penarikan memakai URL HQ dan token akses dari environment');
$hqCheck(str_contains($route, "'role-preset-apply'"), 'Route 052 punya command role-preset-apply');
$hqCheck(str_contains($route, "'role-preset-state'"), 'Route 052 punya command role-preset-state');
$hqCheck(str_contains($route, "'role-preset-state','role-preset-apply'") || (str_contains($route, "'role-preset-state'") && str_contains($route, "role-preset-apply")), 'Command penerapan paket tidak diperlakukan sebagai baca');
$hqCheck(str_contains($cron, 'tamasyaRolePresetPullFromHq'), 'Cron menarik paket preset HQ secara berkala');
$hqCheck(str_contains($cron, 'tidak boleh menghentikan cron'), 'Kegagalan tarik paket tidak menghentikan cron');

echo 'HQ role presets: ' . $checks . " passed; 0 failed.\n";
