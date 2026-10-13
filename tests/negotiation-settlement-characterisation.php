<?php
/**
 * Characterisation guard for negotiated checkout.
 *
 * This file records what the settlement path does TODAY. It deliberately does not
 * assert that today's behaviour is desirable. Its only job is to make the current
 * arithmetic visible and stable, so that any later change to the negotiated total
 * cannot silently alter what a cashier collects.
 *
 * The open question this file makes explicit:
 *
 *   When a receptionist enters a negotiated figure for a guest who already paid
 *   part of the stay, is that figure the FINAL TOTAL of the whole stay, or the
 *   PRICE OF THE UNPAID PORTION?
 *
 * The web label says "Harga Nego Perpanjangan", which reads as the unpaid portion.
 * The implementation writes it as the whole booking total, and settlement then
 * deducts the settled payment a second time.
 *
 * Nothing here is a pass or fail judgement on that ambiguity. Each entry below is
 * the observed value, so a diff here means the money a cashier collects moved.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/030_booking_finance.php';
require dirname(__DIR__).'/api/modules/hr_staff/020_identity_access_audit.php';
require dirname(__DIR__).'/api/modules/setup_admin/010_schema_contract.php';
require dirname(__DIR__).'/api/modules/setup_admin/004_property_setup.php';

$passed=0;
function checkObserved($ok,$label,$observed){
    global $passed;
    if(!$ok)throw new RuntimeException($label.' observed='.json_encode($observed));
    $passed++;
    echo "PASS $label observed=".json_encode($observed)."\n";
}

// The settlement expression used by api/routes/020_hotel_booking.php and by the
// Telegram checkout path in api/routes/080_telegram_webhook.php.
// Kept verbatim so the characterisation tracks the production arithmetic.
function settlementRemaining(float $bookingTotal,float $ledgerNet): float {
    return max(0.0,round($bookingTotal-$ledgerNet,2));
}

// Reported case: one night settled on arrival, three more added as an open
// extension, then a negotiated figure entered at checkout.
$settledNight=220000.0;
$extensionMaster=660000.0;
$bookingTotal=$settledNight+$extensionMaster;   // 880.000
$ledgerNet=$settledNight;                        // 220.000 already received
$negotiated=600000.0;

$booking=['totalAmount'=>$bookingTotal,'vatAmount'=>80000,'vatRate'=>10,'extras'=>[
    ['id'=>'extension1','total'=>$extensionMaster,'price'=>$extensionMaster,'qty'=>1,
     'baseAmount'=>600000,'taxAmount'=>60000,'taxRate'=>10,'allocationType'=>'extension','paymentStatus'=>'unpaid'],
]];
// The extension is a room charge, so it is discountable capacity; only the settled
// night is excluded because its component is already paid in full.
$components=[['key'=>'room','allocationType'=>'room','gross'=>$settledNight,'tax'=>20000,'rate'=>10],
             ['key'=>'room:extension1','allocationType'=>'room','gross'=>$extensionMaster,'tax'=>60000,'rate'=>10]];

// The paid night is recorded against its own component, so only the extension is due.
$paid=['room'=>$settledNight,'room:extension1'=>0];

checkObserved(
    abs(($bookingTotal-$ledgerNet)-$extensionMaster)<0.01,
    'Booking total minus cash received equals the unpaid extension',
    ['total'=>$bookingTotal,'net'=>$ledgerNet,'due'=>$extensionMaster]
);

$plan=tamasyaNegotiatedPricePlan($booking,$components,$paid,$ledgerNet,$negotiated);

checkObserved(
    abs($plan['totalAmount']-$negotiated)<0.01,
    'Negotiated figure REPLACES the whole booking total',
    ['entered'=>$negotiated,'bookingTotalAfter'=>$plan['totalAmount']]
);

checkObserved(
    abs($plan['totalAmount']-($bookingTotal+$negotiated))>0.01,
    'Negotiated figure is not added on top of the previous total',
    ['previousTotal'=>$bookingTotal,'after'=>$plan['totalAmount'],'sumIfAdded'=>$bookingTotal+$negotiated]
);

checkObserved(
    !isset($plan['componentCuts']['room']),
    'The already settled component receives no discount cut',
    ['roomCut'=>$plan['componentCuts']['room']??null]
);

$remaining=settlementRemaining($plan['totalAmount'],$ledgerNet);

checkObserved(
    abs($remaining-380000.0)<0.01,
    'Cashier collects negotiated figure minus the settled payment a second time',
    ['negotiated'=>$negotiated,'alreadyPaid'=>$ledgerNet,'collected'=>$remaining]
);

checkObserved(
    abs($remaining-600000.0)>0.01,
    'Cashier does NOT collect the negotiated figure itself',
    ['collected'=>$remaining,'enteredFigure'=>$negotiated]
);

// A guest with nothing paid yet keeps the negotiated figure as the collected amount.
$freshBooking=['totalAmount'=>$extensionMaster,'vatAmount'=>60000,'vatRate'=>10,'extras'=>$booking['extras']];
$freshPaid=['room'=>0,'room:extension1'=>0];
$freshPlan=tamasyaNegotiatedPricePlan($freshBooking,$components,$freshPaid,0.0,$negotiated);

checkObserved(
    abs(settlementRemaining($freshPlan['totalAmount'],0.0)-600000.0)<0.01,
    'With nothing paid yet the negotiated figure is collected in full',
    ['collected'=>settlementRemaining($freshPlan['totalAmount'],0.0)]
);

// The guard that keeps a negotiation from behaving as a surcharge.
$raisedRejected=false;
try{tamasyaNegotiatedPricePlan($booking,$components,$paid,$ledgerNet,$bookingTotal+160000.0);}catch(InvalidArgumentException|RuntimeException $e){$raisedRejected=true;}
checkObserved($raisedRejected,'Entering more than the current bill is rejected',['attempted'=>$bookingTotal+160000.0]);

$belowCashRejected=false;
try{tamasyaNegotiatedPricePlan($booking,$components,$paid,$ledgerNet,$ledgerNet-1000.0);}catch(InvalidArgumentException|RuntimeException $e){$belowCashRejected=true;}
checkObserved($belowCashRejected,'Reducing the total below cash already received is rejected',['attempted'=>$ledgerNet-1000.0]);

echo "negotiation settlement characterisation: $passed checks\n";
