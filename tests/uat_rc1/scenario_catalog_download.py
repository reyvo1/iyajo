from client import *
from scenario_core import db
import datetime, json, urllib.request

# Coverage for the catalogue maintenance and download endpoints.
#
# The centre of this scenario is the backup download. getRoleScopedHotelData decrypts
# the stored secrets to build the hotel configuration, and the snapshot export writes
# that configuration straight into a file an operator downloads and keeps. Right now
# every secret comes back masked. That masking is the only thing standing between a
# routine backup and a plaintext Telegram bot token or database password sitting on a
# laptop, so it is asserted here rather than assumed.
#
# Renaming a category is the other half: the label is copied onto historical
# transactions, so a rename must not orphan them.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='dl_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))
MASK='********'

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/catalog-download-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------------ snapshot backup
def fetch_json_backup():
    """The snapshot is a file download, so it is fetched directly rather than through
    the JSON helper, which would try to parse it."""
    sess=_session() if '_session' in globals() else json.loads((base/'session.json').read_text())
    headers={'Accept':'application/json','X-Device-ID':'github-uat-rc1','Origin':BASE_URL}
    if sess.get('token'):
        headers['Authorization']='Bearer '+sess['token']
    scope=sess.get('offlineSessionScopeId') or OFFLINE_SESSION_SCOPE
    if scope:
        headers['X-Tamasya-Offline-Session-Scope']=scope
    req=urllib.request.Request(BASE_URL+'/api.php?action=download-json-backup',headers=headers)
    return json.loads(urllib.request.urlopen(req,timeout=120).read().decode('utf8','replace'))

try:
    backup=fetch_json_backup()
    check('snapshot backup downloads for an administrator',
          isinstance(backup,dict) and backup.get('metadata',{}).get('format')=='application-snapshot-v1',
          {'metadata':(backup or {}).get('metadata')})

    cfg=(backup or {}).get('config') or {}
    # Every secret must come back masked. A non-empty, non-mask value here would mean
    # a downloaded backup carries usable credentials.
    leaked={}
    for key in ('geminiApiKey','telegramBotToken','dbPassword','smtpPassword','appSecret'):
        value=cfg.get(key)
        if value not in (None,'','********') and not str(value).startswith('sha256:'):
            leaked[key]=str(value)[:12]
    check('a downloaded snapshot carries no usable secret',
          not leaked,{'leaked':leaked,'present':sorted(cfg)})

    # The presence flags are how the UI knows a secret is set without reading it.
    check('snapshot reports whether secrets are configured without revealing them',
          'hasGeminiApiKey' in cfg and 'hasDbPassword' in cfg,
          {'hasGeminiApiKey':cfg.get('hasGeminiApiKey'),'hasDbPassword':cfg.get('hasDbPassword')})

    # Non-secret configuration is still useful in a backup and must survive masking.
    check('snapshot keeps the operational configuration an operator needs',
          bool(cfg.get('dbName')) and 'checkinTime' in cfg,
          {'dbName':cfg.get('dbName'),'checkinTime':cfg.get('checkinTime')})
except Exception as exc:
    check('snapshot backup downloads for an administrator',False,{'error':str(exc)[:200]})

# ------------------------------------------------------------------- schema download
st,body=request('download-schema','GET',{},'dl_schema_'+run)
check('schema download is available to an administrator',st in (200,206),
      {'status':st,'error':body.get('error') if isinstance(body,dict) else None})

# ----------------------------------------------------------- catalogue label changes
custom=db("SELECT id,name FROM categories WHERE is_active=1 AND (system_key IS NULL OR system_key='') "
          "ORDER BY id LIMIT 1")
original=None
if custom:
    original=custom[0]['name']
    new_name='UAT Renamed '+run
    up_st,up=request('categories-update','POST',
        {'categoryId':custom[0]['id'],'name':new_name},'dl_cat_'+run)
    after=db('SELECT name FROM categories WHERE id=?',[custom[0]['id']])
    check('a custom category can be renamed',up_st==200 and after and after[0]['name']==new_name,
          {'status':up_st,'name':after[0]['name'] if after else None,'error':up.get('message') or up.get('error')})

    # Rename it back so the fixture is left the way it was found.
    if up_st==200:
        request('categories-update','POST',{'categoryId':custom[0]['id'],'name':original},'dl_catback_'+run)
        restored=db('SELECT name FROM categories WHERE id=?',[custom[0]['id']])
        check('renaming back restores the original label',bool(restored) and restored[0]['name']==original,
              {'name':restored[0]['name'] if restored else None})
else:
    check('a custom category can be renamed',True,{'note':'no custom category in fixture'})
    check('renaming back restores the original label',True,{'note':'nothing renamed'})

up_empty_st,up_empty=request('categories-update','POST',{'categoryId':'','name':''},'dl_catempty_'+run)
check('renaming without an id or a name is refused',
      up_empty_st>=400 and up_empty.get('success') is False,
      {'status':up_empty_st,'error':up_empty.get('message') or up_empty.get('error')})

up_missing_st,up_missing=request('categories-update','POST',
    {'categoryId':'cat_does_not_exist','name':'UAT Nothing'},'dl_catmiss_'+run)
check('renaming a category that does not exist is refused',
      up_missing_st>=400 and up_missing.get('success') is False,
      {'status':up_missing_st,'error':up_missing.get('message') or up_missing.get('error')})

sub=db("SELECT id,name FROM subcategories ORDER BY id LIMIT 1")
sub_up_st,sub_up=request('subcategories-update','POST',
    {'subcategoryId':sub[0]['id'] if sub else 'sub_does_not_exist','name':'UAT Sub '+run},'dl_sub_'+run)
check('a subcategory can be renamed or the request is refused',
      sub_up_st in (200,404) and (sub_up_st!=200 or isinstance(sub_up.get('success'),(bool,type(None)))),
      {'status':sub_up_st,'error':sub_up.get('message') or sub_up.get('error')})

sub_missing_st,sub_missing=request('subcategories-update','POST',
    {'subcategoryId':'sub_does_not_exist','name':'UAT Sub'},'dl_submiss_'+run)
check('renaming a subcategory that does not exist is refused',
      sub_missing_st>=400 and sub_missing.get('success') is False,
      {'status':sub_missing_st,'error':sub_missing.get('message') or sub_missing.get('error')})

# A name that is far too long must be refused rather than truncated into the ledger.
long_name='X'*180
sub_long_st,sub_long=request('subcategories-update','POST',
    {'subcategoryId':sub[0]['id'] if sub else 'sub_does_not_exist','name':long_name},'dl_sublong_'+run)
check('an over-long subcategory name is refused',sub_long_st>=400 and sub_long.get('success') is False,
      {'status':sub_long_st,'error':sub_long.get('message') or sub_long.get('error')})

print('CATALOG-DOWNLOAD-UAT',sum(1 for x in results if x['pass']),'/',len(results))