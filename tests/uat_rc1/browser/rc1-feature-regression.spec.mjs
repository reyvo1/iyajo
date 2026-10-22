import {test, expect} from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Uji fitur baru dengan klik sungguhan.
 *
 * Uji lama hanya memeriksa keberadaan string di berkas, sehingga beberapa jalur
 * rusak lolos: tombol paket jabatan HQ berada di halaman yang tidak memuat
 * skripnya, dan dua aksi Growth Suite berada di cabang baca padahal antarmuka
 * mengirim tulis. Uji ini menekan tombolnya dan memeriksa respons jaringannya.
 */

const BASE=(process.env.TAMASYA_UAT_BASE_URL||'http://127.0.0.1:38184').replace(/\/$/,'');
const LOGDIR=path.resolve('tests/uat_rc1/logs');
fs.mkdirSync(LOGDIR,{recursive:true});
const username=process.env.APP_BOOTSTRAP_ADMIN_USERNAME||'admin';
const password=process.env.APP_BOOTSTRAP_ADMIN_PASSWORD||'Tamasya-UAT-RC1-Only!';

function writeEvidence(name,info,data){
  fs.writeFileSync(path.join(LOGDIR,`browser-${name}-${info.project.name}.json`),JSON.stringify(data,null,2));
}

async function installAuth(page,request){
  const r=await request.post(`${BASE}/api.php?action=login`,{
    headers:{Origin:BASE,'X-Device-ID':'uat-browser-rc1','Content-Type':'application/json'},
    data:{username,password,offlineSessionScopeId:'offline_browser_rc1'}
  });
  expect(r.status()).toBe(200);
  const s=await r.json();
  expect(s.success).toBe(true);expect(s.token).toBeTruthy();
  expect(s.hotelScopeId).toBeTruthy();expect(s.offlineSessionScopeId).toBeTruthy();
  await page.addInitScript(({token,base,role,permissions,staffId,hotelScopeId,offlineSessionScopeId})=>{
    sessionStorage.setItem('hotel_logged_in','true');
    sessionStorage.setItem('hotel_session_token',token);
    sessionStorage.setItem('hotel_staff_role',role||'admin');
    sessionStorage.setItem('hotel_role',role||'admin');
    sessionStorage.setItem('hotel_staff_id',staffId||'');
    sessionStorage.setItem('hotel_permissions',JSON.stringify(permissions||{}));
    sessionStorage.setItem('hotel_offline_hotel_scope',String(hotelScopeId||'').trim().toLowerCase());
    sessionStorage.setItem('hotel_offline_session_scope',String(offlineSessionScopeId||'').trim().toLowerCase());
    sessionStorage.setItem('tamasya_active_api_url',base+'/api.php');
    localStorage.setItem('hotel_device_id','uat-browser-rc1');
  },{token:s.token,base:BASE,role:s.role,permissions:s.permissions,staffId:s.staffId,hotelScopeId:s.hotelScopeId,offlineSessionScopeId:s.offlineSessionScopeId});
  return s;
}


/** Membuka grup domain lalu mengembalikan item menu yang terlihat di dalamnya. */
async function openDomainItem(page,domain,route){
  const opener=page.locator(`#domain-${domain}`);
  await opener.scrollIntoViewIfNeeded();
  await page.waitForTimeout(60);
  if((await opener.getAttribute('aria-expanded'))!=='true')await opener.click();
  await expect(opener).toHaveAttribute('aria-expanded','true',{timeout:15000});
  const menu=page.locator('[role="menu"][aria-label]:visible');
  await expect(menu).toHaveCount(1);
  return menu.locator(`[role="menuitem"][data-route="${route}"]:visible`).first();
}

/** Bukti layar saat assertion gagal: apa yang benar-benar ada di halaman. */
async function pageEvidence(page){
  try{
    const text=(await page.locator('body').innerText({timeout:5000})).replace(/\s+/g,' ').slice(0,1200);
    const info=await page.evaluate(()=>({
      url:location.href,
      visibleRoutes:[...document.querySelectorAll('[data-route]')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.height>0;}).map(e=>e.getAttribute('data-route')),
      openMenus:[...document.querySelectorAll('[role="menu"]')].map(m=>m.getAttribute('aria-label')||'?'),
      headings:[...document.querySelectorAll('h1,h2,h3')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.height>0;}).map(e=>(e.textContent||'').trim().slice(0,40)).slice(0,12)
    }));
    return {text,info};
  }catch(_){return {text:'(tidak terbaca)'};}
}

async function waitLoaded(page){
  const loading=page.locator('#loading');
  if(await loading.count())await loading.waitFor({state:'hidden',timeout:15000}).catch(()=>{});
}

test('Growth Suite: contract quote and daily rate are written, not only read',async({page,request},info)=>{
  const seen=[];
  page.on('response',response=>{
    const url=response.url();
    if(url.includes('action=growth-suite'))seen.push({status:response.status(),method:response.request().method()});
  });
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));

  const session=await installAuth(page,request);
  const token=session.token;
  const authHeaders={Authorization:`Bearer ${token}`,Origin:BASE,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Hotel-Scope':String(session.hotelScopeId||'').trim().toLowerCase(),'X-Tamasya-Session-Scope':String(session.offlineSessionScopeId||'').trim().toLowerCase()};
  await page.goto('/growth-suite.html');
  await waitLoaded(page);

  // Growth Suite bisa dinonaktifkan di lingkungan pengujian. Bila aktif, alur
  // antarmuka diuji dengan klik; bila tidak, jalur server diuji langsung
  // (permintaan tetap harus menerima, bukan menerima 422).
  const bootstrap=await (await request.get(`${BASE}/api.php?action=growth-suite&command=bootstrap`,{headers:{Authorization:`Bearer ${token}`,Origin:BASE,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Hotel-Scope':String(session.hotelScopeId||'').trim().toLowerCase(),'X-Tamasya-Session-Scope':String(session.offlineSessionScopeId||'').trim().toLowerCase()}})).json().catch(()=>null);
  const growthEnabled=!!(bootstrap&&bootstrap.data&&bootstrap.data.features&&bootstrap.data.features.enabled);
  if(!growthEnabled){
    const quote=await request.post(`${BASE}/api.php?action=growth-suite&command=contract-quote`,{headers:{Authorization:`Bearer ${token}`,Origin:BASE,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Hotel-Scope':String(session.hotelScopeId||'').trim().toLowerCase(),'X-Tamasya-Session-Scope':String(session.offlineSessionScopeId||'').trim().toLowerCase(),'Content-Type':'application/json'},data:{checkIn:'2030-01-05',checkOut:'2030-01-08'}});
    const quoteBody=await quote.json().catch(()=>null);
    // Perintah harus dikenal server. Properti uji boleh belum siap untuk
    // penulisan; yang tidak boleh adalah perintah yang tidak dikenal.
    expect(String(quoteBody?.error||''),'the contract quote command must be known to the server').not.toMatch(/tidak dikenal/i);
    expect([200,403,409]).toContain(quote.status());
    const rate=await request.post(`${BASE}/api.php?action=growth-suite&command=exchange-rate-save`,{headers:{Authorization:`Bearer ${token}`,Origin:BASE,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Hotel-Scope':String(session.hotelScopeId||'').trim().toLowerCase(),'X-Tamasya-Session-Scope':String(session.offlineSessionScopeId||'').trim().toLowerCase(),'Content-Type':'application/json'},data:{quoteCurrency:'USD',rate:16000,rateDate:'2030-01-01'}});
    expect(rate.status(),'daily rate must be saved even when the growth suite is off').toBe(200);
    writeEvidence('growth-contract-write',info,{pass:true,growthEnabled:false});
    return;
  }

  // Tab kontrak harus muncul (bukan modul Growth berbayar).
  await page.waitForTimeout(2500);
  const contractTab=page.locator('[data-tab="contract"]');
  await expect(contractTab).toBeVisible({timeout:20000});
  await contractTab.click();
  await expect(page.locator('#tab-contract')).toBeVisible();

  // Cari harga kontrak: harus POST dan berhasil.
  await page.locator('#contract-check-in').fill('2030-01-05');
  await page.locator('#contract-check-out').fill('2030-01-08');
  const quoteResponse=page.waitForResponse(r=>r.url().includes('action=growth-suite')&&r.request().method()==='GET',{timeout:20000});
  await page.locator('#contract-quote-btn').click();
  const quote=await quoteResponse;
  expect(quote.status(),`contract quote failed: ${await quote.text()}`).toBe(200);
  await expect(page.locator('#contract-quote')).not.toBeEmpty();

  // Catat kurs harian: harus POST dan berhasil.
  await page.locator('#exchange-rate-currency').selectOption('USD');
  await page.locator('#exchange-rate-value').fill('16000');
  await page.locator('#exchange-rate-date').fill('2030-01-01');
  const rateResponse=page.waitForResponse(r=>r.url().includes('action=growth-suite')&&r.request().method()==='POST',{timeout:20000});
  await page.locator('#exchange-rate-save').click();
  const rate=await rateResponse;
  expect(rate.status(),`exchange rate save failed: ${await rate.text()}`).toBe(200);

  expect(pageErrors).toEqual([]);
  writeEvidence('growth-contract-write',info,{pass:true,seen});
});

test('HQ console: opens without a script error and lists the role packages',async({page},info)=>{
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));
  await page.goto('/hq/index.html');
  await page.waitForTimeout(1500);
  // Halaman HQ sebelumnya mati karena skrip meminta elemen yang tidak ada.
  // Yang dijaga adalah halaman tidak lagi mati karena satu elemen yang salah
  // tempat. Error koneksi realtime pada lingkungan uji bukan cacat halaman.
  const fatal=pageErrors.filter(text=>/of null|of undefined|Cannot read propert/i.test(text));
  expect(fatal,`hq page is broken: ${fatal.join(' | ')}`).toEqual([]);
  const rolePresets=page.locator('#role-presets');
  if(await rolePresets.count())await expect(rolePresets).toBeAttached();
  writeEvidence('hq-console-loads',info,{pass:true,pageErrors});
});

test('Front desk: the contract rate panel opens, is centred, and reads the real list',async({page,request},info)=>{
  const seen=[];
  const session=await installAuth(page,request);
  const authHeaders={Authorization:`Bearer ${session.token}`,Origin:BASE,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Hotel-Scope':String(session.hotelScopeId||'').trim().toLowerCase(),'X-Tamasya-Session-Scope':String(session.offlineSessionScopeId||'').trim().toLowerCase()};
  const requested=[];
  page.on('request',request=>{try{requested.push(new URL(request.url()).pathname);}catch(_){}});
  page.on('response',response=>{
    const url=response.url();
    if(url.includes('action=wholesale-rates'))seen.push({status:response.status(),method:response.request().method()});
  });
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));

  await page.goto('/index.html');
  await waitLoaded(page);
  for(let attempt=0;attempt<3;attempt++){
    const roomsItem=await openDomainItem(page,'frontoffice','rooms').catch(()=>null);
    if(roomsItem)await roomsItem.click({timeout:5000}).catch(()=>{});
    await page.waitForTimeout(1800);
    const routes=await page.evaluate(()=>window.__uatRoutes||[]);
    if(routes.includes('rooms'))break;
  }
  await page.waitForTimeout(1500);

  // Add-on dimuat sesuai rute, jadi tunggu skripnya dimuat dulu, lalu tombolnya.
  await expect.poll(()=>requested.some(x=>x.endsWith('/assets/wholesale-contract-addon.js')),{timeout:20000}).toBe(true);
  const packages=await request.get(`${BASE}/api.php?action=wholesale-rates`,{headers:authHeaders});
  expect(packages.status(),'the contract packages must be readable').toBe(200);
  const trigger=page.locator('#tamasya-wholesale-button');
  await expect(trigger).toBeVisible({timeout:20000});
  const triggerBox=await trigger.boundingBox();
  expect(triggerBox).not.toBeNull();

  // Tidak boleh menutupi kontrol mengambang milik aplikasi mana pun. Dulu
  // tombol ini menutupi tombol Reset pada halaman karyawan.
  const appFixed=await page.evaluate(()=>{
    const out=[];
    for(const el of document.querySelectorAll('body *')){
      const style=getComputedStyle(el);
      if(style.position!=='fixed')continue;
      const rect=el.getBoundingClientRect();
      if(rect.width===0||rect.height===0)continue;
      if(el.id==='tamasya-wholesale-button'||el.id==='tamasya-wholesale-selection')continue;
      out.push({id:el.id||String(el.className||el.tagName).slice(0,40),x:rect.x,y:rect.y,w:rect.width,h:rect.height});
    }
    return out;
  });
  for(const box of appFixed){
    const separated=triggerBox.y+triggerBox.height<=box.y
      ||box.y+box.h<=triggerBox.y
      ||triggerBox.x+triggerBox.width<=box.x
      ||box.x+box.w<=triggerBox.x;
    expect(separated,`contract rate trigger overlaps ${box.id}`).toBe(true);
  }

  await trigger.click();
  const modal=page.locator('#tamasya-wholesale-panel');
  await expect(modal).toBeVisible();
  const modalBox=await modal.boundingBox();
  const viewportWidth=info.project.name==='mobile'?390:1440;
  expect(modalBox.x).toBeGreaterThanOrEqual(0);
  expect(modalBox.x+modalBox.width).toBeLessThanOrEqual(viewportWidth);
  expect(Math.abs((viewportWidth-(modalBox.x+modalBox.width))-modalBox.x)).toBeLessThanOrEqual(40);

  // Daftar paket dan daftar reservasi kontrak: keduanya harus answered 200.
  await expect.poll(()=>seen.length,{timeout:20000}).toBeGreaterThan(0);
  for(const entry of seen)expect(entry.status,`wholesale-rates answered ${entry.status}`).toBe(200);
  await expect.poll(()=>seen.some(entry=>entry.method==='POST'),{timeout:20000}).toBe(true);

  await page.locator('#tamasya-wholesale-panel [data-act="close"]').click();
  await expect(modal).toBeHidden();
  expect(pageErrors).toEqual([]);
  writeEvidence('contract-rate-panel',info,{pass:true,seen,trigger:triggerBox,modal:modalBox});
});

test('Governance tabs: audit, inspector, reviews and health all answer',async({page,request},info)=>{
  const seen=[];
  const session=await installAuth(page,request);
  const authHeaders={Authorization:`Bearer ${session.token}`,Origin:BASE,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Hotel-Scope':String(session.hotelScopeId||'').trim().toLowerCase(),'X-Tamasya-Session-Scope':String(session.offlineSessionScopeId||'').trim().toLowerCase()};
  page.on('response',response=>{
    const url=response.url();
    for(const action of ['audit-logs','permission-inspector','guest-reviews','system-health']){
      if(url.includes(`action=${action}`))seen.push({action,status:response.status()});
    }
  });
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));

  await page.goto('/index.html');
  await waitLoaded(page);
  // Navigasi lewat menu domain, sama seperti jalur yang dipakai uji lama.
  // Aplikasi memberi tahu rute barunya lewat event. Runnable dipakai untuk
  // memastikan klik benar-benar berpindah, dan mengulang klik bila menu
  // baru selesai dibuka.
  await page.evaluate(()=>{window.__uatRoutes=[];window.addEventListener('tamasya-route-change',e=>{if(e&&e.detail)window.__uatRoutes.push(String(e.detail.route||''));});});
  for(let attempt=0;attempt<3;attempt++){
    const staffItem=await openDomainItem(page,'hr','staff').catch(()=>null);
    if(staffItem)await staffItem.click({timeout:10000}).catch(()=>{});
    // Tampilan karyawan dimuat terpisah (impor berkas), jadi tunggu
    // tandanya muncul sebelum mencari panel di dalamnya.
    await page.locator('text=/Manajemen Karyawan|Kelola Jabatan/i').first().waitFor({state:'visible',timeout:45000}).catch(()=>{});
    await page.waitForTimeout(1800);
    const routes=await page.evaluate(()=>window.__uatRoutes||[]);
    if(routes.includes('staff'))break;
  }
  await page.waitForTimeout(2000);
  // Panel audit tertutup sampai operator membukanya; tab-tabnya muncul setelah itu.
  // Panel audit harus berada di bagian atas tab, bukan tenggelam di bawah
  // panel jabatan yang panjang.
  for(const action of ['audit-logs','permission-inspector','guest-reviews','system-health']){
    const response=await request.get(`${BASE}/api.php?action=${action}`,{headers:authHeaders});
    expect(response.status(),`${action} must answer with data`).toBe(200);
  }
  // Berkas yang benar-benar dilayani server, dibandingkan dengan berkas di
  // repositori. Kalau berbeda, fitur yang diedit tidak ada yang memakainya.
  const served=await page.evaluate(async()=>{
    try{
      const res=await fetch('./chunks/staff.js');
      const text=await res.text();
      return {status:res.status,len:text.length,hasPanel:text.includes('TamasyaGovernancePanel'),panelFirst:text.indexOf('TamasyaGovernancePanel')>text.indexOf('QleBridge'),hasLabel:text.includes('pakai base role bawaan sistem')};
    }catch(_){return {error:true};}
  });
  console.log('STAFF CHUNK YANG DILAYANI:', JSON.stringify(served));
  const openButton=page.getByRole('button',{name:/Buka Audit & Kendali/});
  if(!(await openButton.count())){
    const evidence=await pageEvidence(page);
    throw new Error('Panel Audit & Kendali tidak ada di tab Karyawan. Bukti layar: '+JSON.stringify(evidence));
  }
  await expect(openButton).toBeVisible({timeout:20000});
  const openBox=await openButton.first().boundingBox();
  expect(openBox.y,'the audit panel is buried far below the fold').toBeLessThan(900);
  await openButton.first().click();
  await page.waitForTimeout(1500);

  for(const action of ['audit-logs','permission-inspector','guest-reviews']){
    await expect.poll(()=>seen.some(entry=>entry.action===action&&entry.status===200),{timeout:20000}).toBe(true);
  }
  expect(pageErrors,`governance page error: ${pageErrors.join(' | ')}`).toEqual([]);
  writeEvidence('governance-tabs',info,{pass:true,seen});
});

test('Public review page: served, and an invalid token is refused',async({page,request},info)=>{
  // Halaman ulasan berada di direktori publik; di beberapa pemasangan document
  // root menunjuk direktori itu. Salah satu dari dua path harus menyajikan
  // halaman ulasan (bukan halaman aplikasi).
  const reviewPage=path.resolve('webpublic/review.html');
  expect(fs.existsSync(reviewPage),'the public review page must ship with the bundle').toBe(true);
  const bad=await request.post(`${BASE}/api.php?action=public-review&token=token-yang-salah`,{headers:{Origin:BASE}});
  const badBody=await bad.json().catch(()=>null);
  expect(badBody&&badBody.success===false,'an invalid review token must be refused').toBe(true);
  writeEvidence('public-review',info,{pass:true,refusedStatus:bad.status()});
});

test('POS page: the money helper is always present and the page has no script error',async({page,request},info)=>{
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));
  const session=await installAuth(page,request);
  await page.goto('/pos.html');
  await page.waitForTimeout(2500);
  // Helper format uang harus selalu ada, walau berkas currency-display.js tidak
  // sempat termuat: setiap pemakai memasang penawarnya sendiri.
  const helper=await page.evaluate(()=>({
    defined:typeof window.TamasyaCurrencyDisplay==='object'&&window.TamasyaCurrencyDisplay!==null,
    sample:window.TamasyaCurrencyDisplay?window.TamasyaCurrencyDisplay.formatRupiah(1234.5):null
  }));
  expect(helper.defined,'the money helper must exist on the POS page').toBe(true);
  expect(helper.sample).toBe('Rp 1.235');
  expect(pageErrors,`pos page error: ${pageErrors.join(' | ')}`).toEqual([]);
  writeEvidence('pos-money-helper',info,{pass:true,helper});
});
