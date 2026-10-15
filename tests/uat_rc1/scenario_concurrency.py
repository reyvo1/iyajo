from client import *
from scenario_core import db,state
from scenario_fixtures import ensure_open_shift
import concurrent.futures,datetime
# The overlap race needs exactly one precondition the suite does not otherwise
# guarantee at this point: an open cash shift. Without it both racers are
# rejected for the same environmental reason and the test measures nothing.
ensure_open_shift('rc1_concurrency_shift')
results=[]
def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail}); print('PASS' if ok else 'FAIL',name,detail or '',flush=True)
    (base/'logs/concurrency-results.json').write_text(json.dumps(results,indent=2),encoding='utf8')

run='rc1conc_'+datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).strftime('%H%M%S')+str(int(datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).microsecond/1000))
future=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()+datetime.timedelta(days=30)
# Room 103 is hardcoded history, but earlier suites legitimately hold it for
# overlapping dates (e.g. a converted public reservation). Racing on an
# occupied room measures nothing, so take a room that is actually free for the
# target window instead of assuming one.
# Overlap uses the same rule as the booking route: an existing stay blocks the
# window when it ends after the window starts and starts before the window ends.
free_rooms=db("SELECT r.number FROM rooms r WHERE r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active') AND b.checkOut>? AND b.checkIn<?) ORDER BY CAST(r.number AS UNSIGNED),r.number LIMIT 1",[future.isoformat(),(future+datetime.timedelta(days=1)).isoformat()])
if not free_rooms:
    raise RuntimeError('No free room for the concurrency overlap window')
payload={'guestName':'RC1 Concurrent Guest '+run,'roomNumber':free_rooms[0]['number'],'checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=1)),'totalAmount':220000,'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False}
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    jobs=[pool.submit(request,'bookings','POST',payload,'rc1_concurrent_booking_'+run+'_'+str(i),38184+i) for i in range(2)]
    replies=[j.result() for j in jobs]
check('Concurrent overlap has exactly one winner',sorted(r[0] for r in replies)==[200,409],
      {'statuses':[r[0] for r in replies],'bodies':[str(r[1])[:300] for r in replies],
       'openShifts':db("SELECT id,shift_time,staff_id,status FROM shift_sessions WHERE status='open'"),
       'room103':db("SELECT id,status,checkIn,checkOut FROM bookings WHERE roomNumber='103' AND status IN ('reserved','active')")})
check('Concurrent overlap writes one booking',db("SELECT COUNT(*) n FROM bookings WHERE guestName=?",['RC1 Concurrent Guest '+run])[0]['n']==1)
for row in db("SELECT id FROM bookings WHERE guestName=? AND status IN ('reserved','active')",['RC1 Concurrent Guest '+run]):
    request('bookings-status','POST',{'id':row['id'],'status':'cancelled'},'rc1_concurrent_release_'+run)
cart={'paymentMethod':'cash','items':[{'productId':state['product_2'],'quantity':1}]}
before=db('SELECT COUNT(*) n FROM pos_sales')[0]['n']
operation='rc1_concurrent_same_sale_'+run
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    jobs=[pool.submit(request,'pos-sale-create','POST',cart,operation,38184+i) for i in range(2)]
    replies=[j.result() for j in jobs]
check('Concurrent same operation succeeds/replays without duplicate money',all(r[0] in (200,202,409) for r in replies),[r[0] for r in replies])
status,body=request('pos-sale-create','POST',cart,operation)
check('Same operation becomes a successful deterministic replay',status==200 and body.get('success') is True,{'status':status,'error':body.get('error')})
check('Concurrent operation writes one sale',db('SELECT COUNT(*) n FROM pos_sales')[0]['n']==before+1)
print('CONCURRENCY',sum(x['pass'] for x in results),'/',len(results))
