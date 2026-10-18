<?php
// Read-only unit fixtures: no database connection, SMTP, or outbound email.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
if (!defined('TAMASYA_RECEIPT_EMAIL_LIBRARY')) define('TAMASYA_RECEIPT_EMAIL_LIBRARY', true);
require dirname(__DIR__).'/api/support/060_shift_receipts.php';

$GLOBALS['fake_side_effects'] = true;
$GLOBALS['fake_smtp_ok'] = false;
$GLOBALS['fake_smtp_throws'] = false;
$GLOBALS['fake_php_mail_ok'] = false;
$GLOBALS['fake_smtp_host'] = '';
$GLOBALS['fake_mail_log'] = [];
$GLOBALS['fake_sql_log'] = [];
$GLOBALS['fake_audits'] = [];
$GLOBALS['fake_company'] = false;
$GLOBALS['fake_group_detail'] = [];

function tamasyaPropertyProfile(?PDO $pdo = null): array {
    return ['hotelName'=>'Hotel Uji','address'=>'Jl. Uji 1','phone'=>'0811-000','email'=>'hotel@uji.test','invoicePrefix'=>'INV'];
}
function tamasyaMaskEmailAddress(string $email): string {
    $parts = explode('@', $email, 2);
    return substr($parts[0], 0, 2).'***@'.($parts[1] ?? '');
}
function tamasyaExternalSideEffectsAllowed(): bool { return (bool)$GLOBALS['fake_side_effects']; }
function sendSmtpMail($to, $subject, $html, $conf, $attachments = []): bool {
    $GLOBALS['fake_mail_log'][] = ['via'=>'smtp','to'=>$to,'subject'=>$subject];
    if (!empty($GLOBALS['fake_smtp_throws'])) throw new RuntimeException('simulated smtp transport failure');
    return (bool)$GLOBALS['fake_smtp_ok'];
}
function tamasyaPhpMailWithAttachments($to, $subject, $html, $attachments = [], $conf = []): bool {
    $GLOBALS['fake_mail_log'][] = ['via'=>'php','to'=>$to,'subject'=>$subject];
    return (bool)$GLOBALS['fake_php_mail_ok'];
}
function writeRequiredEnterpriseAudit($pdo, $actor, $action, $entityType, $entityId = null, $before = null, $after = [], $channel = 'web'): void {
    $GLOBALS['fake_audits'][] = ['action'=>$action,'entityType'=>$entityType,'entityId'=>$entityId,'after'=>$after,'channel'=>$channel];
}
function tamasyaMultiRoomDetail(PDO $pdo, string $groupId): array { return $GLOBALS['fake_group_detail']; }

final class ReceiptEmailFixture extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return new ReceiptEmailStatement($query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ReceiptEmailStatement($query);
    }
}
final class ReceiptEmailStatement extends PDOStatement {
    public function __construct(private string $query) {}
    public function execute(?array $params = null): bool {
        $GLOBALS['fake_sql_log'][] = ['sql'=>$this->query,'params'=>$params];
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (str_contains($this->query, 'FROM config')) return ['id'=>'system_default','smtp_host'=>$GLOBALS['fake_smtp_host']];
        if (str_contains($this->query, 'FROM growth_companies')) return $GLOBALS['fake_company'];
        return false;
    }
}

$passed = 0;
function emailCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}

$booking = ['id'=>'abcd1234-5678-90ab','createdAt'=>'2026-01-15 10:00:00','roomNumber'=>'101','roomType'=>'Deluxe','guestName'=>'Irfan','guestPhone'=>'0811-222','checkIn'=>'2026-01-15','checkOut'=>'2026-01-17','totalAmount'=>500000,'vatAmount'=>50000,'vatRate'=>10,'extras'=>[['name'=>'Laundry','qty'=>2,'price'=>50000,'total'=>100000,'baseAmount'=>100000,'taxAmount'=>0]],'paymentStatus'=>'paid','paymentMethod'=>'qris'];
$guest = tamasyaBookingReceiptEmailContent($booking,'Resepsionis Uji',tamasyaPropertyProfile());
emailCheck($guest['invoiceNumber']==='INV/2026/ABCD1234','Invoice number follows prefix/year/booking-id format');
emailCheck($guest['subject']==='Nota INV/2026/ABCD1234 - Kamar 101 - Hotel Uji','Guest subject carries invoice room and property');
emailCheck(str_contains($guest['html'],'Rp 350.000'),'Room rent subtracts PBJT and extras base exactly like the thermal receipt');
emailCheck(str_contains($guest['html'],'Pajak PBJT (10%)') && str_contains($guest['html'],'Rp 50.000'),'PBJT row keeps configured rate and amount');
emailCheck(str_contains($guest['html'],'Tarif Kamar (2x)') && str_contains($guest['html'],'Laundry (x2)'),'Nights and extras rows are rendered');
emailCheck(str_contains($guest['html'],'TOTAL TAGIHAN') && str_contains($guest['html'],'Rp 500.000'),'Grand total stays booking totalAmount');
emailCheck(str_contains($guest['html'],'LUNAS') && str_contains($guest['html'],'QRIS'),'Paid badge includes payment method label');
$unpaid = tamasyaBookingReceiptEmailContent(array_merge($booking,['guestName'=>'<b>Eko</b> & Co','paymentStatus'=>'unpaid','paymentMethod'=>'cash','vatAmount'=>0,'vatRate'=>null,'extras'=>[]]),'Resepsionis Uji',tamasyaPropertyProfile());
emailCheck(!str_contains($unpaid['html'],'<b>Eko</b>') && str_contains($unpaid['html'],'&lt;b&gt;Eko&lt;/b&gt; &amp; Co'),'Guest-supplied text is HTML-escaped');
emailCheck(!str_contains($unpaid['html'],'Pajak PBJT') && str_contains($unpaid['html'],'BELUM BAYAR'),'Zero VAT omits the row and unpaid badge is explicit');

$company = ['id'=>'cmp1','name'=>'PT Uji Tour','tax_id'=>'01.234.567.8-901.000','billing_email'=>'billing@uji.test','phone'=>'021-1','address'=>'Jl. Korporat 5'];
$detail = [
    'group'=>['id'=>'grp00001xyz','group_code'=>'GRP-2026-0007','name'=>'PT Uji Tour','arrival_date'=>'2026-02-01','departure_date'=>'2026-02-03','billing_mode'=>'master','company_id'=>'cmp1'],
    'bookings'=>[
        ['roomNumber'=>'201','roomType'=>'Deluxe','guestName'=>'A','checkIn'=>'2026-02-01','checkOut'=>'2026-02-03','status'=>'active','totalAmount'=>1000000,'discountAmount'=>0,'amountPaid'=>400000,'balanceDue'=>600000],
        ['roomNumber'=>'202','roomType'=>'Suite','guestName'=>'B','checkIn'=>'2026-02-01','checkOut'=>'2026-02-03','status'=>'cancelled','totalAmount'=>800000,'discountAmount'=>0,'amountPaid'=>0,'balanceDue'=>0],
    ],
    'totals'=>['total'=>1800000,'paid'=>400000,'balance'=>1400000],
];
$report = tamasyaGroupReportEmailContent($detail,'Resepsionis Uji',tamasyaPropertyProfile(),$company);
emailCheck($report['reportReference']==='GRP-2026-0007','Group reference prefers the stored group code');
emailCheck($report['subject']==='Laporan Grup GRP-2026-0007 - PT Uji Tour - Hotel Uji','Group subject carries reference group and property');
emailCheck(str_contains($report['html'],'NPWP: 01.234.567.8-901.000') && str_contains($report['html'],'Jl. Korporat 5'),'Company identity block carries NPWP and address');
emailCheck(str_contains($report['html'],'Kamar 201') && str_contains($report['html'],'Kamar 202') && str_contains($report['html'],'Dibatalkan'),'Every room row is listed with localized status');
emailCheck(str_contains($report['html'],'Rp 1.800.000') && str_contains($report['html'],'Rp 400.000') && str_contains($report['html'],'Rp 1.400.000'),'Collective totals mirror the on-screen group numbers');
$fallbackDetail = $detail;
$fallbackDetail['group'] = array_merge($detail['group'], ['group_code'=>'']);
$fallback = tamasyaGroupReportEmailContent($fallbackDetail,'Staf',tamasyaPropertyProfile(),null);
emailCheck($fallback['reportReference']==='GRP-GRP00001','Missing group code falls back to GRP- plus id prefix');
emailCheck(str_contains($fallback['html'],'Perusahaan'),'Missing company still renders a safe recipient label');

$pdo = new ReceiptEmailFixture();
$actor = ['id'=>'stf1','name'=>'Resepsionis Uji','role'=>'receptionist'];
try {
    tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'not-an-email','web','op-1');
    emailCheck(false,'Invalid recipient email rejected');
} catch (InvalidArgumentException $e) {
    emailCheck(str_contains($e->getMessage(),'Alamat email penerima tidak valid.'),'Invalid recipient email rejected');
}
$GLOBALS['fake_side_effects'] = false;
try {
    tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'tamu@uji.test','web','op-1');
    emailCheck(false,'Non-primary node refuses official email');
} catch (RuntimeException $e) {
    emailCheck(str_contains($e->getMessage(),'Primary'),'Non-primary node refuses official email');
}
$GLOBALS['fake_side_effects'] = true;
$GLOBALS['fake_smtp_host'] = 'smtp.uji.test';
$GLOBALS['fake_smtp_ok'] = true;
$auditCount = count($GLOBALS['fake_audits']);
$sent = tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'Tamu@Uji.test','web','op-1');
emailCheck($sent['method']==='SMTP' && $sent['masked']==='ta***@uji.test','SMTP path reports method and masked recipient');
emailCheck(count($GLOBALS['fake_audits'])===$auditCount+1,'Every successful send writes one enterprise audit row');
$audit = $GLOBALS['fake_audits'][array_key_last($GLOBALS['fake_audits'])];
emailCheck($audit['action']==='Mengirim nota kamar via email' && $audit['entityType']==='booking' && $audit['entityId']===$booking['id'],'Guest audit records action scope and booking id');
emailCheck(preg_match('/^receipt_email_[0-9a-f]{40}$/',(string)$audit['after']['eventId'])===1 && $audit['after']['recipientHash']===hash('sha256','tamu@uji.test') && $audit['after']['method']==='SMTP' && $audit['after']['channel']==='web','Guest audit hashes recipient and pins method channel and event id');
$sentAgain = tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'tamu@uji.test','web','op-1');
$auditAgain = $GLOBALS['fake_audits'][array_key_last($GLOBALS['fake_audits'])];
emailCheck($sentAgain['invoiceNumber']===$sent['invoiceNumber'] && $auditAgain['after']['eventId']===$audit['after']['eventId'],'Same operation replays the same invoice and audit event id');
$updateLogs = fn() => array_values(array_filter($GLOBALS['fake_sql_log'],fn($row)=>str_contains($row['sql'],'UPDATE bookings SET guestEmail')));
$persistRows = $updateLogs();
emailCheck(count($persistRows)>=1 && $persistRows[0]['params']===['tamu@uji.test',$booking['id']],'Successful send persists the normalized email onto the booking');
$persistBaseline = count($updateLogs());
tamasyaSendBookingReceiptEmail($pdo,$actor,array_merge($booking,['guestEmail'=>'tamu@uji.test']),'tamu@uji.test','web','op-1c');
emailCheck(count($updateLogs())===$persistBaseline,'Re-sending to the same stored address skips the redundant write');
tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,str_repeat('a',64).'@'.str_repeat('b',40).'.test','web','op-1d');
emailCheck(count($updateLogs())===$persistBaseline,'Addresses beyond the varchar(100) column are not persisted');
$GLOBALS['fake_smtp_ok'] = false;
$GLOBALS['fake_php_mail_ok'] = true;
$fallbackSend = tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'tamu@uji.test','telegram','op-2');
emailCheck($fallbackSend['method']==='PHP mail()','SMTP failure falls back to PHP mail like canonical reports');
$GLOBALS['fake_smtp_ok'] = true;
$GLOBALS['fake_smtp_throws'] = true;
$thrownFallback = tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'tamu@uji.test','web','op-2b');
emailCheck($thrownFallback['method']==='PHP mail()','SMTP exception also falls back to PHP mail without aborting');
$GLOBALS['fake_smtp_throws'] = false;
$GLOBALS['fake_php_mail_ok'] = false;
$GLOBALS['fake_smtp_host'] = '';
try {
    tamasyaSendBookingReceiptEmail($pdo,$actor,$booking,'tamu@uji.test','web','op-3');
    emailCheck(false,'Both channels failing surfaces the configuration message');
} catch (RuntimeException $e) {
    emailCheck(str_contains($e->getMessage(),'Periksa konfigurasi SMTP/email hosting di Pengaturan.'),'Both channels failing surfaces the configuration message');
}
$GLOBALS['fake_smtp_host'] = 'smtp.uji.test';
$GLOBALS['fake_smtp_ok'] = true;
$GLOBALS['fake_group_detail'] = $detail;
$GLOBALS['fake_company'] = $company;
$groupSent = tamasyaSendGroupReportEmail($pdo,$actor,'grp00001xyz','billing@uji.test','web','op-4');
$groupAudit = $GLOBALS['fake_audits'][array_key_last($GLOBALS['fake_audits'])];
emailCheck($groupSent['reportReference']==='GRP-2026-0007' && $groupSent['method']==='SMTP','Group report sends through the same hardened channel');
emailCheck(preg_match('/^group_report_email_[0-9a-f]{40}$/',(string)$groupAudit['after']['eventId'])===1 && $groupAudit['entityType']==='growth_group_reservation' && $groupAudit['entityId']==='grp00001xyz','Group audit keeps the group id and a distinct hashed event id');
emailCheck($groupAudit['action']==='Mengirim laporan grup via email' && $groupAudit['after']['bookingCount']===2 && (float)$groupAudit['after']['total']===1800000.0 && (float)$groupAudit['after']['balance']===1400000.0,'Group audit captures group totals instead of guest data');

$root = dirname(__DIR__);
$route = file_get_contents($root.'/api/routes/020_hotel_booking.php');
$webhook = file_get_contents($root.'/api/routes/080_telegram_webhook.php');
$multiTelegram = file_get_contents($root.'/api/modules/front_office/044_multi_room_telegram.php');
emailCheck(str_contains($route,"'booking-receipt-email'") && str_contains($route,"'group-report-email'") && str_contains($route,"hasCapability(\$loggedInStaff,'sell_room_access',\$roles)") && str_contains($route,"tamasyaGrowthRequireReady(\$pdo,'group')") && str_contains($route,'Peran ini tidak berhak mengirim email resmi.'),'Web route whitelists both actions behind sell-or-stay plus rooms workspace');
emailCheck(str_contains($webhook,'waiting_for_receipt_email') && str_contains($webhook,'email_receipt:') && str_contains($webhook,'email_receipt_send:'),'Telegram bot exposes receipt email prompt quick-send and state');
emailCheck(str_contains($multiTelegram,'mr_report:') && str_contains($multiTelegram,'mr_report_send:') && str_contains($multiTelegram,'tamasyaSendGroupReportEmail'),'Telegram group detail wires the collective report email flow');
emailCheck(str_contains(file_get_contents($root.'/assets/chunks/booking-receipt.js'),'booking-receipt-email'),'Web receipt dialog calls the guarded receipt-email action');
emailCheck(str_contains(file_get_contents($root.'/assets/chunks/multi-room.js'),'group-report-email'),'Web group detail calls the guarded group-report action');
$smtpModuleSrc = file_get_contents($root.'/api/modules/comms/050_integrations_telegram_mail.php');
emailCheck(str_contains($smtpModuleSrc,'Content-Transfer-Encoding: base64') && str_contains($smtpModuleSrc,'chunk_split(base64_encode('),'No-attachment SMTP path base64-wraps every wire row');
$receiptLibSrc = file_get_contents($root.'/api/support/060_shift_receipts.php');
emailCheck(str_contains($receiptLibSrc,'try{$sent=sendSmtpMail(') && str_contains($receiptLibSrc,'catch(Exception $smtpFailure)'),'Receipt email send wraps SMTP exceptions so the PHP mail fallback always runs');
emailCheck(substr_count($route,"\$result['method']")>=2,'Web success messages expose the real delivery method for receipt and group');
emailCheck(substr_count($webhook,"\$result['method']")>=3,'Telegram receipt and group replies expose the real delivery method');
emailCheck(str_contains($smtpModuleSrc,'[smtp] DATA ditolak server') && str_contains($smtpModuleSrc,'Server SMTP menolak pengirim'),'SMTP client logs DATA rejection and refuses a rejected envelope sender');
emailCheck(str_contains($receiptLibSrc,'[receipt-email] SMTP tidak berhasil'),'Fallback to PHP mail is logged with the SMTP host for evidence');

echo "Receipt email: $passed passed; 0 failed.".PHP_EOL;
