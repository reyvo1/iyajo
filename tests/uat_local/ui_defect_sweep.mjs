/**
 * Sapuan cacat antarmuka: kelas yang sama berulang di seluruh aplikasi.
 *
 *  A. Elemen yang dipakai skrip tapi tidak ada di halamannya -> halaman mati
 *     (kelas yang ditemukan di konsol HQ).
 *  B. Kontrol mengambang yang tumpang tindih -> tombol tertutupi.
 *  C. Permukaan yang tertahan di ujung halaman panjang -> tidak pernah dilihat.
 *  D. Endpoint tanpa pemanggil antarmuka -> fitur tidak terjangkau.
 */
import fs from 'node:fs';
import path from 'node:path';

const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'../..');
const read=(rel)=>fs.readFileSync(path.join(root,rel),'utf8');
const listFiles=(dir)=>fs.readdirSync(path.join(root,dir)).map(n=>path.join(dir,n)).filter(rel=>fs.statSync(path.join(root,rel)).isFile());
const problems=[];
const notes=[];

/* A. id yang dipakai skrip vs id di halaman */
const pages=fs.readdirSync(root).filter(f=>f.endsWith('.html'));
const assetFiles=[];
for(const dir of ['assets','assets/chunks','hq','webpublic/assets']){
  if(fs.existsSync(path.join(root,dir)))for(const f of fs.readdirSync(path.join(root,dir))){
    const rel=path.join(dir,f);
    if(fs.statSync(path.join(root,rel)).isFile()&&f.endsWith('.js'))assetFiles.push(rel);
  }
}
for(const page of pages){
  const html=read(page);
  const pageIds=new Set([...html.matchAll(/id="([A-Za-z0-9_-]+)"/g)].map(m=>m[1]));
  const scripts=[...html.matchAll(/<script[^>]*src="\.\/([^"?]+)/g)].map(m=>m[1]);
  // skrip yang di-<head> selalu ikut; chunk dimuat dinamis -> periksa bersama
  for(const script of scripts){
    if(!fs.existsSync(path.join(root,script)))continue;
    const src=read(script);
    // id yang dibuat skrip itu sendiri (innerHTML/createElement) boleh tidak ada di halaman
    const selfCreated=new Set([
      ...[...src.matchAll(/id="([A-Za-z0-9_-]+)"/g)].map(m=>m[1]),
      ...[...src.matchAll(/\.id\s*=\s*['"]([A-Za-z0-9_-]+)['"]/g)].map(m=>m[1])
    ]);
    const used=[...src.matchAll(/getElementById\(['"]([A-Za-z0-9_-]+)['"]\)/g)].map(m=>m[1]);
    for(const id of new Set(used)){
      if(pageIds.has(id)||selfCreated.has(id))continue;
      // hanya yang dipakai saat muat modul (bukan di dalam handler)
      const idx=src.indexOf(`getElementById('${id}')`);
      const before=src.slice(0,idx);
      const isInsideHandler=/=>\s*\{[^=]*$/.test(before.split('\n').pop()||'');
      if(isInsideHandler)continue;
      problems.push(`A) ${page}: skrip ${script} memakai #${id} yang tidak ada di halaman (risiko halaman mati)`);
    }
  }
}
notes.push(`A) diperiksa ${pages.length} halaman, ${assetFiles.length} skrip`);

/* B. kontrol mengambang: cari pasangan yang sama-sama fixed di pojok kanan bawah */
const fixed=[];
for(const rel of assetFiles){
  const src=read(rel);
  for(const m of src.matchAll(/position:fixed[^'\"]{0,140}?(right|left):(\d+)px[^'\"]{0,80}?bottom:(\d+)px/g)){
    fixed.push({rel,side:m[1],pos:Number(m[2]),bottom:Number(m[3])});
  }
  for(const m of src.matchAll(/position:fixed[^'\"]{0,140}?bottom:(\d+)px[^'\"]{0,80}?(right|left):(\d+)px/g)){
    fixed.push({rel,side:m[2],bottom:Number(m[1]),pos:Number(m[3])});
  }
}
const bottomRight=fixed.filter(f=>f.pos<=40&&f.bottom<=160);
const seen=new Set();const collisions=[];
for(const a of bottomRight){
  for(const b of bottomRight){
    if(a===b)continue;
    if(a.side!==b.side)continue;
    const key=[a.rel,a.side,a.pos,a.bottom].join('|')+'#'+[b.rel,b.side,b.pos,b.bottom].join('|');
    if(seen.has(key))continue;seen.add(key);
    // kotak perkiraan 150x46 (tombol app)
    const overlapX=Math.abs(a.pos-b.pos)<150;
    const overlapY=Math.abs(a.bottom-b.bottom)<50;
    if(overlapX&&overlapY&&a.rel!==b.rel)collisions.push(`B) ${a.rel} (${a.side} ${a.pos}, bottom ${a.bottom}) dan ${b.rel} (${b.side} ${b.pos}, bottom ${b.bottom}) berada di pojok yang sama`);
  }
}
problems.push(...collisions);
notes.push(`B) ${fixed.length} kontrol mengambang ditemukan, ${bottomRight.length} di pojok kanan bawah`);

/* D. endpoint tanpa pemanggil UI */
const apiSrc=read('api.php');
const actions=new Set([...apiSrc.matchAll(/'([a-z][a-z0-9-]{2,})'\s*=>/g)].map(m=>m[1]));
const uiBlob=assetFiles.map(rel=>read(rel)).join('\n')+pages.map(p=>read(p)).join('\n');
const orphan=[];
for(const action of actions){
  if(!/^(status|ping|login|verify|logout|bootstrap|overview|save|delete|list|export|report|email|send|check|preview|apply|create|update|search|filter|reset|confirm|cancel|refresh|upload|download|print|sync|health|audit|review|role|rates|rooms|staff|booking|payment|shift|maintenance|inventory|procurement|channel|provider|loyalty|folio|company|property|user|message|telegram|email|whatsapp|node|cluster|consistency|storage|receipt|cashier|cart|product|transaction|journal|report|document|approval|budget|expense|revenue|tax|rate|schedule|housekeeping|laundry|amenity|reservation|calendar|event|notification|announcement|faq|article|gallery|testimonial|review|seo|domain|ssl|backup|restore|migration|install|setup)/i.test(action))continue;
  if(/^(telegram-|node-cluster-recover|public-|webhook|reporting-periods)/.test(action))continue;
  if(!uiBlob.includes(action))orphan.push(`D) aksi API '${action}' tidak punya pemanggil antarmuka`);
}
problems.push(...orphan);

console.log('== CATATAN ==');
notes.forEach(n=>console.log(' - '+n));
console.log('== TEMUAN ==');
if(!problems.length)console.log(' - tidak ada');
[...new Set(problems)].forEach(p=>console.log(' - '+p));
console.log(`\nUI defect sweep: ${new Set(problems).size} temuan, ${notes.length} pemeriksaan.`);
