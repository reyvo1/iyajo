/**
 * Bukti lokal: helper format uang tidak boleh pernah undefined.
 *
 * Produksi sempat gagal dengan "Cannot read properties of undefined (reading
 * 'formatRupiah')" ketika helper uang tidak termuat (cache service worker).
 * Setiap berkas yang memakai helper itu sekarang memasang penawar sendiri,
 * sehingga halaman tidak pernah gagal hanya karena satu skrip tidak termuat.
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'../..');
const consumers=[
  'assets/chunks/feature-shared.js','assets/pos-minibar.js','assets/growth-suite.js',
  'assets/enterprise-suite.js','assets/guest-center.js','assets/employee-self-service.js',
  'assets/pos-report-archive-addon.js','assets/multi-property-foundation.js'
];
const guard='window.TamasyaCurrencyDisplay=window.TamasyaCurrencyDisplay||';
let passed=0;
for(const rel of consumers){
  const source=fs.readFileSync(path.join(root,rel),'utf8');
  assert.ok(source.includes(guard),`${rel} tidak memasang penawar helper uang`);
  const context={window:{}};
  vm.createContext(context);
  const tail=source.slice(source.indexOf(guard));
  vm.runInContext(tail.slice(0,tail.indexOf('\n')+1),context);
  const helper=context.window.TamasyaCurrencyDisplay;
  assert.equal(typeof helper.formatRupiah,'function',`${rel}: helper tidak punya formatRupiah`);
  assert.equal(helper.formatRupiah(1234.5),'Rp 1.235',`${rel}: format rupiah salah`);
  assert.equal(helper.formatNumber(1234.5),'1.235',`${rel}: format angka salah`);
  passed++;
  console.log(`PASS ${rel} memasang penawar helper uang yang berfungsi`);
}
const real={formatRupiah:()=>'USD 1'};
const context={window:{TamasyaCurrencyDisplay:real}};
vm.createContext(context);
vm.runInContext('window.TamasyaCurrencyDisplay=window.TamasyaCurrencyDisplay||{formatRupiah:()=>"salah"};',context);
assert.equal(context.window.TamasyaCurrencyDisplay,real,'helper asli harus tidak ditimpa');
passed++;
console.log('PASS helper asli tidak ditimpa oleh penawar');
console.log(`UI money helper: ${passed} passed; 0 failed.`);
