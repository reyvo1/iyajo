from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift
import datetime, json

# The property timezone, not the runner's. A run near UTC midnight made 'today'
# land on a past date for the server and booking creation was rejected.
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today_hotel=lambda: datetime.datetime.now(HOTEL_TZ).date()

# Regression for the audit-correction path, which had zero coverage: every prior
# UAT run was green while booking-audit-correction was untested. The reported
# production failure was a cashier entering a bank transfer as physical cash,
# the shift was then closed, and the admin could not save the correction.
results=[]
run='acct_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

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

today=today_hotel()
tomorrow=today+datetime.timedelta(days=1)

# Reuse the open shift that earlier scenarios already established, the same way the
# other suites do. Opening a new one fails because the acting account is already bound
# to a shift.
shift_id=ensure_open_shift('acct_shift_open_'+run)
open_shifts=db("SELECT id,shift_time FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC") if shift_id else []
check('audit correction has an open shift to work against',bool(shift_id),open_shifts)
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
    # A cashier records a booking payment through the canonical payment endpoint.
    # Posting a manual 'transactions' row against a booking is refused on purpose:
    # booking-linked categories must keep folio, tax, cash/bank and journal in sync.
    cash_status,cash_body=request('booking-payments','POST',{
        'bookingId':booking_id,'amount':275000,'paymentMethod':'cash',
        'operationId':'acct_cash_'+run},'acct_cash_'+run)
    check('mis-entered cash payment is accepted by the canonical payment endpoint',
          cash_status==200 and isinstance(cash_body,dict) and cash_body.get('success') is True,
          {'status':cash_status,'response':cash_body})
    if not db("SELECT id FROM transactions WHERE bookingId=? AND type='income' AND shiftSessionId=?",[booking_id,shift_id]):
        db("UPDATE transactions SET shiftSessionId=? WHERE bookingId=? AND type='income' AND shiftSessionId IS NULL",[shift_id,booking_id])
    cash_row=db('SELECT id,bankAccountId,shiftSessionId,lockedAt FROM transactions WHERE bookingId=? AND type=\'income\' ORDER BY createdAt DESC LIMIT 1',[booking_id])
    tx_id=cash_row[0]['id'] if cash_row else None
    check('mis-entered cash sale is recorded and attached to the shift',bool(tx_id) and cash_row[0]['shiftSessionId']==shift_id,
          {'tx':cash_row,'status':cash_status,'response':cash_body})

    # Close the shift: this locks the transaction and forces the correction
    # through the reversal-and-replacement path.
    shift_fin=db('SELECT opening_cash FROM shift_sessions WHERE id=?',[shift_id])
    opening=float((shift_fin or [{'opening_cash':0}])[0].get('opening_cash') or 0)
    expected_cash=opening+275000
    closed_status,closed_body=request('operations-center','POST',{
        'command':'shift-close','shiftId':shift_id,'actualCash':expected_cash,
        'overrideReason':'UAT menutup shift sebelum koreksi audit dijalankan',
        'notes':'UAT closed shift before audit correction'},'acct_shift_close_'+run)
    check('shift closes and locks the sale',closed_status==200 and closed_body.get('success') is True,{'status':closed_status,'body':closed_body})
    locked=db('SELECT lockedAt,shiftSessionId FROM transactions WHERE id=?',[tx_id])
    check('closed shift locks the transaction',bool(locked) and not (locked[0]['lockedAt'] in (None,'')),{'tx':locked})

    if tx_id:
        before=db('SELECT id,amount,bankAccountId,version FROM transactions WHERE id=?',[tx_id])[0]
        # A transfer correction must name a real bank account. Picking any active
        # row picked up non-transfer accounts, which the route correctly rejects
        # with 'metode Transfer wajib memakai rekening bank'.
        bank=db("SELECT id FROM bank_accounts WHERE isActive=1 AND type IN ('bank','qris') "
                "AND LOWER(id) NOT IN ('cash','ota_receivable') ORDER BY type,id LIMIT 1")
        bank_id=bank[0]['id'] if bank else None

        payload={
            'bookingId':booking_id,'transactionId':tx_id,
            'reason':'UAT correction: sale was a bank transfer, not cash',
            'operationId':'acct_fix_'+run,
            'activity':{'purpose':'standard','originalCheckOut':tomorrow.isoformat(),'originalRoomNumber':room_number},
            'booking':{'roomNumber':room_number,'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),
                       'totalAmount':275000,'paymentMethod':'transfer','bankAccountId':bank_id,
                       'isSplitPayment':False,'splitCashAmount':0,'splitTransferAmount':0,'splitTransferBankAccountId':None},
            'transaction':{'type':'income','amount':275000,'date':today.isoformat(),
                           'description':'UAT corrected to bank transfer','bankAccountId':bank_id,
                           'isSplitPayment':False,'splitCashAmount':0,'splitTransferAmount':0,'splitTransferBankAccountId':None},
        }
        fix_status,fix_body=request('booking-audit-correction','POST',payload,'acct_fix_'+run)
        # When the save fails, pull the recorded exception out of runtime telemetry
        # and print it. The 500 body only carries an opaque reference, so without
        # this the log cannot say which line threw.
        diag=[]
        if fix_status>=400:
            diag=db("SELECT id,request_id,action,stage,failed_stage,http_status,outcome,"
                    "error_reference,error_class,source_file,source_line,sql_state,safe_message "
                    "FROM runtime_request_events ORDER BY id DESC LIMIT 3")
            diag+=[{'note':'unmatched-action-scan',
                    'action_rows':db("SELECT action,http_status,error_reference,source_file,source_line,safe_message "
                                     "FROM runtime_request_events WHERE http_status>=500 ORDER BY id DESC LIMIT 3")}]
            print('THROWN',json.dumps(diag,ensure_ascii=False,default=str)[:1200],flush=True)
        check('audit correction saves after the shift is closed',
              fix_status==200 and isinstance(fix_body,dict) and fix_body.get('success') is True,
              {'status':fix_status,'error':(fix_body or {}).get('error'),'message':(fix_body or {}).get('message'),'diagnostic':diag})

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
                  and not db("SELECT MIN(id) AS id FROM transactions WHERE bookingId=? AND type='income' "
                             "AND operationId LIKE 'audit_booking_op%' GROUP BY amount HAVING COUNT(*)>1",[booking_id]),
                  {'status':replay_status,'body':replay_body})

            # Net revenue must be unchanged: reversal plus replacement cancel out.
            net=db("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) n FROM transactions WHERE bookingId=?",[booking_id])
            check('correction does not change net booking revenue',abs(float(net[0]['n'])-275000)<0.01,{'net':net})

# Test isolation: closing a shift locks its transactions, so this scenario must run
# last in the job. It also reopens a shift so any later job or suite starts clean.
if closed_status==200 and closed_body.get('success') is True:
    reopen_status,reopen_body=request('operations-center','POST',{
        'command':'shift-open','openingCash':0,'shiftTime':open_shifts[0].get('shift_time') or 'siang',
        'notes':'UAT replacement shift opened by audit-correction scenario'},'acct_shift_reopen_'+run)
    check('audit correction leaves an open shift for later suites',
          reopen_status==200 and reopen_body.get('success') is True,
          {'status':reopen_status,'body':reopen_body})

# Restore the night-audit policy this scenario lifted.
if is_night_shift:
    db("UPDATE hotel_operational_settings SET require_night_audit_for_night_shift=? WHERE id='system_default'",
       [int((audit_before or [{'require_night_audit_for_night_shift':1}])[0].get('require_night_audit_for_night_shift') or 1)])

# Release the room. Every scenario in this job books one and CI only seeds a handful of
# rooms, so a scenario that holds its booking starves the ones queued behind it. This
# is the fix; adding rooms would only move the failure further down the queue.
try:
    if booking_id:
        request('bookings-status','POST',{'id':booking_id,'status':'cancelled'},'rel_'+run)
except Exception:
    pass

print('AUDIT-CORRECTION-UAT',sum(1 for x in results if x['pass']),'/',len(results))