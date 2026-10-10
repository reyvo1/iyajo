<?php
/** Bounded, read-only storage diagnostics. No maintenance mutations are exposed. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (($action ?? '') !== 'receipt-storage-health') return;
$routeHandled=true;
requireRoles($loggedInStaff, ['admin']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')) !== 'GET') {
    http_response_code(405);
    echo tamasyaJsonEncode(['success'=>false,'code'=>'METHOD_NOT_ALLOWED']);
    return;
}
try {
    require_once dirname(__DIR__).'/support/077_receipt_storage_health.php';
    if (!tamasyaTableExists($pdo, 'request_operation_receipts')) {
        http_response_code(409);
        echo tamasyaJsonEncode(['success'=>false,'code'=>'RECEIPT_SCHEMA_MISSING']);
        return;
    }
    $result=tamasyaReceiptStorageHealth($pdo);
    header('Cache-Control: no-store, private');
    echo tamasyaJsonEncode(['success'=>true,'readOnly'=>true,'data'=>$result]);
} catch (Throwable $error) {
    tamasyaRuntimeSetStage('receipt_storage_health:failed');
    tamasyaRuntimeCaptureThrowable($error);
    http_response_code(500);
    echo tamasyaJsonEncode(['success'=>false,'code'=>'STORAGE_INSPECTION_FAILED','requestId'=>tamasyaRuntimeRequestId()]);
}
