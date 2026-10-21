<?php
/**
 * Health dashboard terpadu: satu tampilan untuk kondisi hotel.
 *
 * Setiap bagian dihitung terpisah dan tidak boleh menggagalkan bagian lain:
 * tabel yang belum ada (modul opsional belum diaktifkan) harus muncul sebagai
 * "tidak tersedia", bukan membuat seluruh halaman mati.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (($action ?? '') !== 'system-health') return;
$routeHandled=true;
requireCapability($loggedInStaff, 'view_audit_log', ['admin', 'manager', 'finance', 'owner']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'error' => 'Kesehatan sistem hanya dapat dibaca dengan GET.']);
    return;
}
try {
    $section = static function (string $label, callable $probe): array {
        try {
            $value = $probe();
            return ['label' => $label, 'status' => 'ok', 'data' => $value];
        } catch (Throwable $error) {
            return ['label' => $label, 'status' => 'unavailable', 'error' => clientExceptionMessage('Bagian health gagal dibaca', $error)];
        }
    };
    $query = static function (PDO $pdo, string $sql, $default = null) {
        try {
            $stmt = $pdo->query($sql);
            if (!$stmt) { return $default; }
            $value = $stmt->fetchColumn();
            return $value === false ? $default : $value;
        } catch (Throwable $error) {
            return $default;
        }
    };

    $sections = [];

    $sections['identity'] = $section('Identitas & setup property', static function () use ($pdo, $query) {
        $setup = function_exists('tamasyaPropertySetupSnapshot') ? tamasyaPropertySetupSnapshot($pdo) : [];
        return [
            'ready' => (bool)($setup['ready'] ?? false),
            'setupStatus' => (string)($setup['setupStatus'] ?? 'unknown'),
            'currency' => function_exists('tamasyaPropertyCurrency') ? tamasyaPropertyCurrency($pdo) : 'IDR',
            'locale' => function_exists('tamasyaPropertyLocale') ? tamasyaPropertyLocale($pdo) : 'id-ID',
            'schemaMigrations' => (int)$query($pdo, 'SELECT COUNT(*) FROM `schema_migrations`', 0),
        ];
    });

    $sections['rbac'] = $section('Izin & katalog RBAC', static function () use ($pdo, $query) {
        $active = (int)$query($pdo, 'SELECT COUNT(*) FROM `rbac_capabilities` WHERE `active` = 1', 0);
        $inactive = (int)$query($pdo, 'SELECT COUNT(*) FROM `rbac_capabilities` WHERE `active` = 0', 0);
        $tabsInactive = (int)$query($pdo, 'SELECT COUNT(*) FROM `rbac_desktop_tabs` WHERE `active` = 0', 0);
        $presets = (int)$query($pdo, 'SELECT COUNT(*) FROM `role_capability_presets`', 0);
        $titles = (int)$query($pdo, 'SELECT COUNT(*) FROM `staff_job_titles` WHERE `active` = 1', 0);
        $staff = (int)$query($pdo, 'SELECT COUNT(*) FROM `staff` WHERE `status` = \'active\'', 0);
        $unmapped = [];
        try {
            $rows = $pdo->query('SELECT `role` FROM `role_capability_presets`')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $known = array_map(static fn($r) => strtolower(trim((string)$r)), (array)$rows);
            $activeStaff = $pdo->query('SELECT DISTINCT `role` FROM `staff` WHERE `status` = \'active\'')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ((array)$activeStaff as $role) {
                $role = strtolower(trim((string)$role));
                // Owner memang tidak memakai preset jabatan: haknya read-only
                // dan diturunkan dari paket Admin, jadi bukan role yang hilang.
                if ($role !== '' && $role !== 'owner' && !in_array($role, $known, true)) { $unmapped[] = $role; }
            }
        } catch (Throwable $error) { }
        return [
            'capabilitiesActive' => $active,
            'capabilitiesInactive' => $inactive,
            'desktopTabsInactive' => $tabsInactive,
            'rolePresets' => $presets,
            'activeJobTitles' => $titles,
            'activeStaff' => $staff,
            'rolesWithoutPreset' => $unmapped,
        ];
    });

    $sections['finance'] = $section('Kesehatan keuangan & backup', static function () use ($pdo, $query) {
        $metrics = function_exists('tamasyaFinancialIntegrityMetrics') ? tamasyaFinancialIntegrityMetrics($pdo) : null;
        $latest = null;
        try {
            $row = $pdo->query('SELECT id,status,created_at,restore_tested_at,size_bytes,checksum FROM `backup_runs` ORDER BY `created_at` DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $latest = [
                    'id' => (string)($row['id'] ?? ''),
                    'status' => (string)($row['status'] ?? ''),
                    'createdAt' => (string)($row['created_at'] ?? ''),
                    'restoreTestedAt' => (string)($row['restore_tested_at'] ?? ''),
                    'ageHours' => !empty($row['created_at']) ? round((time() - strtotime((string)$row['created_at'])) / 3600, 1) : null,
                ];
            }
        } catch (Throwable $error) { }
        return ['integrity' => $metrics, 'latestBackup' => $latest];
    });

    $sections['operations'] = $section('Shift, antrean & pengiriman', static function () use ($pdo, $query) {
        $openShift = null;
        try {
            $row = $pdo->query('SELECT id,shift_session_id,opened_at,status FROM `shift_sessions` ORDER BY `opened_at` DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) { $openShift = $row; }
        } catch (Throwable $error) { }
        return [
            'latestShift' => $openShift,
            'outboxPending' => (int)$query($pdo, 'SELECT COUNT(*) FROM `hybrid_outbox` WHERE `status` = \'pending\'', null),
            'outboxDeadLetter' => (int)$query($pdo, 'SELECT COUNT(*) FROM `hybrid_outbox` WHERE `status` = \'dead_letter\'', null),
            'deliveryFailed' => (int)$query($pdo, 'SELECT COUNT(*) FROM `communication_delivery_attempts` WHERE `status` = \'failed\'', null),
        ];
    });

    $sections['cluster'] = $section('Klaster & sinkronisasi', static function () use ($pdo, $query) {
        return [
            'nodeRole' => function_exists('tamasyaNodeRole') ? tamasyaNodeRole() : null,
            'nodeId' => function_exists('tamasyaNodeId') ? tamasyaNodeId() : null,
            'clusterId' => function_exists('tamasyaClusterId') ? tamasyaClusterId() : null,
            'syncEnabled' => function_exists('tamasyaNodeSyncEnabled') ? tamasyaNodeSyncEnabled() : null,
            'pendingSync' => (int)$query($pdo, 'SELECT COUNT(*) FROM `node_sync_queue` WHERE `status` <> \'done\'', null),
        ];
    });

    $sections['integrations'] = $section('Konfigurasi integrasi', static function () use ($pdo, $query) {
        $config = [];
        try {
            $row = $pdo->query("SELECT `smtp_host`,`smtp_user`,`smtp_password`,`telegram_bot_token` FROM `config` WHERE `id` = 'system_default'")->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) { $config = $row; }
        } catch (Throwable $error) { }
        return [
            'smtpConfigured' => trim((string)($config['smtp_host'] ?? '')) !== '',
            'telegramConfigured' => trim((string)($config['telegram_bot_token'] ?? '')) !== '',
        ];
    });

    // Ringkasan singkat: bagian yang tidak tersedia dan tanda pencampuran mata uang.
    $notices = [];
    foreach ($sections as $key => $section) {
        if (($section['status'] ?? '') !== 'ok') { $notices[] = $section['label'] . ' tidak tersedia'; }
    }
    if (function_exists('tamasyaTransactionCurrencyGuard')) {
        $guard = tamasyaTransactionCurrencyGuard($pdo);
        if (!empty($guard['mixedCurrencies'])) {
            $notices[] = 'Ada transaksi dengan mata uang ' . implode(', ', (array)$guard['foreignCurrencies']) . ' selain mata uang property (' . $guard['propertyCurrency'] . '). Penjumlahan lintas mata uang tidak boleh dipakai.';
        }
    }

    writeEnterpriseAudit($pdo, $loggedInStaff, 'Memeriksa kesehatan sistem', 'system', 'health', null, ['sections' => count($sections)], 'system_health');

    header('Cache-Control: no-store, private');
    echo tamasyaJsonEncode(['success' => true, 'readOnly' => true, 'data' => ['sections' => $sections, 'notices' => $notices]]);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('system_health:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'SYSTEM_HEALTH_FAILED', 'requestId' => tamasyaRuntimeRequestId()]);
}
