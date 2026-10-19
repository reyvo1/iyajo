<?php
/**
 * TAMASYA Fase 2 "Kelola Jabatan": registry paket + nilai default per jabatan.
 *
 * Enforcement izin tidak berubah: hasCapability/requireCapability (017) tetap
 * membaca override akun lalu fallback role. File ini hanya menyediakan registry
 * kanonik (capability/tab/notifikasi), nilai paket default per jabatan (cermin
 * preset editor staf di app-core.js), normalisasi entri paket dari klien, dan
 * pembanding paket vs kondisi akun untuk pratinjau penerapan. Route 052 yang
 * menulis; owner tidak pernah termasuk (paketnya tetap read-only).
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Jabatan non-owner yang paketnya dapat dikelola (owner read-only, lihat 017). */
function tamasyaRolePresetRoles(): array {
    return ['admin','manager','finance','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'];
}

/** Registry 19 capability: cermin tamasyaSessionPermissions (017) dan grid
 *  centangan editor staf (assets/chunks/staff.js). Urutan = urutan seed. */
function tamasyaCapabilityRegistry(): array {
    return ['approve_sensitive_actions','manage_sessions','resolve_sync_conflict','manage_tax_rules','manage_backup','view_audit_log','reconcile_payments','correct_booking_audit','view_guest_identity','manage_room_access','sell_room_access','manage_stay_access','receive_payment_access','perform_night_audit','perform_housekeeping','resolve_night_audit','manage_operational_settings','override_key_checkout','manage_public_website'];
}

/** Registry 15 tab workspace (= array `db` app-core.js; editor staf memakai daftar
 *  yang sama). pos/local_connect sengaja di luar paket (jalur owner/legacy). */
function tamasyaDesktopTabRegistry(): array {
    return ['dashboard','rooms','finance','savings','report','operations','website','support','telegram','staff','config','db_config','inventory','leaves','attendance'];
}

/** Registry 6 grup notifikasi Telegram. */
function tamasyaTelegramNotificationRegistry(): array {
    return ['bookings','finance','inventory','operations','hr','system'];
}

/** Paket centangan default per jabatan (= nilai preset app-core.js hari ini).
 *  Dipakai untuk seed tabel role_capability_presets saat pertama kali & sebagai
 *  fallback bila baris jabatan belum ada. Owner tidak termasuk (paket tetap). */
function tamasyaRolePresetSeed(): array {
    return [
        'admin' => [
            'desktopTabs' => ['dashboard'=>true, 'rooms'=>true, 'finance'=>true, 'savings'=>true, 'report'=>true, 'operations'=>true, 'website'=>true, 'support'=>true, 'telegram'=>true, 'staff'=>true, 'config'=>true, 'db_config'=>true, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>true, 'finance'=>true, 'inventory'=>true, 'operations'=>true, 'hr'=>true, 'system'=>true],
            'capabilities' => ['approve_sensitive_actions'=>true, 'manage_sessions'=>true, 'resolve_sync_conflict'=>true, 'manage_tax_rules'=>true, 'manage_backup'=>true, 'view_audit_log'=>true, 'reconcile_payments'=>true, 'correct_booking_audit'=>true, 'view_guest_identity'=>true, 'manage_room_access'=>true, 'sell_room_access'=>true, 'manage_stay_access'=>true, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>true, 'manage_operational_settings'=>true, 'override_key_checkout'=>true, 'manage_public_website'=>true],
        ],
        'manager' => [
            'desktopTabs' => ['dashboard'=>true, 'rooms'=>true, 'finance'=>false, 'savings'=>true, 'report'=>true, 'operations'=>true, 'website'=>true, 'support'=>true, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>true, 'finance'=>true, 'inventory'=>true, 'operations'=>true, 'hr'=>true, 'system'=>true],
            'capabilities' => ['approve_sensitive_actions'=>true, 'manage_sessions'=>true, 'resolve_sync_conflict'=>true, 'manage_tax_rules'=>true, 'manage_backup'=>false, 'view_audit_log'=>true, 'reconcile_payments'=>true, 'correct_booking_audit'=>true, 'view_guest_identity'=>true, 'manage_room_access'=>true, 'sell_room_access'=>true, 'manage_stay_access'=>true, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>true, 'manage_operational_settings'=>true, 'override_key_checkout'=>true, 'manage_public_website'=>true],
        ],
        'finance' => [
            'desktopTabs' => ['dashboard'=>true, 'rooms'=>false, 'finance'=>true, 'savings'=>true, 'report'=>true, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>true, 'inventory'=>false, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>true, 'reconcile_payments'=>true, 'correct_booking_audit'=>true, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
        'receptionist' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>true, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>true, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>true, 'finance'=>false, 'inventory'=>true, 'operations'=>true, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>true, 'manage_room_access'=>true, 'sell_room_access'=>true, 'manage_stay_access'=>true, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
        'cleaning_service' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>true, 'operations'=>true, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>true, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
        'keamanan' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>false, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>false, 'operations'=>true, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>true, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>true, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
        'koki' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>true, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
        'tukang_kebun' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>false, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>false, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
        'lain_lain' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>false, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>false, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false],
        ],
    ];
}

/** Paket default (seed) satu jabatan; null bila jabatan tidak dikenal. */
function tamasyaRolePresetDefault(string $role): ?array {
    $seed = tamasyaRolePresetSeed();
    return $seed[$role] ?? null;
}

/** Normalisasi entri paket dari klien. Terima array atau JSON string; ketiga grup
 *  wajib ada dan hanya boleh memuat kunci registry; nilai harus boolean tegas
 *  (true/false/1/0 — bukan null atau teks lain). Kunci yang tidak dikirim = false:
 *  paket selalu eksplisit, tidak ada warisan diam-diam dari baseline role. */
function tamasyaRolePresetNormalize($raw): array {
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') throw new InvalidArgumentException('Paket jabatan tidak boleh kosong.');
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException('Format paket jabatan tidak valid. Kirim objek JSON.');
        }
        $raw = $decoded;
    }
    if (!is_array($raw)) throw new InvalidArgumentException('Format paket jabatan tidak valid. Kirim objek JSON.');
    $groups = [
        'desktopTabs' => tamasyaDesktopTabRegistry(),
        'telegramNotifications' => tamasyaTelegramNotificationRegistry(),
        'capabilities' => tamasyaCapabilityRegistry(),
    ];
    $normalized = [];
    foreach ($groups as $group => $keys) {
        $input = $raw[$group] ?? null;
        if (!is_array($input)) throw new InvalidArgumentException('Grup paket '.$group.' wajib ada dan berupa objek.');
        foreach (array_keys($input) as $key) {
            if (!in_array((string)$key, $keys, true)) throw new InvalidArgumentException('Kunci '.$group.' tidak dikenal: '.$key);
        }
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) { $values[$key] = false; continue; }
            $value = $input[$key];
            if (is_bool($value)) $values[$key] = $value;
            elseif ($value === 1 || $value === '1') $values[$key] = true;
            elseif ($value === 0 || $value === '0') $values[$key] = false;
            elseif (is_string($value) && strtolower(trim($value)) === 'true') $values[$key] = true;
            elseif (is_string($value) && strtolower(trim($value)) === 'false') $values[$key] = false;
            else throw new InvalidArgumentException('Nilai '.$group.'.'.$key.' harus boolean.');
        }
        $normalized[$group] = $values;
    }
    return $normalized;
}

/** Pembacaan toleran untuk baris DB (paket lama yang kuncinya sudah berubah):
 *  setiap kunci registry diambil dengan tamasyaPermissionOverride, sisanya false. */
function tamasyaRolePresetCoerce($raw): array {
    $groups = [
        'desktopTabs' => tamasyaDesktopTabRegistry(),
        'telegramNotifications' => tamasyaTelegramNotificationRegistry(),
        'capabilities' => tamasyaCapabilityRegistry(),
    ];
    $coerced = [];
    foreach ($groups as $group => $keys) {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = (tamasyaPermissionOverride($raw, $group, $key) ?? false) === true;
        }
        $coerced[$group] = $values;
    }
    return $coerced;
}

/** Gabungkan paket (target) ke override akun: pertahankan kunci lain yang sudah
 *  ada (mis. readOnly), timpa tiga grup paket dengan nilai preset eksplisit. */
function tamasyaRolePresetBuildOverride(array $preset, $currentPermissions): array {
    if (is_string($currentPermissions) && trim($currentPermissions) !== '') $currentPermissions = json_decode($currentPermissions, true);
    $base = is_array($currentPermissions) ? $currentPermissions : [];
    $base['desktopTabs'] = $preset['desktopTabs'];
    $base['telegramNotifications'] = $preset['telegramNotifications'];
    $base['capabilities'] = $preset['capabilities'];
    return $base;
}

/** Diff paket vs kondisi akun untuk pratinjau/penentuan akun terdampak. "Sebelum"
 *  per kunci = override akun bila ada, jika tidak paket default jabatan (itulah
 *  yang tampil di editor dan berlaku sekarang). Kosong = akun tidak berubah. */
function tamasyaRolePresetAccountDiff(array $preset, string $role, $currentPermissions): array {
    $seed = tamasyaRolePresetDefault($role) ?: [];
    $groups = [
        'desktopTabs' => tamasyaDesktopTabRegistry(),
        'telegramNotifications' => tamasyaTelegramNotificationRegistry(),
        'capabilities' => tamasyaCapabilityRegistry(),
    ];
    $changes = [];
    foreach ($groups as $group => $keys) {
        foreach ($keys as $key) {
            $before = tamasyaPermissionOverride($currentPermissions, $group, $key);
            if ($before === null) $before = ($seed[$group][$key] ?? false) === true;
            $after = ($preset[$group][$key] ?? false) === true;
            if ($before !== $after) $changes[$group][$key] = ['before' => $before, 'after' => $after];
        }
    }
    return $changes;
}

/** Baris paket dari DB dalam urutan jabatan kanonik. Tabel yang belum ada (install
 *  lama sebelum migrasi) ditolak dengan pesan yang bisa ditindaklanjuti; jabatan
 *  yang belum punya baris langsung di-seed dari nilai default (idempoten). */
function tamasyaRolePresetRows(PDO $pdo): array {
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_capability_presets'")->fetchColumn();
    if ($exists === 0) {
        throw new InvalidArgumentException('Tabel role_capability_presets belum ada di database ini. Backup dulu, lalu jalankan migrasi migrations/20261019_ROLE_CAPABILITY_PRESETS.sql.');
    }
    $load = static function () use ($pdo): array {
        $rows = $pdo->query("SELECT role, permissions, updated_by, updated_at FROM role_capability_presets ORDER BY role")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byRole = [];
        foreach ($rows as $row) $byRole[(string)$row['role']] = $row;
        return $byRole;
    };
    $byRole = $load();
    $seed = tamasyaRolePresetSeed();
    $insert = null;
    foreach (tamasyaRolePresetRoles() as $role) {
        if (isset($byRole[$role])) continue;
        if ($insert === null) $insert = $pdo->prepare("INSERT IGNORE INTO role_capability_presets (role, permissions, updated_by) VALUES (?, ?, NULL)");
        $insert->execute([$role, json_encode($seed[$role] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    if ($insert !== null) $byRole = $load();
    $ordered = [];
    foreach (tamasyaRolePresetRoles() as $role) {
        if (isset($byRole[$role])) $ordered[] = $byRole[$role];
    }
    return $ordered;
}
