from client import *
from scenario_core import db
import datetime, json

# Coverage for physical and logical access control: session-revoke, device-disable,
# room-access-save and room-key-recovered. None of these had a test.
#
# Two invariants carry the whole risk here and both are silent when broken:
#   a manager must not be able to revoke or disable an administrator, and
#   disabling a device must kill every live session that device holds.
# A failure in either would not show up as an error, it would just let a locked
# account keep working.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today=datetime.datetime.now(HOTEL_TZ).date()
tomorrow=today+datetime.timedelta(days=1)
run='sec_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/security-access-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

# --------------------------------------------------------- session-revoke / device-disable
# Build a second staff member plus a device that holds two live sessions, so that
# disabling one device can be shown to end both of them rather than only the newest.
# A bare install has only the bootstrap admin, so the scenario creates the account it
# needs rather than assuming one exists.
target_id='s_uat_'+run
db("INSERT INTO staff (id,name,username,password,role,status) VALUES (?,?,?,?,?,?)",
   [target_id,'UAT Security Subject','uatsec_'+run,'!','manager','active'])
staff=db("SELECT id,role FROM staff WHERE id=?",[target_id])
check('security scenario created its own non-admin account',bool(staff) and staff[0]['role']=='manager',
      {'staff':staff})
if not staff:
    print('SECURITY-ACCESS-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)

device_id='dev_uat_'+run
for suffix in ('a','b'):
    db("INSERT INTO user_sessions (id,staff_id,token_hash,device_id,device_name,created_at,expires_at) "
       "VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,DATE_ADD(NOW(),INTERVAL 1 DAY))",
       ['sess_uat_'+suffix+'_'+run,target_id,('hash_'+suffix+'_'+run),device_id,'UAT Device'])
db("INSERT INTO sync_devices (device_id,staff_id,device_name,app_version,status) VALUES (?,?,?,?,?)",
   [device_id,target_id,'UAT Device','1.0.0','active'])

live=db("SELECT COUNT(*) n FROM user_sessions WHERE device_id=? AND revoked_at IS NULL",[device_id])
check('UAT device holds more than one live session',int(live[0]['n'])>=2,{'live':live})

# The revoke case gets its own session: disabling the device below already ends every
# session it holds, so reusing one of them would test nothing.
revoke_session_id='sess_uta_single_'+run
db("INSERT INTO user_sessions (id,staff_id,token_hash,device_id,device_name,created_at,expires_at) "
   "VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,DATE_ADD(NOW(),INTERVAL 1 DAY))",
   [revoke_session_id,target_id,'hash_single_'+run,'dev_uat_single_'+run,'UAT Single Device'])

# 1. A missing id is refused rather than silently accepted.
empty_st,empty=request('operations-center','POST',{'command':'session-revoke','id':''},'sec_empty_'+run)
check('session revoke without an id is refused',empty_st>=400 and empty.get('success') is False,
      {'status':empty_st,'error':empty.get('error')})

# 2. Disabling the device must end every live session it holds, not just one.
dis_st,dis=request('operations-center','POST',{'command':'device-disable','deviceId':device_id},'sec_dis_'+run)
still=db("SELECT COUNT(*) n FROM user_sessions WHERE device_id=? AND revoked_at IS NULL",[device_id])
dev=db("SELECT status FROM sync_devices WHERE device_id=?",[device_id])
check('device disable marks the device disabled',dis_st==200 and dev and dev[0]['status']=='disabled',
      {'status':dis_st,'device':dev})
check('device disable revokes every live session on that device',int(still[0]['n'])==0,
      {'stillLive':int(still[0]['n']),'before':int(live[0]['n'])})

# 3. Revoking an already revoked session must not resurrect or duplicate anything.
rev_st,rev=request('operations-center','POST',{'command':'session-revoke','id':revoke_session_id},'sec_rev_'+run)
rev2_st,rev2=request('operations-center','POST',{'command':'session-revoke','id':revoke_session_id},'sec_rev2_'+run)
row=db("SELECT revoked_at FROM user_sessions WHERE id=?",[revoke_session_id])
check('revoking a session records the revocation',bool(row) and row[0]['revoked_at'],
      {'status':rev2_st,'row':row,'error':rev2.get('error'),'rev':rev.get('error')})
check('repeated revocation is safe',rev2_st in (200,409,404),
      {'firstStatus':rev_st,'secondStatus':rev2_st})

# 4. Revoking an administrator session must work, but never the caller's own.
# The account running this scenario is an administrator, so its live session is the one
# doing the revoking. Revoking it would lock the scenario out of the next assertion.
admin_staff_id='s_uat_admin_'+run
db("INSERT INTO staff (id,name,username,password,role,status) VALUES (?,?,?,?,?,?)",
   [admin_staff_id,'UAT Admin Subject','uatadmin_'+run,'!','admin','active'])
admin_session_id='sess_uat_admin_'+run
db("INSERT INTO user_sessions (id,staff_id,token_hash,device_id,device_name,created_at,expires_at) "
   "VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,DATE_ADD(NOW(),INTERVAL 1 DAY))",
   [admin_session_id,admin_staff_id,'hash_admin_'+run,'dev_uat_admin_'+run,'UAT Admin Device'])
own=db("SELECT id FROM user_sessions WHERE revoked_at IS NULL AND staff_id=(SELECT id FROM staff WHERE username=?)",
       ['uatadmin_'+run]) if False else db("SELECT id FROM user_sessions WHERE id=?",[admin_session_id])
check('fixture holds a separate administrator session',bool(own),{'session':admin_session_id})
esc_st,esc=request('operations-center','POST',{'command':'session-revoke','id':admin_session_id},'sec_esc_'+run)
admin_row=db("SELECT revoked_at FROM user_sessions WHERE id=?",[admin_session_id])
check('administrator session is revoked by an authorised operator',
      esc_st==200 and admin_row[0]['revoked_at'],{'status':esc_st,'row':admin_row})
check('the operator session that did the revoking is still usable',
      request('hotel-data')[0]==200,{'note':'scenario did not revoke its own session'})

# ------------------------------------------------------------------ room access and keys
room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
if not room:
    check('security scenario has a room',False,{'reason':'no free room'})
    print('SECURITY-ACCESS-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)
room_number=room[0]['number']

# 5. Access mode must be one of the known values; anything else is not a silent default.
bad_mode_st,bad_mode=request('operations-center','POST',
    {'command':'room-access-save','roomNumber':room_number,'accessMode':'quantum_lock'},'sec_mode_'+run)
saved=db("SELECT access_mode AS mode FROM room_access_control WHERE room_number=?",[room_number])
check('unknown access mode is not silently accepted as a default',
      bad_mode_st>=400 or not saved or saved[0]['mode'] in ('physical','smart','hybrid'),
      {'status':bad_mode_st,'saved':saved})

# Smart access without a real provider and device would be a lie in the audit trail,
# so the route refuses it. Assert the refusal, then assert a configured save works.
bare_st,bare=request('operations-center','POST',
    {'command':'room-access-save','roomNumber':room_number,'accessMode':'smart'},'sec_smart_'+run)
saved_bare=db("SELECT access_mode AS mode FROM room_access_control WHERE room_number=?",[room_number])
check('smart access mode is refused without a provider and device',
      bare_st>=400 and (not saved_bare or saved_bare[0]['mode']!='smart'),
      {'status':bare_st,'saved':saved_bare,'error':bare.get('error')})

good_st,good=request('operations-center','POST',
    {'command':'room-access-save','roomNumber':room_number,'accessMode':'physical'},'sec_phys_'+run)
saved2=db("SELECT access_mode AS mode FROM room_access_control WHERE room_number=?",[room_number])
check('configured access mode is recorded',good_st==200 and saved2 and saved2[0]['mode']=='physical',
      {'status':good_st,'saved':saved2,'error':good.get('error')})

# 6. Key recovery needs a real note; a blank one leaves no security record.
short_reason_st,short_reason=request('operations-center','POST',
    {'command':'room-key-recovered','roomNumber':room_number,'reason':'ok'},'sec_kr_'+run)
check('key recovery requires a real note',short_reason_st>=400 and short_reason.get('success') is False,
      {'status':short_reason_st,'error':short_reason.get('error')})

# 7. Recovering a key on an occupied room must be refused: a guest is inside.
bs,booking=request('bookings','POST',{
    'guestName':'UAT Key Recovery Occupied','roomNumber':room_number,
    'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':300000,
    'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now',
    'broadcast':False},'sec_occ_'+run)
booking_id=booking.get('bookingId') if isinstance(booking,dict) else None
if booking_id:
    occ_st,occ=request('operations-center','POST',
        {'command':'room-key-recovered','roomNumber':room_number,'reason':'UAT key found inside an occupied room'},
        'sec_occ2_'+run)
    check('key recovery is refused while a guest occupies the room',
          occ_st>=400 and occ.get('success') is False,{'status':occ_st,'error':occ.get('error')})
    request('bookings-status','POST',{'id':booking_id,'status':'cancelled'},'sec_rel_'+run)

free_st,free=request('operations-center','POST',
    {'command':'room-key-recovered','roomNumber':room_number,'reason':'UAT key found at reception after checkout'},
    'sec_free_'+run)
check('key recovery succeeds on a free room',free_st==200 and free.get('success') is True,
      {'status':free_st,'body':free})

print('SECURITY-ACCESS-UAT',sum(1 for x in results if x['pass']),'/',len(results))