from client import *
from scenario_core import db
import datetime, json, os

# Coverage for test-data-purge and data-cleanup-execute: the two endpoints that can
# destroy operational records.
#
# test-data-purge is guarded by admin role, an exact confirmation phrase built from
# the booking id, a minimum reason, a re-entered admin password, and a preview that
# refuses while anything is active, locked, reconciled or awaiting approval. Those
# gates are what stand between an operator and irreversible deletion, so each one is
# exercised individually rather than only checking that a happy path works.
#
# Note on scope: the endpoint is named for test data but classifies nothing by that
# name. Its blockers are all about state. This scenario asserts the gates that exist
# and does not pretend the endpoint enforces a data classification it does not.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='purge_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))
admin_password=os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!')

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/destructive-purge-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)

# Earlier scenarios in the same run leave active bookings behind, so pick a room with
# no stay at all rather than any available room.
room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
if not room:
    check('purge scenario has a room',False,{'reason':'no available room'})
    print('DESTRUCTIVE-PURGE-UAT 0/1'); raise SystemExit(0)
room_number=room[0]['number']

# Each scenario in this job leaves a booking active and rooms do not come back, so a
# date range is passed explicitly and spaced apart instead of reusing the same nights.
def make_booking(tag,check_in=None,check_out=None):
    st,body=request('bookings','POST',{
        'guestName':'UAT Purge Fixture '+tag,'roomNumber':room_number,
        'checkIn':(check_in or today).isoformat(),'checkOut':(check_out or tomorrow).isoformat(),
        'totalAmount':250000,
        'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False},tag)
    return st,(body or {}).get('bookingId'),body

# ---------------------------------------------------------------- test-data-purge
st,booking_id,body=make_booking('p_'+run)
check('purge fixture booking is created',st==200 and bool(booking_id),{'status':st,'body':body})

if booking_id:
    # 1. A short reason must be refused outright.
    short_st,short=request('test-data-purge','POST',{
        'bookingId':booking_id,'reason':'short','confirmationPhrase':'HAPUS DATA UJI '+booking_id,
        'currentPassword':admin_password},'p_short_'+run)
    check('purge requires a real reason',short_st>=400 and short.get('success') is False,
          {'status':short_st,'error':short.get('error')})

    # 2. A wrong confirmation phrase must be refused, even with the right password.
    phrase_st,phrase=request('test-data-purge','POST',{
        'bookingId':booking_id,'reason':'UAT verifying the confirmation phrase gate',
        'confirmationPhrase':'HAPUS DATA UJI','currentPassword':admin_password},'p_phrase_'+run)
    check('purge requires the exact confirmation phrase',phrase_st>=400 and phrase.get('success') is False,
          {'status':phrase_st,'error':phrase.get('error')})

    # 3. A wrong password must be refused.
    pass_st,wrong=request('test-data-purge','POST',{
        'bookingId':booking_id,'reason':'UAT verifying the password gate',
        'confirmationPhrase':'HAPUS DATA UJI '+booking_id,'currentPassword':'definitely-not-the-password'},
        'p_pass_'+run)
    check('purge refuses a wrong admin password',pass_st>=400 and wrong.get('success') is False,
          {'status':pass_st,'error':wrong.get('error')})
    check('booking still exists after every gate refused',
          bool(db('SELECT id FROM bookings WHERE id=?',[booking_id])),{'booking':booking_id})

    # 4. An active reservation is a blocker: purge must refuse rather than delete.
    active_st,active=request('test-data-purge','POST',{
        'bookingId':booking_id,'reason':'UAT purge must refuse an active reservation',
        'confirmationPhrase':'HAPUS DATA UJI '+booking_id,'currentPassword':admin_password},'p_active_'+run)
    check('purge refuses an active reservation',active_st>=400 and active.get('success') is False,
          {'status':active_st,'error':active.get('error'),'blockers':(active.get('preview') or {}).get('blockers')})
    check('active booking survives the refused purge',
          bool(db('SELECT id FROM bookings WHERE id=?',[booking_id])),{'booking':booking_id})

    # 5. A preview must state whether the purge is allowed, before anything is deleted.
    preview_st,preview=request('data-cleanup-preview','POST',{'bookingIds':[booking_id]},'p_prev_'+run)
    pv=preview.get('preview') if isinstance(preview,dict) else None
    check('cleanup preview reports whether purging is possible',
          preview_st==200 and isinstance((pv or {}).get('canPurge'),bool),
          {'status':preview_st,'canPurge':(pv or {}).get('canPurge')})

# ------------------------------------------------------------------- data-cleanup-execute
# Far enough out that no other suite in this job has claimed those nights.
room2=db("SELECT number FROM rooms r WHERE r.status='available' AND r.number<>? AND NOT EXISTS ("
         "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
         "ORDER BY CAST(number AS UNSIGNED) LIMIT 1",[room_number])
if room2:
    room_number=room2[0]['number']
st2,booking2,body2=make_booking('p2_'+run,
                                today+datetime.timedelta(days=90),today+datetime.timedelta(days=91))
check('second purge fixture booking is created',st2==200 and bool(booking2),{'status':st2,'body':body2})

if booking2:
    # 6. Executing a cleanup without the implementation gate must be refused.
    no_gate_st,no_gate=request('data-cleanup-execute','POST',{
        'bookingIds':[booking2],'reasonCategory':'implementation_test_data',
        'reason':'UAT cleanup without the implementation gate'},'p_nogate_'+run)
    check('cleanup execute is refused without a backup confirmation',
          no_gate_st>=400 and no_gate.get('success') is False,
          {'status':no_gate_st,'error':no_gate.get('error')})

    bad_reason_st,bad_reason=request('data-cleanup-execute','POST',{
        'bookingIds':[booking2],'reasonCategory':'not_a_real_category',
        'reason':'UAT cleanup with an invented reason category',
        'backupConfirmed':True},'p_badreason_'+run)
    check('cleanup execute refuses an invented reason category',
          bad_reason_st>=400 and bad_reason.get('success') is False,
          {'status':bad_reason_st,'error':bad_reason.get('error')})

    short_reason_st,short_reason=request('data-cleanup-execute','POST',{
        'bookingIds':[booking2],'reasonCategory':'implementation_test_data',
        'reason':'short','backupConfirmed':True},'p_shortreason_'+run)
    check('cleanup execute requires a reason of real length',
          short_reason_st>=400 and short_reason.get('success') is False,
          {'status':short_reason_st,'error':short_reason.get('error')})
    check('cleanup execute is refused without its gate',
          no_gate_st>=400 and no_gate.get('success') is False,
          {'status':no_gate_st,'error':no_gate.get('error')})
    check('booking survives a refused cleanup execute',
          bool(db('SELECT id FROM bookings WHERE id=?',[booking2])),{'booking':booking2})

# Release the room. Every scenario in this job books one and CI only seeds a handful of
# rooms, so a scenario that holds its booking starves the ones queued behind it. This
# is the fix; adding rooms would only move the failure further down the queue.
try:
    # This scenario books twice; releasing only the first leaked the second and
    # starved whatever ran next.
    for held in (booking_id, booking2):
        if held:
            request('bookings-status','POST',{'id':held,'status':'cancelled'},'rel_'+run)
except Exception:
    pass

print('DESTRUCTIVE-PURGE-UAT',sum(1 for x in results if x['pass']),'/',len(results))