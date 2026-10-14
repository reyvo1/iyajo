from client import *
from scenario_core import db
import datetime, json

# Coverage for configurable shift boundaries (R14.1). The boundaries tile the day
# (pagi=day..siang, siang=siang..malam, malam=malam..day) and every consumer reads
# the same values: settings save, the web default, the server default and the
# legacy Night Audit classification. Unordered values must fail closed without
# touching the stored row. Operation ids are run-unique so a re-run cannot replay
# a previous response.
results=[]
run='shcfg_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:400],flush=True)
    (base/'logs/operational-settings-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def settings_row():
    return db("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")[0]

def save(label,payload,expected=200):
    op='shcfg_'+label+'_'+run
    s,b=request('operations-center','POST',dict(payload,command='operational-settings-save'),op)
    check('settings save '+label+' -> '+str(expected),s==expected,{'status':s,'body':b if s!=expected else b.get('message')})
    return s,b

# Lepas setiap shift terbuka dari suite sebelumnya supaya buka-shift uji tidak
# tertahan pada guard satu-shift-terbuka, dan buka-shift malam yang masih menggantung
# juga dilepas lewat override selisih.
stuck=db("SELECT id,expected_cash,shift_time FROM shift_sessions WHERE status='open' ORDER BY opened_at")
released=0
for row in stuck:
    s_rel,b_rel=request('operations-center','POST',{'command':'shift-close','shiftId':row['id'],'actualCash':float(row['expected_cash'] or 0),'overrideReason':'Release shift suite sebelumnya sebelum menguji batas jam shift','notes':'release sebelum uji batas shift'},'shcfg_release_'+row['id'][-8:]+'_'+run)
    if s_rel==200:
        released+=1
check('every open shift from the previous suite is released',released==len(stuck) and int(db("SELECT COUNT(*) n FROM shift_sessions WHERE status='open'")[0]['n'])==0,{'open':stuck,'released':released})

# ---------------------------------------------------------------- valid custom boundaries
save('shift_custom',{'shiftDayStart':'06:00','shiftSiangStart':'14:00','shiftMalamStart':'22:00'})
after=settings_row()
check('custom boundaries persisted exactly',
      str(after['shift_day_start'])=='06:00:00' and str(after['shift_siang_start'])=='14:00:00' and str(after['shift_malam_start'])=='22:00:00',
      {k:str(after[k]) for k in ('shift_day_start','shift_siang_start','shift_malam_start')})

# ---------------------------------------------------------------- unordered rejected, row intact
for bad,label,expected in [({'shiftDayStart':'15:00','shiftSiangStart':'07:00'},'day_after_siang',400),
                           ({'shiftSiangStart':'21:00','shiftMalamStart':'20:00'},'siang_after_malam',400),
                           ({'shiftDayStart':'ab:cd'},'garbage_time',400)]:
    save('reject_'+label,bad,expected=expected)
intact=settings_row()
check('rejected save leaves the stored row unchanged',
      str(intact['shift_day_start'])=='06:00:00' and str(intact['shift_siang_start'])=='14:00:00' and str(intact['shift_malam_start'])=='22:00:00',
      {k:str(intact[k]) for k in ('shift_day_start','shift_siang_start','shift_malam_start')})

# ---------------------------------------------------------------- PATCH semantics: omitted keys preserved
save('patch_only_checkin',{'checkinTime':'15:30'})
patched=settings_row()
check('PATCH-like save preserves shift boundaries and moves only check-in',
      str(patched['shift_day_start'])=='06:00:00' and str(patched['shift_siang_start'])=='14:00:00' and str(patched['shift_malam_start'])=='22:00:00' and str(patched['checkin_time'])=='15:30:00',
      {'day':str(patched['shift_day_start']),'checkin':str(patched['checkin_time'])})

# ---------------------------------------------------------------- shift-open honours explicit slot under custom boundaries
s,b=request('operations-center','POST',{'command':'shift-open','openingCash':100000,'shiftTime':'siang','notes':'shift boundaries UAT'},'shcfg_open_'+run)
check('explicit siang slot opens under custom boundaries',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:200]})
opened=db("SELECT * FROM shift_sessions WHERE notes='shift boundaries UAT' ORDER BY opened_at DESC LIMIT 1")
check('opened shift stores the chosen slot verbatim',bool(opened) and opened[0]['shift_time']=='siang',opened[0]['shift_time'] if opened else None)
if opened:
    s2,b2=request('operations-center','POST',{'command':'shift-close','shiftId':opened[0]['id'],'actualCash':100000},'shcfg_close_'+run)
    check('siang shift opened under custom boundaries closes cleanly',s2==200 and b2.get('success') is True,{'status':s2,'message':str(b2)[:200]})

# ---------------------------------------------------------------- restore historical defaults
save('restore_defaults',{'shiftDayStart':'07:00','shiftSiangStart':'15:00','shiftMalamStart':'23:00'})
restored=settings_row()
check('historical defaults restored for the remaining job',
      str(restored['shift_day_start'])=='07:00:00' and str(restored['shift_siang_start'])=='15:00:00' and str(restored['shift_malam_start'])=='23:00:00',
      {k:str(restored[k]) for k in ('shift_day_start','shift_siang_start','shift_malam_start')})
save('restore_checkin',{'checkinTime':'14:00'})
final=settings_row()
check('check-in time restored to 14:00',str(final['checkin_time'])=='14:00:00',{'checkin':str(final['checkin_time'])})

print('OPERATIONAL-SETTINGS-UAT',sum(1 for x in results if x['pass']),'/',len(results))
