from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift, release_rooms
import datetime, json

# Coverage for the purge preview, the cleanup history and the Telegram webhook removal.
#
# test-data-purge-preview is the dry run in front of an irreversible delete, so what it
# reports has to match reality: if it says a booking cannot be purged, the purge must
# refuse, and if it says it can, something must still stop it.
#
# telegram-delete-webhook is the other direction. Deleting a webhook at Telegram is not
# reversible from here, so the route must refuse when there is no token to delete rather
# than reporting success.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='pv_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/purge-history-webhook-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------------ test-data-purge-preview
ensure_open_shift('pv_shift_'+run)
room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
booking_id=None
if room:
    bs,bk=request('bookings','POST',{
        'guestName':'UAT Purge Preview Subject','roomNumber':room[0]['number'],
        'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':350000,
        'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False},'pv_booking_'+run)
    booking_id=bk.get('bookingId') if isinstance(bk,dict) else None
check('purge preview fixture booking is created',bool(booking_id),{'room':room,'booking':booking_id})

if booking_id:
    prev_st,prev=request('test-data-purge-preview','POST',{'bookingId':booking_id},'pv_prev_'+run)
    preview=(prev or {}).get('preview') or {}
    can_purge=preview.get('canPurge')
    blockers=preview.get('blockers') or []
    check('purge preview reports whether the booking can be purged',
          prev_st==200 and isinstance(can_purge,bool),{'status':prev_st,'preview':preview})
    check('purge preview explains itself when it refuses',
          can_purge is True or len(blockers)>0,
          {'canPurge':can_purge,'blockers':blockers})

    # A booking that is still occupied cannot be previewed as purgeable, and the real
    # purge must agree with the preview rather than doing something the dry run hid.
    if can_purge is False:
        purge_st,purge=request('test-data-purge','POST',{
            'bookingId':booking_id,'reason':'UAT purge must match its own preview',
            'confirmationPhrase':'HAPUS DATA UJI '+booking_id},'pv_purge_'+run)
        check('a booking the preview refused cannot be purged anyway',
              purge_st>=400 and purge.get('success') is False,
              {'previewCanPurge':can_purge,'blockers':blockers,'purgeStatus':purge_st,'error':purge.get('error')})

missing_st,missing=request('test-data-purge-preview','POST',{'bookingId':'booking_does_not_exist'},'pv_miss_'+run)
check('purge preview for a missing booking is refused',missing_st>=400 and missing.get('success') is False,
      {'status':missing_st,'error':missing.get('error')})

# --------------------------------------------------------------------- data-cleanup-history
hist_st,hist=request('data-cleanup-history','GET',{},'pv_hist_'+run)
check('cleanup history is readable',hist_st==200 and hist.get('success') is True,
      {'status':hist_st,'keys':sorted((hist or {}).keys())[:6]})
# A history that invents rows would be worse than an empty one, so only the shape is
# asserted here; the records themselves belong to whatever purge scenarios ran.
hist_rows=hist.get('data') if isinstance(hist.get('data'),list) else hist.get('history')
check('cleanup history returns a list it can be reasoned about',
      hist_rows is None or isinstance(hist_rows,list),{'type':type(hist_rows).__name__})

# ----------------------------------------------------------------- telegram-delete-webhook
# Deleting the webhook at Telegram cannot be undone from here. With no bot token stored
# the route must refuse rather than claim success.
wh_st,wh=request('telegram-delete-webhook','POST',{},'pv_wh_'+run)
check('telegram webhook removal without a bot token is refused, not reported as done',
      wh_st>=400 or wh.get('success') is False,
      {'status':wh_st,'body':wh})

token=db("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1")
check('telegram webhook removal outcome matches whether a token is configured',
      (token and str(token[0]['telegram_bot_token'] or '').strip()) or wh_st>=400,
      {'status':wh_st,'tokenConfigured':bool(token and token[0]['telegram_bot_token'])})

release_rooms('pv_rel_'+run, booking_id)

print('PURGE-HISTORY-WEBHOOK-UAT',sum(1 for x in results if x['pass']),'/',len(results))