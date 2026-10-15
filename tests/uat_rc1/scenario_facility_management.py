from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift, release_rooms
import datetime, json

# End-to-end coverage for dynamic facility management (R15).
#
# Types and units are data: pool (open/visit), karaoke (room/hour) and a small
# quota room are created through the API, then consumed. Prices come from the
# server, a taken slot and an exhausted quota fail closed, usage posts a folio
# extra through the canonical booking workflow (no money moves here), and only
# an unpaid usage can leave through slot cancel. Operation ids are run-unique
# so a re-run cannot replay an old response.
results=[]
run='fac_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))
today=datetime.date.today()

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:400],flush=True)
    (base/'logs/facility-management-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def fac(command,method='GET',payload=None,op=''):
    op = op or ('fac_'+command.replace('-','_')+'_'+run)
    if method == 'GET':
        return request('facilities','GET',dict({'command':command},**(payload or {})),op)
    return request('facilities','POST',dict({'command':command},**(payload or {})),op)

ensure_open_shift('fac_shift_'+run)

# ---------------------------------------------------------------- master data is data
s,b=fac('facility-type-save','POST',{'code':'pool_'+run,'name':'UAT Pool','capacityModel':'open','pricingModel':'visit'},'fac_t1_'+run)
check('open/visit type is created',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:160]})
s,b=fac('facility-type-save','POST',{'code':'ktv_'+run,'name':'UAT Karaoke','capacityModel':'room','pricingModel':'hour'},'fac_t2_'+run)
check('room/hour type is created',s==200 and b.get('success') is True,{'status':s})
s,b=fac('facility-type-save','POST',{'code':'studio_'+run,'name':'UAT Studio','capacityModel':'quota','pricingModel':'visit'},'fac_t3_'+run)
check('quota/visit type is created',s==200 and b.get('success') is True,{'status':s})
s,b=fac('facility-type-list','GET',{},'fac_tl_'+run)
types={t['code']:t for t in (b.get('types') or [])} if s==200 else {}
check('type list shows the three new types',all(k in types for k in ('pool_'+run,'ktv_'+run,'studio_'+run)),{'status':s,'count':len(types)})

s,b=fac('facility-save','POST',{'code':'pool_a_'+run,'name':'UAT Pool A','facilityTypeId':types['pool_'+run]['id'],'capacity':0,'basePrice':50000},'fac_u1_'+run)
check('pool unit with visit price is created',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:160]})
s,b=fac('facility-save','POST',{'code':'ktv_1_'+run,'name':'UAT KTV 1','facilityTypeId':types['ktv_'+run]['id'],'capacity':1,'basePrice':150000},'fac_u2_'+run)
check('karaoke room with hourly price is created',s==200 and b.get('success') is True,{'status':s})
s,b=fac('facility-save','POST',{'code':'studio_s_'+run,'name':'UAT Studio S','facilityTypeId':types['studio_'+run]['id'],'capacity':2,'basePrice':25000},'fac_u3_'+run)
check('quota studio with capacity 2 is created',s==200 and b.get('success') is True,{'status':s})
s,b=fac('facility-list','GET',{},'fac_ul_'+run)
units={u['code']:u for u in (b.get('facilities') or [])} if s==200 else {}
check('unit list shows the three new units',all(k in units for k in ('pool_a_'+run,'ktv_1_'+run,'studio_s_'+run)),{'status':s})

# ---------------------------------------------------------------- guest booking fixture
free=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
if not free:
    print('FAIL no free room for the facility scenario'); raise SystemExit(1)
s,bk=request('bookings','POST',{'guestName':'UAT Facility Guest','roomNumber':free[0]['number'],'checkIn':today.isoformat(),'checkOut':(today+datetime.timedelta(days=1)).isoformat(),'totalAmount':300000,'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now','broadcast':False},'fac_book_'+run)
booking_id=bk.get('bookingId') if s==200 else None
check('fixture guest booking is checked in',bool(booking_id),{'status':s})
before_total=float(db('SELECT totalAmount FROM bookings WHERE id=?',[booking_id])[0]['totalAmount']) if booking_id else -1

# ---------------------------------------------------------------- karaoke slot: price from server, conflict closed
s,b=fac('facility-book','POST',{'facilityId':units['ktv_1_'+run]['id'],'bookingId':booking_id,'guestName':'UAT Facility Guest','date':today.isoformat(),'startTime':'19:00','endTime':'21:00','pax':4},'fac_ktv_'+run)
ktv=b.get('data') if isinstance(b,dict) else None
check('karaoke 19:00-21:00 books at the server hourly price (2h x 150rb)',
      s==200 and ktv and abs(float(ktv['total'])-300000)<0.01 and ktv['status']=='reserved',{'status':s,'data':ktv})
s,b=fac('facility-book','POST',{'facilityId':units['ktv_1_'+run]['id'],'guestName':'UAT Overlap','date':today.isoformat(),'startTime':'20:00','endTime':'22:00','pax':2},'fac_overlap_'+run)
check('an overlapping slot on the same room is refused',s==409 and b.get('success') is False,{'status':s,'body':str(b)[:200]})
s,b=fac('facility-book','POST',{'facilityId':units['ktv_1_'+run]['id'],'guestName':'UAT Adjacent','date':today.isoformat(),'startTime':'21:00','endTime':'22:00','pax':2},'fac_adjacent_'+run)
check('an adjacent slot touching the boundary is allowed',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:200]})
adjacent_id=(b.get('data') or {}).get('id') if isinstance(b,dict) else None
if adjacent_id:
    s,_=fac('facility-booking-cancel','POST',{'id':adjacent_id},'fac_adjrel_'+run)
    check('adjacent hold is released again',s==200,{'status':s})

# ---------------------------------------------------------------- folio charge, no money
after=db('SELECT totalAmount,extras FROM bookings WHERE id=?',[booking_id])[0]
extras=json.loads(after.get('extras') or '[]')
facility_extras=[x for x in extras if str(x.get('id','')).startswith('ex_fac_')]
check('karaoke usage posts exactly one folio extra',len(facility_extras)==1,{'extras':len(facility_extras)})
check('folio extra carries the extra tax kind and unpaid status',
      facility_extras and facility_extras[0].get('taxKind')=='extra' and facility_extras[0].get('paymentStatus')=='unpaid',facility_extras[0] if facility_extras else None)
check('booking total grows by the gross charge only',
      abs(float(after['totalAmount'])-before_total-float((facility_extras[0].get('total') if facility_extras else 0)))<0.01,
      {'before':before_total,'after':float(after['totalAmount'])})
check('usage booking posts no transaction row',int(db("SELECT COUNT(*) n FROM transactions WHERE bookingId=?",[booking_id])[0]['n'])==0)

# ---------------------------------------------------------------- pool visit and quota
s,b=fac('facility-book','POST',{'facilityId':units['pool_a_'+run]['id'],'bookingId':booking_id,'guestName':'UAT Facility Guest','date':today.isoformat(),'pax':3},'fac_pool_'+run)
pool=(b.get('data') or {}) if isinstance(b,dict) else {}
check('pool visit charges per head (3 x 50rb)',s==200 and abs(float(pool.get('total',-1))-150000)<0.01,{'status':s,'data':pool})
s,b=fac('facility-book','POST',{'facilityId':units['studio_s_'+run]['id'],'guestName':'UAT S1','date':today.isoformat(),'pax':2},'fac_q1_'+run)
check('quota studio takes the full capacity of 2',s==200,{'status':s,'body':str(b)[:160]})
s,b=fac('facility-book','POST',{'facilityId':units['studio_s_'+run]['id'],'guestName':'UAT S2','date':today.isoformat(),'pax':1},'fac_q2_'+run)
check('an exhausted quota is refused',s==409 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# ---------------------------------------------------------------- lifecycle: activate, complete, cancel rules
ktv_id=(ktv or {}).get('id')
s,b=fac('facility-booking-activate','POST',{'id':ktv_id},'fac_act_'+run)
check('reserved usage activates',s==200 and (b.get('data') or {}).get('status')=='active',{'status':s})
s,b=fac('facility-booking-complete','POST',{'id':ktv_id},'fac_done_'+run)
check('active usage completes',s==200 and (b.get('data') or {}).get('status')=='completed',{'status':s})
pool_id=pool.get('id')
s,b=fac('facility-booking-cancel','POST',{'id':pool_id},'fac_cancel_'+run)
check('unpaid usage cancels and reverses its folio charge',s==200,{'status':s,'body':str(b)[:200]})
restored=float(db('SELECT totalAmount FROM bookings WHERE id=?',[booking_id])[0]['totalAmount'])
karaoke_gross=float(facility_extras[0].get('total')) if facility_extras else 0.0
check('booking total keeps only the karaoke charge after the unpaid pool cancel',
      abs(restored-(before_total+karaoke_gross))<0.01,{'restored':restored,'expected':before_total+karaoke_gross})
s,b=fac('facility-book','POST',{'facilityId':units['pool_a_'+run]['id'],'bookingId':booking_id,'guestName':'UAT Facility Guest','date':today.isoformat(),'pax':1},'fac_paid_'+run)
paid_id=(b.get('data') or {}).get('id')
s,pay=request('booking-payments','POST',{'bookingId':booking_id,'amount':float(db('SELECT balanceDue FROM bookings WHERE id=?',[booking_id])[0]['balanceDue']),'paymentMethod':'cash','operationId':('fac_pay_'+run)[:100]},'fac_payop_'+run)
check('guest settles the folio through the canonical payment flow',s==200,{'status':s})
s,b=fac('facility-booking-cancel','POST',{'id':paid_id},'fac_cancelpaid_'+run)
check('a paid usage cannot leave through slot cancel',s==409 and b.get('success') is False,{'status':s,'body':str(b)[:200]})

# ---------------------------------------------------------------- idempotent replay
s,b=fac('facility-book','POST',{'facilityId':units['pool_a_'+run]['id'],'guestName':'UAT Replay','date':today.isoformat(),'pax':1},'fac_replay_'+run)
first=(b.get('data') or {}).get('id') if isinstance(b,dict) else None
check('replay fixture booking is created',s==200 and bool(first),{'status':s,'body':str(b)[:200]})
# A byte-identical replay returns the original row; a changed payload under the
# same operation id is rejected by the global idempotency guard (409), which is
# the system-wide contract covered by the core suites.
# The global operation guard replays the stored create-response, so the replayed
# body carries no duplicate flag of its own; what matters is the same row comes
# back and no second row is written. (The route-level duplicate branch stays as
# defense in depth for direct/internal calls that bypass the guard.)
s2,b2=fac('facility-book','POST',{'facilityId':units['pool_a_'+run]['id'],'guestName':'UAT Replay','date':today.isoformat(),'pax':1},'fac_replay_'+run)
check('replayed operation id returns the original booking',s2==200 and (b2.get('data') or {}).get('id')==first and len(db("SELECT id FROM facility_bookings WHERE operation_id=?",['fac_replay_'+run]))==1,{'status':s2,'body':str(b2)[:200]})

# cleanup: release the fixture booking
if booking_id:
    release_rooms('fac_rel_'+run,booking_id)

print('FACILITY-UAT',sum(1 for x in results if x['pass']),'/',len(results))
