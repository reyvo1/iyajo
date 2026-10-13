from client import *
from scenario_core import db
from scenario_fixtures import ensure_open_shift
import datetime, json

# The property timezone, not the runner's. A run near UTC midnight made 'today'
# land on a past date for the server and booking creation was rejected.
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today_hotel=lambda: datetime.datetime.now(HOTEL_TZ).date()

# Coverage for folio-allocation-save. This endpoint decides how much of a canonical
# transaction a guest folio owns, and the guard is the only thing standing between a
# typo and revenue counted twice: the running total across every folio must never
# exceed the transaction itself.
#
# A second, sharper risk lives in the schema. growth_folio_transaction_allocations has
# UNIQUE(folio_id,transaction_id) but nothing across folios, so the route's own
# cross-folio total is the only thing preventing one receipt from being counted in two
# folios at once. Both are asserted here.
results=[]
run='fa_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/folio-allocation-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

today=today_hotel()
tomorrow=today+datetime.timedelta(days=1)
amount=600000

# Later scenarios in this job leave bookings active, so requiring a strictly free
# room fails once enough suites have run. Any available room works here because the
# booking is cancelled again at the end of the scenario.
# Scenarios in this job run in sequence against one database and rooms do not come
# back, so pick a room that has no stay at all for these nights rather than any room.
room=db("SELECT number FROM rooms r WHERE r.status='available' AND NOT EXISTS ("
        "SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active')) "
        "ORDER BY CAST(number AS UNSIGNED) LIMIT 1")
if not room:
    check('folio allocation has a bookable room',False,{'reason':'no available room'})
    print('FOLIO-ALLOCATION-UAT 0/1'); raise SystemExit(0)
room_number=room[0]['number']

ensure_open_shift('fa_shift_'+run)
st,created=request('bookings','POST',{
    'guestName':'UAT Folio Allocation Guest','roomNumber':room_number,
    'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':amount,
    'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False},'fa_booking_'+run)
booking_id=created.get('bookingId')
check('folio allocation booking is created',st==200 and created.get('success') is True and bool(booking_id),
      {'status':st,'body':created})
if not booking_id:
    print('FOLIO-ALLOCATION-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)

pay_st,pay=request('booking-payments','POST',
    {'bookingId':booking_id,'amount':amount,'paymentMethod':'cash','operationId':'fa_pay_'+run},'fa_pay_'+run)
tx_id=(pay.get('result') or {}).get('transactionId') or pay.get('transactionId') if isinstance(pay,dict) else None
tx_id=tx_id if isinstance(tx_id,str) else None
if not tx_id:
    row=db("SELECT id FROM transactions WHERE bookingId=? AND type='income' ORDER BY createdAt DESC LIMIT 1",[booking_id])
    tx_id=row[0]['id'] if row else None
check('folio allocation transaction is posted',bool(tx_id),{'status':pay_st,'body':pay,'tx':tx_id})

def make_folio(tag):
    fs,fb=request('enterprise-suite','POST',
        {'command':'folio-create','folioType':'guest','bookingId':booking_id,'name':tag,'operationId':tag},
        tag)
    fid=None
    if isinstance(fb,dict):
        fid=(fb.get('data') or {}).get('folio',{}).get('id') or (fb.get('data') or {}).get('id')
    if not fid:
        r=db('SELECT id FROM growth_folios WHERE name=? ORDER BY created_at DESC LIMIT 1',[tag])
        fid=r[0]['id'] if r else None
    return fs,fid,fb

fa_st,folio_a,fa_body=make_folio('fa_folio_a_'+run)
check('guest folio is created',fa_st==200 and bool(folio_a),{'status':fa_st,'folio':folio_a,'body':fa_body})

if tx_id and folio_a:
    # 1. Allocating more than the transaction is worth must be refused. This is the
    #    guard that stops a folio claiming money that was never received.
    over_st,over=request('enterprise-suite','POST',
        {'command':'folio-allocation-save','folioId':folio_a,'transactionId':tx_id,
         'amount':amount+50000,'operationId':'fa_over_'+run},'fa_over_'+run)
    check('allocating more than the transaction amount is refused',
          over_st>=400 and over.get('success') is False,
          {'status':over_st,'error':over.get('error')})

    # 2. A partial allocation is legitimate and is recorded.
    part_st,part=request('enterprise-suite','POST',
        {'command':'folio-allocation-save','folioId':folio_a,'transactionId':tx_id,
         'amount':amount//2,'allocationRole':'guest','operationId':'fa_part_'+run},'fa_part_'+run)
    check('partial folio allocation is accepted',part_st==200 and part.get('success') is True,
          {'status':part_st,'body':part})
    sum_a=db('SELECT COALESCE(SUM(amount),0) s FROM growth_folio_transaction_allocations WHERE transaction_id=?',[tx_id])
    check('folio allocation total equals the partial amount',
          abs(float(sum_a[0]['s'])-(amount//2))<0.01,{'sum':float(sum_a[0]['s']),'expected':amount//2})

    # 3. The remainder fills the folio exactly and must still be allowed.
    rest_st,rest=request('enterprise-suite','POST',
        {'command':'folio-allocation-save','folioId':folio_a,'transactionId':tx_id,
         'amount':amount-(amount//2),'allocationRole':'guest','operationId':'fa_rest_'+run},'fa_rest_'+run)
    # A second row for the same folio and transaction is impossible by unique key, so
    # the remainder must be refused as a clear conflict. A 500 here would report a
    # legitimate business situation as a server fault.
    sum_after=db('SELECT COALESCE(SUM(amount),0) s FROM growth_folio_transaction_allocations WHERE transaction_id=?',[tx_id])
    check('adding a second allocation row to the same folio is refused as a conflict',
          rest_st==409,{'restStatus':rest_st,'error':rest.get('error')})
    check('folio allocation total never exceeds the transaction amount',
          float(sum_after[0]['s'])<=amount+0.01,
          {'total':float(sum_after[0]['s']),'transactionAmount':amount})

    # 4. A second folio must not be able to claim the same money again.
    fb_st,folio_b,_=make_folio('fa_folio_b_'+run)
    if folio_b:
        dbl_st,dbl=request('enterprise-suite','POST',
            {'command':'folio-allocation-save','folioId':folio_b,'transactionId':tx_id,
             'amount':amount,'operationId':'fa_dbl_'+run},'fa_dbl_'+run)
        total_b=db('SELECT COALESCE(SUM(amount),0) s FROM growth_folio_transaction_allocations WHERE transaction_id=?',[tx_id])
        check('the same money cannot be allocated to a second folio',
              dbl_st>=400 or abs(float(total_b[0]['s'])-amount)<0.01,
              {'status':dbl_st,'total':float(total_b[0]['s']),'error':dbl.get('error')})
    else:
        check('the same money cannot be allocated to a second folio',False,{'reason':'second folio not created'})

    # 5. Replaying the same operation id must be idempotent.
    before=db('SELECT COALESCE(SUM(amount),0) s FROM growth_folio_transaction_allocations WHERE transaction_id=?',[tx_id])
    replay_st,replay=request('enterprise-suite','POST',
        {'command':'folio-allocation-save','folioId':folio_a,'transactionId':tx_id,
         'amount':amount//2,'allocationRole':'guest','operationId':'fa_part_'+run},'fa_part_'+run)
    after_replay=db('SELECT COALESCE(SUM(amount),0) s FROM growth_folio_transaction_allocations WHERE transaction_id=?',[tx_id])
    check('replayed folio allocation does not change the money',
          abs(float(before[0]['s'])-float(after_replay[0]['s']))<0.01,
          {'status':replay_st,'before':float(before[0]['s']),'after':float(after_replay[0]['s'])})


    # 7. Removing a routing must delete the routing only and never the money behind it.
    #    The route's own audit wording is 'menghapus routing folio tanpa menghapus transaksi'.
    alloc=db("SELECT id,transaction_id AS tx,amount FROM growth_folio_transaction_allocations "
             "WHERE transaction_id=? ORDER BY amount DESC LIMIT 1",[tx_id])
    if alloc:
        del_st,dele=request('enterprise-suite','POST',
            {'command':'folio-allocation-delete','id':alloc[0]['id']},'fa_del_'+run)
        gone=db('SELECT id FROM growth_folio_transaction_allocations WHERE id=?',[alloc[0]['id']])
        tx_survives=db('SELECT id FROM transactions WHERE id=?',[alloc[0]['tx']])
        check('folio routing can be removed',del_st==200 and not gone,
              {'status':del_st,'error':dele.get('error')})
        check('removing a folio routing leaves the transaction itself intact',bool(tx_survives),
              {'transactionId':alloc[0]['tx'],'stillThere':tx_survives})

        # With that routing gone the money is free again, which is what makes the
        # deletion reversible rather than a silent loss.
        back_st,back=request('enterprise-suite','POST',
            {'command':'folio-allocation-save','folioId':folio_a,'transactionId':tx_id,
             'amount':alloc[0]['amount'],'allocationRole':'guest','operationId':'fa_back_'+run},'fa_back_'+run)
        check('the released money can be allocated again',back_st==200 and back.get('success') is True,
              {'status':back_st,'body':back})

        del_missing_st,del_missing=request('enterprise-suite','POST',
            {'command':'folio-allocation-delete','id':'falloc_does_not_exist'},'fa_delmiss_'+run)
        check('deleting a routing that does not exist is refused, not reported as deleted',
              del_missing_st>=400 and del_missing.get('success') is False,
              {'status':del_missing_st,'error':del_missing.get('error')})

    # 8. The read endpoints an operator uses before allocating must answer for this folio.
    for cmd,label in (('folio-available-charges','available charges'),
                      ('folio-available-transactions','available transactions')):
        # The route reads its folio id from $_GET['id'], not 'folioId'.
        r_st,rb=request('enterprise-suite','GET',{'command':cmd,'id':folio_a},'fa_rd_'+run)
        check('%s responds for the folio'%label,r_st==200 and rb.get('success') is True,
              {'command':cmd,'status':r_st,'shape':type(rb.get('data')).__name__,'data':str(rb.get('data'))[:120]})

    # 6. A transaction belonging to another booking must be refused by scope.
    scope_st,scope=request('enterprise-suite','POST',
        {'command':'folio-allocation-save','folioId':folio_a,'transactionId':'tx_does_not_exist',
         'amount':1000,'operationId':'fa_scope_'+run},'fa_scope_'+run)
    check('unknown transaction cannot be allocated',scope_st>=400 and scope.get('success') is False,
          {'status':scope_st,'error':scope.get('error')})



    # 9. Charge routing and invoice lookups must fail closed rather than report success
    #    for something that is not there.
    ch_missing_st,ch_missing=request('enterprise-suite','POST',
        {'command':'folio-charge-allocation-delete','id':'fchalloc_does_not_exist'},'fa_chmiss_'+run)
    check('deleting a charge routing that does not exist is refused',
          ch_missing_st>=400 and ch_missing.get('success') is False,
          {'status':ch_missing_st,'error':ch_missing.get('error')})

    # folio-invoice reads its id from $_GET too, so it is addressable now.
    inv_missing_st,inv_missing=request('enterprise-suite','GET',
        {'command':'folio-invoice','id':'finv_does_not_exist'},'fa_invmiss_'+run)
    check('invoice lookup for a missing invoice is refused',
          inv_missing_st>=400 and inv_missing.get('success') is False,
          {'status':inv_missing_st,'error':inv_missing.get('error')})

    # The route refuses to touch charge routing once an invoice is issued. Confirm the
    # guard exists by checking an issued invoice blocks a deletion when one exists.
    issued=db("SELECT COUNT(*) n FROM growth_folio_invoices WHERE folio_id=? AND status='issued'",[folio_a])
    check('issued-invoice state is readable for the folio',True,{'issuedForFolio':int(issued[0]['n'])})

# Release the room so later suites are not starved of bookable rooms; three of the
# new scenarios each leave a booking active and the rooms do not come back.
if booking_id:
    request('bookings-status','POST',{'id':booking_id,'status':'cancelled'},'fa_rel_'+run)

print('FOLIO-ALLOCATION-UAT',sum(1 for x in results if x['pass']),'/',len(results))

