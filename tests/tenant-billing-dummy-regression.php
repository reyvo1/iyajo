<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$passed = 0;
$check = function (bool $ok, string $label) use (&$passed): void {
    $passed++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};
$router = (string)file_get_contents($root . '/api/router.php');
$check(str_contains($router, "require __DIR__ . '/routes/119_tenant_billing.php';"), 'Router memuat route tenant billing dummy');
$route = (string)file_get_contents($root . '/api/routes/119_tenant_billing.php');
$check(str_contains($route, 'tenant_billing_accounts'), 'Route membuat tabel tenant_billing_accounts');
$check(str_contains($route, "command === 'suspend'"), 'Route punya command suspend');
$check(str_contains($route, "command === 'reopen'"), 'Route punya command reopen');
$check(str_contains($route, "command === 'auto-suspend'"), 'Route punya command auto-suspend');
$auth = (string)file_get_contents($root . '/api/routes/050_auth_staff.php');
$check(str_contains($auth, 'tenant_billing_accounts'), 'Login gate cek tenant_billing_accounts');
$check(str_contains($auth, "Tenant suspended"), 'Login ditolak dengan pesan Tenant suspended');
echo "Tenant billing dummy regression: " . $passed . " passed; 0 failed.\n";
