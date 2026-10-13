from client import *
from scenario_core import db
import datetime, json

# Coverage for the Lost & Found custody chain, which had no test.
#
# A lost item passes through states and each one has a legal meaning: something
# found in a room blocks that room, and it must be secured before it can be handed
# over, disposed of, donated or given to the authorities. The chain is where a
# mistake is silent, because an item closed straight from 'found' would look
# resolved while the room is still blocked.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='lf_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/lost-found-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
check('lost and found scenario has a room',bool(room),{'room':room})
if not room:
    print('LOST-FOUND-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)
room_number=room[0]['number']

# 1. An unknown resolution must never be accepted, because each value has a different
#    legal meaning and a typo could end up recorded as a disposal.
bad_st,bad=request('operations-center','POST',
    {'command':'lost-found-close','id':'lf_does_not_exist','resolution':'thrown_away'},'lf_bad_'+run)
check('unknown lost and found resolution is refused',bad_st>=400 and bad.get('success') is False,
      {'status':bad_st,'error':bad.get('error')})

op_st,op=request('operations-center','POST',
    {'command':'lost-found-open','roomNumber':room_number,'itemDescription':'UAT Lost Wallet '+run,
     'operationId':'lf_open_'+run},
    'lf_open_'+run)
item_id=None
if isinstance(op,dict):
    item_id=(op.get('data') or {}).get('lostFound',{}).get('id') or (op.get('data') or {}).get('id') or op.get('id')
if not item_id:
    row=db("SELECT id FROM lost_found_items WHERE item_description=? ORDER BY created_at DESC LIMIT 1",['UAT Lost Wallet '+run])
    item_id=row[0]['id'] if row else None
check('lost and found record is opened',op_st==200 and bool(item_id),{'status':op_st,'id':item_id})

if not item_id:
    print('LOST-FOUND-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)

state=db("SELECT custody_status AS state FROM lost_found_items WHERE id=?",[item_id])
check('a newly found item starts in the room-blocking state',
      state and str(state[0]['state']).lower()=='found',{'state':state})

# 2. Closing straight from 'found' must be refused: the item has to be secured first,
#    otherwise the room stays blocked while the record claims it is resolved.
early_st,early=request('operations-center','POST',
    {'command':'lost-found-close','id':item_id,'resolution':'returned','note':'UAT closing before securing'},
    'lf_early_'+run)
still=db("SELECT custody_status AS state FROM lost_found_items WHERE id=?",[item_id])
check('an item cannot be closed before it is secured',
      early_st>=400 and early.get('success') is False and str(still[0]['state']).lower()=='found',
      {'status':early_st,'error':early.get('error'),'state':still})

# 3. Secure it, then the custody chain becomes closable.
sec_st,sec=request('operations-center','POST',
    {'command':'lost-found-secure','id':item_id,'note':'Front desk safe','custodyTag':'UAT-1'},
    'lf_secure_'+run)
secured=db("SELECT custody_status AS state FROM lost_found_items WHERE id=?",[item_id])
check('lost and found item is secured',sec_st==200 and str(secured[0]['state']).lower()!='found',
      {'status':sec_st,'error':sec.get('error'),'state':secured})

close_st,close=request('operations-center','POST',
    {'command':'lost-found-close','id':item_id,'resolution':'returned',
     'note':'UAT handed back to the guest at the front desk'},'lf_close_'+run)
closed=db("SELECT custody_status AS state FROM lost_found_items WHERE id=?",[item_id])
check('a secured item can be closed as returned',
      close_st==200 and str(closed[0]['state']).lower()=='returned',
      {'status':close_st,'error':close.get('error'),'state':closed})

# 4. A second close is accepted by the route but must not overwrite the first outcome,
#    because the resolution is what an insurer or an auditor relies on. The invariant
#    is the preserved outcome, not the status code the route happens to return.
reclose_st,reclose=request('operations-center','POST',
    {'command':'lost-found-close','id':item_id,'resolution':'disposed',
     'note':'UAT second attempt with a different outcome'},'lf_reclose_'+run)
final_state=db("SELECT custody_status AS state FROM lost_found_items WHERE id=?",[item_id])
check('a closed item keeps its first outcome',
      str(final_state[0]['state']).lower()=='returned',
      {'status':reclose_st,'error':reclose.get('error'),'state':final_state})

print('LOST-FOUND-UAT',sum(1 for x in results if x['pass']),'/',len(results))