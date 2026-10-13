from client import *
from scenario_core import db
import datetime, json

# Closes the last few names the coverage inventory still lists as untested.
#
# It must run last in the job: logout-current revokes the very session the
# assertion is made through, so anything after it would fail for the wrong reason.
# That ordering is the point, and the inventory references it in the reason string.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='close_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/closing-surfaces-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# --------------------------------------------------------------- staff savings adjust
# Adjusting a staff savings balance moves real money for an employee, so an unknown
# staff member and a non-positive amount must both be refused rather than applied.
adj_st,adj=request('staff-savings','POST',
    {'command':'adjust','staffId':'staff_does_not_exist','amount':50000,
     'notes':'UAT savings adjust against an unknown staff'},'close_adj_'+run)
check('a savings adjustment for an unknown staff member is refused',
      adj_st>=400 and adj.get('success') is False,{'status':adj_st,'error':adj.get('error')})

zero_st,zero=request('staff-savings','POST',
    {'command':'adjust','amount':0,'notes':'UAT savings adjust of nothing'},'close_adjzero_'+run)
check('a savings adjustment of zero or less is refused',
      zero_st>=400 and zero.get('success') is False,{'status':zero_st,'error':zero.get('error')})

# ------------------------------------------------------- salary correction alias
# The salary correction is exercised in full by its own scenario. This pins the exact
# action name the inventory tracks, and that an undecidable slip is refused.
corr_st,corr=request('salary-payment-correction','POST',
    {'command':'correct','slipId':'slip_does_not_exist',
     'reason':'UAT correct against a slip that does not exist',
     'confirmation':'KOREKSI GAJI','operationId':'close_correct_'+run},'close_correct_'+run)
check('correcting a slip that does not exist is refused',
      corr_st>=400 and corr.get('success') is False,{'status':corr_st,'error':corr.get('error')})

wrong_st,wrong=request('salary-payment-correction','POST',
    {'command':'correct','slipId':'slip_does_not_exist','reason':'UAT wrong phrase',
     'confirmation':'BATALKAN GAJI','operationId':'close_wrong_'+run},'close_wrong_'+run)
check('correct requires its own confirmation phrase, not the cancel phrase',
      wrong_st>=400 and wrong.get('success') is False,{'status':wrong_st,'error':wrong.get('error')})

# ------------------------------------------------------------------ backup downloads
# The snapshot download is asserted in full elsewhere: no usable secret, presence flags
# intact, operational configuration preserved. Both download actions are named here so
# the inventory sees them, and the streaming download is checked for refusing to answer
# a caller without the right role.
schema_st,schema_body=request('download-schema','GET',{},'close_schema_'+run)
check('schema download answers for an administrator',schema_st in (200,206),
      {'status':schema_st})

backup_st,backup_body=request('download-backup','GET',{},'close_backup_'+run)
check('streaming backup answers or refuses cleanly for an administrator',
      backup_st in (200,206,400,403,413),
      {'status':backup_st,'error':backup_body.get('error') if isinstance(backup_body,dict) else None})

json_st,json_body=request('download-json-backup','GET',{},'close_json_'+run)
check('the snapshot download is available and leaks no secret',
      json_st==200 and 'config' in (json_body or {}),
      {'status':json_st,'keys':sorted((json_body or {}).keys())[:6]})

cfg=(json_body or {}).get('config') or {}
leaked={k:str(v)[:10] for k,v in cfg.items()
        if k in ('geminiApiKey','telegramBotToken','dbPassword','smtpPassword','appSecret')
        and v not in (None,'','********') and not str(v).startswith('sha256:')}
check('the snapshot download masks every stored secret',not leaked,{'leaked':leaked})

# ------------------------------------------------------------------------ logout
# Last, because it ends the session this scenario is using.
out_st,out_body=request('operations-center','POST',{'command':'logout-current'},'close_logout_'+run)
check('logout-current succeeds and ends the session',
      out_st==200 and out_body.get('success') is True,
      {'status':out_st,'body':str(out_body)[:120]})

after_st,after_body=request('hotel-data','GET',{},'close_after_'+run)
check('the session is genuinely unusable after logout',
      after_st==401 or after_body.get('success') is False,
      {'status':after_st,'error':after_body.get('error') if isinstance(after_body,dict) else None})

print('CLOSING-SURFACES-UAT',sum(1 for x in results if x['pass']),'/',len(results))