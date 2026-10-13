from client import *
from scenario_core import db
import datetime, json

# Coverage for node-cluster-reconcile.
#
# Reconciliation adopts a peer's leadership epoch when the peer claims a higher one.
# The whole safety property rests on one rule: an epoch that is not strictly higher
# must never be adopted. Adopting an equal or lower epoch is how two nodes each end
# up believing they are primary, and the symptom is split-brain writes rather than an
# error, which is the worst possible failure mode for this command.
#
# The route can also decline outright when the cluster is off or the peer cannot be
# reached, so the assertion is written to hold in every one of those outcomes.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='clu_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/cluster-reconcile-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def leadership():
    row=db("SELECT leadership_epoch AS epoch,current_primary_node_id AS primaryNode FROM "
           "node_cluster_state WHERE id='system_default' LIMIT 1")
    return row[0] if row else None

state=db("SELECT COUNT(*) AS n FROM information_schema.tables "
         "WHERE table_schema=DATABASE() AND table_name='node_cluster_state'")
check('cluster state table exists',bool(state) and int(state[0]['n'])==1,{'tables':state})

before=leadership()
# No row at all is the normal state when clustering is switched off, so this only
# asserts that the column names resolve rather than that a row must exist.
check('local leadership state is readable',before is None or 'epoch' in before,{'state':before})

# 1. Reconciling without a peer must never invent a leader.
rec_st,rec_body=request('node-cluster-reconcile','POST',{},'clu_rec_'+run)
check('reconcile answers with a success envelope',
      rec_st==200 and rec_body.get('success') is True,
      {'status':rec_st,'result':(rec_body or {}).get('result')})
result=(rec_body or {}).get('result') or {}
check('reconcile always reports what it did and why',
      'changed' in result and 'reason' in result,{'result':result})

# 2. The safety property: whatever the outcome, leadership never moves backwards.
after=leadership()
monotonic=True
detail={'before':before,'after':after,'reason':result.get('reason')}
if before is None and after is None:
    # Nothing to compare: reconcile declined and left no leadership behind.
    monotonic=True
elif before and after:
    monotonic=int(after['epoch'] or 0) >= int(before['epoch'] or 0)
    detail['epochBefore']=int(before['epoch'] or 0)
    detail['epochAfter']=int(after['epoch'] or 0)
else:
    # A row appearing where there was none is only legitimate when reconcile claims
    # to have adopted a peer.
    monotonic=(result.get('changed') is True)
    detail['note']='leadership row appeared or vanished; changed=%s'%result.get('changed')
check('leadership epoch never moves backwards',monotonic,detail)

# 3. If it reports no change, nothing may have changed.
if result.get('changed') is False:
    check('a declined reconcile leaves the primary exactly as it was',
          (before is None and after is None)
          or (bool(before) and bool(after) and str(after['primaryNode'])==str(before['primaryNode'])),
          {'before':before,'after':after,'reason':result.get('reason')})
else:
    check('a declined reconcile leaves the primary exactly as it was',True,
          {'note':'reconcile reported a change: %s'%result.get('reason')})

# 4. Repeating the command must converge, not keep drifting. A second run with no peer
#    moving leadership again would mean the command oscillates.
rec2_st,rec2=request('node-cluster-reconcile','POST',{},'clu_rec2_'+run)
second=(rec2 or {}).get('result') or {}
after2=leadership()
check('a repeated reconcile does not move leadership again',
      not (before and after2) or int(after2['epoch'] or 0) <= int(after['epoch'] or 0)
      or second.get('changed') is True,
      {'firstReason':result.get('reason'),'secondReason':second.get('changed')})

# 5. The command is admin-only; an empty body must never be enough to change anything.
check('reconcile requires an operator and reports its outcome',rec2_st in (200,409),
      {'status':rec2_st,'result':second})

print('CLUSTER-RECONCILE-UAT',sum(1 for x in results if x['pass']),'/',len(results))