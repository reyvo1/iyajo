<?php
/** R16.4 P1: Canonical room key issuance shared by Web and Telegram.
 * Identical booking/shift/payment/key-custody gates; no separate Telegram SQL write path.
 * The Telegram flow intentionally supports physical keys only: no PIN leaves the secure Web session.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
/** R16.4: one fail-closed custody guard for Web, Telegram and read-only previews.
 * The booking must have no unresolved key, provider action, or conflicting custody.
 * A recovered missing key is marked secured by room-key-recovered first.
 */
function tamasyaAssertRoomAccessIssueState(array $booking,string $bookingId): void {
    $state=strtolower(trim((string)($booking['keyControlStatus']??'not_issued')));
    if(!in_array($state,['not_issued','returned'],true))
        throw new RuntimeException('Penyerahan baru ditolak: status kunci/PIN masih aktif, belum direkonsiliasi, atau tidak dikenal.');
    $owner=trim((string)($booking['current_booking_id']??''));
    if($owner!=='' && $owner!==$bookingId)
        throw new RuntimeException('Kunci kamar masih tercatat pada booking lain. Selesaikan konflik custody sebelum menerbitkan lagi.');
    $physicalState=strtolower(trim((string)($booking['physical_key_status']??'secured')));
    if($physicalState!=='secured')
        throw new RuntimeException('Kunci fisik belum aman (hilang, override, atau masih diserahkan); lakukan pemulihan dan audit terlebih dahulu.');
}
/** Only missing legacy room-access metadata defaults to physical.
 * A nonempty unknown mode is corrupted state, never permission to issue a key.
 */
function tamasyaResolveRoomAccessMode(array $booking): string {
    $raw=strtolower(trim((string)($booking['access_mode']??'')));
    if($raw==='')return 'physical';
    if(!in_array($raw,['physical','smart','hybrid'],true))
        throw new RuntimeException('Mode akses kamar tidak dikenali; lakukan rekonsiliasi pengaturan kamar.');
    return $raw;
}

function tamasyaIssueRoomAccess(PDO $pdo,array $actor,string $bookingId,string $reason,string $source='web',?string $operationId=null,bool $physicalOnly=false): array {
    requireCapability($actor,'manage_room_access',['admin','manager','receptionist']);
    if(trim($bookingId)==='')throw new InvalidArgumentException('ID booking wajib diisi.');
    if(!in_array($source,['web','telegram'],true))throw new InvalidArgumentException('Sumber transaksi kunci tidak valid.');
    if($source==='telegram' && ($operationId===null || trim($operationId)===''))throw new InvalidArgumentException('ID idempotensi Telegram wajib diisi.');
    if($pdo->inTransaction())throw new RuntimeException('Transaksi lain masih terbuka saat menerbitkan akses kamar.');
    try {
                $jobId=null;$code=null;$last4=null;$mode='physical';$booking=null;$bridgeResult=null;
                $pdo->beginTransaction();
                if($operationId!==null){
                    claimTelegramMutation($pdo,$operationId,(string)$actor['id'],'booking',$bookingId,
                        ['action'=>'key-issue','bookingId'=>$bookingId,'source'=>$source],'callback');
                }
                $stmt=$pdo->prepare("SELECT b.*,rac.access_mode,rac.physical_key_ref,rac.physical_key_status,rac.current_booking_id,rac.smart_lock_provider,rac.smart_lock_device_id,rac.smart_lock_enabled FROM bookings b JOIN rooms r ON r.number=b.roomNumber LEFT JOIN room_access_control rac ON rac.room_number=b.roomNumber WHERE b.id=? AND b.status='active' LIMIT 1 FOR UPDATE");
                $stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
                tamasyaAssertRoomAccessIssueState($booking,$bookingId);
                $settingsRaw=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
                $keyIssueShiftSessionId=tamasyaRequireOpenShiftForRoomAccessIssue($pdo,$actor,$settingsRaw);
                $accessLedger=bookingLedgerTotals($pdo,$bookingId);
                if((int)($settingsRaw['require_payment_before_key_issue']??0)===1 && (float)$accessLedger['net']<=0)throw new RuntimeException('Kebijakan hotel mensyaratkan pembayaran atau panjar sebelum kunci/PIN diserahkan. Catat penerimaan yang benar atau ubah kebijakan operasional oleh Manager/Admin; jangan membuat transaksi palsu hanya untuk melewati kontrol akses.');
                $mode=tamasyaResolveRoomAccessMode($booking);
                // A physical-only Telegram button cannot silently create a new PIN.
                // Recheck under the same FOR UPDATE booking lock as Web operations.
                if(($physicalOnly || $source==='telegram') && $mode!=='physical')throw new RuntimeException('Penyerahan PIN smart-lock/hybrid memerlukan konfirmasi aman di Pusat Operasional Web.');
                $beforeBooking=$booking;
                if(in_array($mode,['smart','hybrid'],true)){
                    if(!empty($booking['isOpenEnded']) || trim((string)($booking['checkoutDueAt']??''))==='')throw new RuntimeException('Smart-lock membutuhkan batas waktu checkout yang pasti. Untuk booking open-ended, tetapkan masa inap terlebih dahulu atau gunakan akses fisik sesuai kebijakan hotel.');
                    $code=(string)random_int(100000,999999);$last4=substr($code,-4);
                    $validFrom=date('Y-m-d H:i:s');
                    $validUntil=(string)($booking['checkoutDueAt']??'');
                    if($validUntil==='')$validUntil=$booking['checkOut'].' '.substr((string)($settingsRaw['checkout_time']??'12:00:00'),0,8);
                    $checkoutTimestamp=strtotime($validUntil);
                    if($checkoutTimestamp===false || $checkoutTimestamp<=time())
                        throw new RuntimeException('Batas waktu checkout tidak valid atau sudah berlalu; smart-lock tidak boleh menerbitkan PIN kadaluarsa.');
                    $validUntil=date('Y-m-d H:i:s',$checkoutTimestamp+max(0,(int)($settingsRaw['late_grace_minutes']??60))*60);
                    $jobId=queueSmartLockBridgeJob($pdo,$booking,[
                        'action'=>'grant','roomNumber'=>$booking['roomNumber'],'bookingId'=>$bookingId,
                        'deviceId'=>$booking['smart_lock_device_id']??null,'code'=>$code,
                        'credentialLast4'=>$last4,'validFrom'=>$validFrom,'validUntil'=>$validUntil,
                        'accessMode'=>$mode,'physicalKeyRef'=>$booking['physical_key_ref']?:('KEY-'.$booking['roomNumber']),
                        'reason'=>$reason,'source'=>$source,'actor'=>tamasyaSmartLockActorSnapshot($actor)
                    ]);
                    $pdo->prepare("UPDATE bookings SET keyControlStatus='pending_smart_issue',accessMode=?,keyIssuedAt=NULL,keyIssuedBy=?,keyReturnedAt=NULL,keyReturnedBy=NULL,smartLockCodeHash=?,smartLockCodeLast4=?,smartLockValidFrom=?,smartLockValidUntil=? WHERE id=?")
                        ->execute([$mode,$actor['id'],hash('sha256',$code),$last4,$validFrom,$validUntil,$bookingId]);
                    $physicalStatus=$mode==='hybrid'?'issued':'secured';
                    $pdo->prepare("INSERT INTO room_access_control (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,last_event_at,updated_by,updated_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode),physical_key_status=VALUES(physical_key_status),current_booking_id=VALUES(current_booking_id),last_event_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                        ->execute([$booking['roomNumber'],$mode,$booking['physical_key_ref']?:('KEY-'.$booking['roomNumber']),$physicalStatus,$bookingId,$actor['id']]);
                    insertRoomKeyEvent($pdo,$actor,$booking['roomNumber'],$bookingId,'smart_grant_queued',$mode,$booking['physical_key_ref']?:('KEY-'.$booking['roomNumber']),$last4,'pending',$reason);
                }else{
                    $pdo->prepare("UPDATE bookings SET keyControlStatus='issued',accessMode='physical',keyIssuedAt=CURRENT_TIMESTAMP,keyIssuedBy=?,keyReturnedAt=NULL,keyReturnedBy=NULL WHERE id=?")
                        ->execute([$actor['id'],$bookingId]);
                    $pdo->prepare("INSERT INTO room_access_control (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,last_event_at,updated_by,updated_at) VALUES (?,'physical',?,'issued',?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE access_mode='physical',physical_key_status='issued',current_booking_id=VALUES(current_booking_id),last_event_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                        ->execute([$booking['roomNumber'],$booking['physical_key_ref']?:('KEY-'.$booking['roomNumber']),$bookingId,$actor['id']]);
                    insertRoomKeyEvent($pdo,$actor,$booking['roomNumber'],$bookingId,'issued','physical',$booking['physical_key_ref']?:('KEY-'.$booking['roomNumber']),null,'active',$reason);
                }
                $afterStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$afterStmt->execute([$bookingId]);$afterBooking=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                writeRequiredEnterpriseAudit($pdo,$actor,'Menyerahkan akses kamar','booking',$bookingId,$beforeBooking,$afterBooking,$source,[
                    'smartLockJobId'=>$jobId,'accessMode'=>$mode,'shiftSessionId'=>$keyIssueShiftSessionId,'externalStatus'=>$jobId?'pending':'not_required'
                ]);
                if($operationId!==null) completeTelegramMutation($pdo,$operationId,[
                    'bookingId'=>$bookingId,'roomNumber'=>$booking['roomNumber'],
                    'keyStatus'=>$mode==='physical'?'issued':'pending_smart_issue',
                    'mode'=>$mode
                ]);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);

    } catch (Throwable $error) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    if($jobId!==null){
        try {
            $bridgeResult=processSmartLockBridgeJobById($pdo,$jobId,$actor,$source);
        }catch(Throwable $bridgeError){
            // Mutation and durable outbox job were committed. Provider failure
            // must not be reported as a rolled-back key issue or disclose PIN.
            error_log('[Smart-lock bridge] job pending: '.clientExceptionMessage('provider failed',$bridgeError));
            $bridgeResult=['status'=>'pending','jobId'=>$jobId];
        }
    }
    $confirmed=$jobId===null || (($bridgeResult['status']??'')==='completed');
    return [
        'booking'=>$booking,'jobId'=>$jobId,'code'=>$code,'last4'=>$last4,
        'mode'=>$mode,'bridgeResult'=>$bridgeResult,'confirmed'=>$confirmed
    ];
}

/** Shared custody invariant for returning a physical key or revoking a PIN.
 * Does not mutate state; caller already holds the booking row FOR UPDATE.
 */
function tamasyaAssertRoomAccessReturnState(array $booking,string $bookingId): void {
    $state=(string)($booking['keyControlStatus']??'not_issued');
    if(in_array($state,['returned','pending_smart_revoke'],true))
        throw new RuntimeException('Akses kamar sudah dikembalikan atau pencabutan smart-lock sedang diproses.');
    // Do not enqueue a revoke while a grant is still pending: out-of-order
    // bridge delivery could activate a credential after a successful revoke.
    // Wait for grant confirmation/reconciliation before returning access.
    if($state==='pending_smart_issue')
        throw new RuntimeException('Pemberian akses smart-lock belum terkonfirmasi. Selesaikan job grant sebelum mengembalikan atau mencabut PIN.');
    if($state!=='issued')
        throw new RuntimeException('Akses kamar belum pernah diserahkan; pengembalian kunci tidak boleh dibuat.');
    $other=trim((string)($booking['current_booking_id']??''));
    // This also protects smart-only rooms, whose physical_key_status is secured.
    if($other!=='' && $other!==$bookingId)
        throw new RuntimeException('Akses kamar masih tercatat pada booking lain. Selesaikan konflik sebelum pengembalian.');
}
/** The database mutation and smart-lock job have ALREADY committed when called.
 * Provider timeout cannot retroactively roll back the durable outbox request.
 * Never issue a PIN or report external confirmation until bridge says completed.
 */
function tamasyaSafelyProcessSmartLockJob(PDO $pdo,string $jobId,array $actor,string $source='web'): array {
    try{
        $result=processSmartLockBridgeJobById($pdo,$jobId,$actor,$source);
        if(!is_array($result) || !isset($result['status']))
            throw new RuntimeException('Smart-lock provider returned no confirmed status.');
        return $result;
    }catch(Throwable $error){
        error_log('[Smart-lock bridge pending] '.clientExceptionMessage('provider unavailable',$error));
        return ['status'=>'pending','jobId'=>$jobId];
    }
}
