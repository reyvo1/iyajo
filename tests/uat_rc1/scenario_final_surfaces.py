from client import *
from scenario_core import db
import datetime, json

# The final untested surface: multi-property manifest, CRM and growth search,
# report previews, support inbox, proof upload, session refresh and two-factor.
#
# Most of these return documents rather than moving money, so the risk is not
# corruption but an unhandled path or a document that answers with something the
# caller cannot use. Each is held to that floor: it answers, or it refuses with a
# status, and never a 500.
#
# The two that carry real weight are the loyalty award, which writes to a guest
# ledger, and refresh-session, which mints credentials.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
run='last_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/final-surfaces-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def answers_or_refuses(st,body,allowed=(200,400,401,403,404,409,422)):
    return st in allowed and not (st==200 and isinstance(body,dict) and body.get('success') is False)

broken=[]
def probe(name,action,method='GET',data=None,op=None):
    st,body=request(action,method,data if data is not None else {},op or ('last_'+name+'_'+run))
    ok=answers_or_refuses(st,body)
    if not ok:
        broken.append({'surface':name,'status':st,'error':str((body or {}).get('error'))[:100] if isinstance(body,dict) else None})
    return st,body,ok

# ------------------------------------------------------------------ multi-property
for cmd in ('manifest','summary-preview','requeue-snapshot'):
    st,body,ok=probe(cmd,'multi-property-foundation','GET',{'command':cmd})
    check('multi-property %s answers or refuses cleanly'%cmd,ok,{'status':st})

# ------------------------------------------------------------------ growth and CRM search
for cmd in ('booking-search','expense-search'):
    st,body,ok=probe(cmd,'growth-suite','GET',{'command':cmd,'q':'UAT'})
    check('%s answers for a query'%cmd,ok,{'status':st,'shape':type(body).__name__})

st,body,ok=probe('crm-segment-preview','enterprise-suite','POST',
                 {'command':'crm-segment-preview','segmentId':'seg_does_not_exist'})
check('a segment preview for an unknown segment is refused',ok and st>=400,
      {'status':st,'error':body.get('error') if isinstance(body,dict) else None})

# --------------------------------------------------------------------- OTA receivables
st,body,ok=probe('ota-receivables','ota-receivables','GET')
check('OTA receivables answers for an administrator',ok,{'status':st})

# ------------------------------------------------------------------- loyalty award
# This one writes to a guest ledger, so an unknown guest must be refused rather than
# creating an award against nothing.
st,body,ok=probe('loyalty-award-booking','enterprise-suite','POST',
                 {'command':'loyalty-award-booking','guestProfileId':'gp_does_not_exist','points':100})
check('a loyalty award against an unknown guest is refused',ok and st>=400,
      {'status':st,'error':body.get('error') if isinstance(body,dict) else None})

# ------------------------------------------------------------------------ proof upload
proof_st,proof_body,ok=probe('transaction-proof','transaction-proof','GET',
                             {'transactionId':'tx_does_not_exist'})
check('a proof lookup for an unknown transaction is refused',ok and proof_st>=400,
      {'status':proof_st,'error':proof_body.get('error') if isinstance(proof_body,dict) else None})

st,body,ok=probe('finance-ledger-page','finance-ledger-page','GET',{'limit':5})
check('the finance ledger page answers for an authorised reader',ok,{'status':st})

# -------------------------------------------------------------- public website admin
for cmd in ('website-cms-media-upload','website-cms-promotion-save'):
    st,body,ok=probe(cmd,'website-cms','POST',{'command':cmd})
    check('%s answers or refuses cleanly'%cmd,ok,{'status':st})

# -------------------------------------------------------------------- support inbox
for cmd in ('public-support-reply','public-support-close'):
    st,body,ok=probe(cmd,cmd,'POST',{'id':'sup_does_not_exist'})
    check('%s refuses an unknown support item'%cmd,ok and st>=400,{'status':st})

# ------------------------------------------------------------------------- telegram admin
for cmd in ('gemini-test','telegram-set-webhook','telegram-webhook-info'):
    method='POST'
    # gemini-test is a POST probe; a GET there is a 405 by design.
    st,body,ok=probe(cmd,cmd,method)
    check('%s answers or refuses cleanly'%cmd,ok,{'status':st})

st,body,ok=probe('telegram-webhook','telegram-webhook','POST',{})
check('telegram-webhook refuses an empty request',ok and st>=400,
      {'status':st,'error':body.get('error') if isinstance(body,dict) else None})

# telegram-clear is idempotent by design: clearing an already clear state succeeds.
st,body,ok=probe('telegram-clear','telegram-clear','POST',{})
check('telegram-clear is idempotent on an already clear state',ok,{'status':st})

# ------------------------------------------------------------------------ session and 2FA
# These mint credentials, so both must refuse a bad one rather than issuing anything.
st,body,ok=probe('refresh-session','refresh-session','POST',{'refreshToken':'not-a-real-token'})
check('refresh-session refuses an unknown token',ok and st>=400,
      {'status':st,'error':body.get('error') if isinstance(body,dict) else None})

st,body,ok=probe('verify-2fa','verify-2fa','POST',{'username':'nobody','code':'000000'})
check('verify-2fa refuses an unknown account and code',ok and st>=400,
      {'status':st,'error':body.get('error') if isinstance(body,dict) else None})

# ------------------------------------------------------------------ consistency guard
st,body,ok=probe('consistency-guard-maintenance','consistency-guard-maintenance','POST',{})
check('consistency guard maintenance answers or refuses cleanly',ok,{'status':st})

# Nothing above may have returned a 500.
check('no untested surface returned a server error',not broken,{'broken':broken})

print('FINAL-SURFACES-UAT',sum(1 for x in results if x['pass']),'/',len(results))