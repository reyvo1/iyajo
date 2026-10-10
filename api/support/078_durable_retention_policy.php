<?php
/** R16.4 durable operation history policy (no SQL mutation). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
/**
 * Idempotency metadata cannot safely age out on a timer: delayed retries,
 * uncertainty resolution, and standby replay are not bounded by a number of days.
 * Existing retention env vars remain diagnostics only. Compaction is separate.
 */
function tamasyaDurableRetentionPolicySummary(): array {
    $bodyDays=max(7,min(120,(int)(getenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS')?:30)));
    $metadataDays=max($bodyDays,min(365,(int)(getenv('REQUEST_RECEIPT_RETENTION_DAYS')?:120)));
    return [
        'request_receipt_body_retention_days'=>$bodyDays,
        'request_receipt_metadata_retention_days'=>$metadataDays,
        'request_receipt_retention_config_only'=>true,
        'request_receipt_body_pruned'=>false,
        'request_receipt_metadata_pruned'=>false,
        'sync_operations_cleaned'=>false,
        'durable_history_cleanup_mode'=>'preserve_metadata_and_replay',
        'durable_history_compaction_mode'=>'primary_only_lossless_explicit',
        'durable_history_manual_review_required'=>true,
        'telegram_update_log_cleaned'=>false,
        'telegram_update_log_retention_mode'=>'preserve_update_ids',
    ];
}
