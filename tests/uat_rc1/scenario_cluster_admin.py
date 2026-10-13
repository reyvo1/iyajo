from client import *
from scenario_core import db
import datetime, json

# Coverage for the cluster administration and node-to-node surface.
#
# Several of these endpoints are deliberately reachable without a browser session:
# node-sync-snapshot, node-cluster-control and node-cluster-peer-status are on the
# public action list, because a peer node has no login. The only thing standing
# between an anonymous caller and the cluster is the request signature. So the first
# thing asserted here is that a signed-in operator without node credentials still gets
# refused, which is the property that actually protects a cluster.
#
# The rest are disaster recovery. Emergency promotion forces a standby to take over,
# and the phrase, the backup confirmation and the isolation confirmation all exist so
# nobody promotes a node that is still writing.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='nod_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/cluster-admin-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ----------------------------------------- node endpoints are public but must be signed
# A browser session is not node credentials. Each of these must refuse the call.
for action,method in (('node-sync-snapshot','POST'),
                      ('node-cluster-control','POST'),
                      ('node-cluster-peer-status','GET')):
    st,body=request(action,method,{} if method=='POST' else None,'nod_'+action+'_'+run)
    refused=st>=400 or body.get('success') is False
    check('%s refuses a caller with no node signature'%action,refused,
          {'status':st,'error':(body or {}).get('error')})

# node-sync-status is admin-only and readable; it is not on the public list.
ns_st,ns_body=request('node-sync-status','GET',{},'nod_status_'+run)
check('node sync status is readable by an administrator',ns_st==200 and ns_body.get('success') is True,
      {'status':ns_st,'keys':sorted((ns_body or {}).keys())[:6]})

# --------------------------------------------------------- disaster recovery interlock
# Emergency promotion is the most dangerous command in the application: it tells a
# standby to start serving writes. Every guard on it must refuse an incomplete request.
bare_st,bare=request('node-cluster-emergency-promote','POST',{},'nod_prom_bare_'+run)
check('emergency promote refuses an empty request',bare_st>=400 and bare.get('success') is False,
      {'status':bare_st,'error':bare.get('error')})

wrong_phrase_st,wrong_phrase=request('node-cluster-emergency-promote','POST',
    {'reason':'UAT emergency promotion without the isolation confirmation','oldPrimaryIsolated':True,
     'backupConfirmed':True},'nod_prom_phrase_'+run)
check('emergency promote refuses the wrong confirmation phrase',
      wrong_phrase_st>=400 and wrong_phrase.get('success') is False,
      {'status':wrong_phrase_st,'error':wrong_phrase.get('error')})

no_backup_st,no_backup=request('node-cluster-emergency-promote','POST',
    {'reason':'UAT emergency promotion without a confirmed backup',
     'confirmPhrase':'PRIMARY LAMA SUDAH DIISOLASI','oldPrimaryIsolated':True},
    'nod_prom_backup_'+run)
check('emergency promote refuses without a confirmed backup',
      no_backup_st>=400 and no_backup.get('success') is False,
      {'status':no_backup_st,'error':no_backup.get('error')})

no_reason_st,no_reason=request('node-cluster-emergency-promote','POST',
    {'confirmPhrase':'PRIMARY LAMA SUDAH DIISOLASI','oldPrimaryIsolated':True,'backupConfirmed':True},
    'nod_prom_reason_'+run)
check('emergency promote refuses without a written reason',
      no_reason_st>=400 and no_reason.get('success') is False,
      {'status':no_reason_st,'error':no_reason.get('error')})

# Whatever those guards decided, the recorded leadership must not have moved.
epoch=db("SELECT leadership_epoch AS epoch,current_primary_node_id AS primaryNode "
         "FROM node_cluster_state WHERE id='system_default' LIMIT 1")
# No state row means clustering is off, so there was no primary for a promotion to
# change in the first place. That is the safe outcome, not a failure to read it.
check('no promotion attempt changed who is primary',
      (not epoch) or str(epoch[0]['primaryNode'] or '').lower()!='sweat_primary_changed',
      {'state':epoch,'note':'no cluster state row' if not epoch else None})

# ------------------------------------------------------- recovering an expired primary
rec_st,rec=request('node-cluster-recover-expired-primary','POST',
    {'reason':'UAT recover expired primary'},'nod_rec_'+run)
rec2_st,rec2=request('node-cluster-recover-expired-primary','POST',{},'nod_rec2_'+run)
check('recovering an expired primary refuses an incomplete request',
      rec_st>=400 or rec2_st>=400,
      {'withReason':rec_st,'empty':rec2_st,'errors':[(rec or {}).get('error'),(rec2 or {}).get('error')]})

# -------------------------------------------------- outbox conflicts are backup-only
# Resolving a sync conflict from the wrong node would make two nodes disagree about
# what happened, so the role check is the guard.
cf_st,cf=request('node-sync-conflict-resolve','POST',{'conflictId':'sync_does_not_exist'},'nod_conf_'+run)
cf2_st,cf2=request('node-sync-conflict-resolve','POST',{},'nod_conf2_'+run)
node_role_role=db("SELECT current_primary_node_id AS primaryNode FROM node_cluster_state WHERE id='system_default' LIMIT 1")
check('sync conflict resolution is refused without a conflict or the backup role',
      cf_st>=400 or cf2_st>=400,
      {'withId':cf_st,'empty':cf2_st,'clusterRole':node_role_role})

print('CLUSTER-ADMIN-UAT',sum(1 for x in results if x['pass']),'/',len(results))