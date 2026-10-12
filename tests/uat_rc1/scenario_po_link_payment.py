from client import *
from scenario_core import db
import datetime, json

# The property timezone, not the runner's. A run near UTC midnight made 'today'
# land on a past date for the server and booking creation was rejected.
HOTEL_TZ=datetime.timezone(datetime.timedelta(hours=8))
today_hotel=lambda: datetime.datetime.now(HOTEL_TZ).date()

# Coverage for po-link-payment: linking a real finance payment to a purchase order.
#
# The table is unique on (po_id,transaction_id) while the route's own running totals
# are per PO and per transaction across every PO. Both are asserted, because the gap
# between "unique per pair" and "summed per entity" is where procurement money goes
# missing or gets counted twice.
results=[]
run='po_'+datetime.datetime.now(HOTEL_TZ).strftime('%H%M%S')+str(int(datetime.datetime.now(HOTEL_TZ).microsecond/1000))

def check(name,ok,details=None):
    results.append({'test':name,'pass':bool(ok),'details':details})
    print('PASS' if ok else 'FAIL',name,str(details or '')[:400],flush=True)
    (base/'logs/po-link-payment-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

today=today_hotel()
po_total=750000.0

# Vendors live in the optional growth suite, and their status column is `status`.
vendor=db("SELECT id FROM growth_vendors WHERE status='active' ORDER BY id LIMIT 1")
if not vendor:
    vs,vb=request('growth-suite','POST',{
        'command':'vendor-save','name':'UAT PO Vendor '+run,'operationId':'po_v_'+run},'po_v_'+run)
    row=db("SELECT id FROM growth_vendors WHERE name=? ORDER BY created_at DESC LIMIT 1",['UAT PO Vendor '+run])
    vendor_id=row[0]['id'] if row else None
else:
    vendor_id=vendor[0]['id']

po_st,po_body=request('growth-suite','POST',{
    'command':'po-save','vendorId':vendor_id,'orderDate':today.isoformat(),
    'items':[{'itemName':'UAT PO item','quantity':1,'unitPrice':po_total,'taxRate':0}],
    'notes':'UAT purchase order','operationId':'po_'+run},'po_'+run)
po_id=None
if isinstance(po_body,dict):
    d=po_body.get('data') or {}
    po_id=d.get('purchaseOrder',{}).get('id') or d.get('poId') or d.get('id')
if not po_id:
    row=db("SELECT id FROM growth_purchase_orders WHERE operation_id=? LIMIT 1",['po_'+run])
    po_id=row[0]['id'] if row else None
check('purchase order is created',po_st==200 and bool(po_id),{'status':po_st,'po':po_id,'body':po_body})

# The route refuses payments against a draft PO; make it payable first.
if po_id:
    # PO status is a state machine: draft -> submitted -> approved. approved cannot be
    # reached directly, so the PO would stay draft and every payment would be refused.
    transitions={}
    for target in ('submitted','approved'):
        t_st,_=request('growth-suite','POST',{'command':'po-status','id':po_id,'status':target,
            'operationId':'po_'+target[:3]+'_'+run},'po_'+target[:3]+'_'+run)
        transitions[target]=t_st
    st=db('SELECT status,total_amount FROM growth_purchase_orders WHERE id=?',[po_id])
    check('purchase order walks the status machine to approved',
          bool(st) and st[0]['status']=='approved',{'transitions':transitions,'row':st})

if not po_id:
    print('PO-LINK-PAYMENT-UAT',sum(1 for x in results if x['pass']),'/',len(results)); raise SystemExit(0)

# A payment must be an expense. Income must never settle a purchase order.
# Manual entries need a real expense category; the endpoint rejects a bare type+amount.
exp_cat=db("SELECT id FROM categories WHERE type='expense' AND is_active=1 ORDER BY is_system DESC,id LIMIT 1")
exp_st,exp=request('transactions','POST',{
    'type':'expense','categoryId':exp_cat[0]['id'] if exp_cat else None,'amount':po_total,
    'date':today.isoformat(),'description':'UAT supplier payment','recordOrigin':'historical_import',
    'historicalImportReason':'UAT purchase order settlement fixture',
    'operationId':'po_exp_'+run},'po_exp_'+run)
exp_tx=None
if isinstance(exp_body:=exp,dict):
    exp_tx=(exp.get('result') or {}).get('transactionId') or exp.get('transactionId')
if not exp_tx:
    row=db("SELECT id FROM transactions WHERE description='UAT supplier payment' ORDER BY createdAt DESC LIMIT 1")
    exp_tx=row[0]['id'] if row else None
check('supplier expense is recorded',exp_st==200 and bool(exp_tx),{'status':exp_st,'tx':exp_tx,'body':exp})

inc_cat=db("SELECT id FROM categories WHERE type='income' AND is_active=1 ORDER BY is_system DESC,id LIMIT 1")
inc_st,inc=request('transactions','POST',{
    'type':'income','categoryId':inc_cat[0]['id'] if inc_cat else None,'amount':1000,'date':today.isoformat(),
    'description':'UAT income must not settle a PO','recordOrigin':'historical_import',
    'historicalImportReason':'UAT negative control fixture','operationId':'po_inc_'+run},'po_inc_'+run)
inc_tx=None
if isinstance(inc,dict):
    inc_tx=(inc.get('result') or {}).get('transactionId') or inc.get('transactionId')
if not inc_tx:
    row=db("SELECT id FROM transactions WHERE description='UAT income must not settle a PO' ORDER BY createdAt DESC LIMIT 1")
    inc_tx=row[0]['id'] if row else None

if exp_tx:
    # 1. More than the PO is worth must be refused, and refused as a client conflict.
    over_st,over=request('growth-suite','POST',{
        'command':'po-link-payment','poId':po_id,'transactionId':exp_tx,
        'amountApplied':po_total+50000,'operationId':'po_over_'+run},'po_over_'+run)
    check('allocating beyond the purchase order total is refused as a conflict',
          over_st==409,{'status':over_st,'error':over.get('error')})

    # 2. Full payment links cleanly.
    full_st,full=request('growth-suite','POST',{
        'command':'po-link-payment','poId':po_id,'transactionId':exp_tx,
        'amountApplied':po_total,'operationId':'po_link_'+run},'po_link_'+run)
    check('purchase order payment is linked',full_st==200 and full.get('success') is True,
          {'status':full_st,'body':full})

    linked=db('SELECT COALESCE(SUM(amount_applied),0) s FROM growth_purchase_order_payments WHERE po_id=?',[po_id])
    check('linked amount matches the purchase order total',
          abs(float(linked[0]['s'])-po_total)<0.01,{'linked':float(linked[0]['s']),'expected':po_total})

    # 3. Replaying the same operation id is idempotent.
    replay_st,replay=request('growth-suite','POST',{
        'command':'po-link-payment','poId':po_id,'transactionId':exp_tx,
        'amountApplied':po_total,'operationId':'po_link_'+run},'po_link_'+run)
    after=db('SELECT COALESCE(SUM(amount_applied),0) s FROM growth_purchase_order_payments WHERE po_id=?',[po_id])
    check('replayed PO payment does not double the money',
          abs(float(after[0]['s'])-po_total)<0.01,
          {'status':replay_st,'total':float(after[0]['s'])})

    # 4. Linking again under a fresh operation id must be a conflict, not a server
    #    error: the row cannot exist because of the (po_id,transaction_id) key.
    again_st,again=request('growth-suite','POST',{
        'command':'po-link-payment','poId':po_id,'transactionId':exp_tx,
        'amountApplied':1,'operationId':'po_again_'+run},'po_again_'+run)
    after2=db('SELECT COALESCE(SUM(amount_applied),0) s FROM growth_purchase_order_payments WHERE po_id=?',[po_id])
    check('second link of the same pair is refused as a conflict',
          again_st==409,{'status':again_st,'error':again.get('error')})
    check('the purchase order total is still not exceeded',
          abs(float(after2[0]['s'])-po_total)<0.01,{'total':float(after2[0]['s'])})

if inc_tx and po_id:
    inc_st2,inc_res=request('growth-suite','POST',{
        'command':'po-link-payment','poId':po_id,'transactionId':inc_tx,
        'amountApplied':1000,'operationId':'po_inc2_'+run},'po_inc2_'+run)
    check('income can never settle a purchase order',
          inc_st2==400 and inc_res.get('success') is False,
          {'status':inc_st2,'error':inc_res.get('error')})

print('PO-LINK-PAYMENT-UAT',sum(1 for x in results if x['pass']),'/',len(results))