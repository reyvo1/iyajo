import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const root=new URL('../',import.meta.url);
const read=file=>readFileSync(new URL(file,root),'utf8');
const window={};
vm.runInNewContext(read('assets/universal-catalog-workspace.js'),{window,document:{},location:{pathname:'/index.html'},sessionStorage:{},URLSearchParams,console});
const make=window.TAMASYA_UNIVERSAL_CATALOG.prepareCashDraft;
let pass=0;
function ok(test,name){assert.ok(test,name);pass++;console.log('PASS '+name);}
const quote={success:true,postingStatus:'DRAFT_ONLY',taxStatus:'NOT_CALCULATED',cashDraftEligible:true,
 itemSnapshot:{itemRevision:1,itemId:'item-x',rateId:'rate-v2',itemName:'Layanan universal',unit:'unit',categoryId:'cat-x',categoryName:'Pemasukan Bebas',categoryType:'income',subcategoryId:'sub-x',subcategoryName:'Satuan',validFrom:'2026-10-09',unitPrice:'275000.00'},
 quote:{subtotalCents:55000000}};
const draft=make(quote,'2','2026-10-09');
ok(draft.quantity==='2','Exact quantity is preserved for server revalidation');
ok(draft.itemId==='item-x' && draft.rateId==='rate-v2','Item and immutable rate identities retained');
ok(draft.date==='2026-10-09','Service date authoritative');
ok(draft.subtotalCents===55000000,'Whole-rupiah total sent without fractional truncation');
const finance=read('assets/chunks/finance.js'),shell=read('assets/chunks/app-shell.js'),core=read('assets/app-core.js'),index=read('index.html');
ok(finance.includes('catalogIntentRef=f.useRef(null),tamasyaCatalogDraftBridge=f.useEffect(()=>{') && !finance.includes('tamasyaCatalogDraftBridge=f.useEffect(()=>{\nconst catalogIntentRef=f.useRef(null);'),'React Hooks remain at the component top level (not inside effects)');
ok(finance.includes("catalogIntentRef.current={schema:'tamasya-catalog-intent-v2'"),'Form creates versioned server intent');
ok(finance.includes('catalogIntent:!qt&&!st&&!pa?catalogIntentRef.current||void 0:void 0'),'Only normal live form transmits catalog intent');
ok(finance.includes('catalogIntentRef.current=null;$e(qt)'),'Successful save clears catalog intent to prevent stale reuse');
ok(finance.includes('catalogIntentRef.current=null;$e(!1)'),'Cancel clears catalog identity');
ok(shell.includes('const Z={...A,transactionKind:A.transactionKind||"manual"}'),'Existing app shell preserves supplied intent in canonical payload');
ok(shell.includes('body:JSON.stringify({...Z,operationId:re})'),'Posting operation carries same catalog intent and server-side idempotency');
ok(shell.includes('if(!e&&A.recordOrigin!=="historical_import")throw new Error'),'Live catalog cannot enqueue an unconfirmed offline financial posting');
ok(core.includes('finance.js?v=20261009-r164-browserfix1'),'Updated finance chunk cache key used');
ok(index.includes('app-core.js?v=20261009-r164-night-audit') && core.includes('operations.js?v=20261009-r164-night-audit'),'Entry point and operations chunk both bust stale import graph');
ok(read('assets/master-data-workspace.js').includes('universal-catalog-workspace.js?v=20261009-catalog-price-onestep1'),'Catalog lazy loader busts stale cache');
const all=read('api/routes/040_transactions_sync.php');
ok(all.includes('tamasyaCatalogTransactionIntent($pdo,$catalogIntent'),'Server recalculates independently of browser');
ok(all.includes('tamasyaCatalogAssertTransactionReplay($pdo'),'Server disallows replay with different catalog item/rate');
ok(!read('assets/universal-catalog-workspace.js').includes("request('transactions'"),'Master page cannot post directly');
console.log(`CATALOG R16.2/16.3 UI: ${pass} PASS, 0 FAIL`);
