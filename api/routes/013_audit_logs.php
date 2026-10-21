<?php
/**
 * Audit log viewer. Read-only, capability-gated, property-scoped, paginated.
 *
 * Jejak audit sudah lama dikumpulkan (ratusan call site) tetapi belum bisa
 * dilihat. Route ini membuka pembacaan saja: tidak ada mutasi, tidak ada
 * parameter yang mengubah data. Pembacaan log audit itu sendiri dicatat
 * sebagai jejak-compliant.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
/** Escape wildcard LIKE agar filter teks tidak berubah makna. */
if (!function_exists('selfAuditLikeEscape')) {
    function selfAuditLikeEscape(string $value): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

/**
 * Samarkan nilai sensitif pada snapshot JSON. Snapshot sudah dibersihkan saat
 * ditulis, lapisan ini menutup kemungkinan field baru yang belum masuk daftar.
 */
if (!function_exists('selfAuditRedactSnapshot')) {
function selfAuditRedactSnapshot($snapshot): ?string {
    if ($snapshot === null || $snapshot === '') return $snapshot === '' ? null : $snapshot;
    if (!is_string($snapshot)) return null;
    $decoded = json_decode($snapshot, true);
    if (!is_array($decoded)) return $snapshot;
    $sensitive = ['password', 'passwordhash', 'passwd', 'secret', 'token', 'apikey', 'api_key', 'privatekey', 'private_key', 'signature', 'hash', 'pin', 'otp', 'cvv', 'cardnumber', 'card_number', 'bridge_token', 'smtp_password'];
    $redact = function ($value) use (&$redact, $sensitive) {
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string)$key);
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if ($normalized === $needle || str_contains($normalized, $needle)) { $isSensitive = true; break; }
            }
            $out[$key] = $isSensitive ? '[REDACTED]' : (is_array($item) ? $redact($item) : $item);
        }
        return $out;
    };
    return json_encode($redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
}

if (($action ?? '') !== 'audit-logs') return;
$routeHandled=true;

// Kunci akses memakai capability, bukan daftar role mentah: capability
// 'view_audit_log' mengikuti preset jabatan dan katalog capability, jadi
// Owner (read-only) juga boleh membaca sesuai kontrak workspace.
requireCapability($loggedInStaff, 'view_audit_log', ['admin', 'manager', 'finance', 'owner']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'error' => 'Log audit hanya dapat dibaca dengan GET.']);
    return;
}
try {
    // Scope property wajib: tanpa scope, log properti lain bisa terbaca.
    $propertyId = strtolower(trim((string)($GLOBALS['tamasya_property_id'] ?? ($loggedInStaff['property_id'] ?? ''))));
    if ($propertyId === '' || $propertyId === 'default') {
        http_response_code(409);
        echo tamasyaJsonEncode(['success' => false, 'code' => 'PROPERTY_SCOPE_UNRESOLVED', 'error' => 'Scope property belum terkonfigurasi sehingga log audit tidak dapat dibaca.']);
        return;
    }
    if (!tamasyaTableExists($pdo, 'audit_logs')) {
        http_response_code(409);
        echo tamasyaJsonEncode(['success' => false, 'code' => 'AUDIT_SCHEMA_MISSING', 'error' => 'Tabel audit_logs belum ada di database ini.']);
        return;
    }

    $staffId = trim((string)($_GET['staffId'] ?? ''));
    $entityType = trim((string)($_GET['entityType'] ?? ''));
    $entityId = trim((string)($_GET['entityId'] ?? ''));
    $auditAction = trim((string)($_GET['auditAction'] ?? ''));
    $outcome = strtolower(trim((string)($_GET['outcome'] ?? '')));
    $source = trim((string)($_GET['source'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $search = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where = ['property_id = ?'];
    $args = [$propertyId];
    if ($staffId !== '') { $where[] = 'staff_id = ?'; $args[] = substr($staffId, 0, 50); }
    if ($entityType !== '') { $where[] = 'entity_type = ?'; $args[] = substr($entityType, 0, 100); }
    if ($entityId !== '') { $where[] = 'entity_id = ?'; $args[] = substr($entityId, 0, 190); }
    if ($auditAction !== '') { $where[] = 'action LIKE ?'; $args[] = '%' . selfAuditLikeEscape($auditAction) . '%'; }
    if ($source !== '') { $where[] = 'source = ?'; $args[] = substr($source, 0, 50); }
    if (in_array($outcome, ['success', 'rejected', 'failed', 'pending', 'manual'], true)) { $where[] = 'outcome = ?'; $args[] = $outcome; }
    if ($from !== '') { $where[] = 'created_at >= ?'; $args[] = $from . ' 00:00:00'; }
    if ($to !== '') { $where[] = 'created_at <= ?'; $args[] = $to . ' 23:59:59'; }
    if ($search !== '') {
        $like = '%' . selfAuditLikeEscape($search) . '%';
        $where[] = '(action LIKE ? OR entity_id LIKE ? OR staff_name LIKE ? OR entity_type LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $sql = 'SELECT id, staff_id, staff_name, staff_role, source, channel_id, action, entity_type, entity_id,'
        . ' old_data, new_data, outcome, ip_address, device_id, session_id, request_id, operation_id,'
        . ' node_id, cluster_id, property_id, created_at'
        . ' FROM audit_logs WHERE ' . $whereSql
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE ' . $whereSql);
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    // Facet untuk filter UI: dibatasi supaya tidak menjadi query berat.
    $facetStmt = $pdo->prepare('SELECT action, COUNT(*) AS total FROM audit_logs WHERE property_id = ? GROUP BY action ORDER BY total DESC, action ASC LIMIT 50');
    $facetStmt->execute([$propertyId]);
    $actionFacets = $facetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $entityStmt = $pdo->prepare('SELECT entity_type, COUNT(*) AS total FROM audit_logs WHERE property_id = ? AND entity_type IS NOT NULL AND entity_type <> "" GROUP BY entity_type ORDER BY total DESC, entity_type ASC LIMIT 50');
    $entityStmt->execute([$propertyId]);
    $entityFacets = $entityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $outcomeStmt = $pdo->prepare('SELECT outcome, COUNT(*) AS total FROM audit_logs WHERE property_id = ? GROUP BY outcome ORDER BY total DESC');
    $outcomeStmt->execute([$propertyId]);
    $outcomeFacets = $outcomeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Pengaman kedua: nilai sensitif pada snapshot tetap disamarkan meskipun
    // snapshot sudah dibersihkan saat ditulis.
    foreach ($rows as &$row) {
        $row['old_data'] = selfAuditRedactSnapshot($row['old_data'] ?? null);
        $row['new_data'] = selfAuditRedactSnapshot($row['new_data'] ?? null);
    }
    unset($row);

    // Pembacaan log audit adalah akses data sensitif: catat sebagai jejak.
    writeEnterpriseAudit(
        $pdo,
        $loggedInStaff,
        'Membaca log audit',
        'audit_log',
        null,
        null,
        ['filters' => ['staffId' => $staffId, 'entityType' => $entityType, 'entityId' => $entityId, 'action' => $auditAction, 'outcome' => $outcome, 'from' => $from, 'to' => $to], 'returned' => count($rows), 'total' => $total],
        'audit_view'
    );

    header('Cache-Control: no-store, private');
    echo tamasyaJsonEncode([
        'success' => true,
        'readOnly' => true,
        'data' => [
            'rows' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'propertyId' => $propertyId,
            'facets' => ['actions' => $actionFacets, 'entityTypes' => $entityFacets, 'outcomes' => $outcomeFacets],
        ],
    ]);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('audit_logs:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success' => false, 'code' => 'AUDIT_READ_FAILED', 'requestId' => tamasyaRuntimeRequestId()]);
}
