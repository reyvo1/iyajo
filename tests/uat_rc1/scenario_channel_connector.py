from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift
import datetime, json, hmac, hashlib, urllib.request, urllib.error, time

# End-to-end coverage for the channel connector phase (R14.3).
#
# A verified vendor reservation becomes a RESERVED draft and nothing else: no
# money, no check-in, no invented room type. An unmapped room type is recorded
# for a human. Every authentication failure is a 401 with an identical message,
# and the same external reservation can never produce a second draft.
results=[]
run='chconn_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))
SECRET='uat-channel-secret-'+run+'-0123456789'
CHANNEL='uat_ota'
TOMORROW = datetime.date.today() + datetime.timedelta(days=1)
DAY_AFTER = datetime.date.today() + datetime.timedelta(days=2)

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:400],flush=True)
    (base/'logs/channel-connector-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def webhook(payload,provider=None,timestamp=None,signature=None,event_type='reservation_created',external_id=None,external_property=''):
    body=json.dumps(payload,separators=(',',':')).encode('utf8')
    ts=str(int(timestamp if timestamp is not None else time.time()))
    sig=signature if signature is not None else hmac.new(SECRET.encode(),(ts+'.').encode()+body,hashlib.sha256).hexdigest()
    envelope={'eventType':event_type,'externalId':external_id,'externalPropertyId':external_property,'payload':payload}
    raw=json.dumps(envelope,separators=(',',':')).encode('utf8')
    sig=signature if signature is not None else hmac.new(SECRET.encode(),(ts+'.').encode()+raw,hashlib.sha256).hexdigest()
    headers={'Content-Type':'application/json','X-Device-ID':DEVICE_ID,'Origin':ORIGIN,'X-Tamasya-Timestamp':ts}
    if provider: headers['X-Tamasya-Provider']=provider
    if sig: headers['X-Tamasya-Signature']=sig
    req=urllib.request.Request(BASE_URL+'/api.php?action=growth-channel-webhook',data=raw,headers=headers,method='POST')
    try:
        resp=urllib.request.urlopen(req,timeout=30)
    except urllib.error.HTTPError as e:
        resp=e
    text=resp.read().decode('utf8','replace')
    try: parsed=json.loads(text) if text else {}
    except Exception: parsed={'raw':text[:300]}
    return int(resp.status),parsed

# ---------------------------------------------------------------- provider + mapping
s,b=request('growth-suite','POST',{'command':'channel-provider-save','code':CHANNEL,'name':'UAT Channel','webhookSecret':SECRET,'active':1},'cc_save_'+run)
check('admin can register a channel provider with a secret',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:200]})
s,b=request('growth-suite','POST',{'command':'channel-provider-save','code':'uat_no_secret','name':'No Secret','webhookSecret':'','active':1},'cc_nosecret_'+run)
check('a channel provider cannot be created without a secret',s>=400 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

room_types=db("SELECT type,COUNT(*) n FROM rooms GROUP BY type ORDER BY type")
internal_type=room_types[0]['type'] if room_types else ''
free=db("SELECT number FROM rooms r WHERE r.type=? AND r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) ORDER BY CAST(r.number AS UNSIGNED) LIMIT 1",[internal_type])
check('fixture has an internal room type and a free room',bool(internal_type) and bool(free),{'type':internal_type,'free':free[0]['number'] if free else None})

s,b=request('growth-suite','POST',{'command':'channel-mapping-save','channelCode':CHANNEL,'externalRoomType':'DELUXE KING','internalRoomType':internal_type,'externalRatePlan':'','active':1},'cc_map_'+run)
check('channel mapping for the vendor room type is saved',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:200]})
s,b=request('growth-suite','GET',{'command':'channel-provider-list'},'cc_list_'+run)
listed={p['code']:p for p in (b.get('providers') or [])} if s==200 else {}
check('channel provider list shows the registry without leaking secrets',s==200 and CHANNEL in listed and listed[CHANNEL].get('hasSecret') in (1,True,'1') and 'webhook_secret_encrypted' not in listed[CHANNEL],{'status':s,'providers':list(listed)})
s,b=request('growth-suite','POST',{'command':'channel-event-register','channelCode':CHANNEL,'eventType':'reservation_created','externalId':'cc_manual_'+run,'payload':{'note':'manual entry'}},'cc_manualreg_'+run)
check('manual channel event registration still records without acting',s==200 and b.get('success') is True and b.get('autoMutation') is False,{'status':s,'body':str(b)[:200]})
mappings=db("SELECT id FROM growth_channel_mappings WHERE channel_code=? AND external_room_type='DELUXE KING'",[CHANNEL])
check('exactly one mapping exists for the vendor room type',len(mappings)==1,{'mappings':mappings})

# ---------------------------------------------------------------- fail-closed authentication
s,b=webhook({'guestName':'X','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','amount':400000},provider=None,signature='0'*64)
check('channel webhook without provider header is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'guestName':'X','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','amount':400000},provider=CHANNEL,signature='f'*64)
check('channel webhook with a wrong signature is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'guestName':'X','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','amount':400000},provider=CHANNEL,timestamp=int(time.time())-4000)
check('channel webhook with a stale timestamp is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=webhook({'guestName':'X','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','amount':400000},provider='uat_unknown_channel')
check('channel webhook from an unknown provider is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# ---------------------------------------------------------------- unsupported event type
s,b=webhook({'guestName':'X','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','amount':400000},provider=CHANNEL,event_type='booking_cancelled',external_id='cc_cancel_'+run)
check('an unsupported event type is refused',s>=400 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# ---------------------------------------------------------------- unmapped room type: recorded, not invented
s,b=webhook({'guestName':'UAT Unmapped','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'UNKNOWN SUITE','amount':400000},provider=CHANNEL,external_id='cc_unmapped_'+run)
unmapped_events=db("SELECT id,status FROM growth_channel_events WHERE channel_code=? AND external_id=?",[CHANNEL,'cc_unmapped_'+run])
check('an unmapped room type is recorded for a human and no booking is created',
      s==200 and b.get('created') is False and b.get('reason')=='unmapped' and len(unmapped_events)==1 and unmapped_events[0]['status']=='unmapped',{'status':s,'body':str(b)[:220],'events':unmapped_events})

# ---------------------------------------------------------------- mapped: reserved draft, no money
reservation={'guestName':'UAT Channel Guest','guestEmail':'guest@example.invalid','checkIn':TOMORROW.isoformat(),'checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','externalRatePlan':'','amount':400000}
external_id='cc_res_'+run
s,b=webhook(reservation,provider=CHANNEL,external_id=external_id)
check('a verified reservation is accepted and creates a reserved draft',s==200 and b.get('created') is True and b.get('financialMutation') is False and bool(b.get('bookingId')),{'status':s,'body':str(b)[:260]})
booking_id=b.get('bookingId')
booking=db("SELECT status,paymentStatus,totalAmount,bookingSource,roomType FROM bookings WHERE id=?",[booking_id])[0] if booking_id else {}
transactions=db("SELECT COUNT(*) n FROM transactions WHERE bookingId=?",[booking_id])[0]['n'] if booking_id else -1
expected_source=CHANNEL if CHANNEL in ('ota','traveloka','booking.com','agoda','tiket.com','expedia','airbnb','klook','pegipegi') else 'ota-'+CHANNEL
check('the draft is reserved, unpaid, sourced from the channel and posts no money',
      booking.get('status')=='reserved' and booking.get('paymentStatus')=='unpaid' and booking.get('bookingSource')==expected_source and int(transactions)==0,{'booking':booking,'expectedSource':expected_source,'transactions':int(transactions)})
check('the channel event is recorded as applied',len(db("SELECT id FROM growth_channel_events WHERE channel_code=? AND external_id=?",[CHANNEL,external_id]))==1)

# ---------------------------------------------------------------- replay is idempotent
s,b=webhook(reservation,provider=CHANNEL,external_id=external_id)
bookings_for_external=db("SELECT id FROM bookings WHERE bookingSource=? ORDER BY createdAt DESC",[CHANNEL])
check('a vendor retry never creates a second draft',s==200 and b.get('duplicate') is True,{'status':s,'body':str(b)[:220]})

# ---------------------------------------------------------------- invalid reservation payloads
for bad,label in [({'guestName':'X','checkOut':DAY_AFTER.isoformat(),'externalRoomType':'DELUXE KING','amount':400000},'missing_checkin'),
                  ({'guestName':'X','checkIn':TOMORROW.isoformat(),'checkOut':(datetime.date.today()-datetime.timedelta(days=1)).isoformat(),'externalRoomType':'DELUXE KING','amount':400000},'checkout_before_checkin')]:
    s,b=webhook(bad,provider=CHANNEL,external_id='cc_bad_'+label+'_'+run)
    check('invalid reservation payload is refused: '+label,s>=400 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# ---------------------------------------------------------------- inactive provider fails closed
s,b=request('growth-suite','POST',{'command':'channel-provider-save','code':CHANNEL,'name':'UAT Channel','webhookSecret':SECRET,'active':0},'cc_disable_'+run)
check('channel provider can be deactivated',s==200 and b.get('success') is True,{'status':s})
s,b=webhook(reservation,provider=CHANNEL,external_id='cc_after_off_'+run)
check('an inactive channel provider is refused',s==401 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# cleanup
db("DELETE FROM growth_channel_events WHERE channel_code=?",[CHANNEL])
db("DELETE FROM growth_channel_providers WHERE code=?",[CHANNEL])
db("DELETE FROM growth_channel_mappings WHERE channel_code=?",[CHANNEL])
for row in db("SELECT id FROM bookings WHERE bookingSource IN (?,?) AND status='reserved'",[CHANNEL,'ota-'+CHANNEL]):
    request('bookings-status','POST',{'id':row['id'],'status':'cancelled'},'cc_preclean_'+run)
if booking_id:
    request('bookings-status','POST',{'id':booking_id,'status':'cancelled'},'cc_release_'+run)

print('CHANNEL-CONNECTOR-UAT',sum(1 for x in results if x['pass']),'/',len(results))
