#!/usr/bin/env python3
"""Tenant billing dummy (simulasi) UAT scenario. Single-property."""
from client import *
import json, datetime

results=[]
run='tb_'+datetime.datetime.now().strftime('%H%M%S')
LOG=base/'logs'/'tenant-billing-results.json'

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:400],flush=True)
    LOG.write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')

# status
s,b=request('tenant-billing','GET',{'command':'status'})
check('tenant billing status readable', s==200 and b.get('success') is True, {'status':s,'body':b})

# create invoice
op=run+'-create'
s,b=request('tenant-billing','POST',{'command':'create-invoice','amount':1000,'dueDate':'2099-01-01'},operation=op)
inv=b.get('invoiceId')
check('create invoice issued', s==200 and b.get('status')=='issued' and inv, {'status':s,'body':b})

# pay
op=run+'-pay'
s,b=request('tenant-billing','POST',{'command':'pay','invoiceId':inv},operation=op)
check('pay invoice', s==200 and b.get('status')=='paid', {'status':s,'body':b})

# list
s,b=request('tenant-billing','GET',{'command':'list-invoices'})
check('list invoices', s==200 and b.get('success') is True, {'status':s})

print('DONE tenant billing scenario', flush=True)
