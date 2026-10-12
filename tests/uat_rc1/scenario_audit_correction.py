from client import *
from scenario_core import db
import datetime, json

# Regression for the audit-correction path, which had zero coverage: every prior
# UAT run was green while booking-audit-correction was untested. The reported
# production failure was a cashier entering a bank transfer as physical cash,
# the shift was then closed, and the admin could not save the correction.
results=[]
run='acct_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/audit-correction-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def journal_balanced(entry_id):
    rows=db('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?',[entry_id])
    if not rows: return (False,0,0)
    r=rows[0]
    return (abs(float(r['d'])-float(r['c']))<0.005,float(r['d']),float(r['c']))

def journal_legs(entry_id):
    return db('SELECT account_code,account_name,debit,credit FROM journal_lines WHERE journal_entry_id=? ORDER BY account_code',[entry_id])

today=datetime.date.today()
tomorrow=today+datetime.timedelta(days=1)

# Reuse the open shift that earlier scenarios already established, the same way the
# other suites do. Opening a new one fails because the acting account is already bound
# to a shift.
open_shifts=db("SELECT id,shift_time FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC")
check('audit correction sees an open shift to work against',len(open_shifts)>=1,open_shifts)
shift_id=open_shifts[0]['id'] if open_shifts else None
is_night_shift=shift_time_is_night=open_shifts and str(open_shifts[0].get('shift_time','')).lower() in ('malam','all')

# A night shift cannot be closed until the night audit is finished. The correction
# scenario needs a closed shift, so lift that one policy flag for the duration and
# restore it afterwards, the same technique the integrity scenario uses.
audit_before=db("SELECT require_night_audit_for_night_shift FROM hotel_operational_settings WHERE id='system_default'")
if shift_id and is_night_shift:
    db("UPDATE hotel_operational_settings SET require_night_audit_for_night_shift=0 WHERE id='system_default'")

room=db("SELECT r.number FROM rooms r WHERE r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) ORDER BY CAST(r.number AS UNSIGNED) LIMIT 1")
if not room:
    check('audit correction has a bookable room',False,{'reason':'no available room'})
    raise SystemExit(0)
room_number=room[0]['number']

created_status,created=request('bookings','POST',{
    'guestName':'UAT Cash Then Transfer Correction','roomNumber':room_number,
    'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':275000,
    'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now','broadcast':False},
    'acct_booking_'+run)
booking_id=created.get('bookingId') if isinstance(created,dict) else None
check('audit correction creates an active booking',created_status==200 and created.get('success') is True and bool(booking_id),{'status':created_status,'body':created})

if booking_id:
    # The cashier records the sale as physical cash even though the guest paid by
    # transfer. This is the exact mis-entry the correction has to repair.
    cash_status,cash_body=request('transactions','POST',{
        'bookingId':booking_id,'type':'income','category':'Sewa Kamar','subcategory':room_number,
        'amount':275000,'date':today.isoformat(),'paymentMethod':'cash',
        'description':'UAT sale entered as cash by mistake','operationId':'acct_cash_'+run},
        'acct_cash_'+run)
    cash_tx=(cash_body or {}).get('transactionId') or (cash_body or {}).get('id')
    cash_row=db('SELECT id,bankAccountId,shiftSessionId,lockedAt FROM transactions WHERE bookingId=? AND type=\'income\' ORDER BY createdAt DESC LIMIT 1',[booking_id])
    tx_id=cash_row[0]['id'] if cash_row else None
    check('mis-entered cash sale is recorded and attached to the shift',bool(tx_id) and cash_row[0]['shiftSessionId']==shift_id,{'tx':cash_row})

    # Close the shift: this locks the transaction and forces the correction
    # through the reversal-and-replacement path.
    closed_status,closed_body=request('operations-center','POST',{
        'command':'shift-close','shiftId':shift_id,'actualCash':275000,
        'notes':'UAT closed shift before audit correction'},'acct_shift_close_'+run)
    check('shift closes and locks the sale',closed_status==200 and closed_body.get('success') is True,{'status':closed_status,'body':closed_body})
    locked=db('SELECT lockedAt,shiftSessionId FROM transactions WHERE id=?',[tx_id])
    check('closed shift locks the transaction',bool(locked) and not (locked[0]['lockedAt'] in (None,'')),{'tx':locked})

    if tx_id:
        before=db('SELECT id,amount,bankAccountId,version FROM transactions WHERE id=?',[tx_id])[0]
        bank=db("SELECT id FROM bank_accounts WHERE isActive=1 AND id<>'cash' AND (LOWER(id)<>'cash') ORDER BY id LIMIT 1")
        bank_id=bank[0]['id'] if bank else None

        payload={
            'bookingId':booking_id,'transactionId':tx_id,
            'reason':'UAT correction: sale was a bank transfer, not cash',
            'operationId':'acct_fix_'+run,
            'activity':{'purpose':'standard','originalCheckOut':tomorrow.isoformat(),'originalRoomNumber':room_number},
            'booking':{'roomNumber':room_number,'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),
                       'totalAmount':275000,'paymentMethod':'transfer','bankAccountId':bank_id,
                       'isSplitPayment':False,'splitCashAmount':0,'splitTransferAmount':0,'splitTransferBankAccountId':None},
            'transaction':{'type':'income','category':'Sewa Kamar','amount':275000,'date':today.isoformat(),
                           'description':'UAT corrected to bank transfer','bankAccountId':bank_id,
                           'isSplitPayment':False,'splitCashAmount':0,'splitTransferAmount':0,'splitTransferBankAccountId':None},
        }
        fix_status,fix_body=request('booking-audit-correction','POST',payload,'acct_fix_'+run)
        check('audit correction saves after the shift is closed',
              fix_status==200 and isinstance(fix_body,dict) and fix_body.get('success') is True,
              {'status':fix_status,'error':(fix_body or {}).get('error'),'message':(fix_body or {}).get('message')})

        if fix_status==200 and (fix_body or {}).get('success') is True:
            # The correction must post a balanced replacement on the right account.
            replacement=db("SELECT id,amount,bankAccountId,transactionKind FROM transactions WHERE bookingId=? AND type='income' AND transactionKind<>'ota_transfer' ORDER BY createdAt DESC LIMIT 1",[booking_id])
            check('replacement transaction is posted on the bank account',
                  bool(replacement) and replacement[0]['bankAccountId']==bank_id and abs(float(replacement[0]['amount'])-275000)<0.01,
                  {'replacement':replacement,'expectedBank':bank_id})
            entry=db('SELECT id FROM journal_entries WHERE transaction_id=? LIMIT 1',[replacement[0]['id']]) if replacement else []
            if entry:
                ok,d,c=journal_balanced(entry[0]['id'])
                check('replacement journal entry is balanced',ok and d>0,{'entry':entry[0]['id'],'debit':d,'credit':c,'legs':journal_legs(entry[0]['id'])})

            # Replaying the identical operation id must not create a second charge.
            replay_status,replay_body=request('booking-audit-correction','POST',payload,'acct_fix_'+run)
            check('replayed correction is idempotent',
                  replay_status==200 and (replay_body or {}).get('success') is True
                  and not db("SELECT id FROM transactions WHERE bookingId=? AND type='income' AND operationId LIKE 'audit_booking_op%' GROUP BY amount HAVING COUNT(*)>1",[booking_id]),
                  {'status':replay_status,'body':replay_body})

            # Net revenue must be unchanged: reversal plus replacement cancel out.
            net=db("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) n FROM transactions WHERE bookingId=?",[booking_id])
            check('correction does not change net booking revenue',abs(float(net[0]['n'])-275000)<0.01,{'net':net})

# Restore the night-audit policy this scenario lifted.
if is_night_shift:
    db("UPDATE hotel_operational_settings SET require_night_audit_for_night_shift=? WHERE id='system_default'",
       [int((audit_before or [{'require_night_audit_for_night_shift':1}])[0].get('require_night_audit_for_night_shift') or 1)])

print('AUDIT-CORRECTION-UAT',sum(1 for x in results if x['pass']),'/',len(results))