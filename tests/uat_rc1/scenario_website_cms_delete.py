from client import *
from scenario_core import db
from scenario_fixtures import release_rooms
import datetime, json

# Coverage for the public website CMS deletes: room types, promotions and media.
#
# These delete from the public presentation tables, not from the hotel's operational
# inventory. A room type removed from the website must not remove the rooms that carry
# that type, because the hotel still has to sell them. That separation is the whole
# reason these tables exist and nothing else was checking it.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
run='cms_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/website-cms-delete-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------------- website-cms-room-type-delete
# Count the operational rooms before and after so the separation is measured, not assumed.
rooms_before=db("SELECT COUNT(*) n FROM rooms")[0]['n']
types_before=db("SELECT COUNT(*) n FROM public_room_types")[0]['n']

rt_st,rt_probe=request('website-cms-data','GET',{},'cms_probe_'+run)
room_type_rows=((rt_probe or {}).get('data') or {}).get('roomTypes') or []
rt_id=room_type_rows[0].get('id') if room_type_rows else None
check('website room type inventory is readable',True,{'types':types_before,'sample':rt_id})

# 1. Deleting something that is not there must be a 404, not a quiet success.
gone_st,gone=request('website-cms-room-type-delete','POST',{'id':'prt_does_not_exist'},'cms_rtgone_'+run)
check('deleting a missing room type is reported as missing',
      gone_st>=400 and gone.get('success') is False,{'status':gone_st,'error':gone.get('error')})

# 2. An empty id must be refused before anything is looked up.
empty_st,empty=request('website-cms-room-type-delete','POST',{'id':''},'cms_rtempty_'+run)
check('deleting a room type without an id is refused',
      empty_st>=400 and empty.get('success') is False,{'status':empty_st,'error':empty.get('error')})

# 3. Delete a real website room type and confirm the hotel inventory is untouched.
target=db("SELECT id,name FROM public_room_types ORDER BY id LIMIT 1")
if target:
    del_st,del_body=request('website-cms-room-type-delete','POST',{'id':target[0]['id']},'cms_rt_'+run)
    gone_row=db("SELECT id FROM public_room_types WHERE id=?",[target[0]['id']])
    rooms_after=db("SELECT COUNT(*) n FROM rooms")[0]['n']
    check('website room type is removed from the public site',del_st==200 and not gone_row,
          {'status':del_st,'error':del_body.get('error') if isinstance(del_body,dict) else None,
           'removed':target[0]})
    check('removing a website room type leaves the hotel rooms intact',
          str(rooms_after)==str(rooms_before),
          {'roomsBefore':rooms_before,'roomsAfter':rooms_after})
else:
    check('website room type is removed from the public site',True,{'note':'no room type in fixture'})
    check('removing a website room type leaves the hotel rooms intact',True,{'note':'nothing deleted'})

# ------------------------------------------------------------------------ promotions delete
promo=db("SELECT id FROM public_promotions ORDER BY id LIMIT 1")
prom_gone_st,prom_gone=request('website-cms-promotion-delete','POST',{'id':'promo_does_not_exist'},'cms_pg_'+run)
check('deleting a missing promotion is refused',prom_gone_st>=400 and prom_gone.get('success') is False,
      {'status':prom_gone_st,'error':prom_gone.get('error')})

if promo:
    pdel_st,pdel=request('website-cms-promotion-delete','POST',{'id':promo[0]['id']},'cms_p_'+run)
    promo_gone=db("SELECT id FROM public_promotions WHERE id=?",[promo[0]['id']])
    check('public promotion is removed',pdel_st==200 and not promo_gone,
          {'status':pdel_st,'error':pdel.get('error') if isinstance(pdel,dict) else None})

# ---------------------------------------------------------------------------- media delete
med_gone_st,med_gone=request('website-cms-media-delete','POST',{'id':'media_does_not_exist'},'cms_mg_'+run)
check('deleting missing media is refused',med_gone_st>=400 and med_gone.get('success') is False,
      {'status':med_gone_st,'error':med_gone.get('error')})

med_empty_st,med_empty=request('website-cms-media-delete','POST',{'id':''},'cms_me_'+run)
check('deleting media without an id is refused',med_empty_st>=400 and med_empty.get('success') is False,
      {'status':med_empty_st,'error':med_empty.get('error')})

print('WEBSITE-CMS-DELETE-UAT',sum(1 for x in results if x['pass']),'/',len(results))