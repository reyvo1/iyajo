<?php
/** Read-only per-property receipt aggregates; no operation IDs or response bodies leave the server. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
function tamasyaReceiptStorageHealth(PDO $pdo): array {
    // Explicit user action only. Never poll a full LONGTEXT aggregate during regular refresh.
    $totals=$pdo->query("SELECT COUNT(*) AS receipt_count,
      COALESCE(SUM(CASE WHEN response_body IS NOT NULL THEN OCTET_LENGTH(response_body) ELSE 0 END),0) AS response_bytes,
      COALESCE(SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END),0) AS processing_count,
      COALESCE(SUM(CASE WHEN status='uncertain' THEN 1 ELSE 0 END),0) AS uncertain_count,
      COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) AS failed_count,
      COALESCE(SUM(CASE WHEN response_body IS NOT NULL AND completed_at IS NOT NULL AND completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END),0) AS bodies_over_30_days
      FROM request_operation_receipts")->fetch(PDO::FETCH_ASSOC) ?: [];
    $storage=$pdo->query("SELECT COALESCE(DATA_LENGTH,0) AS data_bytes,
      COALESCE(INDEX_LENGTH,0) AS index_bytes FROM information_schema.TABLES
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_operation_receipts' AND TABLE_TYPE='BASE TABLE' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
      'receiptCount'=>max(0,(int)($totals['receipt_count']??0)),
      'logicalResponseBytes'=>max(0,(int)($totals['response_bytes']??0)),
      'estimatedTableDataBytes'=>max(0,(int)($storage['data_bytes']??0)),
      'estimatedTableIndexBytes'=>max(0,(int)($storage['index_bytes']??0)),
      'processingCount'=>max(0,(int)($totals['processing_count']??0)),
      'uncertainCount'=>max(0,(int)($totals['uncertain_count']??0)),
      'failedCount'=>max(0,(int)($totals['failed_count']??0)),
      'bodiesOlderThan30Days'=>max(0,(int)($totals['bodies_over_30_days']??0)),
      'analyzedAt'=>date('c'),
      'canCompactFromThisEndpoint'=>false,
      'cronRequired'=>false,
      'message'=>'Pemeriksaan baca-saja. Tidak ada kompresi, penghapusan, atau mutasi database.',
    ];
}
