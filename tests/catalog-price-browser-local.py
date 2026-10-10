"""Local read/write mocked browser simulation; no production network or DB."""
from pathlib import Path
from playwright.sync_api import sync_playwright
from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
from threading import Thread
class Handler(BaseHTTPRequestHandler):
 def do_GET(self):
  self.send_response(200);self.send_header("Content-Type","text/html");self.end_headers();self.wfile.write(b"<!doctype html><html><body></body></html>")
 def log_message(self,*args):pass
server=ThreadingHTTPServer(("127.0.0.1",0),Handler)
Thread(target=server.serve_forever,daemon=True).start()
p=Path(__file__).resolve().parents[1]/'assets/universal-catalog-workspace.js'
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
 page=b.new_page()
 page.set_content('<!doctype html><html><body></body></html>')
 page.evaluate('''() => {
  Object.defineProperty(window,'sessionStorage',{configurable:true,value:{getItem:(k)=>k==='hotel_role'?'admin':''}});
  Object.defineProperty(window,'crypto',{configurable:true,value:{randomUUID:()=> 'c86e9851-525b-4535-a3ea-02e4bd6d1815'}});
  window.__catalogCalls=[];
  window.__rows=[];
  window.fetch=async (path,opts={})=>{
    const action=decodeURIComponent(path.split('action=')[1].split('&')[0]);
    let payload;
    if(action==='hotel-data')payload={categories:[{id:'cat-1',name:'Kategori Bebas',type:'income',isActive:1,systemKey:''}],subcategoryCatalog:[]};
    else if(action==='catalog-items')payload={success:true,data:window.__rows,readOnly:false};
    else if(action==='catalog-item-save'){
      const req=JSON.parse(opts.body);window.__catalogCalls.push(req);
      window.__rows.push({id:'item-'+window.__rows.length,category_id:req.categoryId,category_name:'Kategori Bebas',name:req.name,subcategory_name:'',unit_label:req.unit,price_mode:req.priceMode,current_rate:req.initialAmount||null,is_active:1,revision:1});
      payload={success:true,id:'item-'+window.__rows.length,initialRateId:req.initialAmount?'rate1':null};
    }else throw new Error('unexpected action '+action);
    return {ok:true,status:200,json:async()=>payload};
  };
}''')
 page.add_script_tag(path=str(p))
 page.evaluate('window.TAMASYA_UNIVERSAL_CATALOG.open()')
 page.locator('[data-new]').click()
 assert page.locator('[name=initialAmount]').is_visible()
 assert page.locator('[name=initialValidFrom]').is_visible()
 print('PASS: Create form immediately displays nominal price and effective date')
 page.locator('[name=name]').fill('Layanan Bebas')
 page.locator('[name=initialAmount]').fill('250000')
 page.locator('form[data-editor] button[type=submit]').click()
 page.locator('tbody tr').first.wait_for()
 calls=page.evaluate('window.__catalogCalls')
 print('DEBUG calls',calls,'info',page.locator('[data-info]').inner_text())
 assert len(calls)==1 and calls[0]['initialAmount']=='250000' and calls[0]['priceMode']=='fixed' and calls[0]['initialValidFrom']
 print('PASS: Fixed price saved together with item in one API call')
 page.locator('[data-new]').click()
 page.locator('[name=priceMode]').select_option('manual')
 assert page.locator('[name=initialAmount]').is_hidden()
 assert page.locator('[name=initialAmount]').is_disabled()
 page.locator('[name=name]').fill('Layanan Fleksibel')
 page.locator('form[data-editor] button[type=submit]').click()
 page.locator('tbody tr').nth(1).wait_for()
 calls=page.evaluate('window.__catalogCalls')
 assert len(calls)==2 and calls[1]['priceMode']=='manual' and 'initialAmount' not in calls[1]
 print('PASS: Manual price does not require or transmit initial price')
 page.locator('[data-new]').click()
 page.locator('[name=name]').fill('Layanan salah input')
 page.locator('[name=initialAmount]').fill('250.000')
 assert not page.locator('[name=initialAmount]').evaluate('(el)=>el.validity.valid')
 assert len(page.evaluate('window.__catalogCalls'))==2
 print('PASS: Ambiguous price with thousands separator rejected by HTML validation')
 b.close()
 print('BROWSER: 4/4 PASS, 0 FAIL')
server.shutdown()
