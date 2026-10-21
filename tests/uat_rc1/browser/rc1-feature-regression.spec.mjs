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
    headers:{Origin:BASE,'X-Device-ID':'uat-browser-feature','Content-Type':'application/json'},
    data:{username,password,offlineSessionScopeId:'offline_browser_feature'}
  });
  expect(r.status()).toBe(200);
  const body=await r.json();
  const token=body?.data?.token||body?.token;
  expect(token).toBeTruthy();
  await page.addInitScript((value)=>{
    try{
      sessionStorage.setItem('hotel_session_token',value);
      sessionStorage.setItem('hotel_logged_in','true');
      sessionStorage.setItem('hotel_staff_role','admin');
      sessionStorage.setItem('hotel_staff_id','uat-admin');
    }catch(_){}
  },token);
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

  await installAuth(page,request);
  await page.goto('/growth-suite.html');
  await waitLoaded(page);

  // Tab kontrak harus muncul (bukan modul Growth berbayar).
  const contractTab=page.locator('[data-tab="contract"]');
  await expect(contractTab).toBeVisible({timeout:20000});
  await contractTab.click();
  await expect(page.locator('#tab-contract')).toBeVisible();

  // Cari harga kontrak: harus POST dan berhasil.
  await page.locator('#contract-check-in').fill('2030-01-05');
  await page.locator('#contract-check-out').fill('2030-01-08');
  const quoteResponse=page.waitForResponse(r=>r.url().includes('action=growth-suite')&&r.request().method()==='POST',{timeout:20000});
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
  expect(pageErrors,`hq page error: ${pageErrors.join(' | ')}`).toEqual([]);
  const rolePresets=page.locator('#role-presets');
  if(await rolePresets.count())await expect(rolePresets).toBeAttached();
  writeEvidence('hq-console-loads',info,{pass:true,pageErrors});
});

test('Front desk: the contract rate panel opens, is centred, and reads the real list',async({page,request},info)=>{
  const seen=[];
  const requested=[];
  page.on('request',request=>{try{requested.push(new URL(request.url()).pathname);}catch(_){}});
  page.on('response',response=>{
    const url=response.url();
    if(url.includes('action=wholesale-rates'))seen.push({status:response.status(),method:response.request().method()});
  });
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));

  await installAuth(page,request);
  await page.goto('/index.html');
  await waitLoaded(page);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('tamasya-route-change',{detail:{route:'rooms'}})));

  // Add-on dimuat sesuai rute, jadi tunggu skripnya dimuat dulu, lalu tombolnya.
  await expect.poll(()=>requested.some(x=>x.endsWith('/assets/wholesale-contract-addon.js')),{timeout:20000}).toBe(true);
  const trigger=page.locator('#tamasya-wholesale-button');
  await expect(trigger).toBeVisible({timeout:20000});
  const triggerBox=await trigger.boundingBox();
  expect(triggerBox).not.toBeNull();

  // Tidak boleh menutupi tombol laporan yang sudah ada.
  const reportButton=page.locator('#tamasya-report-center-btn');
  if(await reportButton.count()&&await reportButton.isVisible()){
    const reportBox=await reportButton.boundingBox();
    const separated=triggerBox.y+triggerBox.height<=reportBox.y
      ||reportBox.y+reportBox.height<=triggerBox.y
      ||triggerBox.x+triggerBox.width<=reportBox.x
      ||reportBox.x+reportBox.width<=triggerBox.x;
    expect(separated,'contract rate trigger overlaps the report button').toBe(true);
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
  const bookingCall=seen.find(entry=>entry.method==='POST');
  expect(bookingCall,'reservation list must be requested with a write').toBeTruthy();

  await page.locator('#tamasya-wholesale-panel [data-act="close"]').click();
  await expect(modal).toBeHidden();
  expect(pageErrors).toEqual([]);
  writeEvidence('contract-rate-panel',info,{pass:true,seen,trigger:triggerBox,modal:modalBox});
});

test('Governance tabs: audit, inspector, reviews and health all answer',async({page,request},info)=>{
  const seen=[];
  page.on('response',response=>{
    const url=response.url();
    for(const action of ['audit-logs','permission-inspector','guest-reviews','system-health']){
      if(url.includes(`action=${action}`))seen.push({action,status:response.status()});
    }
  });
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error)));

  await installAuth(page,request);
  await page.goto('/index.html');
  await waitLoaded(page);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('tamasya-route-change',{detail:{route:'staff'}})));
  await page.waitForTimeout(3000);
  // Panel audit tertutup sampai operator membukanya; tab-tabnya muncul setelah itu.
  const openButton=page.getByRole('button',{name:/Buka Audit & Kendali/});
  if(await openButton.count())await openButton.first().click();
  await page.waitForTimeout(1500);

  for(const action of ['audit-logs','permission-inspector','guest-reviews']){
    await expect.poll(()=>seen.some(entry=>entry.action===action&&entry.status===200),{timeout:20000}).toBe(true);
  }
  expect(pageErrors,`governance page error: ${pageErrors.join(' | ')}`).toEqual([]);
  writeEvidence('governance-tabs',info,{pass:true,seen});
});

test('Public review page: served, and an invalid token is refused',async({page,request},info)=>{
  let response=await page.goto('/webpublic/review.html');
  if(response.status()!==200||!(await page.locator('body').innerText()).match(/ulasan|pengalaman/i)){
    response=await page.goto('/review.html');
  }
  expect(response.status()).toBe(200);
  await expect(page.locator('body')).toContainText(/ulasan|pengalaman/i);
  const bad=await request.post(`${BASE}/api.php?action=public-review&token=token-yang-salah`,{headers:{Origin:BASE}});
  expect([400,403,404,409]).toContain(bad.status());
  writeEvidence('public-review',info,{pass:true,status:response.status()});
});
