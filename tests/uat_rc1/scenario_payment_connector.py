from client import *
from client import base as client_base
from scenario_core import db
from scenario_fixtures import ensure_open_shift
import datetime, json, hmac, hashlib, urllib.request, urllib.error, time

# End-to-end coverage for the payment connector phase (R14.2).
#
# A verified provider webhook may only move a payment intent to a provider-reported
# status and record the event. It must never post money, and every failure mode
# (missing headers, wrong signature, stale timestamp, unknown provider, unknown
# status, illegal transition) must fail closed before anything is written.
results=[]
run='payconn_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))
SECRET='uat-webhook-secret-'+run+'-0123456789abcdef'

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:400],flush=True)
    (base/'logs/payment-connector-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def webhook(payload,provider=None,timestamp=None,signature=None):
    """POST the exact bytes we sign. The signature covers timestamp + '.' + body."""
    body=json.dumps(payload,separators=(',',':')).encode('utf8')
    ts=str(int(timestamp if timestamp is not None else time.time()))
    sig=signature if signature is not None else hmac.new(SECRET.encode(),(ts+'.').encode()+body,hashlib.sha256).hexdigest()
    headers={'Content-Type':'application/json','X-Device-ID':DEVICE_ID,'Origin':ORIGIN}
    if provider: headers['X-Tamasya-Provider']=provider
    if timestamp is not None or True: headers['X-Tamasya-Timestamp']=ts
    if sig: headers['X-Tamasya-Signature']=sig
    req=urllib.request.Request(BASE_URL+'/api.php?action=growth-payment-webhook',data=body,headers=headers,method='POST')
    try:
        resp=urllib.request.urlopen(req,timeout=30)
    except urllib.error.HTTPError as e:
        resp=e
    raw=resp.read().decode('utf8','replace')
    try: parsed=json.loads(raw) if raw else {}
    except Exception: parsed={'raw':raw[:400]}
    return int(resp.status),parsed

# ---------------------------------------------------------------- provider registry
s,b=request('growth-suite','POST',{'command':'payment-provider-save','code':'uat_gateway','name':'UAT Gateway','webhookSecret':SECRET,'active':1},'pc_save_'+run)
check('admin can register a payment provider with a secret',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:200]})
s,b=request('growth-suite','POST',{'command':'payment-provider-save','code':'uat_nosecret','name':'UAT No Secret','webhookSecret':'','active':1},'pc_nosecret_'+run)
check('a provider cannot be created without a secret',s>=400 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=request('growth-suite','GET',{'command':'payment-provider-list'},'pc_list_'+run)
providers={p['code']:p for p in (b.get('providers') or [])} if s==200 else {}
check('provider list shows the registry without leaking secrets',s==200 and 'uat_gateway' in providers and providers['uat_gateway'].get('hasSecret') in (1,True,'1') and 'webhook_secret_encrypted' not in (providers.get('uat_gateway') or {}),{'status':s,'providers':list(providers)})

# ---------------------------------------------------------------- fixture: booking + intent
ensure_open_shift('pc_shift_'+run)
free=db("SELECT r.number FROM rooms r WHERE r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) ORDER BY CAST(r.number AS UNSIGNED) LIMIT 1")
if not free:
    print('FAIL no free room for the payment connector scenario')
    raise SystemExit(1)
s,bk=request('bookings','POST',{'guestName':'UAT Payment Connector','roomNumber':free[0]['number'],'checkIn':(datetime.date.today()+datetime.timedelta(days=1)).isoformat(),'checkOut':(datetime.date.today()+datetime.timedelta(days=2)).isoformat(),'totalAmount':500000,'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now','broadcast':False},'pc_booking_'+run)
booking_id=bk.get('bookingId') if s==200 else None
check('fixture booking for the payment intent is checked in',bool(booking_id),{'status':s,'body':str(bk)[:200]})
s,intent=request('growth-suite','POST',{'command':'payment-intent-create','bookingId':booking_id,'providerCode':'uat_gateway','amount':500000},'pc_intent_'+run)
check('payment intent is created for the booking',s==200 and intent.get('success') is True and intent.get('financialMutation') is False,{'status':s,'body':str(intent)[:220]})
intent_id=intent.get('data',{}).get('id') if isinstance(intent.get('data'),dict) else None
if not intent_id:
    print('FAIL intent id missing; stop')
    raise SystemExit(1)
transactions_before=db("SELECT COUNT(*) n FROM transactions WHERE bookingId=?",[booking_id])[0]['n']

# ---------------------------------------------------------------- fail-closed authentication
s,b=webhook({'intentId':intent_id,'status':'paid','amount':500000},provider=None,signature='0'*64)
check('webhook without provider header is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'intentId':intent_id,'status':'paid','amount':500000},provider='uat_gateway',signature='f'*64)
check('webhook with a wrong signature is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'intentId':intent_id,'status':'paid','amount':500000},provider='uat_gateway',timestamp=int(time.time())-4000)
check('webhook with a stale timestamp is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'intentId':intent_id,'status':'paid','amount':500000},provider='uat_unknown',timestamp=int(time.time()))
check('webhook from an unknown provider is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# ---------------------------------------------------------------- signature covers the body
tampered=json.dumps({'intentId':intent_id,'status':'paid','amount':999999},separators=(',',':')).encode('utf8')
ts=str(int(time.time()))
sig=hmac.new(SECRET.encode(),(ts+'.').encode()+json.dumps({'intentId':intent_id,'status':'paid','amount':500000},separators=(',',':')).encode(),hashlib.sha256).hexdigest()
req=urllib.request.Request(BASE_URL+'/api.php?action=growth-payment-webhook',data=tampered,headers={'Content-Type':'application/json','X-Device-ID':DEVICE_ID,'Origin':ORIGIN,'X-Tamasya-Provider':'uat_gateway','X-Tamasya-Timestamp':ts,'X-Tamasya-Signature':sig},method='POST')
try:
    resp=urllib.request.urlopen(req,timeout=30); status=int(resp.status); body=json.loads(resp.read().decode('utf8','replace') or '{}')
except urllib.error.HTTPError as e:
    status=int(e.code); body=json.loads(e.read().decode('utf8','replace') or '{}')
check('a body edited after signing is refused',status==401 and body.get('success') is False,{'status':status,'body':str(body)[:200]})

# ---------------------------------------------------------------- unknown status and illegal transition
s,b=webhook({'intentId':intent_id,'status':'voucher_approved_maybe','amount':500000},provider='uat_gateway')
check('unknown provider status is refused',s>=400 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'intentId':intent_id,'status':'paid','amount':500000},provider='uat_gateway',signature='0'*64)
check('unverified request cannot move the intent',s==401,{'status':s,'body':str(b)[:200]})
check('no payment event was recorded by any refused webhook',int(db("SELECT COUNT(*) n FROM growth_payment_events WHERE intent_id=?",[intent_id])[0]['n'])==0)

# ---------------------------------------------------------------- verified webhook records but never posts money
event_id='evt_'+run
s,b=webhook({'intentId':intent_id,'providerEventId':event_id,'status':'paid','amount':500000,'eventAt':datetime.datetime.now().strftime('%Y-%m-%dT%H:%M:%S'),'payload':{'demo':True}},provider='uat_gateway')
check('verified webhook is accepted',s==200 and b.get('success') is True and b.get('financialMutation') is False and b.get('nextAction')=='settle_canonical_payment',{'status':s,'body':str(b)[:250]})
intent_row=db("SELECT status FROM growth_payment_intents WHERE id=?",[intent_id])[0]
event_rows=db("SELECT status,signature_valid,amount FROM growth_payment_events WHERE intent_id=?",[intent_id])
check('intent moved to provider_paid with a verified event',intent_row['status']=='provider_paid' and len(event_rows)==1 and int(event_rows[0]['signature_valid'])==1,{'intent':intent_row,'events':event_rows})
check('verified webhook posted no money',int(db("SELECT COUNT(*) n FROM transactions WHERE bookingId=?",[booking_id])[0]['n'])==transactions_before)

# ---------------------------------------------------------------- replay is idempotent
s,b=webhook({'intentId':intent_id,'providerEventId':event_id,'status':'paid','amount':500000,'payload':{'demo':True}},provider='uat_gateway')
check('replayed provider event is a duplicate, not a second movement',s==200 and b.get('duplicate') is True and len(db("SELECT id FROM growth_payment_events WHERE intent_id=?",[intent_id]))==1,{'status':s,'body':str(b)[:200]})
s,b=webhook({'intentId':intent_id,'providerEventId':'evt_'+run+'_two','status':'paid','amount':500000},provider='uat_gateway')
check('a second distinct paid event cannot move a settled intent',s>=400 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'intentId':intent_id,'providerEventId':'evt_'+run+'_refund','status':'refunded','amount':500000},provider='uat_gateway')
check('a settled intent can be refunded by a verified event',s==200 and db("SELECT status FROM growth_payment_intents WHERE id=?",[intent_id])[0]['status']=='refunded',{'status':s,'body':str(b)[:200]})

s,b=request('growth-suite','POST',{'command':'payment-event-register','intentId':intent_id,'status':'paid','amount':500000,'providerEventId':'evt_'+run+'_manual','payload':{'manual':True}},'pc_manualreg_'+run)
check('manual payment event registration records unverified without posting money',s==200 and b.get('success') is True and b.get('financialMutation') is False and b.get('signatureVerification')=='not_implemented',{'status':s,'body':str(b)[:220]})
check('manual registration amount is auditable as an event row',len(db("SELECT id FROM growth_payment_events WHERE intent_id=? AND provider_event_id=?",[intent_id,'evt_'+run+'_manual']))==1)

# ---------------------------------------------------------------- inactive provider fails closed
s,b=request('growth-suite','POST',{'command':'payment-provider-save','code':'uat_gateway','name':'UAT Gateway','webhookSecret':SECRET,'active':0},'pc_disable_'+run)
check('provider can be deactivated by admin',s==200 and b.get('success') is True,{'status':s})
s,b=webhook({'intentId':intent_id,'providerEventId':'evt_'+run+'_after_off','status':'paid','amount':500000},provider='uat_gateway')
check('an inactive provider is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# cleanup: release the booking and the registry row
request('growth-suite','POST',{'command':'payment-provider-save','code':'uat_gateway','name':'UAT Gateway','webhookSecret':SECRET,'active':0},'pc_off_'+run)
db("DELETE FROM growth_payment_providers WHERE code='uat_gateway'")
request('bookings-status','POST',{'id':booking_id,'status':'cancelled'},'pc_release_'+run)

print('PAYMENT-CONNECTOR-UAT',sum(1 for x in results if x['pass']),'/',len(results))
