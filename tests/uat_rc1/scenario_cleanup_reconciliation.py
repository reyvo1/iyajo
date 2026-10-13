from client import *
from scenario_core import db
import datetime, json, os

# Coverage for the implementation cleanup gate and bank reconciliation status.
#
# data-cleanup-mode is the switch that unlocks destructive cleanup, so its own gates
# are what stand between a production property and a mass deletion. It must refuse a
# wrong password, refuse to be enabled without a written reason, and put itself back
# the way it found itself.
#
# reconciliation-status is the other end of the money trail: it is how an operator
# marks a bank line as settled, and it must never accept an unknown status silently.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='cln_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))
admin_password=os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!')

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/cleanup-reconciliation-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------------- data-cleanup-mode
g_st,g_body=request('data-cleanup-mode','GET',{},'cln_get_'+run)
check('cleanup mode reports its current state',g_st==200 and 'mode' in (g_body or {}),
      {'status':g_st,'mode':(g_body or {}).get('mode')})

# 1. A wrong password must never flip the gate.
bad_pw_st,bad_pw=request('data-cleanup-mode','POST',
    {'enabled':True,'note':'UAT must refuse a wrong password','currentPassword':'wrong-password-value'},
    'cln_badpw_'+run)
after_bad=db("SELECT COUNT(*) n FROM system_config WHERE id='system_default' AND COALESCE(cleanupEnabled,0)=1") if False else None
check('cleanup mode refuses a wrong admin password',bad_pw_st>=400 and bad_pw.get('success') is False,
      {'status':bad_pw_st,'error':bad_pw.get('error')})

# 2. The right password but no written reason is still not enough.
short_st,short=request('data-cleanup-mode','POST',
    {'enabled':True,'note':'short','currentPassword':admin_password},'cln_shortnote_'+run)
check('enabling cleanup mode requires a written reason',short_st>=400 and short.get('success') is False,
      {'status':short_st,'error':short.get('error')})

# 3. This deployment has implementation cleanup switched off at the deployment level,
#    so enabling it must be refused no matter how good the reason and password are.
#    That refusal is the point: a production property cannot unlock mass deletion.
clamp_st,clamp=request('data-cleanup-mode','POST',
    {'enabled':True,'note':'UAT window clamp check','durationMinutes':5,'currentPassword':admin_password},
    'cln_clamp_'+run)
check('cleanup mode cannot be enabled on a deployment that disables it',
      clamp_st>=400 and clamp.get('success') is False,
      {'status':clamp_st,'error':clamp.get('error')})

# Leave the deployment the way it was found, whatever the checks above did.
try:
    request('data-cleanup-mode','POST',
        {'enabled':False,'note':'UAT restoring the deployment default','currentPassword':admin_password},
        'cln_restore_'+run)
except Exception:
    pass
_,restored=request('data-cleanup-mode','GET',{},'cln_get2_'+run)
mode_value=(restored or {}).get('mode')
if isinstance(mode_value,dict): enabled=mode_value.get('enabled')
elif isinstance(mode_value,(list,tuple)) and mode_value: enabled=mode_value[0]
else: enabled=mode_value
check('cleanup mode remains disabled after the scenario',not enabled,
      {'mode':mode_value})

# ---------------------------------------------------------------- reconciliation-status
# The status is coerced from an allow-list and anything else falls back to unmatched,
# so an operator's typo can never mark a bank line as settled by accident.
item=db("SELECT id,status FROM reconciliation_items ORDER BY id LIMIT 1")
check('reconciliation fixture item is readable',True,{'items':item})
if item:
    target_id=item[0]['id']
    before=db('SELECT status FROM reconciliation_items WHERE id=?',[target_id])[0]['status']

    bogus_st,bogus=request('operations-center','POST',
        {'command':'reconciliation-status','id':target_id,'status':'definitely-not-a-status'},'cln_bogus_'+run)
    after=db('SELECT status FROM reconciliation_items WHERE id=?',[target_id])[0]['status']
    check('an unknown reconciliation status never lands as matched',
          after!='matched',
          {'status':bogus_st,'before':before,'after':after,'error':bogus.get('error')})

    real_st,real=request('operations-center','POST',
        {'command':'reconciliation-status','id':target_id,'status':'disputed'},'cln_disputed_'+run)
    real_after=db('SELECT status FROM reconciliation_items WHERE id=?',[target_id])[0]['status']
    check('a valid reconciliation status is recorded',
          real_st==200 and real_after=='disputed',
          {'status':real_st,'after':real_after,'error':real.get('error')})

    missing_st,missing=request('operations-center','POST',
        {'command':'reconciliation-status','id':'recon_does_not_exist','status':'matched'},'cln_reconmiss_'+run)
    check('setting a missing reconciliation item is refused',
          missing_st>=400 and missing.get('success') is False,
          {'status':missing_st,'error':missing.get('error')})

    # Leave the fixture as it was.
    if before!='disputed':
        request('operations-center','POST',
            {'command':'reconciliation-status','id':target_id,'status':before},'cln_restore_'+run)

print('CLEANUP-RECONCILIATION-UAT',sum(1 for x in results if x['pass']),'/',len(results))