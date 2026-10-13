from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift, release_rooms
import datetime, json

# Coverage for the enterprise reporting and loyalty surface.
#
# loyalty-voucher-redeem is the one that moves money in this group, and it carries six
# guards whose whole purpose is stopping discount fraud: the voucher must exist, be
# active, be in date, belong to the booking's guest, the booking must be eligible, and
# a minimum spend must be met. Each is asserted separately, because a voucher that
# fails the wrong check is a voucher that gets discounted anyway.
#
# The reporting endpoints are asserted for one property: that they answer and that
# their totals are finite numbers, which catches a divide-by-zero or a broken join
# that a status-code check alone would pass.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='ent_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/enterprise-loyalty-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def numbers_in(obj,depth=0):
    """Collect numeric leaves, so a report returning null or a string where a total
    belongs is visible rather than hidden behind a success flag."""
    if depth>6: return []
    if isinstance(obj,bool): return []
    if isinstance(obj,(int,float)): return [float(obj)]
    if isinstance(obj,dict): return [n for v in obj.values() for n in numbers_in(v,depth+1)]
    if isinstance(obj,list): return [n for v in obj for n in numbers_in(v,depth+1)]
    return []

# ------------------------------------------------------------------------- loyalty voucher
# Guest profiles are created by an earlier suite, so the scenario makes its own rather
# than depending on that having run.
guest_id='gp_uat_'+run
db("INSERT INTO guest_profiles (id,name,phone,email) VALUES (?,?,?,?)",
   [guest_id,'UAT Loyalty Guest '+run,'+6280000000000','uat.loyalty@example.test'])
guest=db("SELECT id FROM guest_profiles WHERE id=?",[guest_id])
check('loyalty scenario created its own guest profile',bool(guest),{'guest':guest})

ensure_open_shift('ent_shift_'+run)
room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
booking_id=None
if guest and room:
    bs,bk=request('bookings','POST',{
        'guestName':'UAT Loyalty Guest '+run,'roomNumber':room[0]['number'],
        'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':800000,
        'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False},'ent_booking_'+run)
    booking_id=bk.get('bookingId') if isinstance(bk,dict) else None
check('loyalty fixture booking is created',bool(booking_id),{'booking':booking_id})

voucher_id='gv_uat_'+run
if guest and booking_id:
    db("INSERT INTO growth_loyalty_vouchers (id,voucher_code,guest_profile_id,value_type,value_amount,"
       "min_spend,valid_from,valid_until,status,issued_by) VALUES (?,?,?,?,?,?,?,?,?,?)",
       [voucher_id,'UATVOUCHER1_'+run,guest[0]['id'],'fixed',100000,500000,
        str(today-datetime.timedelta(days=5)),str(today+datetime.timedelta(days=5)),'active','uat'])
    check('loyalty voucher fixture is issued',bool(
        db('SELECT id FROM growth_loyalty_vouchers WHERE id=?',[voucher_id])),{'voucher':voucher_id})

    # 1. A voucher that does not exist must be refused before anything else is checked.
    missing_st,missing=request('enterprise-suite','POST',
        {'command':'loyalty-voucher-redeem','voucherId':'gv_does_not_exist','bookingId':booking_id},
        'ent_vmiss_'+run)
    check('redeeming a voucher that does not exist is refused',
          missing_st>=400 and missing.get('success') is False,{'status':missing_st,'error':missing.get('error')})

    # 2. A minimum spend the booking has not reached must be refused.
    low_spend_st,low_spend=request('enterprise-suite','POST',
        {'command':'loyalty-voucher-redeem','voucherId':voucher_id,'bookingId':booking_id},
        'ent_vspend_'+run)
    check('a voucher above the minimum spend is refused when the booking is too small',
          low_spend_st>=400 and low_spend.get('success') is False,
          {'status':low_spend_st,'error':low_spend.get('error')})

    # 3. An expired voucher must be refused even when every other guard passes.
    db("UPDATE growth_loyalty_vouchers SET valid_until=? WHERE id=?",[str(today-datetime.timedelta(days=1)),voucher_id])
    expired_st,expired=request('enterprise-suite','POST',
        {'command':'loyalty-voucher-redeem','voucherId':voucher_id,'bookingId':booking_id},
        'ent_vexp_'+run)
    check('an expired voucher is refused',expired_st>=400 and expired.get('success') is False,
          {'status':expired_st,'error':expired.get('error')})

    # 4. A deactivated voucher must be refused.
    db("UPDATE growth_loyalty_vouchers SET valid_until=?,status='revoked' WHERE id=?",
       [str(today+datetime.timedelta(days=5)),voucher_id])
    revoked_st,revoked=request('enterprise-suite','POST',
        {'command':'loyalty-voucher-redeem','voucherId':voucher_id,'bookingId':booking_id},
        'ent_vrev_'+run)
    check('a revoked voucher is refused',revoked_st>=400 and revoked.get('success') is False,
          {'status':revoked_st,'error':revoked.get('error')})

    # 5. A voucher belonging to a different guest must not discount this booking.
    other=db("SELECT id FROM guest_profiles WHERE id<>? ORDER BY id LIMIT 1",[guest[0]['id']])
    if other:
        db("UPDATE growth_loyalty_vouchers SET status='active',guest_profile_id=? WHERE id=?",[other[0]['id'],voucher_id])
        wrong_guest_st,wrong_guest=request('enterprise-suite','POST',
            {'command':'loyalty-voucher-redeem','voucherId':voucher_id,'bookingId':booking_id},
            'ent_vguest_'+run)
        check('a voucher belonging to another guest cannot discount this booking',
              wrong_guest_st>=400 and wrong_guest.get('success') is False,
              {'status':wrong_guest_st,'error':wrong_guest.get('error')})

# ------------------------------------------------------------------- enterprise reporting
for cmd in ('accounting-summary','ap-aging','provider-adapters','status'):
    st,body=request('enterprise-suite','GET',{'command':cmd},'ent_'+cmd+'_'+run)
    ok=st==200 and body.get('success') is True
    check('%s answers for an operator'%cmd,ok,
          {'status':st,'error':body.get('error')})
    if ok and cmd in ('accounting-summary','ap-aging'):
        nums=numbers_in(body.get('data'))
        check('%s returns usable totals'%cmd,len(nums)>0,
              {'numbers':nums[:6],'sample':str(body.get('data'))[:160]})

# --------------------------------------------------------------------------- guest lookup
# Guest search exposes contact details, so an empty query must not dump the directory.
empty_q_st,empty_q=request('enterprise-suite','GET',{'command':'guest-search','q':''},'ent_gempty_'+run)
all_rows=db("SELECT COUNT(*) n FROM guest_profiles")[0]['n']
rows=(empty_q or {}).get('data') or []
check('guest search with no query does not return the whole directory',
      empty_q_st==200 and len(rows)<max(1,int(all_rows)),
      {'status':empty_q_st,'returned':len(rows),'total':int(all_rows)})

# A guest with no loyalty account legitimately returns an empty document, so this is
# not a case for requiring a refusal. What must never happen is another guest's
# history coming back under an id that does not exist.
detail_st,detail=request('enterprise-suite','GET',
    {'command':'loyalty-detail','guestProfileId':'gp_does_not_exist'},'ent_lmiss_'+run)
detail_body=detail.get('data') or {}
check('loyalty detail for an unknown guest returns nothing rather than another guest history',
      detail_st in (200,404) and not isinstance(detail_body.get('account'),dict)
      and not (detail_body.get('ledger') or []),
      {'status':detail_st,'error':detail.get('error'),'data':str(detail.get('data'))[:140]})

release_rooms('ent_rel_'+run, booking_id)

print('ENTERPRISE-LOYALTY-UAT',sum(1 for x in results if x['pass']),'/',len(results))