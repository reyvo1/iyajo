<?php
/**
 * TAMASYA Fase 2/3 "Kelola Jabatan": registry paket + nilai default per jabatan.
 *
 * Enforcement izin tidak berubah: hasCapability/requireCapability (017) tetap
 * membaca override akun lalu fallback role. File ini hanya menyediakan registry
 * kanonik (capability/tab/notifikasi), nilai paket default per jabatan (cermin
 * preset editor staf di app-core.js), normalisasi entri paket dari klien, dan
 * pembanding paket vs kondisi akun untuk pratinjau penerapan. Route 052 yang
 * menulis; owner tidak pernah termasuk (paketnya tetap read-only).
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Jabatan non-owner yang paketnya dapat dikelola (owner read-only, lihat 017).
 *  Ini daftar 9 role kanonik/statis; jabatan dinamis dibaca dari staff_job_titles
 *  lewat tamasyaJobTitleRows(). Dipertahankan agar kontrak lama & tes tetap utuh. */
function tamasyaRolePresetRoles(): array {
    return ['admin','manager','finance','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'];
}

/** 9 jabatan sistem = role kanonik; key = base_role dan tidak dapat dihapus.
 *  Dipakai sebagai fallback bila tabel staff_job_titles belum dimigrasikan. */
function tamasyaJobTitleSystemDefaults(): array {
    return [
        'admin' => ['label' => 'Admin', 'base_role' => 'admin', 'sort_order' => 10],
        'manager' => ['label' => 'Manajer', 'base_role' => 'manager', 'sort_order' => 20],
        'finance' => ['label' => 'Keuangan', 'base_role' => 'finance', 'sort_order' => 30],
        'receptionist' => ['label' => 'Resepsionis', 'base_role' => 'receptionist', 'sort_order' => 40],
        'cleaning_service' => ['label' => 'Cleaning Service', 'base_role' => 'cleaning_service', 'sort_order' => 50],
        'keamanan' => ['label' => 'Keamanan', 'base_role' => 'keamanan', 'sort_order' => 60],
        'koki' => ['label' => 'Koki', 'base_role' => 'koki', 'sort_order' => 70],
        'tukang_kebun' => ['label' => 'Tukang Kebun', 'base_role' => 'tukang_kebun', 'sort_order' => 80],
        'lain_lain' => ['label' => 'Lain-lain', 'base_role' => 'lain_lain', 'sort_order' => 90],
    ];
}

/** Apakah kolom staff.job_title sudah ada (DB lama tanpa migrasi R19 tetap jalan;
 *  jabatan dinamis otomatis fallback ke 9 sistem dan penyimpanan memakai role). */
function tamasyaStaffJobTitleColumnExists(PDO $pdo): bool {
    static $cache = [];
    $key = function_exists('spl_object_id') ? spl_object_id($pdo) : 0;
    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'job_title'");
            $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/** Daftar jabatan aktif (sistem + dinamis) terurut. Tabel staff_job_titles yang
 *  belum ada dianggap berisi 9 jabatan sistem saja; baris sistem yang hilang
 *  disisipkan ulang (INSERT IGNORE) agar install lama tidak kehilangan jabatan. */
function tamasyaJobTitleRows(PDO $pdo): array {
    $defaults = tamasyaJobTitleSystemDefaults();
    $exists = 0;
    try {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_job_titles'")->fetchColumn();
    } catch (Throwable $e) {
        $exists = 0;
    }
    if ($exists === 0) {
        $rows = [];
        foreach ($defaults as $key => $meta) {
            $rows[] = ['title_key' => $key, 'label' => $meta['label'], 'base_role' => $meta['base_role'], 'is_system' => 1, 'sort_order' => $meta['sort_order'], 'active' => 1];
        }
        return $rows;
    }
    $load = static function () use ($pdo): array {
        return $pdo->query("SELECT title_key, label, base_role, is_system, sort_order, active FROM staff_job_titles WHERE active = 1 ORDER BY sort_order ASC, label ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };
    $rows = $load();
    $systemCount = 0;
    foreach ($rows as $row) if ((int)$row['is_system'] === 1) $systemCount++;
    if ($systemCount < count($defaults)) {
        try {
            $insert = $pdo->prepare("INSERT IGNORE INTO staff_job_titles (title_key,label,base_role,is_system,sort_order,active) VALUES (?,?,?,1,?,1)");
            foreach ($defaults as $key => $meta) $insert->execute([$key, $meta['label'], $meta['base_role'], $meta['sort_order']]);
            $rows = $load();
        } catch (Throwable $e) {
            // Bila insert gagal (mis. race/permission), tetap baca apa yang ada.
        }
    }
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'title_key' => (string)$row['title_key'],
            'label' => (string)$row['label'],
            'base_role' => (string)$row['base_role'],
            'is_system' => (int)$row['is_system'],
            'sort_order' => (int)$row['sort_order'],
            'active' => (int)$row['active'],
        ];
    }
    return $result;
}

/** Peta title_key => baris jabatan (untuk validasi/penyimpanan staf). */
function tamasyaJobTitleMap(PDO $pdo): array {
    $map = [];
    foreach (tamasyaJobTitleRows($pdo) as $row) $map[(string)$row['title_key']] = $row;
    return $map;
}

/** Role basis satu jabatan, atau null bila jabatan tidak dikenal/nonaktif. */
function tamasyaJobTitleBaseRole(PDO $pdo, string $title): ?string {
    $map = tamasyaJobTitleMap($pdo);
    $key = strtolower(trim($title));
    return isset($map[$key]) ? (string)$map[$key]['base_role'] : null;
}

/** Label tampilan satu jabatan (fallback ke key). */
function tamasyaJobTitleLabel(PDO $pdo, string $title): string {
    $map = tamasyaJobTitleMap($pdo);
    $key = strtolower(trim($title));
    return isset($map[$key]) ? (string)$map[$key]['label'] : $title;
}

/** Slug stabil dari label jabatan (huruf kecil, non-alfanumerik -> _), maks 30
 *  karakter agar muat kolom title_key/job_title. Keunikan dijamin pemanggil. */
function tamasyaJobTitleSlug(string $label): string {
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '') $slug = 'jabatan';
    return substr($slug, 0, 30);
}

/** Registry 22 capability: cermin tamasyaSessionPermissions (017) dan grid
 *  centangan editor staf (assets/chunks/staff.js). Urutan = urutan seed.
 *  Fase 3 menambahkan view_reports (095 laporan kanonik), view_support_inbox
 *  (017/018 kotak masuk publik), dan manage_facilities (096 master fasilitas). */
function tamasyaCapabilityRegistry(): array {
    return ['approve_sensitive_actions','manage_sessions','resolve_sync_conflict','manage_tax_rules','manage_backup','view_audit_log','reconcile_payments','correct_booking_audit','view_guest_identity','manage_room_access','sell_room_access','manage_stay_access','receive_payment_access','perform_night_audit','perform_housekeeping','resolve_night_audit','manage_operational_settings','override_key_checkout','manage_public_website','view_reports','view_support_inbox','manage_facilities'];
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
            'capabilities' => ['approve_sensitive_actions'=>true, 'manage_sessions'=>true, 'resolve_sync_conflict'=>true, 'manage_tax_rules'=>true, 'manage_backup'=>true, 'view_audit_log'=>true, 'reconcile_payments'=>true, 'correct_booking_audit'=>true, 'view_guest_identity'=>true, 'manage_room_access'=>true, 'sell_room_access'=>true, 'manage_stay_access'=>true, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>true, 'manage_operational_settings'=>true, 'override_key_checkout'=>true, 'manage_public_website'=>true, 'view_reports'=>true, 'view_support_inbox'=>true, 'manage_facilities'=>true],
        ],
        'manager' => [
            'desktopTabs' => ['dashboard'=>true, 'rooms'=>true, 'finance'=>false, 'savings'=>true, 'report'=>true, 'operations'=>true, 'website'=>true, 'support'=>true, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>true, 'finance'=>true, 'inventory'=>true, 'operations'=>true, 'hr'=>true, 'system'=>true],
            'capabilities' => ['approve_sensitive_actions'=>true, 'manage_sessions'=>true, 'resolve_sync_conflict'=>true, 'manage_tax_rules'=>true, 'manage_backup'=>false, 'view_audit_log'=>true, 'reconcile_payments'=>true, 'correct_booking_audit'=>true, 'view_guest_identity'=>true, 'manage_room_access'=>true, 'sell_room_access'=>true, 'manage_stay_access'=>true, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>true, 'manage_operational_settings'=>true, 'override_key_checkout'=>true, 'manage_public_website'=>true, 'view_reports'=>true, 'view_support_inbox'=>true, 'manage_facilities'=>true],
        ],
        'finance' => [
            'desktopTabs' => ['dashboard'=>true, 'rooms'=>false, 'finance'=>true, 'savings'=>true, 'report'=>true, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>true, 'inventory'=>false, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>true, 'reconcile_payments'=>true, 'correct_booking_audit'=>true, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>true, 'view_support_inbox'=>false, 'manage_facilities'=>false],
        ],
        'receptionist' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>true, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>true, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>true, 'finance'=>false, 'inventory'=>true, 'operations'=>true, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>true, 'manage_room_access'=>true, 'sell_room_access'=>true, 'manage_stay_access'=>true, 'receive_payment_access'=>true, 'perform_night_audit'=>true, 'perform_housekeeping'=>true, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>false, 'view_support_inbox'=>true, 'manage_facilities'=>false],
        ],
        'cleaning_service' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>true, 'operations'=>true, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>true, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>false, 'view_support_inbox'=>false, 'manage_facilities'=>false],
        ],
        'keamanan' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>false, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>false, 'operations'=>true, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>true, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>true, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>false, 'view_support_inbox'=>false, 'manage_facilities'=>false],
        ],
        'koki' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>true, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>true, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>false, 'view_support_inbox'=>false, 'manage_facilities'=>false],
        ],
        'tukang_kebun' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>false, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>false, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>false, 'view_support_inbox'=>false, 'manage_facilities'=>false],
        ],
        'lain_lain' => [
            'desktopTabs' => ['dashboard'=>false, 'rooms'=>false, 'finance'=>false, 'savings'=>true, 'report'=>false, 'operations'=>true, 'website'=>false, 'support'=>false, 'telegram'=>true, 'staff'=>false, 'config'=>false, 'db_config'=>false, 'inventory'=>false, 'leaves'=>true, 'attendance'=>true],
            'telegramNotifications' => ['bookings'=>false, 'finance'=>false, 'inventory'=>false, 'operations'=>false, 'hr'=>false, 'system'=>false],
            'capabilities' => ['approve_sensitive_actions'=>false, 'manage_sessions'=>false, 'resolve_sync_conflict'=>false, 'manage_tax_rules'=>false, 'manage_backup'=>false, 'view_audit_log'=>false, 'reconcile_payments'=>false, 'correct_booking_audit'=>false, 'view_guest_identity'=>false, 'manage_room_access'=>false, 'sell_room_access'=>false, 'manage_stay_access'=>false, 'receive_payment_access'=>false, 'perform_night_audit'=>false, 'perform_housekeeping'=>false, 'resolve_night_audit'=>false, 'manage_operational_settings'=>false, 'override_key_checkout'=>false, 'manage_public_website'=>false, 'view_reports'=>false, 'view_support_inbox'=>false, 'manage_facilities'=>false],
        ],
    ];
}

/** Paket default (seed) satu jabatan; null bila jabatan tidak dikenal. */
function tamasyaRolePresetDefault(string $role): ?array {
    $seed = tamasyaRolePresetSeed();
    return $seed[$role] ?? null;
}

/** Paket default untuk jabatan apa pun: jabatan sistem memakai seed sendiri,
 *  jabatan dinamis memakai seed base_role-nya (fallback lain_lain). */
function tamasyaRolePresetDefaultFor(string $role, ?string $baseRole = null): array {
    $seed = tamasyaRolePresetSeed();
    if (isset($seed[$role])) return $seed[$role];
    $base = ($baseRole !== null && isset($seed[$baseRole])) ? $baseRole : 'lain_lain';
    return $seed[$base];
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

/** Tambal paket baris lama dengan kunci registry yang belum ada (mis. baris yang
 *  dibuat sebelum migrasi Fase 3): kunci absen diisi nilai seed jabatan, kunci
 *  dan centangan yang sudah tersimpan tidak pernah diubah. Murni; pemanggil
 *  membandingkan hasil untuk menentukan perlu tidaknya tulis-balik. */
function tamasyaRolePresetBackfill(array $preset, string $role, ?string $baseRole = null): array {
    $seed = tamasyaRolePresetDefaultFor($role, $baseRole);
    $groups = [
        'desktopTabs' => tamasyaDesktopTabRegistry(),
        'telegramNotifications' => tamasyaTelegramNotificationRegistry(),
        'capabilities' => tamasyaCapabilityRegistry(),
    ];
    foreach ($groups as $group => $keys) {
        $current = (isset($preset[$group]) && is_array($preset[$group])) ? $preset[$group] : [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $current)) $current[$key] = ($seed[$group][$key] ?? false) === true;
        }
        $preset[$group] = $current;
    }
    return $preset;
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
function tamasyaRolePresetAccountDiff(array $preset, string $role, $currentPermissions, ?string $baseRole = null): array {
    $seed = tamasyaRolePresetDefaultFor($role, $baseRole);
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
 *  yang belum punya baris langsung di-seed dari nilai default (idempoten), dan
 *  baris lama yang belum memuat kunci registry terbaru ditambal dari seed saat
 *  dibaca (idempoten) supaya penerapan tidak pernah menuliskan false diam-diam
 *  untuk capability yang baru ada. */
function tamasyaRolePresetRows(PDO $pdo): array {
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_capability_presets'")->fetchColumn();
    if ($exists === 0) {
        throw new InvalidArgumentException('Tabel role_capability_presets belum ada di database ini. Backup dulu, lalu jalankan migrasi migrations/20261019_ROLE_CAPABILITY_PRESETS.sql dan migrations/20261019_ROLE_CAPABILITY_PRESETS_FASE3.sql.');
    }
    $load = static function () use ($pdo): array {
        $rows = $pdo->query("SELECT role, permissions, updated_by, updated_at FROM role_capability_presets ORDER BY role")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byRole = [];
        foreach ($rows as $row) $byRole[(string)$row['role']] = $row;
        return $byRole;
    };
    $titles = tamasyaJobTitleRows($pdo);
    $titleByKey = [];
    foreach ($titles as $titleRow) $titleByKey[(string)$titleRow['title_key']] = $titleRow;
    $byRole = $load();
    $insert = null;
    foreach ($titles as $titleRow) {
        $role = (string)$titleRow['title_key'];
        if (isset($byRole[$role])) continue;
        $default = tamasyaRolePresetDefaultFor($role, (string)$titleRow['base_role']);
        if ($insert === null) $insert = $pdo->prepare("INSERT IGNORE INTO role_capability_presets (role, permissions, updated_by) VALUES (?, ?, NULL)");
        $insert->execute([$role, json_encode($default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    if ($insert !== null) $byRole = $load();
    $healed = false;
    $update = null;
    foreach ($byRole as $role => $row) {
        if (!isset($titleByKey[(string)$role])) continue;
        $current = is_string($row['permissions'] ?? null) ? json_decode((string)$row['permissions'], true) : null;
        if (!is_array($current)) continue;
        $merged = tamasyaRolePresetBackfill($current, (string)$role, (string)$titleByKey[(string)$role]['base_role']);
        if ($merged === $current) continue;
        if ($update === null) $update = $pdo->prepare("UPDATE role_capability_presets SET permissions=? WHERE role=?");
        $update->execute([json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $role]);
        $healed = true;
    }
    if ($healed) $byRole = $load();
    $ordered = [];
    foreach ($titles as $titleRow) {
        $role = (string)$titleRow['title_key'];
        if (!isset($byRole[$role])) continue;
        $row = $byRole[$role];
        $row['label'] = (string)$titleRow['label'];
        $row['baseRole'] = (string)$titleRow['base_role'];
        $row['isSystem'] = (int)$titleRow['is_system'] === 1;
        $ordered[] = $row;
    }
    return $ordered;
}
