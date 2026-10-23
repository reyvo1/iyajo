const $=id=>document.getElementById(id);
const role=()=>String(sessionStorage.getItem('hotel_staff_role')||sessionStorage.getItem('hotel_role')||'').trim().toLowerCase();
const token=()=>sessionStorage.getItem('hotel_session_token')||'';
function operationId(prefix='tenant-billing'){const uuid=crypto.randomUUID?crypto.randomUUID():`${Date.now()}-${Math.random().toString(16).slice(2)}`;return `${prefix}-${uuid}`.slice(0,100);}
async function api(method='GET',body=null,command=''){
  const verb=String(method||'GET').toUpperCase();
  const u=new URL('./api.php',location.href);
  u.searchParams.set('action','tenant-billing');
  if(command) u.searchParams.set('command',command);
  const h={Accept:'application/json',Authorization:`Bearer ${token()}`,'X-Device-ID':localStorage.getItem('hotel_device_id')||'tenant-billing-browser'};
  const hs=sessionStorage.getItem('hotel_offline_hotel_scope'); if(hs) h['X-Tamasya-Hotel-Scope']=hs;
  const ss=sessionStorage.getItem('hotel_offline_session_scope'); if(ss) h['X-Tamasya-Offline-Session-Scope']=ss;
  if(body!==null) h['Content-Type']='application/json';
  if(['POST','PUT','PATCH','DELETE'].includes(verb)) h['X-Tamasya-Operation-ID']=operationId();
  const r=await fetch(u,{method:verb,headers:h,body:body===null?undefined:JSON.stringify(body),cache:'no-store'});
  const d=await r.json().catch(()=>({}));
  if(!r.ok||d.success===false) throw new Error(d.error||d.message||`HTTP ${r.status}`);
  return d;
}
function toast(msg){const t=$('toast'); if(!t) return; t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2500);}
async function loadStatus(){const d=await api('GET',null,'status'); $('tb-status').textContent=`Properti: ${d.propertyId} | Status: ${d.status} | Plan: ${d.plan}`;}
async function loadList(){const d=await api('GET',null,'list-invoices'); const rows=d.invoices||[]; $('tb-list').innerHTML=rows.length?rows.map(i=>`<div class="panel" style="margin:6px 0"><b>${i.id}</b> — ${i.amount} ${i.currency} — ${i.status} — jatuh tempo ${i.due_date||'-'} ${i.paid_at?'— lunas '+i.paid_at:''} ${i.status==='issued'?`<button class="btn secondary" data-pay="${i.id}">Tandai Lunas</button>`:''}</div>`).join(''):'Tidak ada invoice.'; document.querySelectorAll('[data-pay]').forEach(b=>b.onclick=async()=>{try{await api('POST',{invoiceId:b.dataset.pay},'pay'); toast('Lunas'); await loadList();}catch(e){toast(e.message);}});}
async function refresh(){await loadStatus(); await loadList();}
document.addEventListener('DOMContentLoaded',async()=>{
  if(sessionStorage.getItem('hotel_logged_in')!=='true'||!token()){location.href='./login';return;}
  $('tb-form').onsubmit=async e=>{e.preventDefault(); try{const due=$('tb-due').value; await api('POST',{amount:Number($('tb-amount').value),dueDate:due},'create-invoice'); toast('Invoice dibuat'); await loadList();}catch(err){toast(err.message);}};
  $('tb-suspend').onclick=async()=>{try{await api('POST',{},'suspend'); toast('Suspended'); await refresh();}catch(e){toast(e.message);}};
  $('tb-reopen').onclick=async()=>{try{await api('POST',{},'reopen'); toast('Reopened'); await refresh();}catch(e){toast(e.message);}};
  $('tb-auto').onclick=async()=>{try{const d=await api('POST',{},'auto-suspend'); toast(d.suspended?`Auto-suspend: ${d.overdueCount} overdue`:'Tidak ada overdue'); await refresh();}catch(e){toast(e.message);}};
  $('tb-refresh').onclick=refresh;
  if(!$('tb-due').value) $('tb-due').value=new Date(Date.now()+7*86400000).toISOString().slice(0,10);
  try{await refresh();}catch(e){toast(e.message);}
});
