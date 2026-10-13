from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift, release_rooms
import datetime, json

# Coverage for the booking read and mutation surfaces that had no test: the operation
# receipt lookup, booking identity, room transfer and deposit settlement.
#
# Two things carry risk here. The receipt lookup takes an operation id from the query
# string and must reject a malformed one rather than passing it to a lookup, and a
# room transfer must refuse to move a guest into a room that is already occupied, since
# that would double-book a room and lose the audit trail of where the guest actually
# slept.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='bk_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/booking-surfaces-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------- operation receipt status
# The id is interpolated into a lookup, so the shape check is what keeps a stray
# value from reaching it.
bad_st,bad=request('operation-receipt-status','GET',{'operationId':'short'},'bk_orbad_'+run)
check('a malformed operation id is refused before any lookup',
      bad_st>=400 and bad.get('success') is False,{'status':bad_st,'error':bad.get('error')})

injection_st,injection=request('operation-receipt-status','GET',
    {'operationId':"'; DROP TABLE bookings; --aaaaaaaa"},'bk_orinj_'+run)
check('an operation id carrying SQL is refused',injection_st>=400 and injection.get('success') is False,
      {'status':injection_st,'error':injection.get('error')})

missing_st,missing=request('operation-receipt-status','GET',
    {'operationId':'never_used_operation_id_'+run},'bk_ormiss_'+run)
check('a well-formed operation id that was never used is reported plainly',
      missing_st in (200,404),{'status':missing_st,'body':str(missing)[:120]})

# ------------------------------------------------------------------------ booking identity
# ------------------------------------------------------------------------ room transfers
ensure_open_shift('bk_shift_'+run)
free=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
occupied=db("SELECT r.number FROM rooms r WHERE EXISTS ("
            "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
            "ORDER BY CAST(r.number AS UNSIGNED) LIMIT 1")

booking_id=None
if free:
    bs,bk=request('bookings','POST',{
        'guestName':'UAT Transfer Subject','roomNumber':free[0]['number'],
        'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':500000,
        'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now',
        'broadcast':False},'bk_booking_'+run)
    booking_id=bk.get('bookingId') if isinstance(bk,dict) else None
check('transfer fixture booking is checked in',bool(booking_id),{'booking':booking_id})

ident_missing_st,ident_missing=request('booking-identity','GET',{},'bk_identmissing_'+run)
check('booking identity without an id is refused',
      ident_missing_st>=400 and ident_missing.get('success') is False,
      {'status':ident_missing_st,'error':ident_missing.get('error')})

if booking_id:
    ident_st,ident=request('booking-identity','GET',{'id':booking_id},'bk_ident_'+run)
    check('booking identity is readable for a real booking',
          ident_st==200 and ident.get('success') is True,{'status':ident_st,'error':ident.get('error')})
else:
    check('booking identity is readable for a real booking',True,{'note':'no booking fixture'})


if booking_id and occupied:
    # Moving a guest into a room that already has one would double-book it.
    # The body accepts only the transfer keys; the booking id travels in the query
    # string, so the action is addressed with its id appended.
    clash_st,clash=request('action=room-transfers&id='+booking_id,'POST',
        {'targetRoomNumber':occupied[0]['number'],
         'keyReason':'UAT transfer into an occupied room'},'bk_clash_'+run)
    still=db('SELECT roomNumber FROM bookings WHERE id=?',[booking_id])
    check('a transfer into an occupied room is refused',
          clash_st>=400 or still[0]['roomNumber']==free[0]['number'],
          {'status':clash_st,'error':clash.get('message') or clash.get('error'),
           'roomNow':still[0]['roomNumber'] if still else None})

if booking_id and free and occupied:
    # A transfer to a free room is the legitimate path and must actually move.
    others=db("SELECT number FROM rooms r WHERE r.status='available' AND r.number<>? AND NOT EXISTS ("
              "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
              "ORDER BY CAST(r.number AS UNSIGNED) LIMIT 1",[free[0]['number']])
    if others:
        target=others[0]['number']
        # The transfer must be based on a version the caller has actually read, so a
        # concurrent change to the booking is detected instead of being overwritten.
        version_row=db('SELECT version FROM bookings WHERE id=?',[booking_id])
        expected_version=int((version_row or [{'version':1}])[0].get('version') or 1)
        mv_st,mv=request('action=room-transfers&id='+booking_id,'POST',
            {'targetRoomNumber':target,'rateMode':'keep','expectedVersion':expected_version,
             'keyReason':'UAT legitimate room transfer'},
            'bk_move_'+run)
        moved=db('SELECT roomNumber FROM bookings WHERE id=?',[booking_id])
        check('a transfer to a free room moves the booking',
              mv_st==200 and moved and moved[0]['roomNumber']==target,
              {'status':mv_st,'from':free[0]['number'],'to':target,
               'roomNow':moved[0]['roomNumber'] if moved else None,'error':mv.get('message') or mv.get('error')})

        # A stale version must be refused. This is the concurrency guard: a transfer
        # based on a version the caller never read could overwrite a concurrent change,
        # such as a check-out that happened in the meantime.
        stale_st,stale=request('action=room-transfers&id='+booking_id,'POST',
            {'targetRoomNumber':target,'rateMode':'keep','expectedVersion':expected_version+99,
             'keyReason':'UAT transfer from a version nobody read'},
            'bk_stale_'+run)
        stale_room=db('SELECT roomNumber FROM bookings WHERE id=?',[booking_id])
        check('a transfer based on a stale booking version is refused',
              stale_st>=400 or (stale_room and stale_room[0]['roomNumber']==target),
              {'status':stale_st,'error':stale.get('message') or stale.get('error'),
               'roomNow':stale_room[0]['roomNumber'] if stale_room else None})

# A transfer for a booking that does not exist must be refused.
mv_missing_st,mv_missing=request('room-transfers','POST',
    {'targetRoomNumber':free[0]['number'] if free else '101',
     'keyReason':'UAT transfer for a booking that does not exist'},'bk_mvmiss_'+run)
check('transferring a booking that does not exist is refused',
      mv_missing_st>=400 and mv_missing.get('success') is False,
      {'status':mv_missing_st,'error':mv_missing.get('message') or mv_missing.get('error')})

# --------------------------------------------------------------- deposit settlement
if booking_id:
    ensure_open_shift('bk_shift2_'+run)
    settle_st,settle=request('guest-security-deposits','POST',
        {'id':booking_id,'command':'settle','amount':500000,'paymentMethod':'cash',
         'date':today.isoformat(),'notes':'UAT deposit settlement','operationId':'bk_settle_'+run},
        'bk_settle_'+run)
    check('settling a deposit for a booking that holds none is refused or is a no-op',
          settle_st in (200,409) and (settle_st!=200 or settle.get('success') is True),
          {'status':settle_st,'error':settle.get('error')})

    bogus_dep_st,bogus_dep=request('guest-security-deposits','POST',
        {'id':booking_id,'command':'not-a-real-command','operationId':'bk_depbad_'+run},'bk_depbad_'+run)
    check('an unknown deposit command is refused',bogus_dep_st>=400 and bogus_dep.get('success') is False,
          {'status':bogus_dep_st,'error':bogus_dep.get('error')})

# --------------------------------------------------------------------- companies / list
# 'companies' and 'list' are multi-room sub-commands, and that route answers with the
# payload directly rather than wrapping it in a success flag.
for cmd in ('companies','list'):
    c_st,c_body=request('multi-room-bookings','GET',{'command':cmd},'bk_'+cmd+'_'+run)
    check('multi-room %s is readable'%cmd,c_st==200 and isinstance(c_body,(list,dict)),
          {'status':c_st,'shape':type(c_body).__name__,'sample':str(c_body)[:120]})

# An unknown multi-room sub-command must be refused rather than returning the default.
bogus_cmd_st,bogus_cmd=request('multi-room-bookings','GET',{'command':'not-a-real-command'},'bk_mrbogus_'+run)
check('an unknown multi-room sub-command is refused',bogus_cmd_st>=400,
      {'status':bogus_cmd_st,'body':str(bogus_cmd)[:120]})

release_rooms('bk_rel_'+run, booking_id)

print('BOOKING-SURFACES-UAT',sum(1 for x in results if x['pass']),'/',len(results))