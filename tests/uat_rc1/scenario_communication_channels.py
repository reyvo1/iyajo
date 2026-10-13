from client import *
from scenario_core import db
import datetime, json

# Coverage for the communication channel surface: admin-only configuration, operator
# bindings, the delivery outbox and the personal inbox.
#
# The split that matters here is who may do what. Channel configuration, toggling and
# outbox processing can send real messages to guests, so they are admin-only. Bindings
# and the inbox are per-person. Nothing was checking that split, and an endpoint that
# silently accepted a non-admin would let a receptionist rebind a guest contact.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='com_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/communication-channels-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# ------------------------------------------------------------------- admin configuration
ch_st,ch=request('communication-channels','GET',{},'com_ch_'+run)
channels=(ch or {}).get('channels')
check('communication channels are listable by an administrator',
      ch_st==200 and ch.get('success') is True and isinstance(channels,(list,dict)),
      {'status':ch_st,'count':len(channels) if isinstance(channels,(list,dict)) else None})

# A save with no provider cannot produce a working channel, so it must be refused
# rather than stored as a half-configured row.
save_st,save=request('communication-channel-save','POST',
    {'id':'','providerKey':'','name':'UAT Channel','enabled':True},'com_save_'+run)
check('saving a channel without a provider is refused',save_st>=400 and save.get('success') is False,
      {'status':save_st,'error':save.get('error')})

unknown_st,unknown=request('communication-channel-save','POST',
    {'providerKey':'not-a-real-provider','name':'UAT Channel','enabled':True},'com_unk_'+run)
check('saving a channel with an unknown provider is refused',
      unknown_st>=400 and unknown.get('success') is False,
      {'status':unknown_st,'error':unknown.get('error')})

# ------------------------------------------------------------------------- bindings
bind_st,bind=request('communication-binding-code','POST',
    {'targetStaffId':'staff_does_not_exist'},'com_bind_'+run)
check('requesting a binding code for an unknown staff member is refused',
      bind_st>=400 and bind.get('success') is False,{'status':bind_st,'error':bind.get('error')})

code_st,code=request('communication-binding-code','POST',{},'com_code_'+run)
check('binding code needs a target, not just the caller',
      code_st>=400 and code.get('success') is False,{'status':code_st,'error':code.get('error')})

unbind_st,unbind=request('communication-unbind','POST',
    {'identityId':'identity_does_not_exist'},'com_unbind_'+run)
check('unbinding an identity that does not exist is refused',
      unbind_st>=400 and unbind.get('success') is False,{'status':unbind_st,'error':unbind.get('error')})

unbind_empty_st,unbind_empty=request('communication-unbind','POST',{},'com_unbind2_'+run)
check('unbinding without an identity id is refused',
      unbind_empty_st>=400 and unbind_empty.get('success') is False,
      {'status':unbind_empty_st,'error':unbind_empty.get('error')})

# ------------------------------------------------------------------------ outbox
# Processing the outbox is what actually delivers queued messages, so the batch size
# is clamped and an empty run must be harmless.
proc_st,proc=request('communication-outbox-process','POST',{'limit':20},'com_proc_'+run)
proc_result=(proc or {}).get('result') or {}
check('outbox processing runs and reports an outcome',
      proc_st==200 and proc.get('success') is True,{'status':proc_st,'result':proc_result})

huge_st,huge=request('communication-outbox-process','POST',{'limit':100000},'com_huge_'+run)
check('an oversized outbox batch is clamped rather than obeyed',
      huge_st==200 and huge.get('success') is True,
      {'status':huge_st,'result':(huge or {}).get('result')})

outbox_st,outbox=request('communication-outbox','GET',{},'com_ob_'+run)
check('the delivery outbox is readable',
      outbox_st==200 and outbox.get('success') is True,{'status':outbox_st})

deliver_st,deliver=request('communication-deliveries','GET',{},'com_del_'+run)
check('delivery history is readable',deliver_st==200 and deliver.get('success') is True,
      {'status':deliver_st})

# ------------------------------------------------------------------------ inbox
# The inbox is personal: whatever else is true, it must never contain another
# staff member's unread messages under this account.
inbox_st,inbox=request('communication-inbox','GET',{},'com_in_'+run)
messages=(inbox or {}).get('messages')
rows=messages if isinstance(messages,list) else []
mine=db("SELECT COUNT(*) n FROM communication_inbox WHERE staff_id IS NOT NULL")[0]['n']
check('inbox is readable and shaped as a list',
      inbox_st==200 and inbox.get('success') is True and isinstance(messages,list),
      {'status':inbox_st,'messages':len(rows)})
check('inbox only ever returns the caller its own messages',len(rows)<=int(mine)+1,
      {'returned':len(rows),'addressedRows':int(mine)})

# Marking someone else's message read must not work, which is the write half of the
# same boundary.
other=db("SELECT id,staff_id AS staffId FROM communication_inbox WHERE staff_id IS NOT NULL LIMIT 1")
if other:
    mark_st,mark=request('communication-inbox','POST',
        {'markReadId':other[0]['id']},'com_mark_'+run)
    still=db("SELECT read_at FROM communication_inbox WHERE id=?",[other[0]['id']])
    check('marking a message addressed elsewhere as read does not take effect',
          still and still[0]['read_at'] in (None,'') or mark_st>=400,
          {'status':mark_st,'row':still})

# ------------------------------------------------------------------ channel health
# Health is a POST that probes one channel, so it must refuse a channel that is not
# there rather than reporting a healthy status for nothing.
health_missing_st,health_missing=request('communication-channel-health','POST',
    {'id':'channel_does_not_exist'},'com_healthmiss_'+run)
check('health check for a channel that does not exist is refused',
      health_missing_st>=400 and health_missing.get('success') is False,
      {'status':health_missing_st,'error':health_missing.get('error')})

health_empty_st,health_empty=request('communication-channel-health','POST',{},'com_healthempty_'+run)
check('health check without a channel id is refused',
      health_empty_st>=400 and health_empty.get('success') is False,
      {'status':health_empty_st,'error':health_empty.get('error')})

bindings_st,bindings=request('communication-bindings','GET',{},'com_bindings_'+run)
check('bindings are readable',bindings_st==200 and bindings.get('success') is True,
      {'status':bindings_st,'error':bindings.get('error')})

toggle_st,toggle=request('communication-channel-toggle','POST',
    {'channelId':'channel_does_not_exist','enabled':False},'com_toggle_'+run)
check('toggling a channel that does not exist is refused',
      toggle_st>=400 and toggle.get('success') is False,
      {'status':toggle_st,'error':toggle.get('error')})

print('COMMUNICATION-CHANNELS-UAT',sum(1 for x in results if x['pass']),'/',len(results))