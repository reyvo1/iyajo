<?php
/** Optional TAMASYA Growth Suite route. Disabled by default. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

// R14.2 payment connector phase. Verified provider webhooks are public (providers
// are authenticated by their own HMAC, not a staff session) and never post money:
// they only move an intent to a provider-reported status and record the event.
// Everything after that still goes through the canonical settlement flow.
if ((string)($action ?? '') === 'growth-payment-webhook') {
    $routeHandled=true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']); exit; }
    try {
        if(!tamasyaGrowthModuleEnabled('payment')){ http_response_code(404); echo tamasyaJsonEncode(['success'=>false,'error'=>'Modul payment foundation tidak aktif.']); exit; }
        if(tamasyaGrowthModuleNeedsSchema('payment') && !tamasyaGrowthSchemaReady($pdo)){ http_response_code(404); echo tamasyaJsonEncode(['success'=>false,'error'=>'Schema Growth Suite belum terpasang.']); exit; }
        $providerCodeHeader=trim((string)($_SERVER['HTTP_X_TAMASYA_PROVIDER']??''));
        $rateKey='growth-payment-webhook:'.strtolower($providerCodeHeader).':'.(string)($_SERVER['REMOTE_ADDR']??'unknown');
        if(!enforceRateLimit($pdo,$rateKey,120,60,600)){ http_response_code(429); echo tamasyaJsonEncode(['success'=>false,'error'=>'Terlalu banyak webhook. Coba lagi nanti.']); exit; }
        // Autentikasi gagal selalu 401, apa pun penyebabnya, agar penyerang tidak
        // bisa membedakan provider tak dikenal, tanda tangan salah, dan timestamp lama.
        try{
            $provider=tamasyaPaymentWebhookAuthenticate($pdo,(string)($rawRequestBody??''));
        }catch(Throwable $authFailure){
            http_response_code(401);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Webhook pembayaran ditolak.']);
            exit;
        }
        $intentId=trim((string)($input['intentId']??''));
        $providerEventId=trim((string)($input['providerEventId']??''));
        $providerStatus=trim((string)($input['status']??''));
        $amount=round((float)($input['amount']??0),2);
        $payload=$input['payload']??[];
        $eventAt=trim((string)($input['eventAt']??''));
        if($intentId===''||$providerStatus==='')throw new InvalidArgumentException('intentId dan status provider wajib.');
        if($providerEventId!=='' && !preg_match('/^[A-Za-z0-9._:-]{4,190}$/',$providerEventId))throw new InvalidArgumentException('providerEventId tidak valid.');
        if($eventAt!=='' && strtotime($eventAt)===false)throw new InvalidArgumentException('eventAt tidak valid.');
        $intentStatus=tamasyaPaymentProviderStatusMap($providerStatus);
        if($intentStatus===null)throw new InvalidArgumentException('Status provider tidak dikenal: '.$providerStatus.'.');
        $pdo->beginTransaction();
        $intentS=$pdo->prepare("SELECT * FROM growth_payment_intents WHERE id=? LIMIT 1 FOR UPDATE");
        $intentS->execute([$intentId]);
        $intent=$intentS->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$intent)throw new RuntimeException('Payment intent tidak ditemukan.');
        if(strtolower((string)$intent['provider_code'])!==strtolower((string)$provider['code']))throw new RuntimeException('Provider webhook tidak sesuai dengan intent.');
        if($providerEventId!==''){
            $dupS=$pdo->prepare("SELECT * FROM growth_payment_events WHERE provider_event_id=? LIMIT 1 FOR UPDATE");
            $dupS->execute([$providerEventId]);
            $existing=$dupS->fetch(PDO::FETCH_ASSOC);
            if($existing){
                $freshIntent=$pdo->prepare("SELECT * FROM growth_payment_intents WHERE id=? LIMIT 1");
                $freshIntent->execute([$intentId]);
                tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$existing,'intent'=>$freshIntent->fetch(PDO::FETCH_ASSOC)?:null,'financialMutation'=>false]);
                exit;
            }
        }
        if(!tamasyaPaymentIntentTransitionAllowed((string)$intent['status'],$intentStatus)){
            throw new DomainException('Intent berstatus '.(string)$intent['status'].' tidak dapat dipindah ke '.$intentStatus.'.');
        }
        $eventId=tamasyaGrowthId('pev');
        $pdo->prepare("INSERT INTO growth_payment_events(id,intent_id,provider_event_id,status,amount,payload_hash,signature_valid,event_at) VALUES (?,?,?,?,?,?,1,?)")
            ->execute([$eventId,$intentId,$providerEventId!==''?$providerEventId:null,$intentStatus,$amount,hash('sha256',tamasyaJsonEncode($payload)),$eventAt!==''?$eventAt:null]);
        $pdo->prepare("UPDATE growth_payment_intents SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$intentStatus,$intentId]);
        $afterIntentS=$pdo->prepare("SELECT * FROM growth_payment_intents WHERE id=? LIMIT 1");
        $afterIntentS->execute([$intentId]);
        $afterIntent=$afterIntentS->fetch(PDO::FETCH_ASSOC)?:[];
        writeRequiredEnterpriseAudit($pdo,['id'=>'provider:'.(string)$provider['code'],'name'=>(string)$provider['name'],'role'=>'system'], 'Webhook pembayaran provider terverifikasi','growth_payment_intent',$intentId,['status'=>(string)$intent['status'],'intent'=>$intent],['status'=>$intentStatus,'intent'=>$afterIntent],'payment-provider');
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        echo tamasyaJsonEncode([
            'success'=>true,'financialMutation'=>false,'intentId'=>$intentId,'intentStatus'=>$intentStatus,
            'nextAction'=>'settle_canonical_payment',
            'note'=>'Uang belum diposting. Lakukan pelunasan melalui alur pembayaran canonical setelah verifikasi laporan provider.',
        ]);
    }catch(Throwable $e){
        if(isset($pdo) && $pdo->inTransaction())$pdo->rollBack();
        tamasyaApplyExceptionHttpStatus($e,422);
        echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Webhook pembayaran ditolak',$e)]);
    }
    exit;
}

// R14.3 channel connector phase. A verified vendor reservation creates a RESERVED
// draft through the canonical booking workflow. It never posts money, never checks
// a guest in, and never invents a room: an unmapped room type is recorded for a
// human instead of being guessed.
if ((string)($action ?? '') === 'growth-channel-webhook') {
    $routeHandled=true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']); exit; }
    try {
        if(!tamasyaGrowthModuleEnabled('channel')){ http_response_code(404); echo tamasyaJsonEncode(['success'=>false,'error'=>'Modul channel foundation tidak aktif.']); exit; }
        if(tamasyaGrowthModuleNeedsSchema('channel') && !tamasyaGrowthSchemaReady($pdo)){ http_response_code(404); echo tamasyaJsonEncode(['success'=>false,'error'=>'Schema Growth Suite belum terpasang.']); exit; }
        $channelCodeHeader=trim((string)($_SERVER['HTTP_X_TAMASYA_PROVIDER']??''));
        $rateKey='growth-channel-webhook:'.strtolower($channelCodeHeader).':'.(string)($_SERVER['REMOTE_ADDR']??'unknown');
        if(!enforceRateLimit($pdo,$rateKey,120,60,600)){ http_response_code(429); echo tamasyaJsonEncode(['success'=>false,'error'=>'Terlalu banyak webhook. Coba lagi nanti.']); exit; }
        // Authentication failures are always 401 with one fixed message, so the
        // endpoint cannot be used to probe which providers exist.
        try{
            $provider=tamasyaChannelWebhookAuthenticate($pdo,(string)($rawRequestBody??''));
        }catch(Throwable $authFailure){
            http_response_code(401);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Webhook channel ditolak.']);
            exit;
        }
        $channelCode=(string)$provider['code'];
        $webhookFetchAll=static function(PDO $pdo,string $sql,array $args=[]): array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];};
        $webhookFetchOne=static function(PDO $pdo,string $sql,array $args=[]): ?array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;};
        $eventType=trim((string)($input['eventType']??''));
        $externalId=trim((string)($input['externalId']??''));
        $externalPropertyId=trim((string)($input['externalPropertyId']??''));
        $payload=$input['payload']??[];
        $payloadHash=hash('sha256',tamasyaJsonEncode($payload));
        if($eventType===''||$externalId==='')throw new InvalidArgumentException('eventType dan externalId wajib.');
        if(!preg_match('/^[A-Za-z0-9._:-]{4,190}$/',$externalId))throw new InvalidArgumentException('externalId tidak valid.');
        if(!tamasyaChannelEventTypeSupported($eventType))throw new InvalidArgumentException('Jenis event channel belum didukung: '.$eventType.'.');
        $pdo->beginTransaction();
        try{
            // A vendor retry of the same reservation must never create a second draft.
            $dupS=$pdo->prepare("SELECT * FROM growth_channel_events WHERE channel_code=? AND external_id=? LIMIT 1 FOR UPDATE");
            $dupS->execute([$channelCode,$externalId]);
            $existingEvent=$dupS->fetch(PDO::FETCH_ASSOC);
            if($existingEvent && (string)$existingEvent['status']==='applied'){
                $bookingRow=$webhookFetchOne($pdo,"SELECT id,roomNumber,checkIn,checkOut,totalAmount,status FROM bookings WHERE id=? LIMIT 1",[(string)$existingEvent['id']]);
                tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'created'=>false,'event'=>$existingEvent,'booking'=>$bookingRow,'financialMutation'=>false]);
                exit;
            }
            $externalRoomType=trim((string)($payload['externalRoomType']??''));
            $externalRatePlan=trim((string)($payload['externalRatePlan']??''));
            $mapping=tamasyaChannelReservationMapping($pdo,$channelCode,$externalRoomType,$externalRatePlan,$externalPropertyId);
            $eventId=tamasyaGrowthId('chev');
            if(!$mapping){
                // Recorded for a human: the vendor is answered, nothing is invented.
                $pdo->prepare("INSERT INTO growth_channel_events(id,channel_code,event_type,external_id,operation_id,status,payload_hash,created_by) VALUES (?,?,?,?,?,'unmapped',?,?)")
                    ->execute([$eventId,$channelCode,$eventType,$externalId,'chevt_'.$eventId,$payloadHash,(string)$provider['id']]);
                tamasyaGrowthAudit($pdo,['id'=>'system-channel-'.$channelCode,'name'=>'Channel Manager '.$channelCode,'role'=>'system'],'Event channel tanpa mapping tipe kamar disimpan untuk penanganan staf','growth_channel_event',$eventId,null,['channel'=>$channelCode,'externalId'=>$externalId,'externalRoomType'=>$externalRoomType]);
                tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'created'=>false,'reason'=>'unmapped','eventId'=>$eventId,'financialMutation'=>false,
                    'note'=>'Tipe kamar vendor belum dipetakan. Reservation dicatat untuk pembuatan draft oleh staf.']);
                exit;
            }
            $internalRoomType=trim((string)$mapping['internal_room_type']);
            $ratePlanId=trim((string)($mapping['internal_rate_plan_id']??''));
            $rooms=$webhookFetchAll($pdo,"SELECT number FROM rooms r WHERE r.type=? AND r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active') AND b.checkIn<? AND b.checkOut>?) ORDER BY CAST(r.number AS UNSIGNED),r.number LIMIT 1",
                [$internalRoomType,(string)($payload['checkIn']??'9999-12-31'),(string)($payload['checkOut']??'0000-01-01')]);
            $checkIn=trim((string)($payload['checkIn']??''));
            $checkOut=trim((string)($payload['checkOut']??''));
            $guestName=trim((string)($payload['guestName']??''));
            if($checkIn===''||$checkOut===''||$guestName==='')throw new InvalidArgumentException('checkIn, checkOut dan guestName wajib.');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$checkIn)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$checkOut)||$checkOut<=$checkIn)throw new InvalidArgumentException('Tanggal reservasi tidak valid.');
            if(!$rooms)throw new InvalidArgumentException('Tidak ada kamar '+internalRoomType.' yang tersedia untuk tanggal tersebut.');
            $rateAmount=round((float)($payload['amount']??0),2);
            if($ratePlanId!==''){
                $planS=$pdo->prepare("SELECT base_rate,min_rate,max_rate,active FROM growth_rate_plans WHERE id=? LIMIT 1");
                $planS->execute([$ratePlanId]);
                $plan=$planS->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$plan||(int)$plan['active']!==1)throw new InvalidArgumentException('Rate plan mapping tidak aktif.');
                $nights=max(1,(int)round((strtotime($checkOut)-strtotime($checkIn))/86400));
                $base=round((float)$plan['base_rate'],2);
                $minRate=round((float)$plan['min_rate'],2);
                $maxRate=round((float)$plan['max_rate'],2);
                $fromRate=$rateAmount>0?$rateAmount:$base;
                if($fromRate<$minRate-0.01||($maxRate>0&&$fromRate>$maxRate+0.01))throw new InvalidArgumentException('Tarif vendor di luar batas rate plan mapping.');
                $rateAmount=round($fromRate*$nights,2);
            }
            if($rateAmount<=0)throw new InvalidArgumentException('Nominal reservasi vendor wajib positif atau dipetakan dari rate plan.');
            $bookingSource=in_array(strtolower($channelCode),array_map('strtolower',tamasyaKnownOtaSourceAliases()),true)?$channelCode:'ota-'.$channelCode;
            $systemActor=['id'=>'system-channel-'.$channelCode,'name'=>'Channel Manager '.$channelCode,'role'=>'system'];
            $bookingPayload=[
                'guestName'=>$guestName,'guestEmail'=>trim((string)($payload['guestEmail']??'')),'guestPhone'=>trim((string)($payload['guestPhone']??'')),
                'roomNumber'=>(string)$rooms[0]['number'],'roomType'=>$internalRoomType,'checkIn'=>$checkIn,'checkOut'=>$checkOut,
                'totalAmount'=>$rateAmount,'paymentStatus'=>'unpaid','bookingSource'=>$bookingSource,
                'lifecycleIntent'=>'reserve','broadcast'=>false,
            ];
            $bookingResult=createCanonicalBookingWorkflow($pdo,$systemActor,$bookingPayload,'provider','channel_'.hash('sha256',$channelCode.'|'.$externalId),true);
            $pdo->prepare("INSERT INTO growth_channel_events(id,channel_code,event_type,external_id,operation_id,status,payload_hash,created_by) VALUES (?,?,?,?,?,'applied',?,?)")
                ->execute([$eventId,$channelCode,$eventType,$externalId,'chevt_'.$eventId,$payloadHash,(string)$provider['id']]);
            $afterBooking=$webhookFetchOne($pdo,"SELECT id,roomNumber,roomType,checkIn,checkOut,totalAmount,status,paymentStatus,bookingSource FROM bookings WHERE id=? LIMIT 1",[(string)($bookingResult['bookingId']??'')]);
            tamasyaGrowthAudit($pdo,$systemActor,'Webhook channel terverifikasi membuat draft reservasi','growth_channel_event',$eventId,null,['channel'=>$channelCode,'externalId'=>$externalId,'booking'=>$afterBooking]);
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);
            echo tamasyaJsonEncode(['success'=>true,'created'=>true,'financialMutation'=>false,'eventId'=>$eventId,'bookingId'=>(string)($bookingResult['bookingId']??''),'booking'=>$afterBooking,
                'note'=>'Draft RESERVED dibuat. Tidak ada uang masuk dan tamu belum check-in.']);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }catch(Throwable $e){
        if(isset($pdo) && $pdo->inTransaction())$pdo->rollBack();
        tamasyaApplyExceptionHttpStatus($e,422);
        echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Webhook channel ditolak',$e)]);
    }
    exit;
}

if ((string)($action ?? '') !== 'growth-suite') return;
$routeHandled=true;
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
// Keep Owner read-only even when this route is invoked outside the main dispatcher.
tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action);
$command=trim((string)($method==='GET'?($_GET['command']??'overview'):($input['command']??'overview')));
$allowedRoles=$method==='GET'?['admin','manager','finance','owner']:['admin','manager','finance'];
requireRoles($loggedInStaff,$allowedRoles);

$canAnyTab=static function(array $tabs) use($loggedInStaff,$allowedRoles): bool {
    foreach($tabs as $tab) if(function_exists('hasDesktopTabAccess')&&hasDesktopTabAccess($loggedInStaff,$tab,$allowedRoles)) return true;
    return false;
};
$requireAnyTab=static function(array $tabs,string $message='Hak akses menu tidak tersedia.') use($canAnyTab): void {
    if(!$canAnyTab($tabs)){http_response_code(403);throw new RuntimeException($message);}
};
$requireModule=static function(string $module) use($pdo): void { tamasyaGrowthRequireReady($pdo,$module); };
$commandTabs=[
    'overview'=>['rooms','finance','report','operations','inventory'],
    'bootstrap'=>['rooms','finance','report','operations','inventory'],
    'kpis'=>['report','finance','operations'],
    'folio'=>['rooms','finance','report'],
    'group-detail'=>['rooms','operations','finance'],
    'po-detail'=>['finance','inventory','operations'],
    'booking-search'=>['rooms','operations','finance'],
    'expense-search'=>['finance'],
    'rate-suggestion'=>['rooms','operations','report'],
    'rate-plan-save'=>['rooms','operations','report'],
    'rate-rule-save'=>['rooms','operations','report'],
    'rate-override-save'=>['rooms','operations','report'],
    'company-save'=>['rooms','operations','finance'],
    'group-save'=>['rooms','operations','finance'],
    'group-link-booking'=>['rooms','operations','finance'],
    'group-unlink-booking'=>['rooms','operations','finance'],
    'vendor-save'=>['finance','inventory','operations'],
    'po-save'=>['finance','inventory','operations'],
    'po-status'=>['finance','inventory','operations'],
    'po-link-payment'=>['finance'],
    'channel-mapping-save'=>['rooms','operations','config'],
    'channel-event-register'=>['operations','config'],
    'payment-intent-create'=>['rooms','finance'],
    'payment-provider-list'=>['rooms','finance','config'],
    'channel-provider-list'=>['rooms','operations','config'],
    'payment-event-register'=>['finance','config'],
    'payment-link-transaction'=>['finance'],
];
if(isset($commandTabs[$command])) $requireAnyTab($commandTabs[$command],'Hak akses menu untuk command Growth Suite ini dinonaktifkan.');

$fetchAll=static function(PDO $pdo,string $sql,array $args=[]): array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];};
$fetchOne=static function(PDO $pdo,string $sql,array $args=[]): ?array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;};
$isDuplicateKey=static function(Throwable $error): bool {
    if(!($error instanceof PDOException))return false;
    $driverCode=(int)($error->errorInfo[1]??0);
    return $driverCode===1062 || (string)$error->getCode()==='23000';
};

try {
    if($method==='GET'){
        switch($command){
            case 'overview':
                echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaGrowthOverview($pdo,$loggedInStaff)]);break;
            case 'bootstrap':
                if(!tamasyaGrowthSuiteEnabled()){echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaGrowthOverview($pdo,$loggedInStaff)]);break;}
                if(!tamasyaGrowthSchemaReady($pdo)){echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaGrowthOverview($pdo,$loggedInStaff)]);break;}
                $data=tamasyaGrowthOverview($pdo,$loggedInStaff);
                $role=(string)($loggedInStaff['role']??'');
                $canRoomRead=$canAnyTab(['rooms','operations','report']);
                $canGroupRead=$canAnyTab(['rooms','operations','finance']);
                $canProcurementRead=in_array($role,['admin','manager','finance','owner'],true)&&$canAnyTab(['finance','inventory','operations']);
                $canChannelRead=in_array($role,['admin','owner'],true)&&$canAnyTab(['operations','config']);
                $canPaymentRead=in_array($role,['admin','manager','finance','owner'],true)&&$canAnyTab(['rooms','finance']);
                if($canRoomRead)$data['roomTypes']=$fetchAll($pdo,"SELECT type,COUNT(*) AS room_count FROM rooms GROUP BY type ORDER BY type");
                // Tab kontrak adalah fitur inti, bukan modul Growth berbayar:
                // tabnya tampil begitu Growth Suite aktif, skema tarif kontrak
                // tersedia, dan staf berhak membaca tarif.
                if(isset($data['features']['modules'])&&is_array($data['features']['modules'])){
                    $contractReady=false;
                    try{$contractReady=tamasyaTableExists($pdo,'wholesale_rate_plans');}catch(Throwable $contractProbe){$contractReady=false;}
                    // Tabnya tetap tampil walau skema belum ada: kalau tabnya
                    // disembunyikan, pengguna mengira fitur tidak ada. Yang
                    // berubah adalah isinya: ada catatan tindakan impor.
                    $data['features']['modules']['contract']=$canRoomRead;
                    $data['wholesaleSchemaReady']=$contractReady;
                }
                if(tamasyaGrowthModuleEnabled('rate')&&$canRoomRead){
                    // Tarif kontrak B2B. Skema mungkin belum ada di property lama,
                    // jadi pembacaan dibungkus: Growth Suite tidak boleh gagal
                    // hanya karena tabel kontrak belum dimigrasi.
                    $data['wholesalePlans']=[];$data['wholesaleBookings']=[];
                    try{
                        $wholesaleProperty=strtolower(trim((string)($GLOBALS['tamasya_property_id']??'')));
                        $data['wholesalePlans']=$fetchAll($pdo,"SELECT id,code,name,company_name,room_type,currency,valid_from,valid_to,min_nights,max_nights,cut_off_days,include_tax,breakfast_included,status FROM wholesale_rate_plans WHERE property_id=? ORDER BY status,code",[$wholesaleProperty]);
                        $data['wholesaleBookings']=$fetchAll($pdo,"SELECT booking_id,plan_id,plan_code,company_name,stay_from,stay_to,nights,total,currency,created_at FROM wholesale_rate_bookings WHERE property_id=? ORDER BY created_at DESC LIMIT 100",[$wholesaleProperty]);
                    }catch(Throwable $wholesaleSchemaMissing){$data['wholesalePlans']=[];$data['wholesaleBookings']=[];}
                    $data['exchangeRates']=[];
                    try{
                        $data['exchangeRates']=$fetchAll($pdo,"SELECT id,base_currency,quote_currency,rate,rate_date,source,created_at FROM exchange_rates WHERE base_currency=? ORDER BY quote_currency,rate_date DESC LIMIT 200",[tamasyaPropertyCurrency($pdo)]);
                    }catch(Throwable $exchangeSchemaMissing){$data['exchangeRates']=[];}
                }
                if(tamasyaGrowthModuleEnabled('rate')&&$canRoomRead){$data['ratePlans']=$fetchAll($pdo,"SELECT id,code,name,room_type,base_rate,min_rate,max_rate,currency,active,version FROM growth_rate_plans ORDER BY active DESC,room_type,name");$data['rateRules']=$fetchAll($pdo,"SELECT id,plan_id,name,priority,condition_json,adjustment_type,adjustment_value,valid_from,valid_to,active,version FROM growth_rate_rules ORDER BY plan_id,priority,id");$data['rateOverrides']=$fetchAll($pdo,"SELECT id,plan_id,stay_date,room_type,rate,min_stay,stop_sell,closed_to_arrival,closed_to_departure,note,version FROM growth_rate_overrides WHERE stay_date>=DATE_SUB(CURDATE(),INTERVAL 31 DAY) ORDER BY stay_date DESC,plan_id LIMIT 1000");}
                if(tamasyaGrowthModuleEnabled('group')&&$canGroupRead){$data['companies']=$fetchAll($pdo,"SELECT id,code,name,status FROM growth_companies ORDER BY status,name LIMIT 1000");$data['groups']=$fetchAll($pdo,"SELECT g.id,g.group_code,g.company_id,g.name,g.arrival_date,g.departure_date,g.room_block_qty,g.status,g.billing_mode,g.version,c.name AS company_name,(SELECT COUNT(*) FROM growth_group_booking_links l WHERE l.group_id=g.id) AS booking_count FROM growth_group_reservations g LEFT JOIN growth_companies c ON c.id=g.company_id ORDER BY g.arrival_date DESC,g.id DESC LIMIT 1000");}
                if(tamasyaGrowthModuleEnabled('procurement')&&$canProcurementRead){$data['vendors']=$fetchAll($pdo,"SELECT id,code,name,payment_terms_days,status,version FROM growth_vendors ORDER BY status,name LIMIT 1000");$data['purchaseOrders']=$fetchAll($pdo,"SELECT po.id,po.po_number,po.vendor_id,po.order_date,po.expected_date,po.status,po.subtotal,po.tax_amount,po.total_amount,po.currency,po.version,v.name AS vendor_name,COALESCE((SELECT SUM(p.amount_applied) FROM growth_purchase_order_payments p WHERE p.po_id=po.id),0) AS paid_amount FROM growth_purchase_orders po JOIN growth_vendors v ON v.id=po.vendor_id ORDER BY po.order_date DESC,po.id DESC LIMIT 1500");}
                if(tamasyaGrowthModuleEnabled('channel')&&$canChannelRead){$data['channelMappings']=$fetchAll($pdo,"SELECT id,channel_code,external_property_id,external_room_type,internal_room_type,external_rate_plan,internal_rate_plan_id,active,version FROM growth_channel_mappings ORDER BY channel_code,external_room_type LIMIT 2000");}
                if(tamasyaGrowthModuleEnabled('payment')&&$canPaymentRead){$data['paymentIntents']=$fetchAll($pdo,"SELECT pi.id,pi.booking_id,pi.provider_code,pi.amount,pi.status,pi.external_reference,pi.expires_at,pi.confirmed_transaction_id,pi.created_at,pi.updated_at,b.guestName,b.roomNumber FROM growth_payment_intents pi LEFT JOIN bookings b ON b.id=pi.booking_id ORDER BY pi.created_at DESC LIMIT 500");}
                if(tamasyaGrowthModuleEnabled('channel')&&$canChannelRead){$data['channelProviders']=$fetchAll($pdo,"SELECT id,code,name,signature_mode,active,created_at,updated_at,CHAR_LENGTH(COALESCE(webhook_secret_encrypted,'')) AS secret_length FROM growth_channel_providers ORDER BY code");}
                if(tamasyaGrowthModuleEnabled('payment')&&$canPaymentRead){$data['paymentProviders']=$fetchAll($pdo,"SELECT id,code,name,signature_mode,active,created_at,updated_at,CHAR_LENGTH(COALESCE(webhook_secret_encrypted,'')) AS secret_length FROM growth_payment_providers ORDER BY code");}
                echo tamasyaJsonEncode(['success'=>true,'data'=>$data]);break;
            case 'channel-provider-list':
                $requireModule('channel');$requireAnyTab(['rooms','operations','config'],'Akses daftar provider channel membutuhkan menu terkait.');
                $rows=$pdo->query("SELECT id,code,name,signature_mode,active,created_at,updated_at,CHAR_LENGTH(COALESCE(webhook_secret_encrypted,'')) AS secret_length FROM growth_channel_providers ORDER BY code")->fetchAll(PDO::FETCH_ASSOC)?:[];
                echo tamasyaJsonEncode(['success'=>true,'providers'=>array_map(static function(array $r):array{
                    $r['hasSecret']=(int)($r['secret_length']??0)>0;unset($r['secret_length']);return $r;
                },$rows)]);
                break;
            case 'payment-provider-list':
                $requireModule('payment');$requireAnyTab(['rooms','finance','config'],'Akses daftar provider pembayaran membutuhkan menu terkait.');
                $rows=$pdo->query("SELECT id,code,name,signature_mode,active,created_at,updated_at,CHAR_LENGTH(COALESCE(webhook_secret_encrypted,'')) AS secret_length FROM growth_payment_providers ORDER BY code")->fetchAll(PDO::FETCH_ASSOC)?:[];
                echo tamasyaJsonEncode(['success'=>true,'providers'=>array_map(static function(array $r):array{
                    $r['hasSecret']=(int)($r['secret_length']??0)>0;unset($r['secret_length']);return $r;
                },$rows)]);
                break;
            case 'kpis':
                $requireModule('kpi');$requireAnyTab(['report','finance','operations'],'Akses KPI membutuhkan menu Laporan/Keuangan/Operasional.');
                $from=trim((string)($_GET['from']??date('Y-m-01')));$to=trim((string)($_GET['to']??date('Y-m-d')));
                echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaGrowthOperationalKpis($pdo,$from,$to)]);break;            case 'folio':
                $requireModule('folio');$requireAnyTab(['rooms','finance','report'],'Akses Folio membutuhkan menu Kamar/Keuangan/Laporan.');
                $bookingId=trim((string)($_GET['bookingId']??''));if($bookingId==='')throw new InvalidArgumentException('bookingId wajib.');
                echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaGrowthBookingFolio($pdo,$bookingId)]);break;
            case 'group-detail':
                $requireModule('group');$requireAnyTab(['rooms','operations','finance']);$groupId=trim((string)($_GET['id']??''));if($groupId==='')throw new InvalidArgumentException('ID group wajib.');
                $group=$fetchOne($pdo,"SELECT g.*,c.name AS company_name FROM growth_group_reservations g LEFT JOIN growth_companies c ON c.id=g.company_id WHERE g.id=? LIMIT 1",[$groupId]);if(!$group)throw new InvalidArgumentException('Group tidak ditemukan.');
                $links=$fetchAll($pdo,"SELECT l.*,b.guestName,b.roomNumber,b.roomType,b.checkIn,b.checkOut,b.status,b.totalAmount,b.amountPaid,b.balanceDue,b.paymentStatus FROM growth_group_booking_links l JOIN bookings b ON b.id=l.booking_id WHERE l.group_id=? ORDER BY b.roomNumber,b.checkIn",[$groupId]);
                $total=0;$paid=0;$balance=0;foreach($links as $r){$total+=(float)$r['totalAmount'];$paid+=(float)$r['amountPaid'];$balance+=(float)$r['balanceDue'];}
                echo tamasyaJsonEncode(['success'=>true,'data'=>['group'=>$group,'bookings'=>$links,'masterFolio'=>['total'=>round($total,2),'paid'=>round($paid,2),'balance'=>round($balance,2),'bookingCount'=>count($links)]]]);break;
            case 'po-detail':
                $requireModule('procurement');requireRoles($loggedInStaff,['admin','manager','finance']);$requireAnyTab(['finance','inventory','operations']);$poId=trim((string)($_GET['id']??''));if($poId==='')throw new InvalidArgumentException('ID PO wajib.');
                $po=$fetchOne($pdo,"SELECT po.*,v.name AS vendor_name FROM growth_purchase_orders po JOIN growth_vendors v ON v.id=po.vendor_id WHERE po.id=? LIMIT 1",[$poId]);if(!$po)throw new InvalidArgumentException('PO tidak ditemukan.');
                $items=$fetchAll($pdo,"SELECT * FROM growth_purchase_order_items WHERE po_id=? ORDER BY id",[$poId]);$payments=$fetchAll($pdo,"SELECT p.*,t.documentNumber,t.description,t.`date`,t.amount,t.bankAccountId,t.reconciliationStatus FROM growth_purchase_order_payments p JOIN transactions t ON t.id=p.transaction_id WHERE p.po_id=? ORDER BY p.linked_at",[$poId]);$paid=array_sum(array_map(static fn($x)=>(float)$x['amount_applied'],$payments));
                echo tamasyaJsonEncode(['success'=>true,'data'=>['purchaseOrder'=>$po,'items'=>$items,'payments'=>$payments,'paidAmount'=>round($paid,2),'outstanding'=>max(0,round((float)$po['total_amount']-$paid,2))]]);break;
            case 'booking-search':
                $requireAnyTab(['rooms','operations','finance']);$q=trim((string)($_GET['q']??''));if(strlen($q)<1)throw new InvalidArgumentException('Kata pencarian booking wajib.');$like='%'.$q.'%';$rows=$fetchAll($pdo,"SELECT id,guestName,roomNumber,roomType,checkIn,checkOut,status,paymentStatus,totalAmount,amountPaid,balanceDue,bookingSource FROM bookings WHERE guestName LIKE ? OR roomNumber LIKE ? OR id LIKE ? ORDER BY FIELD(status,'active','reserved','completed','cancelled'),checkIn DESC LIMIT 50",[$like,$like,$like]);echo tamasyaJsonEncode(['success'=>true,'data'=>$rows]);break;
            case 'expense-search':
                $requireModule('procurement');requireRoles($loggedInStaff,['admin','manager','finance']);$requireAnyTab(['finance']);$q=trim((string)($_GET['q']??''));if(strlen($q)<1)throw new InvalidArgumentException('Kata pencarian transaksi wajib.');$like='%'.$q.'%';$rows=$fetchAll($pdo,"SELECT id,documentNumber,amount,`date`,description,category,subcategory,bankAccountId,reconciliationStatus,transactionKind FROM transactions WHERE type='expense' AND (description LIKE ? OR documentNumber LIKE ? OR id LIKE ?) ORDER BY `date` DESC,createdAt DESC LIMIT 50",[$like,$like,$like]);echo tamasyaJsonEncode(['success'=>true,'data'=>$rows]);break;
            case 'rate-suggestion':
                $requireModule('rate');$requireAnyTab(['rooms','operations','report']);$planId=trim((string)($_GET['planId']??''));$stayDate=trim((string)($_GET['stayDate']??date('Y-m-d')));$los=max(1,min(365,(int)($_GET['lengthOfStay']??1)));$source=trim((string)($_GET['bookingSource']??'Direct'))?:'Direct';$roomType=trim((string)($_GET['roomType']??''));
                echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaGrowthRateSuggestion($pdo,$planId,$stayDate,$los,$source,$roomType)]);break;
            default: throw new InvalidArgumentException('Command Growth Suite GET tidak dikenal.');
        }
        return;
    }
    if($method!=='POST'){http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);return;}

    tamasyaGrowthRequireWriter();
    $op=tamasyaGrowthOperationId((array)$input,'growth');
    switch($command){
        case 'rate-plan-save':
            $requireModule('rate');requireRoles($loggedInStaff,['admin','manager']);$requireAnyTab(['rooms','operations','report']);
            $id=trim((string)($input['id']??''));$code=strtoupper(trim((string)($input['code']??'')));$name=trim((string)($input['name']??''));if($code===''||$name==='')throw new InvalidArgumentException('Kode dan nama rate plan wajib.');
            $roomType=trim((string)($input['roomType']??''));$base=tamasyaGrowthMoney($input['baseRate']??0);$min=tamasyaGrowthMoney($input['minRate']??0);$max=tamasyaGrowthMoney($input['maxRate']??0);if($min<0||$max<0||$base<0)throw new InvalidArgumentException('Rate tidak boleh negatif.');if($max>0&&$min>$max)throw new InvalidArgumentException('Minimum rate melebihi maksimum.');
            $pdo->beginTransaction();try{$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_rate_plans WHERE id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Rate plan tidak ditemukan.');$pdo->prepare("UPDATE growth_rate_plans SET code=?,name=?,room_type=?,base_rate=?,min_rate=?,max_rate=?,active=?,updated_by=?,version=version+1 WHERE id=?")->execute([$code,$name,$roomType!==''?$roomType:null,$base,$min,$max,!empty($input['active'])?1:0,$loggedInStaff['id'],$id]);}else{$id=tamasyaGrowthId('rate');$pdo->prepare("INSERT INTO growth_rate_plans(id,code,name,room_type,base_rate,min_rate,max_rate,active,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$id,$code,$name,$roomType!==''?$roomType:null,$base,$min,$max,isset($input['active'])?(!empty($input['active'])?1:0):1,$loggedInStaff['id'],$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_rate_plans WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan rate plan','growth_rate_plan',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'rate-rule-save':
            $requireModule('rate');requireRoles($loggedInStaff,['admin','manager']);$requireAnyTab(['rooms','operations','report']);$id=trim((string)($input['id']??''));$planId=trim((string)($input['planId']??''));$name=trim((string)($input['name']??''));if($planId===''||$name==='')throw new InvalidArgumentException('Plan dan nama rule wajib.');$condition=$input['condition']??[];if(!is_array($condition))throw new InvalidArgumentException('Condition rule harus object.');$json=tamasyaJsonEncode($condition);$type=strtolower(trim((string)($input['adjustmentType']??'percent')));if(!in_array($type,['percent','fixed','set'],true))throw new InvalidArgumentException('Jenis adjustment rate tidak valid.');$value=(float)($input['adjustmentValue']??0);$priority=max(1,min(10000,(int)($input['priority']??100)));$vf=trim((string)($input['validFrom']??''));$vt=trim((string)($input['validTo']??''));if($vf!==''&&!tamasyaGrowthValidDate($vf))throw new InvalidArgumentException('validFrom tidak valid.');if($vt!==''&&!tamasyaGrowthValidDate($vt))throw new InvalidArgumentException('validTo tidak valid.');
            $pdo->beginTransaction();try{$p=$pdo->prepare("SELECT id FROM growth_rate_plans WHERE id=? LIMIT 1 FOR UPDATE");$p->execute([$planId]);if(!$p->fetchColumn())throw new InvalidArgumentException('Rate plan tidak ditemukan.');$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_rate_rules WHERE id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Rate rule tidak ditemukan.');$pdo->prepare("UPDATE growth_rate_rules SET plan_id=?,name=?,priority=?,condition_json=?,adjustment_type=?,adjustment_value=?,valid_from=?,valid_to=?,active=?,updated_by=?,version=version+1 WHERE id=?")->execute([$planId,$name,$priority,$json,$type,$value,$vf!==''?$vf:null,$vt!==''?$vt:null,isset($input['active'])?(!empty($input['active'])?1:0):1,$loggedInStaff['id'],$id]);}else{$id=tamasyaGrowthId('rr');$pdo->prepare("INSERT INTO growth_rate_rules(id,plan_id,name,priority,condition_json,adjustment_type,adjustment_value,valid_from,valid_to,active,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$id,$planId,$name,$priority,$json,$type,$value,$vf!==''?$vf:null,$vt!==''?$vt:null,isset($input['active'])?(!empty($input['active'])?1:0):1,$loggedInStaff['id'],$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_rate_rules WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan revenue rate rule','growth_rate_rule',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'rate-override-save':
            $requireModule('rate');requireRoles($loggedInStaff,['admin','manager']);$planId=trim((string)($input['planId']??''));$date=trim((string)($input['stayDate']??''));if($planId===''||!tamasyaGrowthValidDate($date))throw new InvalidArgumentException('Plan/tanggal override wajib valid.');$roomType=trim((string)($input['roomType']??''));$rate=tamasyaGrowthMoney($input['rate']??0);$minStay=max(1,min(365,(int)($input['minStay']??1)));$note=trim((string)($input['note']??''));
            $pdo->beginTransaction();try{$dup=$pdo->prepare("SELECT id,plan_id,stay_date,rate FROM growth_rate_overrides WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$existing=$dup->fetch(PDO::FETCH_ASSOC);if($existing){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'id'=>$existing['id'],'data'=>$existing]);break;}$id=tamasyaGrowthId('ro');$pdo->prepare("INSERT INTO growth_rate_overrides(id,plan_id,stay_date,room_type,rate,min_stay,stop_sell,closed_to_arrival,closed_to_departure,note,operation_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate),min_stay=VALUES(min_stay),stop_sell=VALUES(stop_sell),closed_to_arrival=VALUES(closed_to_arrival),closed_to_departure=VALUES(closed_to_departure),note=VALUES(note),operation_id=VALUES(operation_id),version=version+1")->execute([$id,$planId,$date,$roomType,$rate,$minStay,!empty($input['stopSell'])?1:0,!empty($input['closedToArrival'])?1:0,!empty($input['closedToDeparture'])?1:0,$note!==''?$note:null,$op,$loggedInStaff['id']]);$after=$fetchOne($pdo,"SELECT * FROM growth_rate_overrides WHERE plan_id=? AND stay_date=? AND room_type=? LIMIT 1",[$planId,$date,$roomType]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan override kalender rate','growth_rate_override',(string)($after['id']??$id),null,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'company-save':
            $requireModule('group');requireRoles($loggedInStaff,['admin','manager']);$id=trim((string)($input['id']??''));$code=strtoupper(trim((string)($input['code']??'')));$name=trim((string)($input['name']??''));if($code===''||$name==='')throw new InvalidArgumentException('Kode dan nama perusahaan wajib.');$limit=max(0,tamasyaGrowthMoney($input['creditLimit']??0));$terms=max(0,min(365,(int)($input['paymentTermsDays']??0)));
            $pdo->beginTransaction();try{$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_companies WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Perusahaan tidak ditemukan.');$pdo->prepare("UPDATE growth_companies SET code=?,name=?,tax_id=?,billing_email=?,phone=?,address=?,credit_limit=?,payment_terms_days=?,status=?,notes=?,updated_by=?,version=version+1 WHERE id=?")->execute([$code,$name,trim((string)($input['taxId']??''))?:null,trim((string)($input['billingEmail']??''))?:null,trim((string)($input['phone']??''))?:null,trim((string)($input['address']??''))?:null,$limit,$terms,trim((string)($input['status']??'active'))?:'active',trim((string)($input['notes']??''))?:null,$loggedInStaff['id'],$id]);}else{$id=tamasyaGrowthId('corp');$pdo->prepare("INSERT INTO growth_companies(id,code,name,tax_id,billing_email,phone,address,credit_limit,payment_terms_days,status,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$id,$code,$name,trim((string)($input['taxId']??''))?:null,trim((string)($input['billingEmail']??''))?:null,trim((string)($input['phone']??''))?:null,trim((string)($input['address']??''))?:null,$limit,$terms,trim((string)($input['status']??'active'))?:'active',trim((string)($input['notes']??''))?:null,$loggedInStaff['id'],$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_companies WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan akun corporate','growth_company',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'group-save':
            $requireModule('group');requireRoles($loggedInStaff,['admin','manager']);$id=trim((string)($input['id']??''));$name=trim((string)($input['name']??''));$arrival=trim((string)($input['arrivalDate']??''));$departure=trim((string)($input['departureDate']??''));if($name===''||!tamasyaGrowthValidDate($arrival)||!tamasyaGrowthValidDate($departure)||$departure<=$arrival)throw new InvalidArgumentException('Nama dan periode group wajib valid.');$code=strtoupper(trim((string)($input['groupCode']??'')));if($code==='')$code='GRP-'.strtoupper(substr(hash('sha256',$op.'|group-code'),0,10));$status=trim((string)($input['status']??'draft'));if(!in_array($status,['draft','tentative','confirmed','in_house','completed','cancelled'],true))throw new InvalidArgumentException('Status group tidak valid.');$billing=trim((string)($input['billingMode']??'individual'));if(!in_array($billing,['individual','master','split'],true))throw new InvalidArgumentException('Billing mode group tidak valid.');
            $pdo->beginTransaction();try{$dup=$pdo->prepare("SELECT id FROM growth_group_reservations WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$dupId=$dup->fetchColumn();if($dupId&&$id===''){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'id'=>$dupId]);break;}$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_group_reservations WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Group tidak ditemukan.');if((json_decode((string)($before['master_notes']??''),true)['format']??'')==='multi-room-v1')throw new RuntimeException('Grup operasional dikelola melalui Reservasi Beberapa Kamar; perubahan ini dapat merusak alokasi folio.');$pdo->prepare("UPDATE growth_group_reservations SET group_code=?,company_id=?,name=?,arrival_date=?,departure_date=?,room_block_qty=?,status=?,billing_mode=?,master_notes=?,updated_by=?,version=version+1 WHERE id=?")->execute([$code,trim((string)($input['companyId']??''))?:null,$name,$arrival,$departure,max(0,(int)($input['roomBlockQty']??0)),$status,$billing,trim((string)($input['masterNotes']??''))?:null,$loggedInStaff['id'],$id]);}else{$id=tamasyaGrowthId('grp');$pdo->prepare("INSERT INTO growth_group_reservations(id,group_code,company_id,name,arrival_date,departure_date,room_block_qty,status,billing_mode,master_notes,operation_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$id,$code,trim((string)($input['companyId']??''))?:null,$name,$arrival,$departure,max(0,(int)($input['roomBlockQty']??0)),$status,$billing,trim((string)($input['masterNotes']??''))?:null,$op,$loggedInStaff['id'],$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_group_reservations WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan group reservation','growth_group',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'group-link-booking':
            $requireModule('group');requireRoles($loggedInStaff,['admin','manager']);$groupId=trim((string)($input['groupId']??''));$bookingId=trim((string)($input['bookingId']??''));if($groupId===''||$bookingId==='')throw new InvalidArgumentException('Group dan booking wajib.');$billing=trim((string)($input['billingMode']??'individual'));if(!in_array($billing,['individual','master','split'],true))throw new InvalidArgumentException('Billing mode link tidak valid.');$pct=max(0,min(100,(float)($input['routedPercent']??0)));
            $pdo->beginTransaction();try{$g=$pdo->prepare("SELECT * FROM growth_group_reservations WHERE id=? FOR UPDATE");$g->execute([$groupId]);$group=$g->fetch(PDO::FETCH_ASSOC);if(!$group)throw new InvalidArgumentException('Group tidak ditemukan.');if((json_decode((string)($group['master_notes']??''),true)['format']??'')==='multi-room-v1')throw new RuntimeException('Gunakan Tambah kamar pada reservasi grup operasional.');$b=$pdo->prepare("SELECT id,guestName,roomNumber,checkIn,checkOut,status FROM bookings WHERE id=? FOR UPDATE");$b->execute([$bookingId]);$booking=$b->fetch(PDO::FETCH_ASSOC);if(!$booking)throw new InvalidArgumentException('Booking tidak ditemukan.');$dup=$pdo->prepare("SELECT * FROM growth_group_booking_links WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$existing=$dup->fetch(PDO::FETCH_ASSOC);if($existing){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$existing]);break;}$existingLinkStmt=$pdo->prepare("SELECT * FROM growth_group_booking_links WHERE booking_id=? LIMIT 1 FOR UPDATE");$existingLinkStmt->execute([$bookingId]);$existingLink=$existingLinkStmt->fetch(PDO::FETCH_ASSOC)?:null;if($existingLink && (string)$existingLink['group_id']!==$groupId)throw new RuntimeException('Booking sudah terhubung ke group lain. Lepas link lama lebih dulu.');if($existingLink){$id=(string)$existingLink['id'];$pdo->prepare("UPDATE growth_group_booking_links SET billing_mode=?,routed_percent=?,notes=?,operation_id=?,linked_by=?,linked_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$billing,$pct,trim((string)($input['notes']??''))?:null,$op,$loggedInStaff['id'],$id]);}else{$id=tamasyaGrowthId('gl');$pdo->prepare("INSERT INTO growth_group_booking_links(id,group_id,booking_id,billing_mode,routed_percent,notes,operation_id,linked_by) VALUES (?,?,?,?,?,?,?,?)")->execute([$id,$groupId,$bookingId,$billing,$pct,trim((string)($input['notes']??''))?:null,$op,$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_group_booking_links WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menautkan booking ke group','growth_group_booking',(string)($after['id']??$id),null,['group'=>$group,'booking'=>$booking,'link'=>$after]);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'group-unlink-booking':
            $requireModule('group');requireRoles($loggedInStaff,['admin','manager']);$id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID link wajib.');$pdo->beginTransaction();try{$s=$pdo->prepare("SELECT * FROM growth_group_booking_links WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC);if(!$before)throw new InvalidArgumentException('Link group-booking tidak ditemukan.');$group=tamasyaEnterpriseFetch($pdo,'SELECT master_notes FROM growth_group_reservations WHERE id=? FOR UPDATE',[$before['group_id']]);if((json_decode((string)($group['master_notes']??''),true)['format']??'')==='multi-room-v1')throw new RuntimeException('Gunakan Lepas grup atau Batalkan pada reservasi grup operasional agar billing tetap konsisten.');$pdo->prepare("DELETE FROM growth_group_booking_links WHERE id=?")->execute([$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Melepas booking dari group','growth_group_booking',$id,$before,null);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'vendor-save':
            $requireModule('procurement');requireRoles($loggedInStaff,['admin','manager','finance']);$id=trim((string)($input['id']??''));$code=strtoupper(trim((string)($input['code']??'')));$name=trim((string)($input['name']??''));if($code===''||$name==='')throw new InvalidArgumentException('Kode dan nama vendor wajib.');$pdo->beginTransaction();try{$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_vendors WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Vendor tidak ditemukan.');$pdo->prepare("UPDATE growth_vendors SET code=?,name=?,tax_id=?,email=?,phone=?,address=?,payment_terms_days=?,status=?,notes=?,updated_by=?,version=version+1 WHERE id=?")->execute([$code,$name,trim((string)($input['taxId']??''))?:null,trim((string)($input['email']??''))?:null,trim((string)($input['phone']??''))?:null,trim((string)($input['address']??''))?:null,max(0,min(365,(int)($input['paymentTermsDays']??0))),trim((string)($input['status']??'active'))?:'active',trim((string)($input['notes']??''))?:null,$loggedInStaff['id'],$id]);}else{$id=tamasyaGrowthId('ven');$pdo->prepare("INSERT INTO growth_vendors(id,code,name,tax_id,email,phone,address,payment_terms_days,status,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$id,$code,$name,trim((string)($input['taxId']??''))?:null,trim((string)($input['email']??''))?:null,trim((string)($input['phone']??''))?:null,trim((string)($input['address']??''))?:null,max(0,min(365,(int)($input['paymentTermsDays']??0))),trim((string)($input['status']??'active'))?:'active',trim((string)($input['notes']??''))?:null,$loggedInStaff['id'],$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_vendors WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan vendor procurement','growth_vendor',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'po-save':
            $requireModule('procurement');requireRoles($loggedInStaff,['admin','manager','finance']);$id=trim((string)($input['id']??''));$vendorId=trim((string)($input['vendorId']??''));$orderDate=trim((string)($input['orderDate']??date('Y-m-d')));if($vendorId===''||!tamasyaGrowthValidDate($orderDate))throw new InvalidArgumentException('Vendor/tanggal PO wajib valid.');$items=$input['items']??[];if(!is_array($items)||count($items)<1)throw new InvalidArgumentException('PO minimal memiliki satu item.');if(count($items)>200)throw new InvalidArgumentException('Item PO maksimal 200 baris.');$normalized=[];$subtotal=0.0;$taxTotal=0.0;foreach($items as $item){if(!is_array($item))continue;$name=trim((string)($item['itemName']??''));$qty=round((float)($item['quantity']??0),3);$price=tamasyaGrowthMoney($item['unitPrice']??0);$taxRate=max(0,min(100,(float)($item['taxRate']??0)));if($name===''||$qty<=0||$price<0)throw new InvalidArgumentException('Nama, qty dan harga item PO wajib valid.');$lineBase=round($qty*$price,2);$tax=round($lineBase*$taxRate/100,2);$line=round($lineBase+$tax,2);$subtotal+=$lineBase;$taxTotal+=$tax;$normalized[]=['inventoryId'=>trim((string)($item['inventoryId']??''))?:null,'sku'=>trim((string)($item['sku']??''))?:null,'itemName'=>$name,'quantity'=>$qty,'unit'=>trim((string)($item['unit']??'pcs'))?:'pcs','unitPrice'=>$price,'taxRate'=>$taxRate,'taxAmount'=>$tax,'lineTotal'=>$line,'notes'=>trim((string)($item['notes']??''))?:null];}$total=round($subtotal+$taxTotal,2);$expected=trim((string)($input['expectedDate']??''));if($expected!==''&&!tamasyaGrowthValidDate($expected))throw new InvalidArgumentException('Expected date tidak valid.');
            $pdo->beginTransaction();try{$v=$pdo->prepare("SELECT id FROM growth_vendors WHERE id=? AND status='active' FOR UPDATE");$v->execute([$vendorId]);if(!$v->fetchColumn())throw new InvalidArgumentException('Vendor tidak aktif/tidak ditemukan.');$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_purchase_orders WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('PO tidak ditemukan.');if(!in_array((string)$before['status'],['draft','submitted'],true))throw new RuntimeException('PO hanya dapat diedit saat draft/submitted.');$pdo->prepare("UPDATE growth_purchase_orders SET vendor_id=?,order_date=?,expected_date=?,subtotal=?,tax_amount=?,total_amount=?,notes=?,updated_by=?,version=version+1 WHERE id=?")->execute([$vendorId,$orderDate,$expected!==''?$expected:null,round($subtotal,2),round($taxTotal,2),$total,trim((string)($input['notes']??''))?:null,$loggedInStaff['id'],$id]);$pdo->prepare("DELETE FROM growth_purchase_order_items WHERE po_id=?")->execute([$id]);}else{$dup=$pdo->prepare("SELECT id FROM growth_purchase_orders WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$dupId=$dup->fetchColumn();if($dupId){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'id'=>$dupId]);break;}$id=tamasyaGrowthId('po');$poNo=trim((string)($input['poNumber']??''));if($poNo==='')$poNo='PO-'.strtoupper(substr(hash('sha256',$op.'|po-number'),0,10));$pdo->prepare("INSERT INTO growth_purchase_orders(id,po_number,vendor_id,order_date,expected_date,status,subtotal,tax_amount,total_amount,notes,operation_id,created_by,updated_by) VALUES (?,?,?,?,?,'draft',?,?,?,?,?,?,?)")->execute([$id,$poNo,$vendorId,$orderDate,$expected!==''?$expected:null,round($subtotal,2),round($taxTotal,2),$total,trim((string)($input['notes']??''))?:null,$op,$loggedInStaff['id'],$loggedInStaff['id']]);}$ins=$pdo->prepare("INSERT INTO growth_purchase_order_items(id,po_id,inventory_id,sku,item_name,quantity,unit,unit_price,tax_rate,tax_amount,line_total,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");foreach($normalized as $n)$ins->execute([tamasyaGrowthId('poi'),$id,$n['inventoryId'],$n['sku'],$n['itemName'],$n['quantity'],$n['unit'],$n['unitPrice'],$n['taxRate'],$n['taxAmount'],$n['lineTotal'],$n['notes']]);$after=$fetchOne($pdo,"SELECT * FROM growth_purchase_orders WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan purchase order','growth_purchase_order',$id,$before,$after+['items'=>$normalized]);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'id'=>$id,'data'=>$after,'items'=>$normalized]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'po-status':
            $requireModule('procurement');$id=trim((string)($input['id']??''));$target=trim((string)($input['status']??''));if($id===''||$target==='')throw new InvalidArgumentException('ID/status PO wajib.');$pdo->beginTransaction();try{$s=$pdo->prepare("SELECT * FROM growth_purchase_orders WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('PO tidak ditemukan.');$current=(string)$before['status'];$allowed=['draft'=>['submitted','cancelled'],'submitted'=>['draft','approved','cancelled'],'approved'=>['received','cancelled'],'received'=>['closed'],'closed'=>[],'cancelled'=>[]];if(!in_array($target,$allowed[$current]??[],true))throw new RuntimeException("Transisi PO {$current} → {$target} tidak diizinkan.");if($target==='approved'){requireCapability($loggedInStaff,'approve_sensitive_actions',['admin','manager']);$pdo->prepare("UPDATE growth_purchase_orders SET status='approved',approved_by=?,approved_at=CURRENT_TIMESTAMP,updated_by=?,version=version+1 WHERE id=?")->execute([$loggedInStaff['id'],$loggedInStaff['id'],$id]);}elseif($target==='received'){requireCapability($loggedInStaff,'approve_sensitive_actions',['admin','manager']);$pdo->prepare("UPDATE growth_purchase_orders SET status='received',received_by=?,received_at=CURRENT_TIMESTAMP,updated_by=?,version=version+1 WHERE id=?")->execute([$loggedInStaff['id'],$loggedInStaff['id'],$id]);}elseif($target==='closed'){requireRoles($loggedInStaff,['admin','manager','finance']);$paid=$pdo->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM growth_purchase_order_payments WHERE po_id=?");$paid->execute([$id]);if((float)$paid->fetchColumn()<(float)$before['total_amount']-0.01)throw new RuntimeException('PO belum boleh ditutup karena pembayaran tertaut belum mencapai total PO.');$pdo->prepare("UPDATE growth_purchase_orders SET status='closed',updated_by=?,version=version+1 WHERE id=?")->execute([$loggedInStaff['id'],$id]);}else{requireRoles($loggedInStaff,['admin','manager','finance']);$pdo->prepare("UPDATE growth_purchase_orders SET status=?,updated_by=?,version=version+1 WHERE id=?")->execute([$target,$loggedInStaff['id'],$id]);}$after=$fetchOne($pdo,"SELECT * FROM growth_purchase_orders WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Mengubah status purchase order','growth_purchase_order',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'po-link-payment':
            $requireModule('procurement');requireRoles($loggedInStaff,['admin','manager','finance']);$poId=trim((string)($input['poId']??''));$txId=trim((string)($input['transactionId']??''));$amount=tamasyaGrowthMoney($input['amountApplied']??0);if($poId===''||$txId===''||$amount<=0)throw new InvalidArgumentException('PO, transaksi dan nominal alokasi wajib.');$pdo->beginTransaction();try{$poS=$pdo->prepare("SELECT * FROM growth_purchase_orders WHERE id=? FOR UPDATE");$poS->execute([$poId]);$po=$poS->fetch(PDO::FETCH_ASSOC)?:null;if(!$po)throw new InvalidArgumentException('PO tidak ditemukan.');if(in_array((string)$po['status'],['draft','cancelled'],true))throw new DomainException('Pembayaran tidak boleh ditautkan ke PO draft/cancelled.');$txS=$pdo->prepare("SELECT id,type,amount,transactionKind,description,`date`,reconciliationStatus FROM transactions WHERE id=? FOR UPDATE");$txS->execute([$txId]);$tx=$txS->fetch(PDO::FETCH_ASSOC)?:null;if(!$tx||strtolower((string)$tx['type'])!=='expense')throw new InvalidArgumentException('Transaksi pembayaran PO harus transaksi pengeluaran existing.');$poPaidS=$pdo->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM growth_purchase_order_payments WHERE po_id=?");$poPaidS->execute([$poId]);$poPaid=(float)$poPaidS->fetchColumn();$txUsedS=$pdo->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM growth_purchase_order_payments WHERE transaction_id=?");$txUsedS->execute([$txId]);$txUsed=(float)$txUsedS->fetchColumn();if($amount>max(0,(float)$po['total_amount']-$poPaid)+0.01)throw new DomainException('Alokasi melebihi sisa PO.');if($amount>max(0,(float)$tx['amount']-$txUsed)+0.01)throw new DomainException('Alokasi melebihi sisa nominal transaksi pengeluaran.');// growth_purchase_order_payments is unique on (po_id,transaction_id). Checking only
// operation_id let a second link for the same pair reach the INSERT and surface as
// PDOException, which the status mapper turns into 500 for what is a client conflict.
$pairStmt=$pdo->prepare("SELECT * FROM growth_purchase_order_payments WHERE po_id=? AND transaction_id=? LIMIT 1 FOR UPDATE");$pairStmt->execute([$poId,$txId]);if($pairStmt->fetch(PDO::FETCH_ASSOC))throw new DomainException('Transaksi pembayaran ini sudah ditautkan ke PO yang sama.');$dup=$pdo->prepare("SELECT * FROM growth_purchase_order_payments WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$existing=$dup->fetch(PDO::FETCH_ASSOC);if($existing){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$existing]);break;}$id=tamasyaGrowthId('pop');$pdo->prepare("INSERT INTO growth_purchase_order_payments(id,po_id,transaction_id,amount_applied,operation_id,linked_by) VALUES (?,?,?,?,?,?)")->execute([$id,$poId,$txId,$amount,$op,$loggedInStaff['id']]);$after=['id'=>$id,'poId'=>$poId,'transaction'=>$tx,'amountApplied'=>$amount];tamasyaGrowthAudit($pdo,$loggedInStaff,'Menautkan pembayaran Keuangan ke purchase order','growth_purchase_order_payment',$id,null,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'channel-mapping-save':
            $requireModule('channel');requireRoles($loggedInStaff,['admin']);$channel=strtolower(trim((string)($input['channelCode']??'')));$external=trim((string)($input['externalRoomType']??''));$internal=trim((string)($input['internalRoomType']??''));if($channel===''||$external===''||$internal==='')throw new InvalidArgumentException('Channel, external room type dan internal room type wajib.');$id=trim((string)($input['id']??''));$externalProperty=trim((string)($input['externalPropertyId']??''));$externalRate=trim((string)($input['externalRatePlan']??''));$pdo->beginTransaction();try{$before=null;if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_channel_mappings WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Mapping tidak ditemukan.');$pdo->prepare("UPDATE growth_channel_mappings SET channel_code=?,external_property_id=?,external_room_type=?,internal_room_type=?,external_rate_plan=?,internal_rate_plan_id=?,active=?,notes=?,version=version+1 WHERE id=?")->execute([$channel,$externalProperty,$external,$internal,$externalRate,trim((string)($input['internalRatePlanId']??''))?:null,isset($input['active'])?(!empty($input['active'])?1:0):1,trim((string)($input['notes']??''))?:null,$id]);}else{$dup=$pdo->prepare("SELECT id FROM growth_channel_mappings WHERE channel_code=? AND external_property_id=? AND external_room_type=? AND external_rate_plan=? LIMIT 1 FOR UPDATE");$dup->execute([$channel,$externalProperty,$external,$externalRate]);if($dup->fetchColumn()!==false)throw new DomainException('Channel mapping untuk kombinasi channel/properti/kamar/rate tersebut sudah ada. Edit mapping existing bila ingin mengubah tujuan internal.');$id=tamasyaGrowthId('chmap');$pdo->prepare("INSERT INTO growth_channel_mappings(id,channel_code,external_property_id,external_room_type,internal_room_type,external_rate_plan,internal_rate_plan_id,active,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$id,$channel,$externalProperty,$external,$internal,$externalRate,trim((string)($input['internalRatePlanId']??''))?:null,isset($input['active'])?(!empty($input['active'])?1:0):1,trim((string)($input['notes']??''))?:null,$loggedInStaff['id']]);}$after=$fetchOne($pdo,"SELECT * FROM growth_channel_mappings WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menyimpan channel mapping (fondasi, tanpa auto booking)','growth_channel_mapping',$id,$before,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($isDuplicateKey($e))throw new DomainException('Channel mapping untuk kombinasi channel/properti/kamar/rate tersebut sudah ada. Edit mapping existing bila ingin mengubah tujuan internal.',0,$e);throw $e;}break;
        case 'channel-event-register':
            $requireModule('channel');requireRoles($loggedInStaff,['admin']);$channel=strtolower(trim((string)($input['channelCode']??'')));$eventType=trim((string)($input['eventType']??''));$externalId=trim((string)($input['externalId']??''));$payload=$input['payload']??[];if($channel===''||$eventType==='')throw new InvalidArgumentException('Channel dan event type wajib.');$payloadHash=hash('sha256',tamasyaJsonEncode($payload));$pdo->beginTransaction();try{$dup=$pdo->prepare("SELECT * FROM growth_channel_events WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$existing=$dup->fetch(PDO::FETCH_ASSOC);if($existing){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$existing]);break;}$id=tamasyaGrowthId('chev');$pdo->prepare("INSERT INTO growth_channel_events(id,channel_code,event_type,external_id,operation_id,status,payload_hash,created_by) VALUES (?,?,?,?,?,'registered',?,?)")->execute([$id,$channel,$eventType,$externalId!==''?$externalId:null,$op,$payloadHash,$loggedInStaff['id']]);$after=$fetchOne($pdo,"SELECT * FROM growth_channel_events WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Mendaftarkan event channel tanpa mutasi otomatis','growth_channel_event',$id,null,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after,'autoMutation'=>false]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'payment-intent-create':
            $requireModule('payment');requireRoles($loggedInStaff,['admin','manager','finance']);$bookingId=trim((string)($input['bookingId']??''));$provider=strtolower(trim((string)($input['providerCode']??'')));$amount=tamasyaGrowthMoney($input['amount']??0);if($bookingId===''||$provider===''||$amount<=0)throw new InvalidArgumentException('Booking, provider dan amount wajib.');$pdo->beginTransaction();try{$b=$pdo->prepare("SELECT id,guestName,roomNumber,status,balanceDue FROM bookings WHERE id=? FOR UPDATE");$b->execute([$bookingId]);$booking=$b->fetch(PDO::FETCH_ASSOC)?:null;if(!$booking)throw new InvalidArgumentException('Booking tidak ditemukan.');if(!in_array((string)$booking['status'],['reserved','active','completed'],true))throw new RuntimeException('Status booking tidak menerima payment intent.');if($amount>max(0,(float)$booking['balanceDue'])+0.01)throw new RuntimeException('Payment intent melebihi sisa tagihan booking.');$dup=$pdo->prepare("SELECT * FROM growth_payment_intents WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$existing=$dup->fetch(PDO::FETCH_ASSOC);if($existing){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$existing]);break;}$id=tamasyaGrowthId('pi');$expires=trim((string)($input['expiresAt']??''));$pdo->prepare("INSERT INTO growth_payment_intents(id,booking_id,provider_code,amount,status,external_reference,operation_id,expires_at,notes,created_by) VALUES (?,?,?,?,'created',?,?,?,?,?)")->execute([$id,$bookingId,$provider,$amount,trim((string)($input['externalReference']??''))?:null,$op,$expires!==''?$expires:null,trim((string)($input['notes']??''))?:null,$loggedInStaff['id']]);$after=$fetchOne($pdo,"SELECT * FROM growth_payment_intents WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Membuat payment intent (belum menjadi pembayaran)','growth_payment_intent',$id,null,$after);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after,'financialMutation'=>false]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
        case 'payment-event-register':
            $requireModule('payment');requireRoles($loggedInStaff,['admin']);$intentId=trim((string)($input['intentId']??''));$status=trim((string)($input['status']??''));$amount=tamasyaGrowthMoney($input['amount']??0);$payload=$input['payload']??[];if($intentId===''||$status==='')throw new InvalidArgumentException('Intent/status event wajib.');$pdo->beginTransaction();try{$intentS=$pdo->prepare("SELECT * FROM growth_payment_intents WHERE id=? FOR UPDATE");$intentS->execute([$intentId]);$intent=$intentS->fetch(PDO::FETCH_ASSOC)?:null;if(!$intent)throw new InvalidArgumentException('Payment intent tidak ditemukan.');$providerEvent=trim((string)($input['providerEventId']??''));if($providerEvent!==''){$d=$pdo->prepare("SELECT * FROM growth_payment_events WHERE provider_event_id=? LIMIT 1 FOR UPDATE");$d->execute([$providerEvent]);$existing=$d->fetch(PDO::FETCH_ASSOC);if($existing){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$existing]);break;}}$id=tamasyaGrowthId('pev');$pdo->prepare("INSERT INTO growth_payment_events(id,intent_id,provider_event_id,status,amount,payload_hash,signature_valid,event_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$id,$intentId,$providerEvent!==''?$providerEvent:null,$status,$amount,hash('sha256',tamasyaJsonEncode($payload)),0,trim((string)($input['eventAt']??''))?:null]);$after=$fetchOne($pdo,"SELECT * FROM growth_payment_events WHERE id=?",[$id]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Mendaftarkan event payment tanpa posting otomatis / signature belum diverifikasi provider','growth_payment_event',$id,null,['intentId'=>$intentId,'event'=>$after]);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after,'financialMutation'=>false,'signatureVerification'=>'not_implemented']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
            case 'channel-provider-save':
                $requireModule('channel');requireRoles($loggedInStaff,['admin']);$code=strtolower(trim((string)($input['code']??'')));$id=trim((string)($input['id']??''));$name=trim((string)($input['name']??''));
                if(!preg_match('/^[a-z0-9._-]{2,50}$/',$code))throw new InvalidArgumentException('Kode provider hanya huruf kecil, angka, titik, garis bawah, atau tanda hubung (2-50 karakter).');
                if($name==='')throw new InvalidArgumentException('Nama provider wajib.');
                $pdo->beginTransaction();
                try{
                    $before=null;
                    if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_channel_providers WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Provider channel tidak ditemukan.');}
                    elseif($code!==''){$s=$pdo->prepare("SELECT * FROM growth_channel_providers WHERE code=? FOR UPDATE");$s->execute([$code]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;}
                    $secretInput=array_key_exists('webhookSecret',$input)?(string)$input['webhookSecret']:'';
                    if($before){
                        $storedSecret=$secretInput!==''?encryptStoredSecret($secretInput):$before['webhook_secret_encrypted'];
                        $active=array_key_exists('active',$input)?(!empty($input['active'])?1:0):(int)$before['active'];
                        $pdo->prepare("UPDATE growth_channel_providers SET name=?,webhook_secret_encrypted=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                            ->execute([$name,$storedSecret,$active,(string)$before['id']]);
                        $providerId=(string)$before['id'];
                    }else{
                        // A provider without a secret can never authenticate, so refuse
                        // to create a silent no-op row.
                        if($secretInput===''||strlen($secretInput)<32)throw new InvalidArgumentException('Secret webhook provider wajib minimal 32 karakter.');
                        $providerId=tamasyaGrowthId('chp');
                        $pdo->prepare("INSERT INTO growth_channel_providers(id,code,name,webhook_secret_encrypted,active,created_by) VALUES (?,?,?,?,?,?)")
                            ->execute([$providerId,$code,$name,encryptStoredSecret($secretInput),array_key_exists('active',$input)?(!empty($input['active'])?1:0):1,$loggedInStaff['id']]);
                    }
                    $after=$fetchOne($pdo,"SELECT * FROM growth_channel_providers WHERE id=?",[$providerId]);
                    if($after)$after['webhook_secret_encrypted']=empty($after['webhook_secret_encrypted'])?null:'[TERSIMPAN]';
                    if($before)$before['webhook_secret_encrypted']=empty($before['webhook_secret_encrypted'])?null:'[TERSIMPAN]';
                    tamasyaGrowthAudit($pdo,$loggedInStaff,'Mengatur provider channel (konektor fase terpisah)','growth_channel_provider',$providerId,$before,$after);
                    bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                    echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($isDuplicateKey($e))throw new DomainException('Kode provider channel sudah dipakai provider lain.',0,$e);throw $e;}
                break;
        case 'payment-provider-save':
            $requireModule('payment');requireRoles($loggedInStaff,['admin']);$code=strtolower(trim((string)($input['code']??'')));$id=trim((string)($input['id']??''));$name=trim((string)($input['name']??''));
            if(!preg_match('/^[a-z0-9._-]{2,50}$/',$code))throw new InvalidArgumentException('Kode provider hanya huruf kecil, angka, titik, garis bawah, atau tanda hubung (2-50 karakter).');
            if($name==='')throw new InvalidArgumentException('Nama provider wajib.');
            $pdo->beginTransaction();
            try{
                $before=null;
                if($id!==''){$s=$pdo->prepare("SELECT * FROM growth_payment_providers WHERE id=? FOR UPDATE");$s->execute([$id]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Provider tidak ditemukan.');}
                elseif($code!==''){$s=$pdo->prepare("SELECT * FROM growth_payment_providers WHERE code=? FOR UPDATE");$s->execute([$code]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;}
                $secretInput=array_key_exists('webhookSecret',$input)?(string)$input['webhookSecret']:'';
                if($before){
                    $storedSecret=$secretInput!==''?encryptStoredSecret($secretInput):$before['webhook_secret_encrypted'];
                    $active=array_key_exists('active',$input)?(!empty($input['active'])?1:0):(int)$before['active'];
                    $pdo->prepare("UPDATE growth_payment_providers SET name=?,webhook_secret_encrypted=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                        ->execute([$name,$storedSecret,$active,(string)$before['id']]);
                    $providerId=(string)$before['id'];
                }else{
                    // A provider without a secret can never authenticate a webhook,
                    // so refuse to create one instead of shipping a silent no-op row.
                    if($secretInput===''||strlen($secretInput)<32)throw new InvalidArgumentException('Secret webhook provider wajib minimal 32 karakter.');
                    $providerId=tamasyaGrowthId('pvp');$providerCode=$code;
                    $pdo->prepare("INSERT INTO growth_payment_providers(id,code,name,webhook_secret_encrypted,active,created_by) VALUES (?,?,?,?,?,?)")
                        ->execute([$providerId,$providerCode,$name,encryptStoredSecret($secretInput),array_key_exists('active',$input)?(!empty($input['active'])?1:0):1,$loggedInStaff['id']]);
                }
                $after=$fetchOne($pdo,"SELECT * FROM growth_payment_providers WHERE id=?",[$providerId]);
                if($after){$after['webhook_secret_encrypted']=empty($after['webhook_secret_encrypted'])?null:'[TERSIMPAN]';}
                if($before && array_key_exists('webhook_secret_encrypted',$before))$before['webhook_secret_encrypted']=empty($before['webhook_secret_encrypted'])?null:'[TERSIMPAN]';
                tamasyaGrowthAudit($pdo,$loggedInStaff,'Mengatur provider pembayaran (konektor fase terpisah)','growth_payment_provider',$providerId,$before,$after);
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($isDuplicateKey($e))throw new DomainException('Kode provider pembayaran sudah dipakai provider lain.',0,$e);throw $e;}
            break;
        case 'payment-link-transaction':
            $requireModule('payment');requireRoles($loggedInStaff,['admin','manager','finance']);$intentId=trim((string)($input['intentId']??''));$txId=trim((string)($input['transactionId']??''));if($intentId===''||$txId==='')throw new InvalidArgumentException('Intent dan transactionId wajib.');$pdo->beginTransaction();try{$s=$pdo->prepare("SELECT * FROM growth_payment_intents WHERE id=? FOR UPDATE");$s->execute([$intentId]);$before=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$before)throw new InvalidArgumentException('Payment intent tidak ditemukan.');$t=$pdo->prepare("SELECT id,bookingId,type,amount,transactionKind,operationId FROM transactions WHERE id=? FOR UPDATE");$t->execute([$txId]);$tx=$t->fetch(PDO::FETCH_ASSOC)?:null;if(!$tx||strtolower((string)$tx['type'])!=='income'||(string)$tx['bookingId']!==(string)$before['booking_id'])throw new InvalidArgumentException('Transaksi harus pemasukan canonical milik booking yang sama.');if(!moneyMatches((float)$tx['amount'],(float)$before['amount'],0.01))throw new RuntimeException('Nominal transaksi berbeda dari payment intent.');$pdo->prepare("UPDATE growth_payment_intents SET status='confirmed',confirmed_transaction_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$txId,$intentId]);$after=$fetchOne($pdo,"SELECT * FROM growth_payment_intents WHERE id=?",[$intentId]);tamasyaGrowthAudit($pdo,$loggedInStaff,'Menautkan canonical booking payment ke payment intent','growth_payment_intent',$intentId,$before,['intent'=>$after,'transaction'=>$tx]);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'data'=>$after,'sourceOfTruth'=>'transactions']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}break;
                    case 'exchange-rate-save':
                // Mencatat kurs harian. Tanpa kurs, transaksi dalam mata uang
                // asing ditolak server, jadi kurs dicatat dari Growth Suite.
                if(!tamasyaGrowthSuiteEnabled()||!tamasyaGrowthSchemaReady($pdo)){http_response_code(409);echo tamasyaJsonEncode(['success'=>false,'error'=>'Growth Suite belum aktif pada property ini.']);break;}
                if(!$canAnyTab(['finance','operations','report'])){http_response_code(403);echo tamasyaJsonEncode(['success'=>false,'error'=>'Izin mencatat kurs tidak ada.']);break;}
                $rateBase=tamasyaPropertyCurrency($pdo);
                try{
                    $rateId=tamasyaSaveExchangeRate($pdo,$rateBase,trim((string)($input['quoteCurrency']??'')),(float)($input['rate']??0),trim((string)($input['rateDate']??'')),trim((string)($input['source']??'manual')),(string)($loggedInStaff['id']??''));
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat kurs transaksi','exchange_rate',$rateId,null,['baseCurrency'=>$rateBase,'quoteCurrency'=>strtoupper(trim((string)($input['quoteCurrency']??''))),'rate'=>(float)($input['rate']??0),'rateDate'=>trim((string)($input['rateDate']??''))],'exchange_rate');
                    echo tamasyaJsonEncode(['success'=>true,'data'=>['id'=>$rateId]]);
                }catch(InvalidArgumentException $rateInvalid){
                    http_response_code(422);
                    echo tamasyaJsonEncode(['success'=>false,'error'=>$rateInvalid->getMessage()]);
                }catch(Throwable $rateSchemaMissing){
                    http_response_code(409);
                    echo tamasyaJsonEncode(['success'=>false,'error'=>'Tabel kurs belum tersedia. Jalankan migrasi 20261021_TRANSACTION_CURRENCY.sql.']);
                }
                break;
            case 'contract-quote':
                // Kuotasi tarif kontrak untuk satu inap. Server yang menghitung
                // nominal; Growth Suite hanya menampilkan.
                if(!tamasyaGrowthSuiteEnabled()||!tamasyaGrowthSchemaReady($pdo)){http_response_code(409);echo tamasyaJsonEncode(['success'=>false,'error'=>'Growth Suite belum aktif pada property ini.']);break;}
                if(!$canAnyTab(['rooms','operations','report','finance'])){http_response_code(403);echo tamasyaJsonEncode(['success'=>false,'error'=>'Izin membaca tarif kontrak tidak ada.']);break;}
                $contractQuote=[
                    'checkIn'=>trim((string)($input['checkIn']??'')),
                    'checkOut'=>trim((string)($input['checkOut']??'')),
                    'roomType'=>trim((string)($input['roomType']??'')),
                    'companyName'=>trim((string)($input['companyName']??'')),
                    'planId'=>trim((string)($input['planId']??'')),
                ];
                if($contractQuote['planId']===''){
                    $candidates=[];
                    try{
                        $candidates=tamasyaWholesaleContractRateCandidates($pdo,strtolower(trim((string)($GLOBALS['tamasya_property_id']??''))),$contractQuote);
                    }catch(Throwable $wholesaleSchemaMissing){$candidates=[];}
                    echo tamasyaJsonEncode(['success'=>true,'data'=>['candidates'=>$candidates,'applicable'=>array_values(array_filter($candidates,function($row){return (bool)($row['applicable']??false);}))]]);break;
                }
                try{
                    $contractQuoteResult=tamasyaWholesaleContractRateQuote($pdo,strtolower(trim((string)($GLOBALS['tamasya_property_id']??''))),$contractQuote);
                    echo tamasyaJsonEncode(['success'=>true,'data'=>['quote'=>$contractQuoteResult]]);
                }catch(Throwable $wholesaleSchemaMissing){
                    http_response_code(409);
                    echo tamasyaJsonEncode(['success'=>false,'error'=>'Skema tarif kontrak belum tersedia. Jalankan migrasi 20261021_WHOLESALE_RATES.sql.']);
                }
                break;
default: throw new InvalidArgumentException('Command Growth Suite POST tidak dikenal.');
    }
} catch(Throwable $growthError) {
    if($pdo instanceof PDO && $pdo->inTransaction()){try{$pdo->rollBack();}catch(Throwable $ignored){}}
    tamasyaApplyExceptionHttpStatus($growthError,$growthError instanceof InvalidArgumentException?422:500);
    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Growth Suite gagal',$growthError),'features'=>tamasyaGrowthFeatureStatus()]);
}
