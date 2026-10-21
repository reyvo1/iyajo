<?php
/**
 * Regresi P2-6: ulasan tamu.
 *
 * Undangan dibuat staf untuk kamar yang sudah checkout, tamu memberi rating
 * 1..5 melalui token, dan staf moderasi. Token adalah satu-satunya kunci akses
 * tamu dan hanya bisa dipakai sekali.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$reviewCheck = function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$read = function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};

$route = $read('api/routes/087_guest_reviews.php');
$api = $read('api.php');
$migration = $read('migrations/20261021_GUEST_REVIEWS.sql');
$canonical = $read('database_setup.sql');
$router = $read('api/router.php');
$manifest = json_decode($read('api/MODULARIZATION_MANIFEST.json'), true);

/* Registrasi */
$reviewCheck(str_contains($router, "require __DIR__ . '/routes/087_guest_reviews.php';"), 'Router memuat route ulasan');
$reviewCheck(in_array('087_guest_reviews.php', (array)($manifest['routeModules'] ?? []), true), 'Manifest mendaftarkan route ulasan');
$reviewCheck(str_contains($api, "'public-review'"), 'public-review terdaftar sebagai public action (tamu tidak bisa login)');
$reviewCheck(str_contains($api, "'guest-reviews'=>['staff','operations']"), 'guest-reviews mengikuti tab staff/operations');

/* Skema */
$reviewCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `guest_reviews`'), 'Migrasi membuat tabel guest_reviews');
$reviewCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `guest_review_invites`'), 'Migrasi membuat tabel guest_review_invites');
$reviewCheck(str_contains($canonical, '`guest_reviews`'), 'Skema kanonik punya tabel guest_reviews');
$reviewCheck(str_contains($canonical, '`guest_review_invites`'), 'Skema kanonik punya tabel guest_review_invites');
$reviewCheck(str_contains($canonical, "UNIQUE KEY `uq_guest_review_token`"), 'Token undangan unik');

/* Aturan main */
$reviewCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'sell_room_access'"), 'Undangan ulasan boleh dibuat staf layanan (resepsionis)');
$reviewCheck(str_contains($route, "requireCapability(\$loggedInStaff, 'manage_operational_settings'"), 'Moderasi ulasan dikunci capability pengelola');
$reviewCheck(str_contains($route, "if (\$rating < 1 || \$rating > 5)"), 'Rating dibatasi 1..5');
$reviewCheck(str_contains($route, "status='responded'"), 'Undangan ditutup setelah ulasan masuk (tidak bisa dinilai ulang)');
$reviewCheck(str_contains($route, 'random_bytes(24)'), 'Token undangan dibuat dari sumber acak yang kuat');
$reviewCheck(str_contains($route, "\$invite['status'] !== 'pending'"), 'Undangan yang sudah terjawab ditolak');
$reviewCheck(str_contains($route, "scope property aktif"), 'Ulasan di luar scope property ditolak');
$reviewCheck(!preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+`?(transactions|bookings|staff)`?/i', $route), 'Route tidak pernah menulis ke tabel transaksi, reservasi, atau staf');
$reviewCheck(str_contains($route, "'review-publish'") && str_contains($route, "'review-hide'") && str_contains($route, "'review-reply'"), 'Moderasi: tampilkan, sembunyikan, dan balas tersedia');

/* UI: tab ulasan di panel governance + halaman publik */
$governance = $read('assets/chunks/governance.js');
$reviewCheck(str_contains($governance, 'function GuestReviewsPanel'), 'Panel UI punya bagian ulasan');
$reviewCheck(str_contains($governance, 'action=guest-reviews'), 'Panel UI memanggil endpoint ulasan');
$reviewCheck(str_contains($governance, 'review-publish') && str_contains($governance, 'review-hide') && str_contains($governance, 'review-reply'), 'Panel UI menyediakan moderasi: tampilkan, sembunyikan, balas');
$reviewCheck(str_contains($governance, 'review-invite'), 'Panel UI bisa membuat undangan ulasan');
$publicPage = $read('webpublic/review.html');
$publicJs = $read('webpublic/assets/review.js');
$reviewCheck(str_contains($publicPage, 'assets/review.js'), 'Halaman publik memuat JS eksternal (CSP script-src self)');
$reviewCheck(str_contains($publicPage, 'runtime-config.js'), 'Halaman publik memuat runtime config untuk URL API');
$reviewCheck(str_contains($publicJs, 'TAMASYA_PUBLIC_API_URL'), 'Halaman publik memakai URL API absolut');
$reviewCheck(str_contains($publicJs, 'action=public-review'), 'Halaman publik mengirim penilaian ke action public-review');
$reviewCheck(str_contains($publicPage, 'maxlength="2000"'), 'Halaman publik membatasi panjang komentar');

echo 'Guest reviews: ' . $checks . " passed; 0 failed.\n";
