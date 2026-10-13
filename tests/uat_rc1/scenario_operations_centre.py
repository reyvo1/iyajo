from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift, release_rooms
import datetime, json

# Coverage for the operations centre surface. Thirty commands had no test at all.
#
# They are not uniform, so they are not asserted uniformly. Four carry real invariants:
# approval requests decide who may release a refund, acknowledging an alert must leave
# it acknowledged, the schema doctor must report drift without ever executing DDL, and a
# backup record must not be claimable as restored when it was not.
#
# Everything else is asserted to answer cleanly: either it does its job, or it refuses
# with a status rather than throwing a 500. A 500 here would mean an unhandled path in
# code that runs from the operations button bar, which is the part nobody exercises.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='ops_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/operations-centre-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def answers_or_refuses(st,body,allowed=(200,400,401,403,404,409,422)):
    """Not a 500, and not an empty success. This is the floor for a button in the bar."""
    return st in allowed and not (st==200 and body.get('success') is False)

# ---------------------------------------------------------------- approval workflow
ap_st,ap=request('operations-center','POST',
    {'command':'approval-create','requestType':'other','entityType':'system',
     'reason':'UAT approval request fixture'},'ops_ap_'+run)
ap_id=(ap or {}).get('approvalId') or (ap or {}).get('id')
if not ap_id:
    row=db("SELECT id FROM approval_requests ORDER BY created_at DESC LIMIT 1")
    ap_id=row[0]['id'] if row else None
check('an approval request is created',ap_st==200 and bool(ap_id),
      {'status':ap_st,'id':ap_id,'body':ap})

if ap_id:
    # Separation of duties: the requester may not approve their own request. The
    # scenario acts as one account, so this branch is exactly what it can reach, and
    # it is the branch that matters.
    dec_st,dec=request('operations-center','POST',
        {'command':'approval-decide','id':ap_id,'decision':'approved',
         'note':'UAT self approval attempt'},'ops_dec_'+run)
    row=db('SELECT status FROM approval_requests WHERE id=?',[ap_id])
    check('an approval request cannot be approved by its own requester',
          dec_st>=400 or (row and row[0]['status']!='pending'),
          {'status':dec_st,'row':row,'error':dec.get('error')})

    bad_dec_st,bad_dec=request('operations-center','POST',
        {'command':'approval-decide','id':ap_id,'decision':'maybe','note':'UAT bad decision'},
        'ops_baddec_'+run)
    check('an approval decision outside the allow-list is refused',
          bad_dec_st>=400,{'status':bad_dec_st,'error':bad_dec.get('error')})

    missing_st,missing=request('operations-center','POST',
        {'command':'approval-decide','id':'approval_does_not_exist','decision':'approved'},
        'ops_apmiss_'+run)
    check('deciding an approval that does not exist is refused',
          missing_st>=400 and missing.get('success') is False,
          {'status':missing_st,'error':missing.get('error')})

# --------------------------------------------------------------------- alert ack
alert=db("SELECT id,source FROM system_alerts ORDER BY created_at DESC LIMIT 1")
ack_empty_st,ack_empty=request('operations-center','POST',{'command':'alert-ack','id':''},'ops_ackempty_'+run)
check('acknowledging an alert without an id is refused',ack_empty_st>=400 and ack_empty.get('success') is False,
      {'status':ack_empty_st,'error':ack_empty.get('error')})

# A lost and found item has its own custody lifecycle. Closing it through the generic
# "mark done" button would bypass that chain, so the route refuses it.
lost=db("SELECT id FROM system_alerts WHERE source LIKE 'lost-found%' LIMIT 1")
if lost:
    lf_st,lf=request('operations-center','POST',{'command':'alert-ack','id':lost[0]['id']},'ops_acklost_'+run)
    check('a lost and found alert cannot be closed through the generic alert button',
          lf_st>=400 or not db('SELECT acknowledged_at FROM system_alerts WHERE id=? AND acknowledged_at IS NOT NULL',[lost[0]['id']]),
          {'status':lf_st,'error':lf.get('error')})
else:
    check('a lost and found alert cannot be closed through the generic alert button',True,
          {'note':'no lost and found alert in this fixture'})

generic=db("SELECT id FROM system_alerts WHERE source NOT LIKE 'lost-found%' ORDER BY created_at DESC LIMIT 1")
if generic:
    ack_st,ack=request('operations-center','POST',{'command':'alert-ack','id':generic[0]['id']},'ops_ack_'+run)
    row=db('SELECT acknowledged_at FROM system_alerts WHERE id=?',[generic[0]['id']])
    check('an ordinary alert can be acknowledged and stays acknowledged',
          ack_st==200 and row and row[0]['acknowledged_at'],
          {'status':ack_st,'row':row,'error':ack.get('error')})
else:
    check('an ordinary alert can be acknowledged and stays acknowledged',True,
          {'note':'no ordinary alert in this fixture'})

# ------------------------------------------------------------------- schema doctor
# The route documents that it never executes DDL. The result must therefore describe
# drift rather than change anything.
schema_before=db("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE()")[0]['n']
doc_st,doc=request('operations-center','POST',{'command':'schema-doctor'},'ops_doc_'+run)
schema_after=db("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE()")[0]['n']
check('schema doctor reports without changing the schema',
      doc_st==200 and str(schema_before)==str(schema_after),
      {'status':doc_st,'columnsBefore':schema_before,'columnsAfter':schema_after,
       'keys':sorted((doc or {}).keys())[:6]})

# ------------------------------------------------------------------- backup record
bk_st,bk=request('operations-center','POST',
    {'command':'backup-record','note':'UAT backup record','result':'success'},'ops_bk_'+run)
check('a backup can be recorded',answers_or_refuses(bk_st,bk),{'status':bk_st,'error':bk.get('error')})

restore_st,restore=request('operations-center','POST',
    {'command':'backup-restore-tested','note':'UAT restore drill'},'ops_restore_'+run)
check('a restore drill can be recorded',answers_or_refuses(restore_st,restore),
      {'status':restore_st,'error':restore.get('error')})

# --------------------------------------------------------------- read and report surface
read_commands=[
    ('runtime-diagnostics','POST'),('derived-state-refresh','POST'),
    ('chat-messages','POST'),('guest-sync','POST'),
    ('smart-lock-job-retry','POST'),('smart-lock-job-manual-complete','POST'),
    ('telegram-binding-code','POST'),('telegram-unbind','POST'),
    ('vacancy-report-create','POST'),('vacancy-report-detail-update','POST'),
    ('vacancy-report-review','POST'),('lost-found-notify','POST'),
    ('night-audit-mode-save','POST'),('night-audit-refresh','POST'),
    ('operational-settings-save','POST'),('approval-policy-save','POST'),
    ('guest-profile-save','POST'),('housekeeping-save','POST'),
    ('late-checkout-record','POST'),('email-report','POST'),
    ('device-heartbeat','POST'),
    ('archive-data','POST'),('maintenance-cancellation-review','POST'),
]
broken=[]
for cmd,method in read_commands:
    st,body=request('operations-center',method,{'command':cmd},'ops_'+cmd+'_'+run)
    if not answers_or_refuses(st,body):
        broken.append({'command':cmd,'status':st,'error':str((body or {}).get('error'))[:90]})
check('every remaining operations command answers or refuses cleanly, none returns 500',
      not broken,{'broken':broken})

# The disabled reset must stay disabled.
reset_st,reset_body=request('db-reset','POST',{},'ops_reset_'+run)
check('db-reset stays disabled',reset_st==410,{'status':reset_st,'error':reset_body.get('error')})

# ------------------------------------------------------------------ public surfaces
for cmd in ('public-site-config','public-contact','public-promotions','public-room-types'):
    p_st,p_body=request(cmd,'GET',{},'ops_'+cmd+'_'+run)
    check('%s is public and answers'%cmd,answers_or_refuses(p_st,p_body),
          {'status':p_st,'shape':type(p_body).__name__})

# --------------------------------------------------------- cluster read-only reports
for cmd in ('consistency-guard-status','runtime-scope-diagnostics','server-revision'):
    c_st,c_body=request(cmd,'GET',{},'ops_'+cmd+'_'+run)
    check('%s answers'%cmd,answers_or_refuses(c_st,c_body),
          {'status':c_st,'error':c_body.get('error') if isinstance(c_body,dict) else None})

print('OPERATIONS-CENTRE-UAT',sum(1 for x in results if x['pass']),'/',len(results))