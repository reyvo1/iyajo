/* Halaman ulasan tamu. Memakai API publik dengan token sekali pakai. */
(function(){
 'use strict';
 var API=String(window.TAMASYA_PUBLIC_API_URL||new URL('../api.php',window.location.href).href).trim();
 var token=new URLSearchParams(window.location.search).get('token')||'';
 var rating=0,msg=document.getElementById('msg'),send=document.getElementById('send');
 var stars=document.getElementById('stars'),room=document.getElementById('room');
 function say(text,ok){msg.textContent=text;msg.className='msg '+(ok?'ok':'err');}
 for(var i=1;i<=5;i++){
  (function(v){
   var b=document.createElement('button');
   b.type='button';b.textContent=v+'★';b.setAttribute('aria-label',v+' bintang');b.setAttribute('aria-pressed','false');
   b.addEventListener('click',function(){
    rating=v;send.disabled=false;
    Array.prototype.forEach.call(stars.children,function(c){c.setAttribute('aria-pressed',c.textContent.charAt(0)===String(v)?'true':'false');});
    say('Rating '+v+' bintang dipilih.',true);
   });
   stars.appendChild(b);
  })(i);
 }
 if(!token){say('Tautan ulasan tidak lengkap. Minta tautan baru kepada front desk.',false);send.disabled=true;return;}
 fetch(API+'?action=public-review&token='+encodeURIComponent(token),{cache:'no-store'})
  .then(function(r){return r.json().then(function(j){return{status:r.status,json:j};});})
  .then(function(res){
   var d=(res.json&&res.json.data)||{};
   if(d.roomNumber)room.textContent='Kamar '+d.roomNumber;
   if(d.status&&d.status!=='pending'){
    document.getElementById('form').hidden=true;document.getElementById('done').hidden=false;
    if(d.rating){say('Ulasan Anda: '+d.rating+'/5. Terima kasih!',true);room.textContent='Kamar '+(d.roomNumber||'');}
   }else if(res.status!==200){say((res.json&&res.json.error)||'Ulasan tidak dapat dibuka.',false);send.disabled=true;}
  })
  .catch(function(){say('Gagal menghubungi server. Coba lagi.',false);});
 send.addEventListener('click',function(){
  if(!rating){say('Pilih rating terlebih dahulu.',false);return;}
  send.disabled=true;say('Mengirim…',true);
  fetch(API+'?action=public-review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:token,rating:rating,comment:document.getElementById('comment').value})})
   .then(function(r){return r.json().then(function(j){return{status:r.status,json:j};});})
   .then(function(res){
    if(res.status===200&&res.json&&res.json.success){
     document.getElementById('form').hidden=true;document.getElementById('done').hidden=false;
    }else{
     send.disabled=false;say((res.json&&res.json.error)||'Ulasan gagal dikirim.',false);
    }
   })
   .catch(function(){send.disabled=false;say('Gagal mengirim ulasan. Coba lagi.',false);});
 });
})();
