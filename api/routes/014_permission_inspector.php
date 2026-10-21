<?php
/**
 * Permission inspector: menjelaskan kenapa sebuah akun bisa atau tidak bisa
 * melakukan sesuatu, dan lapisan mana yang memutuskan.
 *
 * Verdict diambil dari fungsi produksi yang sama (hasCapability /
 * hasDesktopTabAccess), sehingga jawaban inspector pasti sama dengan
 * perilaku aplikasi. Lapisan yang menentukan dilaporkan terpisah supaya
 * perbaikan tidak perlu membaca ulang kode.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (($action ?? '') !== 'permission-inspector') return;
$routeHandled=true;

// Membuka hak akses akun lain adalah aksi sensitivitas: memakai capability
// transparansi yang sudah ada agar tidak menambah capability baru yang belum
// ter-seed di database lama.
requireCapability($loggedInStaff, 'view_audit_log', ['admin', 'manager', 'finance', 'owner']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'error' => 'Inspector hak akses hanya dapat dibaca dengan GET.']);
    return;
}
try {
    $propertyId = strtolower(trim((string)($GLOBALS['tamasya_property_id'] ?? ($loggedInStaff['property_id'] ?? ''))));
    $targetId = trim((string)($_GET['staffId'] ?? ''));
    $targetName = trim((string)($_GET['username'] ?? ''));
    if ($targetId === '' && $targetName === '') {
        $targetId = (string)($loggedInStaff['id'] ?? '');
    }
    if ($targetId === '' && $targetName === '') {
        http_response_code(400);
        echo tamasyaJsonEncode(['success' => false, 'code' => 'TARGET_REQUIRED', 'error' => 'staffId atau username wajib diisi.']);
        return;
    }
    $target = null;
    if ($targetId !== '') {
        $stmt = $pdo->prepare('SELECT id, name, username, role, job_title, status, permissions FROM staff WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stmt = $pdo->prepare('SELECT id, name, username, role, job_title, status, permissions FROM staff WHERE username = ? LIMIT 1');
        $stmt->execute([$targetName]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!is_array($target)) {
        http_response_code(404);
        echo tamasyaJsonEncode(['success' => false, 'code' => 'ACCOUNT_NOT_FOUND', 'error' => 'Akun tidak ditemukan.']);
        return;
    }
    // Catatan scope: tabel staff tidak punya kolom property_id karena satu
    // database = satu property (HQ menggabungkan database terpisah). Batas
    // property pada basis data adalah batas database itu sendiri.

    $isOwner = function_exists('tamasyaIsOwnerRole') && tamasyaIsOwnerRole($target);
    $roleKey = tamasyaRbacRoleKeyForUser($target, $pdo);
    $preset = tamasyaRbacRolePreset($roleKey, $pdo);
    $override = $target['permissions'] ?? null;
    if (is_string($override)) {
        $trimmed = trim($override);
        $override = ($trimmed === '') ? null : json_decode($trimmed, true);
    }
    if (!is_array($override)) {
        $override = [];
    }
    $effective = tamasyaRbacEffectivePermissions($target, $pdo);

    $groups = ['capabilities', 'desktopTabs', 'telegramNotifications'];
    $decisions = [];
    foreach ($groups as $group) {
        $keys = [];
        $catalogRows = tamasyaRbacCatalogRows($group, $pdo);
        foreach ($catalogRows as $row) { $keys[(string)$row['key']] = true; }
        if (is_array($preset) && isset($preset[$group]) && is_array($preset[$group])) { foreach ($preset[$group] as $k => $v) { $keys[(string)$k] = true; } }
        foreach ($override[$group] ?? [] as $k => $v) { $keys[(string)$k] = true; }
        foreach (array_keys($keys) as $key) {
            $state = tamasyaRbacCatalogKeyState($group, (string)$key, $pdo);
            // Verdict memakai fungsi produksi yang sama dengan runtime aplikasi.
            if ($group === 'capabilities') {
                $verdict = hasCapability($target, (string)$key, []);
            } elseif ($group === 'desktopTabs') {
                $verdict = hasDesktopTabAccess($target, (string)$key, []);
            } else {
                $verdict = tamasyaPermissionOverride($override, $group, (string)$key);
                if ($verdict === null) { $verdict = false; }
            }
            // Lapisan yang menentukan, di urutan yang sama dengan runtime.
            $layer = 'fallback';
            $reason = 'Tidak ada preset/override; verdict akhir bergantung daftar role pada route pemanggil.';
            $overrideValue = tamasyaPermissionOverride($override, $group, (string)$key);
            $presetValue = is_array($preset) && isset($preset[$group][$key]) ? $preset[$group][$key] : null;
            if ($state === 'inactive') {
                $layer = 'catalog';
                $reason = 'Kunci dinonaktifkan di katalog ' . $group . ' -> deny (menang atas override dan preset).';
            } elseif ($isOwner) {
                $layer = 'owner';
                $reason = 'Owner membaca dengan paket Admin dan tidak dapat mengubah apa pun.';
            } elseif ($overrideValue !== null) {
                $layer = 'override';
                $reason = 'Override akun menentukan: ' . ($overrideValue ? 'true' : 'false') . '.';
            } elseif ($presetValue !== null) {
                $layer = 'preset';
                $reason = 'Preset jabatan "' . $roleKey . '" menentukan: ' . ($presetValue ? 'true' : 'false') . '.';
            } elseif ($state === 'missing') {
                $layer = 'catalog';
                $reason = 'Kunci tidak ada di katalog (instalasi lama) -> memakai fallback role.';
            }
            $decisions[] = [
                'group' => $group,
                'key' => (string)$key,
                'verdict' => (bool)$verdict,
                'layer' => $layer,
                'reason' => $reason,
                'catalogState' => $state,
                'presetValue' => $presetValue,
                'overrideValue' => $overrideValue,
            ];
        }
    }

    // Ringkasan per lapisan supaya mudah dipindai.
    $summary = ['catalog' => 0, 'owner' => 0, 'override' => 0, 'preset' => 0, 'fallback' => 0];
    $allowed = 0;
    foreach ($decisions as $decision) {
        $summary[$decision['layer']] = ($summary[$decision['layer']] ?? 0) + 1;
        if ($decision['verdict']) { $allowed++; }
    }

    // Peringatan: jabatan kustom yang belum punya baris preset akan DIABAIKAN
    // dan akun memakai preset role-nya. Itu sumber hak akses yang tidak
    // terduga, jadi harus terlihat.
    $warnings = [];
    $jobTitle = strtolower(trim((string)($target['job_title'] ?? '')));
    if ($jobTitle !== '' && $jobTitle !== 'owner' && $roleKey !== $jobTitle) {
        $warnings[] = 'Jabatan "' . $jobTitle . '" belum memiliki paket preset, sehingga preset peran "' . $roleKey . '" yang dipakai. Buka panel Kelola Jabatan sekali untuk membuat paketnya.';
    }
    if ($overridePresent = (count($override) > 0)) {
        $warnings[] = 'Akun ini punya override per-akun yang menang atas preset jabatan.';
    }
    if (is_array($preset) && isset($preset['capabilities']) && is_array($preset['capabilities'])) {
        $missing = [];
        foreach (['view_reports', 'view_audit_log', 'manage_tax_rules', 'manage_backup'] as $probe) {
            if (!array_key_exists($probe, $preset['capabilities'])) { $missing[] = $probe; }
        }
        if ($missing) {
            $warnings[] = 'Paket jabatan "' . $roleKey . '" belum punya kunci: ' . implode(', ', $missing) . '.';
        }
    }

    $result = [
        'warnings' => $warnings,
        'account' => [
            'id' => (string)($target['id'] ?? ''),
            'name' => (string)($target['name'] ?? ''),
            'username' => (string)($target['username'] ?? ''),
            'role' => (string)($target['role'] ?? ''),
            'jobTitle' => (string)($target['job_title'] ?? ''),
            'status' => (string)($target['status'] ?? ''),
            'isOwner' => (is_bool($isOwner) ? $isOwner : false),
        ],
        'roleKey' => $roleKey,
        'roleKeySource' => (strtolower((string)($target['job_title'] ?? '')) === $roleKey ? 'job_title' : 'role'),
        'presetFound' => is_array($preset),
        'presetSource' => 'role_capability_presets',
        'overridePresent' => count($override) > 0,
        'effective' => is_array($effective) ? $effective : null,
        'decisions' => $decisions,
        'summary' => $summary,
        'allowedCount' => $allowed,
        'totalCount' => count($decisions),
    ];

    writeEnterpriseAudit($pdo, $loggedInStaff, 'Memeriksa hak akses akun', 'staff', (string)($target['id'] ?? ''), null, ['roleKey' => $roleKey, 'decisions' => count($decisions)], 'permission_inspector');

    header('Cache-Control: no-store, private');
    echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => $result]);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('permission_inspector:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'PERMISSION_INSPECTION_FAILED', 'requestId' => tamasyaRuntimeRequestId()]);
}
