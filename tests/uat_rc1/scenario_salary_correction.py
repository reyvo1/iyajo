from client import *
from scenario_core import db
import datetime, json

# The property timezone, not the runner's. A run near UTC midnight made 'today'
# land on a past date for the server and booking creation was rejected.
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today_hotel=lambda: datetime.datetime.now(HOTEL_TZ).date()

# Coverage for salary-payment-correction. Payroll money has no test at all, and
# the route makes a strong promise in its own comment: the original paid slip and
# payment are never deleted or overwritten; a reversal is created instead. That
# promise is exactly what an auditor would check first, so it is asserted here
# rather than assumed.
results=[]
run='sal_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/salary-correction-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def journal_ok(tx_id):
    rows=db('SELECT je.id FROM journal_entries je WHERE je.transaction_id=? LIMIT 1',[tx_id])
    if not rows: return (False,'no journal entry')
    leg=db('SELECT COALESCE(SUM(debit),0) d,COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?',[rows[0]['id']])[0]
    return (abs(float(leg['d'])-float(leg['c']))<0.005,'debit=%s credit=%s'%(float(leg['d']),float(leg['c'])))

staff=db("SELECT id FROM staff WHERE status='active' ORDER BY id LIMIT 1")
if not staff:
    check('salary correction has an active staff member',False,{'reason':'no active staff'})
    print('SALARY-CORRECTION-UAT 0/1'); raise SystemExit(0)
staff_id=staff[0]['id']

def make_slip(period,basic,tag,status='draft'):
    # The create response returns only the scoped hotel projection, not the slip id,
    # so the row is read back by its unique notes tag. A paid slip must also carry a
    # valid payment method, which the route rejects with 422 otherwise.
    payload={'staffId':staff_id,'period':period,'basicSalary':basic,'bonus':0,
             'notes':tag,'status':status}
    if status=='paid':
        bank=db("SELECT id FROM bank_accounts WHERE isActive=1 AND type IN ('bank','qris') "
                "AND LOWER(id) NOT IN ('cash','ota_receivable') ORDER BY type,id LIMIT 1")
        # Paid by transfer, not cash: a cash payroll leg is refused unless the open
        # shift actually holds that much, which is a control worth keeping but would
        # make this scenario depend on the drawer's balance.
        payload['paymentMethod']='transfer'
        if bank: payload['bankAccountId']=bank[0]['id']
    st,body=request('salary-slips','POST',payload,tag)
    row=db('SELECT id,status FROM salary_slips WHERE notes=? ORDER BY created_at DESC LIMIT 1',[tag])
    return st,(row[0]['id'] if row else None),body

period=datetime.date.today().strftime('%Y-%m')
net=3500000.0

# 1. A draft slip must not be correctable: only a paid slip has money to reverse.
d_st,d_slip_id,_=make_slip(period,net,'sal_draft_'+run)
draft_st,draft=request('salary-payment-correction','POST',{
    'command':'cancel','slipId':d_slip_id,'reason':'UAT attempting to cancel an unpaid slip',
    'confirmation':'BATALKAN GAJI','operationId':'sal_d_'+run},'sal_d_'+run)
check('unpaid salary slip cannot be cancelled',draft_st>=400 and draft.get('success') is False,
      {'status':draft_st,'error':draft.get('error')})

# 2. Pay the slip, then cancel it.
p_st,slip_id,pay_body=make_slip(period,net,'sal_paid_'+run,status='paid')
check('paid salary slip is created',p_st==200 and bool(slip_id),{'status':p_st,'body':pay_body})

# The payment leg is what must never be deleted, so capture it before cancelling.
legs=db("SELECT id,amount,bankAccountId FROM transactions WHERE type='expense' AND transactionKind='salary_payment' "
        "ORDER BY createdAt DESC LIMIT 1")
original_leg=legs[0] if legs else None
check('paid salary slip produced a payment leg',bool(original_leg),{'leg':original_leg})

# 3. The confirmation phrase is a real safety interlock.
bad_st,bad=request('salary-payment-correction','POST',{
    'command':'cancel','slipId':slip_id,'reason':'UAT wrong confirmation phrase test',
    'confirmation':'YA','operationId':'sal_bad_'+run},'sal_bad_'+run)
check('cancel without the exact confirmation phrase is refused',
      bad_st>=400 and bad.get('success') is False,{'status':bad_st,'error':bad.get('error')})

cancel_st,cancel=request('salary-payment-correction','POST',{
    'command':'cancel','slipId':slip_id,'reason':'UAT slip paid to the wrong staff member',
    'confirmation':'BATALKAN GAJI','operationId':'sal_c_'+run},'sal_c_'+run)
check('paid salary slip is cancelled with confirmation',
      cancel_st==200 and cancel.get('success') is True,{'status':cancel_st,'body':cancel})

# 4. The original payment must survive; the route promises never to delete it.
still=db('SELECT id,amount,reconciliationStatus FROM transactions WHERE id=?',[original_leg['id']]) if original_leg else []
check('original salary payment leg is never deleted',
      bool(still),{'original':original_leg,'after':still})
slip_after=db('SELECT status FROM salary_slips WHERE id=?',[slip_id]) if slip_id else []
check('cancelled slip is marked cancelled and kept',
      bool(slip_after) and slip_after[0]['status']=='cancelled',{'slip':slip_after})

# 5. A reversal must exist and its journal must balance.
rev=db("SELECT id,amount FROM transactions WHERE transactionKind='salary_reversal' "
       "ORDER BY createdAt DESC LIMIT 1")
check('salary reversal is posted',bool(rev),{'reversal':rev})
if rev:
    ok,detail=journal_ok(rev[0]['id'])
    check('salary reversal journal entry is balanced',ok,{'entry':rev[0]['id'],'detail':detail})

# 6. Payroll money must net to zero: the expense and its reversal cancel.
net_total=db("SELECT COALESCE(SUM(CASE WHEN type='expense' THEN -amount ELSE amount END),0) n "
             "FROM transactions WHERE transactionKind IN ('salary_payment','salary_reversal') "
             "AND sourceEntityId=?",[slip_id])
check('cancelling a paid slip nets its payroll money to zero',
      abs(float(net_total[0]['n']))<0.01,{'net':float(net_total[0]['n']),'slipId':slip_id})

# 7. Cancelling the same slip twice must not post a second reversal.
again_st,again=request('salary-payment-correction','POST',{
    'command':'cancel','slipId':slip_id,'reason':'UAT repeated cancel of the same slip',
    'confirmation':'BATALKAN GAJI','operationId':'sal_c2_'+run},'sal_c2_'+run)
rev2=db("SELECT COUNT(*) n FROM transactions WHERE transactionKind='salary_reversal' AND sourceEntityId=?",[slip_id])
check('repeated cancel does not post a second reversal',
      int(rev2[0]['n'])<=1,{'againStatus':again_st,'reversals':rev2})

# Release the room. Every scenario in this job books one and CI only seeds a handful of
# rooms, so a scenario that holds its booking starves the ones queued behind it. This
# is the fix; adding rooms would only move the failure further down the queue.
try:
    if booking_id:
        request('bookings-status','POST',{'id':booking_id,'status':'cancelled'},'rel_'+run)
except Exception:
    pass

print('SALARY-CORRECTION-UAT',sum(1 for x in results if x['pass']),'/',len(results))