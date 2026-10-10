import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const base=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=p=>fs.readFileSync(path.join(base,p),'utf8');
let passed=0;
function check(label,fn){fn();passed++;console.log('PASS '+label);}
const index=read('index.html');
const core=read('assets/app-core.js');
const finance=read('assets/chunks/finance.js');
const sw=read('sw.js');
const entry=index.match(/src="\.\/assets\/app-core\.js\?v=([^"\s]+)"/);
check('Exact versioned app-core module entry exists',()=>assert.ok(entry));
check('Catalog React useRef is a hook of the component, outside the effect callback',()=>{
  assert.match(finance, /catalogIntentRef=f\.useRef\(null\),tamasyaCatalogDraftBridge=f\.useEffect\(\(\)=>\{/);
  assert.doesNotMatch(finance,/tamasyaCatalogDraftBridge=f\.useEffect\(\(\)=>\{\s*const catalogIntentRef=f\.useRef/);
});
check('All lazy chunk imports reference exactly the SAME React app-core module identity',()=>{
  let count=0;
  for(const filename of fs.readdirSync(path.join(base,'assets/chunks')).filter(x=>x.endsWith('.js'))){
    const file='assets/chunks/'+filename;
    const matches=[...read(file).matchAll(/app-core\.js\?v=([^"'\s]+)/g)];
    for(const match of matches){assert.equal(match[1],entry[1],file+' has split core identity');count++;}
  }
  assert.ok(count>=25,`core imports not inventoried: ${count}`);
});
check('Finance chunk has a cache-busted URL after React Hook repair',()=>assert.match(core,/finance\.js\?v=20261009-r164-browserfix1/));
check('Service worker precaches EXACT indexed root React module URL',()=>{
  assert.ok(sw.includes('"./assets/app-core.js?v='+entry[1]+'"'));
  assert.ok(!sw.includes('"./assets/app-core.js?v=20261008-r15"'));
});
check('Old intact Playwright finance/navigation assertions are still active',()=>{
  const spec=read('tests/uat_rc1/browser/rc1-ui.spec.mjs');
  assert.ok(spec.includes("expect(new Set(topRoutes)).toEqual(new Set(['dashboard','finance','report','website']))"));
  assert.ok(spec.includes("getByTestId('finance-filter-from').fill"));
  assert.ok(spec.includes("await expect(page.locator('.tamasya-finance-summary')).toBeVisible()"));
  assert.ok(spec.includes("test('R16.4 finance React hooks mount cleanly and preserve top-level routes'"));
});
check('Night Audit source/MySQL gates remain intact',()=>{
  const workflow=read('.github/workflows/tamasya-enterprise-rc1-uat.yml');
  assert.ok(workflow.includes('php tests/night-audit-mode-regression.php'));
  assert.ok(workflow.includes('night-audit-mode-mysql-uat.php'));
});
console.log(`R16.4 REACT FINANCE MODULE: ${passed} PASS, 0 FAIL`);
