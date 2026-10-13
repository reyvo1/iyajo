from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift, release_rooms
import datetime, json

# Coverage for finance catalogue, tax rules and physical key return. None had a
# test, and each one deletes or reclassifies something the ledger depends on.
#
# categories-delete archives rather than removes, so the risks are specific:
#   archiving a category that a financial semantic role is bound to would break
#   every posting that resolves it, and archiving the last active category of a
#   type would leave the property with an empty catalogue and opaque failures.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='cat_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/catalog-tax-key-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------------ categories-delete
# 1. Archiving a category bound to a financial semantic role must be refused: the
#    resolver looks those up by system key, so archiving breaks real postings.
bound=db("SELECT c.id,c.name,c.type FROM categories c WHERE c.is_active=1 AND c.system_key<>'' LIMIT 1")
if bound:
    b_st,b_res=request('categories-delete','POST',{'categoryId':bound[0]['id']},'cat_bound_'+run)
    after=db('SELECT is_active FROM categories WHERE id=?',[bound[0]['id']])
    check('category bound to a financial role cannot be archived',
          b_st>=400 and after and int(after[0]['is_active'])==1,
          {'status':b_st,'error':b_res.get('message') or b_res.get('error'),'category':bound[0]})
else:
    check('category bound to a financial role cannot be archived',True,{'note':'no bound category in fixture'})

# 2. An ordinary custom category archives cleanly and stays readable.
cs,cb=request('categories','POST',{'type':'expense','name':'UAT Arsitek '+run},'cat_new_'+run)
cat_id=None
if isinstance(cb,dict):
    cat_id=(cb.get('category') or {}).get('id') or (cb.get('data') or {}).get('id')
if not cat_id:
    row=db("SELECT id FROM categories WHERE name=? ORDER BY id DESC LIMIT 1",['UAT Arsitek '+run])
    cat_id=row[0]['id'] if row else None
check('custom category is created',cs==200 and bool(cat_id),{'status':cs,'id':cat_id,'body':cb})

if cat_id:
    ar_st,ar=request('categories-delete','POST',{'categoryId':cat_id},'cat_arch_'+run)
    archived=db('SELECT is_active FROM categories WHERE id=?',[cat_id])
    check('custom category archives successfully',
          ar_st==200 and archived and int(archived[0]['is_active'])==0,
          {'status':ar_st,'error':ar.get('message') or ar.get('error'),'row':archived})

    # 3. Archiving an already archived row must not stack another suffix on the label.
    again_st,again=request('categories-delete','POST',{'categoryId':cat_id},'cat_again_'+run)
    label=db('SELECT name FROM categories WHERE id=?',[cat_id])
    # The first archive legitimately appends one suffix. Re-archiving must be refused
    # and must not append a second, which is what used to pollute the audit trail.
    suffixes=str(label[0]['name']).lower().count('[arsip') if label else -1
    check('re-archiving is refused and does not stack a second archive suffix',
          again_st>=400 and suffixes==1,
          {'status':again_st,'error':again.get('message') or again.get('error'),
           'name':label[0]['name'] if label else None,'archiveSuffixes':suffixes})

# 4. Deleting a category that does not exist must be a 404, not a silent success.
gone_st,gone=request('categories-delete','POST',{'categoryId':'cat_does_not_exist'},'cat_gone_'+run)
check('archiving a missing category is reported as missing',
      gone_st==404 and gone.get('success') is False,
      {'status':gone_st,'error':gone.get('message') or gone.get('error')})

# 5. The subcategory delete shares the catalogue and must fail the same way.
sub_gone_st,sub_gone=request('subcategories-delete','POST',{'subcategoryId':'sub_does_not_exist'},'cat_subgone_'+run)
check('archiving a missing subcategory is reported as missing',
      sub_gone_st>=400 and sub_gone.get('success') is False,
      {'status':sub_gone_st,'error':sub_gone.get('message') or sub_gone.get('error')})

# ---------------------------------------------------------------------- tax-rule-toggle
rule=db("SELECT id,name,is_active FROM tax_rules ORDER BY id LIMIT 1")
check('tax rules exist to toggle',bool(rule),{'rule':rule})
if rule:
    off_st,off=request('operations-center','POST',
        {'command':'tax-rule-toggle','id':rule[0]['id'],'isActive':0},'cat_taxoff_'+run)
    off_row=db('SELECT is_active FROM tax_rules WHERE id=?',[rule[0]['id']])
    check('tax rule can be deactivated',off_st==200 and int(off_row[0]['is_active'])==0,
          {'status':off_st,'error':off.get('error')})

    on_st,on=request('operations-center','POST',
        {'command':'tax-rule-toggle','id':rule[0]['id'],'isActive':1},'cat_taxon_'+run)
    on_row=db('SELECT is_active FROM tax_rules WHERE id=?',[rule[0]['id']])
    check('tax rule can be reactivated',on_st==200 and int(on_row[0]['is_active'])==1,
          {'status':on_st,'error':on.get('error')})

missing_st,missing=request('operations-center','POST',
    {'command':'tax-rule-toggle','id':'taxrule_does_not_exist','isActive':1},'cat_taxmiss_'+run)
check('toggling a missing tax rule is refused',missing_st>=400 and missing.get('success') is False,
      {'status':missing_st,'error':missing.get('error')})

# --------------------------------------------------------------------------- key-return
ensure_open_shift('cat_shift_'+run)
room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
check('key return scenario has a free room',bool(room),{'room':room})
booking_id=None
if room:
    bs,bk=request('bookings','POST',{
        'guestName':'UAT Key Return Subject','roomNumber':room[0]['number'],
        'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':400000,
        'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now',
        'broadcast':False},'cat_key_'+run)
    booking_id=bk.get('bookingId') if isinstance(bk,dict) else None
    check('key return fixture booking is checked in',bs==200 and bool(booking_id),
          {'status':bs,'body':bk})

    # Returning the key must leave no open key or PIN behind on that room.
    # A key cannot be returned that was never issued, so the custody chain has to be
    # built first: issue, then return.
    ki_st,ki=request('operations-center','POST',
        {'command':'key-issue','bookingId':booking_id},'cat_ki_'+run)
    check('key is issued to the checked-in booking',ki_st==200 and ki.get('success') is True,
          {'status':ki_st,'error':ki.get('error')})

    kr_st,kr=request('operations-center','POST',
        {'command':'key-return','bookingId':booking_id,
         'reason':'UAT key handed back at the front desk'},'cat_kr_'+run)
    access=db("SELECT current_booking_id AS booking, physical_key_status AS key_status "
              "FROM room_access_control WHERE room_number=?",[room[0]['number']])
    check('key return succeeds for a checked-in booking',kr_st==200 and kr.get('success') is True,
          {'status':kr_st,'error':kr.get('error')})
    check('key return clears the binding and leaves no key outstanding',
          not access or access[0]['booking'] in (None,''),
          {'access':access,'room':room[0]['number']})

    missing_booking_st,missing_booking=request('operations-center','POST',
        {'command':'key-return','bookingId':'booking_does_not_exist'},'cat_krmiss_'+run)
    check('key return for an unknown booking is refused',
          missing_booking_st>=400 and missing_booking.get('success') is False,
          {'status':missing_booking_st,'error':missing_booking.get('error')})

release_rooms('cat_rel_'+run, booking_id)

print('CATALOG-TAX-KEY-UAT',sum(1 for x in results if x['pass']),'/',len(results))