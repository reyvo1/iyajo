from client import *
from scenario_core import db
import datetime, json

# Coverage for guest-security-deposits, which had no test at all despite moving
# real money. The deposit ledger is what a hotel relies on at checkout, so this
# asserts the money actually lands: a balanced journal, a liability that matches
# the ledger, and a replay that cannot double-credit the guest.
results=[]
run='dep_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/security-deposit-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

today=datetime.date.today()
tomorrow=today+datetime.timedelta(days=1)

room=db("SELECT r.number FROM rooms r WHERE r.status='available' AND NOT EXISTS "
        "(SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(r.number AS UNSIGNED) LIMIT 1")
if not room:
    check('security deposit has a bookable room',False,{'reason':'no available room'})
    print('SECURITY-DEPOSIT-UAT 0/1'); raise SystemExit(0)
room_number=room[0]['number']

st,created=request('bookings','POST',{
    'guestName':'UAT Security Deposit Guest','roomNumber':room_number,
    'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':400000,
    'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False},'dep_booking_'+run)
booking_id=created.get('bookingId')
check('security deposit booking is created',st==200 and created.get('success') is True and bool(booking_id),{'status':st,'body':created})

if not booking_id:
    print('SECURITY-DEPOSIT-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)

# 1. Configure the required deposit. Without it, receiving must be refused.
cfg_st,cfg=request('guest-security-deposits','POST',
    {'id':booking_id,'command':'configure','requiredAmount':500000,'notes':'UAT deposit requirement'},
    'dep_cfg_'+run)
check('security deposit requirement is configured',
      cfg_st==200 and cfg.get('success') is True,{'status':cfg_st,'body':cfg})

# 2. Receiving less than required must be refused: a partial deposit would make
#    the guest look covered at checkout when they are not.
short_st,short=request('guest-security-deposits','POST',
    {'id':booking_id,'command':'receive','requiredAmount':500000,'amount':100000,
     'paymentMethod':'cash','date':today.isoformat(),'operationId':'dep_short_'+run},
    'dep_short_'+run)
check('deposit below the required amount is refused',
      short_st>=400 and short.get('success') is False,
      {'status':short_st,'body':short})

# 3. Receive the full deposit.
recv_st,recv=request('guest-security-deposits','POST',
    {'id':booking_id,'command':'receive','requiredAmount':500000,'amount':500000,
     'paymentMethod':'cash','date':today.isoformat(),'notes':'UAT deposit received',
     'operationId':'dep_recv_'+run},'dep_recv_'+run)
check('full security deposit is received',
      recv_st==200 and recv.get('success') is True,{'status':recv_st,'body':recv})

# 4. The journal behind it must balance, or the deposit is recorded on one side only.
tx_id=(recv.get('transactionId') or (recv.get('result') or {}).get('transactionId')) if isinstance(recv,dict) else None
entry=db('SELECT id FROM journal_entries WHERE transaction_id=? LIMIT 1',[tx_id]) if tx_id else []
if entry:
    leg=db('SELECT COALESCE(SUM(debit),0) d,COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?',[entry[0]['id']])[0]
    check('security deposit journal entry is balanced',
          abs(float(leg['d'])-float(leg['c']))<0.005 and float(leg['d'])>0,
          {'entry':entry[0]['id'],'debit':float(leg['d']),'credit':float(leg['c'])})
else:
    check('security deposit journal entry is balanced',False,{'transactionId':tx_id})

# 5. The ledger must reflect exactly what was received, once.
ledger=db('SELECT entry_type AS entryType,COALESCE(SUM(amount),0) amt FROM transactions '
          'WHERE bookingId=? AND transactionKind=\'security_deposit_received\' GROUP BY entry_type',[booking_id])
received=sum(float(r['amt']) for r in ledger)
check('security deposit ledger holds the received amount exactly once',
      abs(received-500000)<0.01,{'ledger':ledger,'expected':500000})

# 6. Replaying the same operation id must not credit the guest twice.
replay_st,replay=request('guest-security-deposits','POST',
    {'id':booking_id,'command':'receive','requiredAmount':500000,'amount':500000,
     'paymentMethod':'cash','date':today.isoformat(),'notes':'UAT deposit received',
     'operationId':'dep_recv_'+run},'dep_recv_'+run)
after=db('SELECT COALESCE(SUM(amount),0) amt FROM transactions '
         'WHERE bookingId=? AND transactionKind=\'security_deposit_received\'',[booking_id])
check('replayed deposit is idempotent',
      replay_st==200 and abs(float(after[0]['amt'])-500000)<0.01,
      {'status':replay_st,'ledgerTotal':float(after[0]['amt'])})

# 7. Refunding the deposit must offset it and leave the guest at zero.
refund_st,refund=request('guest-security-deposits','POST',
    {'id':booking_id,'command':'refund_full','amount':500000,'paymentMethod':'cash',
     'date':today.isoformat(),'notes':'UAT deposit refunded','operationId':'dep_ref_'+run},
    'dep_ref_'+run)
check('full deposit refund succeeds',refund_st==200 and refund.get('success') is True,{'status':refund_st,'body':refund})
net=db('SELECT COALESCE(SUM(CASE WHEN type=\'income\' THEN amount ELSE -amount END),0) n '
       'FROM transactions WHERE bookingId=? AND transactionKind LIKE \'security_deposit%\'',[booking_id])
check('refunded security deposit nets to zero',abs(float(net[0]['n']))<0.01,{'net':float(net[0]['n'])})

print('SECURITY-DEPOSIT-UAT',sum(1 for x in results if x['pass']),'/',len(results))