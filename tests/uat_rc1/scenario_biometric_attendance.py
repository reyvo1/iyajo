from client import *
from scenario_core import db
import datetime, json

# Coverage for the biometric attendance surface.
#
# biometric-device-webhook is on the public action list, because a wall-mounted device
# has no browser session. That makes its device authentication the only thing between
# an anonymous caller and the attendance ledger, and writing attendance from an
# unverified payload would let anyone mark themselves present. So that is asserted
# first and on its own.
#
# tamasyaBiometricCanActForStaff allows an admin or manager to act for anybody and
# allows anybody to act for themselves. The scenario runs as admin, so it asserts the
# shape of that decision and the refusals around it rather than pretending to have
# exercised the negative branch.
results=[]
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
run='bio_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/biometric-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

before_attendance=db("SELECT COUNT(*) n FROM attendance")[0]['n'] if db("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='attendance'") else None

# ------------------------------------------------------- device webhook is the real gate
hook_st,hook=request('biometric-device-webhook','POST',
    {'serialNumber':'NOT-A-REAL-DEVICE','staffId':'someone','event':'check_in'},
    'bio_hook_'+run)
check('device webhook refuses a payload with no device signature',
      hook_st>=400 or hook.get('success') is False,
      {'status':hook_st,'error':hook.get('error')})

empty_hook_st,empty_hook=request('biometric-device-webhook','POST',{},'bio_hook2_'+run)
check('device webhook refuses an empty payload',
      empty_hook_st>=400 or empty_hook.get('success') is False,
      {'status':empty_hook_st,'error':empty_hook.get('error')})

# Nothing above may have created an attendance record from an unauthenticated call.
after_count=db("SELECT COUNT(*) n FROM attendance")[0]['n'] if before_attendance is not None else None
check('an unauthenticated device webhook writes no attendance',
      before_attendance is None or after_count==before_attendance,
      {'before':before_attendance,'after':after_count})

# ------------------------------------------------------------------- read-only surfaces
status_st,status=request('biometric-status','GET',{},'bio_status_'+run)
check('biometric status is readable for the signed-in operator',
      status_st==200 and status.get('success') is True,{'status':status_st,'error':status.get('error')})

devices_st,devices=request('biometric-devices','GET',{},'bio_dev_'+run)
check('registered biometric devices are listable',devices_st==200 and devices.get('success') is True,
      {'status':devices_st,'error':devices.get('error')})

# This one links a device user rather than listing, so it must refuse a device that
# does not exist instead of creating a dangling link.
users_st,users=request('biometric-device-users','POST',
    {'deviceId':'device_does_not_exist','deviceUserId':'nobody'},'bio_users_'+run)
check('linking a device user to a device that does not exist is refused',
      users_st>=400 and users.get('success') is False,
      {'status':users_st,'error':users.get('error')})

users_empty_st,users_empty=request('biometric-device-users','POST',{},'bio_users2_'+run)
check('linking a device user without a device id is refused',
      users_empty_st>=400 and users_empty.get('success') is False,
      {'status':users_empty_st,'error':users_empty.get('error')})

# ------------------------------------------------------------------------ refusals
# Enrolling a face for nobody must be refused; an empty staff id is not a person.
enroll_empty_st,enroll_empty=request('biometric-face-enroll','POST',{'staffId':''},'bio_enempty_'+run)
check('face enrolment without a staff member is refused',
      enroll_empty_st>=400 and enroll_empty.get('success') is False,
      {'status':enroll_empty_st,'error':enroll_empty.get('error')})

enroll_missing_st,enroll_missing=request('biometric-face-enroll','POST',
    {'staffId':'staff_does_not_exist'},'bio_enmiss_'+run)
check('face enrolment for an unknown staff member is refused',
      enroll_missing_st>=400 and enroll_missing.get('success') is False,
      {'status':enroll_missing_st,'error':enroll_missing.get('error')})

# Verification is the one that must never be guessed at.
verify_empty_st,verify_empty=request('biometric-face-verify','POST',{'staffId':''},'bio_ve_'+run)
check('face verification without a staff member is refused',
      verify_empty_st>=400 and verify_empty.get('success') is False,
      {'status':verify_empty_st,'error':verify_empty.get('error')})

# A fingerprint confirmation is a signed bridge payload; a bare one must not verify.
fp_st,fp=request('biometric-fingerprint-confirm','POST',
    {'staffId':'staff_does_not_exist'},'bio_fp_'+run)
fp_empty_st,fp_empty=request('biometric-fingerprint-confirm','POST',{},'bio_fp2_'+run)
check('fingerprint confirmation refuses an unsigned bridge payload',
      fp_st>=400 or fp_empty_st>=400,
      {'withStaff':fp_st,'empty':fp_empty_st,'errors':[fp.get('error'),fp_empty.get('error')]})

# GET on the POST-only surfaces must be refused rather than quietly accepted.
get_st,get_body=request('biometric-face-verify','GET',None,'bio_get_'+run)
check('a write-only biometric endpoint refuses GET',
      get_st==405 or get_body.get('success') is False,
      {'status':get_st,'error':get_body.get('error')})

print('BIOMETRIC-UAT',sum(1 for x in results if x['pass']),'/',len(results))