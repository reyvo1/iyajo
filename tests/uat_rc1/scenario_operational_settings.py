from client import *
from scenario_core import db
import datetime, json, subprocess

# Coverage for configurable shift boundaries (R14.1). The boundaries tile the day
# (pagi=day..siang, siang=siang..malam, malam=malam..day) and every consumer reads
# the same values: settings save, the web default, the server default and the
# legacy Night Audit classification. Unordered values must fail closed without
# touching the stored row.
#
# The scenario never touches another suite's shifts. Shift open/close runs under
# a dedicated staff account it creates and whose session it restores to admin at
# the end. Operation ids are run-unique so a re-run cannot replay old responses.
results=[]
run='shcfg_'+datetime.datetime.now().strftime('%H%M%S')+str(int(datetime.datetime.now().microsecond/1000))
ADMIN_SESSION=(base/'session.json').read_text(encoding='utf8')

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

OWN_USERNAME='shcfg_'+run
OWN_PASSWORD='UatShiftCfg1!-'+run
OWN_ID='s_shcfg_'+run.replace('_','')[:20]
db("INSERT INTO staff (id,name,username,password,role,status) VALUES (?,?,?,?,?,?)",
   [OWN_ID,'UAT Shift Boundaries',OWN_USERNAME,(subprocess.run(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',OWN_PASSWORD],capture_output=True,text=True,cwd='..').stdout.strip()),'manager','active'])
def as_own():
    s,b=request('login','POST',{'username':OWN_USERNAME,'password':OWN_PASSWORD,'offlineSessionScopeId':OFFLINE_SESSION_SCOPE})
    if s!=200 or b.get('success') is not True or not b.get('token'):
        raise RuntimeError('login staf uji gagal: '+str(b)[:200])
    (base/'session.json').write_text(json.dumps(b,ensure_ascii=False,indent=2),encoding='utf8')
def as_admin():
    (base/'session.json').write_text(ADMIN_SESSION,encoding='utf8')
as_own()
check('dedicated boundary-test staff can log in',True)

def complete_night_audit_and_close(shift_id,expected_cash,tag):
    s_aud,_=request('operations-center','POST',{'command':'night-audit-start','shiftSessionId':shift_id},'shcfg_aud_'+tag+'_'+run)
    if s_aud!=200:
        return False
    audit=db("SELECT id FROM night_audit_runs WHERE shift_session_id=? AND status='open' ORDER BY started_at DESC LIMIT 1",[shift_id])
    if not audit:
        return False
    audit_id=audit[0]['id']
    for room in db("SELECT r.number,EXISTS(SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status='active') AS occ FROM rooms r ORDER BY r.number"):
        occupancy='occupied' if int(room.get('occ') or 0)>0 else 'vacant'
        request('operations-center','POST',{'command':'night-audit-check-room','auditId':audit_id,'roomNumber':room['number'],'physicalOccupancy':occupancy,'observedKeyStatus':'secured','notes':'Night audit fixture batas shift'},'shcfg_chk_'+room['number']+'_'+tag+'_'+run)
    for item in db("SELECT id FROM night_audit_items WHERE audit_id=? AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' AND resolution_status<>'resolved'",[audit_id]):
        request('operations-center','POST',{'command':'night-audit-resolve','id':item['id'],'reason':'Release fixture: discrepancy diselesaikan manajer uji'},'shcfg_res_'+item['id'][-8:]+'_'+tag+'_'+run)
    s_fin,_=request('operations-center','POST',{'command':'night-audit-finalize','auditId':audit_id,'notes':'Night audit selesai untuk uji batas shift'},'shcfg_fin_'+tag+'_'+run)
    if s_fin!=200:
        return False
    s_close,_=request('operations-center','POST',{'command':'shift-close','shiftId':shift_id,'actualCash':float(expected_cash or 0),'overrideReason':'Release shift uji setelah night audit resmi selesai','notes':'release setelah uji batas shift'},'shcfg_rel2_'+tag+'_'+run)
    return s_close==200

as_admin()
# ---------------------------------------------------------------- valid custom boundaries
before=settings_row()
save('shift_custom',{'shiftDayStart':'06:00','shiftSiangStart':'14:00','shiftMalamStart':'22:00'})
after=settings_row()
check('custom boundaries persisted exactly',
      str(after['shift_day_start'])=='06:00:00' and str(after['shift_siang_start'])=='14:00:00' and str(after['shift_malam_start'])=='22:00:00',
      {k:str(after[k]) for k in ('shift_day_start','shift_siang_start','shift_malam_start')})
check('other clock settings untouched by the shift save',
      str(after['checkin_time'])==str(before['checkin_time']) and int(after['late_grace_minutes'])==int(before['late_grace_minutes']),
      {'checkin':str(after['checkin_time']),'grace':after['late_grace_minutes']})

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
as_own()
s,b=request('operations-center','POST',{'command':'shift-open','openingCash':100000,'shiftTime':'siang','notes':'shift boundaries UAT'},'shcfg_open_'+run)
check('explicit siang slot opens under custom boundaries',s==200 and b.get('success') is True,{'status':s,'body':str(b)[:200]})
opened=db("SELECT * FROM shift_sessions WHERE notes='shift boundaries UAT' ORDER BY opened_at DESC LIMIT 1")
check('opened shift stores the chosen slot verbatim',bool(opened) and opened[0]['shift_time']=='siang',opened[0]['shift_time'] if opened else None)
if opened:
    s2,b2=request('operations-center','POST',{'command':'shift-close','shiftId':opened[0]['id'],'actualCash':100000,'overrideReason':'Release shift uji staf uji sebelum selesai','notes':'release shift uji'},'shcfg_close_'+run)
    check('siang shift opened under custom boundaries closes cleanly',s2==200 and b2.get('success') is True,{'status':s2,'message':str(b2)[:200]})

# ---------------------------------------------------------------- malam slot stays gated by Night Audit under custom boundaries
s3,b3=request('operations-center','POST',{'command':'shift-open','openingCash':100000,'shiftTime':'malam','notes':'shift boundaries night UAT'},'shcfg_night_open_'+run)
check('explicit malam slot opens under custom boundaries',s3==200 and b3.get('success') is True,{'status':s3,'body':str(b3)[:200]})
night_shift=db("SELECT * FROM shift_sessions WHERE notes='shift boundaries night UAT' ORDER BY opened_at DESC LIMIT 1")
if night_shift:
    ns=night_shift[0]
    s4,b4=request('operations-center','POST',{'command':'shift-close','shiftId':ns['id'],'actualCash':100000,'overrideReason':'Release shift malam uji','notes':'release'},'shcfg_night_close_'+run)
    msg=str((b4 or {}).get('error') or (b4 or {}).get('message') or '')
    check('night slot refuses to close before Night Audit is completed',
          s4 in (400,409) and 'night audit' in msg.lower(),{'status':s4,'message':msg[:200]})
    check('night slot closes after the official Night Audit completes',
          complete_night_audit_and_close(ns['id'],100000,'night'),{'shift':ns['id']})
as_admin()
save('restore_defaults',{'shiftDayStart':'07:00','shiftSiangStart':'15:00','shiftMalamStart':'23:00'})
save('restore_checkin',{'checkinTime':str(before['checkin_time'])[:5]})
restored=settings_row()
check('historical defaults restored for the remaining job',
      str(restored['shift_day_start'])=='07:00:00' and str(restored['shift_siang_start'])=='15:00:00' and str(restored['shift_malam_start'])=='23:00:00',
      {k:str(restored[k]) for k in ('shift_day_start','shift_siang_start','shift_malam_start')})
db("UPDATE staff SET status='disabled' WHERE id=?",[OWN_ID])

print('OPERATIONAL-SETTINGS-UAT',sum(1 for x in results if x['pass']),'/',len(results))
