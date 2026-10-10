import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const read=name=>readFileSync(new URL('../'+name,import.meta.url),'utf8');
let pass=0;
function check(fn,label){fn();pass++;console.log('PASS '+label);}
const window={};
vm.runInNewContext(read('assets/universal-catalog-workspace.js'),{window,document:{},location:{pathname:'/index.html'},sessionStorage:{},URLSearchParams,console});
const build=window.TAMASYA_UNIVERSAL_CATALOG.prepareCashDraft;
const response={success:true,postingStatus:'DRAFT_ONLY',taxStatus:'NOT_CALCULATED',cashDraftEligible:true,
 itemSnapshot:{itemRevision:1,itemId:'item_1',rateId:'rate_1',itemName:'Paket bebas nama',unit:'unit',categoryId:'category_x',categoryName:'Usaha Umum',categoryType:'income',subcategoryId:'sub_x',subcategoryName:'Aktivitas Bebas',validFrom:'2026-10-01',unitPrice:'250000.00'},quote:{subtotalCents:50000000}};
check(()=>assert.equal(typeof build,'function'),'Cash draft builder is independently testable');
const draft=build(response,'2','2026-10-09');
check(()=>assert.equal(draft.schema,'tamasya-catalog-logkas-draft-v1'),'Versioned draft contract');
check(()=>assert.equal(draft.subtotalCents,50000000),'Exact cent amount retained');
check(()=>assert.equal(draft.categoryId,'category_x'),'Immutable category ID retained');
check(()=>assert.equal(draft.subcategoryId,'sub_x'),'Immutable subcategory ID retained');
check(()=>assert.equal(draft.rateId,'rate_1'),'Historical price-version identity retained');
check(()=>assert.ok(draft.description.includes('item_1/rate_1')),'Price-version reference included in editable draft description');
check(()=>assert.equal(draft.taxStatus,'NOT_CALCULATED'),'No implicit tax calculation');
check(()=>assert.equal(draft.postingStatus,'DRAFT_ONLY'),'Draft never claims posting');
const blocked=[
 [{...response,cashDraftEligible:false},'1','2026-10-09','Ineligible quote'],
 [{...response,quote:{subtotalCents:25000030}},'1','2026-10-09','Cents must not be silently rounded'],
 [{...response,quote:{subtotalCents:0}},'1','2026-10-09','Zero amount denied'],
 [{...response,quote:{subtotalCents:NaN}},'1','2026-10-09','Non-finite amount denied'],
 [{...response,postingStatus:'POSTED'},'1','2026-10-09','Unexpected posting status denied'],
 [{...response,taxStatus:'CALCULATED'},'1','2026-10-09','Forged tax result denied'],
 [{...response,itemSnapshot:{...response.itemSnapshot,rateId:''}},'1','2026-10-09','Missing rate identity denied'],
 [{...response,itemSnapshot:{...response.itemSnapshot,categoryType:'system'}},'1','2026-10-09','Invalid category type denied'],
 [response,'0','2026-10-09','Zero quantity denied'],
 [response,'1.1234','2026-10-09','Overprecision quantity denied'],
 [response,'1','tomorrow','Invalid date denied'],
];
for(const [r,q,d,label] of blocked)check(()=>assert.throws(()=>build(r,q,d)),label);
const finance=read('assets/chunks/finance.js'),catalog=read('assets/universal-catalog-workspace.js'),route=read('api/routes/099_universal_catalog.php');
check(()=>assert.ok(catalog.includes('catalog-quote')&&catalog.includes('data-cash-draft')),'Real item-to-quote UI connected');
check(()=>assert.ok(catalog.includes('TAMASYA_MASTER_DATA_WORKSPACE?.close()')),'Overlay closed before finance prefill');
check(()=>assert.ok(catalog.includes('window.dispatchEvent(new CustomEvent(\'tamasya:catalog-finance-draft\'')),'Draft dispatched via CSP-compatible event');
check(()=>assert.ok(finance.includes('window.addEventListener("tamasya:catalog-finance-draft"')),'Existing finance form listens for catalog draft');
check(()=>assert.ok(finance.includes('$e(false);Pe(category.type);de(category.name);Se(sub?.name||"");Ee(String(cents/100));')),'Prefill uses existing React-controlled finance fields');
check(()=>assert.ok(!catalog.includes("request('transactions'")&&!catalog.includes('tamasyaPostFinancialTransaction(')),'Catalog UI has NO independent ledger posting');
check(()=>assert.ok(route.includes("'cashDraftEligible'")&&route.includes("'postingStatus'=>'DRAFT_ONLY'")),'Server quote explicitly marks draft and cents guard');
check(()=>assert.ok(route.includes('category_system_locked')&&route.includes('subcategory_system_locked')),'Server still protects category system semantics');
console.log(`CATALOG → LOG KAS DRAFT: ${pass} PASS, 0 FAIL`);
