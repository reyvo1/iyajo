/* Optional catalog admin; no inline handlers, no independent journal/tax engine. */
(() => {
  'use strict';
  const ID='tamasya-catalog-workspace';
  let state={open:false,rows:[],categories:[],subcategories:[],readOnly:true,selectedCategoryId:''};
  const safe=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function apiPath(action){
    let base=location.pathname.replace(/\/(?:index\.html|dashboard|rooms|finance|report|telegram|staff|config|inventory|operations|activity|website|support|pos)\/?$/,'/');
    if(!base.endsWith('/'))base=base.slice(0,base.lastIndexOf('/')+1);
    return base+'api.php?action='+encodeURIComponent(action);
  }
  async function request(action,body,params){
    const token=sessionStorage.getItem('hotel_session_token')||'';
    const scope=sessionStorage.getItem('hotel_offline_hotel_scope')||'';
    const headers={'Accept':'application/json',...(body?{'Content-Type':'application/json'}:{})};
    if(token)headers.Authorization='Bearer '+token;
    if(scope)headers['X-Tamasya-Hotel-Scope']=scope;
    if(body){
      // One ID per user interaction: retrying same mutation cannot post twice.
      if(!window.crypto?.randomUUID)throw new Error('Operasi master tarif membutuhkan HTTPS dan ID operasi aman.');
      headers['X-Tamasya-Operation-ID']='catalog_'+window.crypto.randomUUID();
    }
    const r=await fetch(apiPath(action)+(params?'&'+new URLSearchParams(params).toString():''),{
      method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers,
      ...(body?{body:JSON.stringify(body)}:{})
    });
    // Legacy hotel-data is a flat, role-scoped projection without `success:true`.
    // Catalog mutations/reads use an explicit success envelope. Validate each
    // contract separately; HTTP 200 alone never proves that an API call worked.
    let data;
    try { data=await r.json(); }
    catch { throw new Error('Respons '+action+' bukan JSON yang valid (HTTP '+r.status+').'); }
    if(!data||typeof data!=='object'||Array.isArray(data))
      throw new Error('Format respons '+action+' tidak valid (HTTP '+r.status+').');
    if(!r.ok||data.success===false)
      throw new Error(data.message||data.error||('HTTP '+r.status));
    if(action==='hotel-data'){
      if(!Array.isArray(data.categories)||!Array.isArray(data.subcategoryCatalog))
        throw new Error('Format data kategori dari hotel-data tidak sesuai (HTTP '+r.status+').');
    }else if(data.success!==true){
      throw new Error(data.message||data.error||('Respons '+action+' tidak mengonfirmasi keberhasilan (HTTP '+r.status+').'));
    }
    return data;
  }
  function root(){
    let el=document.getElementById(ID);
    if(!el){el=document.createElement('section');el.id=ID;el.hidden=true;el.className='tamasya-catalog-overlay';document.body.appendChild(el);}
    return el;
  }
  function info(msg){const el=root().querySelector('[data-info]');if(el)el.textContent=String(msg||'');}
  function currentCategories(){return state.categories.filter(c=>Number(c.isActive??c.is_active??1)===1 && !String(c.systemKey??c.system_key??'').trim());}
  function render(){
    const el=root();
    const opts=currentCategories().map(c=>'<option value="'+safe(c.id)+'" '+(c.id===state.selectedCategoryId?'selected':'')+'>'+safe(c.name)+' ('+safe(c.type)+')</option>').join('');
    const rows=state.rows.filter(r=>!state.selectedCategoryId||r.category_id===state.selectedCategoryId);
    el.innerHTML=`<div class="tamasya-catalog-shell" role="dialog" aria-modal="true" aria-label="Master Item dan Tarif">
      <header><h1>Master Item & Tarif Universal</h1><button type="button" data-close>← Kembali</button></header>
      <p>Klasifikasi kategori tetap terpisah dari tarif. Modul ini hanya menyiapkan harga standar/draft, tidak melakukan posting keuangan dan pajak.</p>
      <div class="tamasya-catalog-toolbar"><label>Kategori <select data-filter><option value="">Semua</option>${opts}</select></label>
      ${state.readOnly?'':`<button type="button" data-new>+ Item Baru</button>`}</div>
      <p data-info role="status" aria-live="polite"></p>
      <div class="tamasya-catalog-table-wrap"><table><thead><tr><th>Item / Kategori</th><th>Satuan</th><th>Mode</th><th>Tarif saat ini</th><th>Status</th><th>Aksi</th></tr></thead><tbody>${rows.length?rows.map(r=>`<tr><td><strong>${safe(r.name)}</strong><small>${safe(r.category_name)}${r.subcategory_name?' / '+safe(r.subcategory_name):''}</small></td>
        <td>${safe(r.unit_label)}</td><td>${safe(r.price_mode)}</td><td>${r.price_mode==='manual'?'Manual':r.current_rate===null?'Belum ada tarif':safe(r.current_rate)}</td>
        <td>${Number(r.is_active)?'Aktif':'Arsip'}</td><td>${state.readOnly?'Lihat':`<button type="button" data-edit="${safe(r.id)}">Ubah</button> <button type="button" data-rate="${safe(r.id)}" ${!Number(r.is_active)||r.price_mode!=='fixed'?'disabled':''}>Tarif</button> <button type="button" data-archive="${safe(r.id)}" ${!Number(r.is_active)?'disabled':''}>Arsip</button> <button type="button" data-cash-draft="${safe(r.id)}" ${!Number(r.is_active)||r.price_mode!=='fixed'?'disabled':''}>Siapkan Log Kas</button>`}</td></tr>`).join(''):'<tr><td colspan="6">Belum ada item untuk kategori ini.</td></tr>'}</tbody></table></div>
      <div data-form-slot></div>
    </div>`;
    el.querySelector('[data-close]').addEventListener('click',close);
    el.querySelector('[data-filter]').addEventListener('change',e=>{state.selectedCategoryId=e.target.value;render();});
    el.querySelector('[data-new]')?.addEventListener('click',()=>formItem(null));
    el.querySelectorAll('[data-edit]').forEach(b=>b.addEventListener('click',()=>formItem(state.rows.find(r=>r.id===b.dataset.edit))));
    el.querySelectorAll('[data-rate]').forEach(b=>b.addEventListener('click',()=>formRate(state.rows.find(r=>r.id===b.dataset.rate))));
    el.querySelectorAll('[data-archive]').forEach(b=>b.addEventListener('click',()=>archiveItem(state.rows.find(r=>r.id===b.dataset.archive))));
    el.querySelectorAll('[data-cash-draft]').forEach(b=>b.addEventListener('click',()=>formCashDraft(state.rows.find(r=>r.id===b.dataset.cashDraft))));
  }
  async function reload(){
    const [master,cat]=await Promise.all([request('hotel-data'),request('catalog-items')]);
    state.categories=Array.isArray(master.categories)?master.categories:[];
    state.subcategories=Array.isArray(master.subcategoryCatalog)?master.subcategoryCatalog:[];
    state.rows=cat.data;state.readOnly=!!cat.readOnly||!['admin','manager'].includes(String(sessionStorage.getItem('hotel_role')||sessionStorage.getItem('hotel_staff_role')||'').toLowerCase());
    render();
  }
  function showForm(html,submit){
    const slot=root().querySelector('[data-form-slot]');
    slot.innerHTML=`<div class="tamasya-catalog-form"><form data-editor>${html}<div class="tamasya-catalog-actions"><button type="button" data-cancel>Batal</button><button type="submit">Simpan</button></div></form></div>`;
    const form=slot.querySelector('form');form.querySelector('[data-cancel]').addEventListener('click',()=>slot.replaceChildren());
    form.addEventListener('submit',async ev=>{ev.preventDefault();const btn=form.querySelector('[type=submit]');btn.disabled=true;
      try{const result=await submit(new FormData(form));if(result==='DRAFT_ONLY')return;await reload();info('Tersimpan. Perubahan master diaudit server.');}
      catch(e){info(e.message);btn.disabled=false;}
    });
    form.querySelector('input,select')?.focus();
  }
  function formItem(item){
    const cats=currentCategories();
    const catId=item?.category_id||state.selectedCategoryId||cats[0]?.id||'';
    const catOpts=cats.map(c=>`<option value="${safe(c.id)}" ${c.id===catId?'selected':''}>${safe(c.name)} (${safe(c.type)})</option>`).join('');
    const subOpts='<option value="">Tanpa subkategori</option>'+state.subcategories.filter(s=>s.categoryId===catId||s.category_id===catId).map(s=>`<option value="${safe(s.id)}" ${s.id===item?.subcategory_id?'selected':''}>${safe(s.name)}</option>`).join('');
    showForm(`<h2>${item?'Ubah':'Tambah'} Item</h2>
      <label>Nama bebas <input name="name" maxlength="150" required value="${safe(item?.name||'')}"></label>
      <label>Kategori <select name="categoryId" ${item?'disabled':''}>${catOpts}</select></label>
      <label>Subkategori <select name="subcategoryId" ${item?'disabled':''}>${subOpts}</select></label>
      <label>Satuan (misalnya jam/orang/unit) <input name="unit" maxlength="40" required value="${safe(item?.unit_label||'unit')}"></label>
      <label>Mode harga <select name="priceMode"><option value="fixed" ${item?.price_mode==='fixed'?'selected':''}>Tarif tetap</option><option value="manual" ${item?.price_mode==='manual'?'selected':''}>Harga manual</option></select></label>
      ${item?'':`<div data-initial-rate>
        <label>Nominal tarif standar (Rp) <input type="text" name="initialAmount" inputmode="decimal" required maxlength="18" pattern="[0-9]+(\\.[0-9]{1,2})?" placeholder="Contoh: 250000"></label>
        <label>Tarif berlaku mulai <input type="date" name="initialValidFrom" required value="${new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10)}"></label>
        <small>Masukkan nominal tanpa pemisah ribuan (contoh 250000 atau 250000.50). Tidak otomatis termasuk pajak.</small>
      </div>`}
      <label>Aktif <input type="checkbox" name="isActive" ${!item||Number(item.is_active)?'checked':''}></label>
      <p>${item?'Untuk mengubah harga, gunakan tombol Tarif di tabel supaya harga historis tetap tersimpan.':'Tarif tetap disimpan bersama item dalam satu operasi. Harga manual dimasukkan saat transaksi di Log Kas.'} Pajak tidak otomatis disamakan dengan PBJT kamar.</p>`,async fd=>{
        const priceMode=String(fd.get('priceMode')||'');
        const initialAmount=!item&&priceMode==='fixed'?String(fd.get('initialAmount')||'').trim():'';
        const initialValidFrom=!item&&priceMode==='fixed'?String(fd.get('initialValidFrom')||'').trim():'';
        if(!item&&priceMode==='fixed'&&(!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(initialAmount)||Number(initialAmount)<=0))
          throw new Error('Nominal tarif wajib positif, tanpa titik pemisah ribuan. Contoh: 250000');
        if(!item&&priceMode==='fixed'&&!fd.has('isActive'))
          throw new Error('Item tarif tetap baru harus aktif saat memasukkan tarif awal.');
        await request('catalog-item-save',{
          id:item?.id||'',revision:Number(item?.revision||0),name:fd.get('name'),categoryId:item?.category_id||fd.get('categoryId'),
          subcategoryId:item?.subcategory_id||fd.get('subcategoryId')||'',unit:fd.get('unit'),priceMode,
          isActive:fd.has('isActive'),
          ...(!item&&priceMode==='fixed'?{initialAmount,initialValidFrom}:{})
        });
      });
    const modeSelector=root().querySelector('[data-editor] select[name="priceMode"]');
    const initialRate=root().querySelector('[data-initial-rate]');
    if(modeSelector&&initialRate){
      const toggleRate=()=>{const fixed=modeSelector.value==='fixed';initialRate.hidden=!fixed;
        initialRate.querySelectorAll('input').forEach(input=>{input.disabled=!fixed;input.required=fixed;});};
      modeSelector.addEventListener('change',toggleRate);
      toggleRate();
    }
    if(!item){root().querySelector('[name=categoryId]').addEventListener('change',e=>{
      const sub=root().querySelector('[name=subcategoryId]');
      sub.innerHTML='<option value="">Tanpa subkategori</option>'+state.subcategories.filter(s=>s.categoryId===e.target.value||s.category_id===e.target.value).map(s=>`<option value="${safe(s.id)}">${safe(s.name)}</option>`).join('');
    });}
  }
  function formRate(item){
    showForm(`<h2>Tarif baru: ${safe(item.name)}</h2><p>Harga lama tetap disimpan; satu tanggal berlaku hanya dapat mempunyai satu tarif.</p>
      <label>Tarif standar (Rp) <input name="amount" required inputmode="decimal" placeholder="250000.00" pattern="[0-9]+(\\.[0-9]{1,2})?"></label>
      <label>Berlaku mulai <input type="date" name="validFrom" required value="${new Date().toISOString().slice(0,10)}"></label>`,async fd=>{
      await request('catalog-rate-save',{itemId:item.id,amount:fd.get('amount'),validFrom:fd.get('validFrom')});
    });
  }
  // This contract only populates the existing cash-entry form. It is NOT a
  // financial posting API and does not calculate or waive any tax.
  function prepareCashDraft(response, quantity, serviceDate){
    if(!response || response.success!==true || response.postingStatus!=='DRAFT_ONLY' || response.taxStatus!=='NOT_CALCULATED')
      throw new Error('Kutipan master tarif tidak sah.');
    const snapshot=response.itemSnapshot||{}, quote=response.quote||{};
    if(!response.cashDraftEligible || !Number.isSafeInteger(quote.subtotalCents) || quote.subtotalCents<=0 || quote.subtotalCents%100!==0)
      throw new Error('Nominal memiliki pecahan rupiah. Log Kas kanonis saat ini membulatkan ke rupiah utuh; draft diblokir supaya pembukuan tidak selisih.');
    if(!Number.isSafeInteger(snapshot.itemRevision) || snapshot.itemRevision<1)throw new Error('Revisi master tarif tidak tersedia. Refresh daftar.');
    if(!['income','expense'].includes(snapshot.categoryType) || !snapshot.categoryId || !snapshot.rateId || !snapshot.itemId)
      throw new Error('Identitas master tarif atau kategori tidak lengkap.');
    if(!/^\d{4}-\d{2}-\d{2}$/.test(serviceDate))throw new Error('Tanggal layanan tidak valid.');
    if(!/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?$/.test(quantity)||Number(quantity)<=0)
      throw new Error('Kuantitas tidak valid.');
    return {
      schema:'tamasya-catalog-logkas-draft-v1',
      categoryId:String(snapshot.categoryId),categoryName:String(snapshot.categoryName||''),
      categoryType:snapshot.categoryType,
      subcategoryId:snapshot.subcategoryId==null?'':String(snapshot.subcategoryId),
      subcategoryName:String(snapshot.subcategoryName||''),
      itemId:String(snapshot.itemId),itemRevision:snapshot.itemRevision,rateId:String(snapshot.rateId),quantity:String(quantity),
      subtotalCents:quote.subtotalCents,date:serviceDate,
      description:`Item: ${snapshot.itemName} | ${quantity} ${snapshot.unit} × Rp${snapshot.unitPrice} | Tarif ${snapshot.validFrom} | Ref katalog ${snapshot.itemId}/${snapshot.rateId}`,
      taxStatus:'NOT_CALCULATED',postingStatus:'DRAFT_ONLY'
    };
  }
  function formCashDraft(item){
    if(!item || Number(item.is_active)!==1 || item.price_mode!=='fixed') {info('Hanya item aktif dengan tarif tetap yang bisa disiapkan otomatis. Harga manual tetap dicatat melalui Log Kas biasa.');return;}
    showForm(`<h2>Siapkan Draft Log Kas — ${safe(item.name)}</h2>
      <p>Ini hanya mengisi formulir, BUKAN transaksi. Konfirmasi pajak dan rekening pembayaran wajib di Log Kas.</p>
      <label>Kuantitas <input name="quantity" required inputmode="decimal" value="1" pattern="[0-9]+(\\.[0-9]{1,3})?"></label>
      <label>Tanggal layanan <input name="date" type="date" required value="${new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10)}"></label>`,async fd=>{
        const quantity=String(fd.get('quantity')||'').trim(), serviceDate=String(fd.get('date')||'').trim();
        const result=await request('catalog-quote',null,{itemId:item.id,quantity,date:serviceDate});
        const draft=prepareCashDraft(result,quantity,serviceDate);
        if(!document.getElementById('cash-entry-form')) throw new Error('Buka modul Log Kas terlebih dahulu sebelum menyiapkan draft.');
        close();window.TAMASYA_MASTER_DATA_WORKSPACE?.close();
        window.dispatchEvent(new CustomEvent('tamasya:catalog-finance-draft',{detail:draft}));
        return 'DRAFT_ONLY';
      });
    const submit=root().querySelector('[data-editor] [type=submit]');if(submit)submit.textContent='Isi Draft Log Kas';
  }
  async function archiveItem(item){
    if(!window.confirm('Arsipkan '+item.name+'? Tarif historis tetap tersimpan.'))return;
    try{await request('catalog-item-archive',{id:item.id,revision:Number(item.revision)});await reload();info('Item berhasil diarsipkan.');}
    catch(e){info(e.message);}
  }
  async function open(categoryId=''){
    const el=root();el.hidden=false;state.open=true;state.selectedCategoryId=String(categoryId||'');
    el.textContent='Membuka Master Item & Tarif…';
    try{await reload();}catch(e){el.innerHTML='<div class="tamasya-catalog-shell"><h2>Master Item & Tarif belum tersedia</h2><p data-error></p><button type="button" data-close>Kembali</button></div>';
      el.querySelector('[data-error]').textContent=e.message;el.querySelector('[data-close]').addEventListener('click',close);}
  }
  function close(){state.open=false;root().hidden=true;}
  window.TAMASYA_UNIVERSAL_CATALOG=Object.freeze({open,close,prepareCashDraft});
})();
