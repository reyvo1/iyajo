/*! TAMASYA governance panel: audit log viewer + permission inspector.
 *  Ditulis tangan (bukan minified) supaya mudah diaudit dan dirawat.
 *  Panel ini read-only: tidak ada aksi tulis, Owner pun hanya bisa membaca. */
import {f,t} from "../app-core.js?v=20261021-core1";
import {Vi} from "./app-shared.js?v=20261021-r18";

const PAGE_SIZE=25;

function Panel({title,subtitle,children}){
  return t.jsx("section",{className:"glass-card p-4 sm:p-5 rounded-2xl border border-white/10 shadow-xl shadow-slate-950/20 space-y-3"},
    t.jsxs("header",{className:"flex flex-wrap items-center justify-between gap-2"},
      t.jsxs("div",{className:"space-y-0.5"},
        t.jsx("h3",{className:"text-sm font-bold text-slate-100",children:title}),
        t.jsx("p",{className:"text-[11px] text-slate-400",children:subtitle})),
      children));
}

function Field({label,value}){
  return t.jsx("div",{className:"min-w-0"},
    t.jsx("p",{className:"text-[10px] uppercase tracking-wide text-slate-500",children:label}),
    t.jsx("p",{className:"text-[11px] text-slate-200 break-words",children:value===null||value===undefined||value===''?'—':String(value)}));
}

function Pill({tone,children}){
  const tones={allow:"bg-emerald-500/10 border-emerald-500/30 text-emerald-300",deny:"bg-rose-500/10 border-rose-500/30 text-rose-300",neutral:"bg-white/5 border-white/10 text-slate-300"};
  return t.jsx("span",{className:"inline-flex items-center rounded-md border px-1.5 py-0.5 text-[9px] font-bold uppercase "+tones[tone]||tones.neutral,children});
}

/* ===== Log audit ===== */
function AuditLogPanel({open}){
  const [state,setState]=f.useState({rows:[],total:0,facets:{actions:[],entityTypes:[],outcomes:[]},loading:false,error:null,filter:{action:'',entityType:'',outcome:'',from:'',to:'',q:''},offset:0});
  const load=f.useCallback(async(offset,filter)=>{
    setState(prev=>({...prev,loading:true,error:null}));
    try{
      const params=new URLSearchParams({action:"audit-logs",limit:String(PAGE_SIZE),offset:String(offset)});
      if(filter.action)params.set("auditAction",filter.action);
      if(filter.entityType)params.set("entityType",filter.entityType);
      if(filter.outcome)params.set("outcome",filter.outcome);
      if(filter.from)params.set("from",filter.from);
      if(filter.to)params.set("to",filter.to);
      if(filter.q)params.set("q",filter.q);
      const res=await fetch("/api.php?"+params.toString(),{cache:"no-store"});
      const json=await Vi(res);
      if(!res.ok||!json||!json.success){setState(prev=>({...prev,loading:false,error:(json&&json.error)||"Log audit tidak dapat dibaca (kode "+res.status+")."}));return;}
      setState(prev=>({...prev,loading:false,error:null,rows:(json.data&&json.data.rows)||[],total:(json.data&&json.data.total)||0,facets:(json.data&&json.data.facets)||prev.facets,offset}));
    }catch(err){setState(prev=>({...prev,loading:false,error:"Gagal memuat log audit."}));}
  },[]);
  f.useEffect(()=>{if(open)load(0,state.filter);},[open]);
  if(!open)return null;
  const apply=(patch)=>{const filter={...state.filter,...patch};setState(prev=>({...prev,filter}));load(0,filter);};
  const page=Math.floor(state.offset/PAGE_SIZE)+1;
  const pages=Math.max(1,Math.ceil(state.total/PAGE_SIZE));
  return t.jsx(Panel,{title:"Log Audit",subtitle:"Jejak perubahan dan akses. Hanya dibaca; nilai sensitif disamarkan otomatis.",
    children:[
      t.jsxs("div",{className:"flex flex-wrap items-end gap-2"},
        t.jsx("label",{className:"text-[10px] text-slate-400 space-y-1"},
          t.jsx("span",{className:"block",children:"Aksi"}),
          t.jsx("input",{value:state.filter.action,onChange:e=>apply({action:e.target.value}),placeholder:"mengubah tarif",className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100 w-44"})),
        t.jsx("label",{className:"text-[10px] text-slate-400 space-y-1"},
          t.jsx("span",{className:"block",children:"Entitas"}),
          t.jsx("input",{value:state.filter.entityType,onChange:e=>apply({entityType:e.target.value}),placeholder:"booking",className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100 w-32"})),
        t.jsx("label",{className:"text-[10px] text-slate-400 space-y-1"},
          t.jsx("span",{className:"block",children:"Dari"}),
          t.jsx("input",{type:"date",value:state.filter.from,onChange:e=>apply({from:e.target.value}),className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100"})),
        t.jsx("label",{className:"text-[10px] text-slate-400 space-y-1"},
          t.jsx("span",{className:"block",children:"Sampai"}),
          t.jsx("input",{type:"date",value:state.filter.to,onChange:e=>apply({to:e.target.value}),className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100"})),
        t.jsx("label",{className:"text-[10px] text-slate-400 space-y-1"},
          t.jsx("span",{className:"block",children:"Cari"}),
          t.jsx("input",{value:state.filter.q,onChange:e=>apply({q:e.target.value}),placeholder:"nama atau id",className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100 w-40"}))),
      state.error?t.jsx("p",{className:"text-[11px] text-rose-300 border border-rose-500/30 bg-rose-500/10 rounded-lg px-2 py-1",children:state.error}):null,
      t.jsxs("div",{className:"flex flex-wrap items-center gap-1"},
        (state.facets.actions||[]).slice(0,8).map(row=>t.jsx("button",{key:"facet-"+row.action,onClick:()=>apply({action:row.action}),className:"rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-[9px] text-slate-300 hover:border-white/25",children:row.action+" · "+row.total})),
        (state.facets.outcomes||[]).map(row=>t.jsx("button",{key:"out-"+row.outcome,onClick:()=>apply({outcome:row.outcome}),className:"rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-[9px] text-slate-300 hover:border-white/25",children:row.outcome+" · "+row.total}))),
      t.jsx("div",{className:"overflow-x-auto max-h-[320px] rounded-xl border border-white/5 bg-slate-950/40"},
        t.jsx("table",{className:"w-full text-left border-collapse text-[11px]"},
          t.jsx("thead",{className:"sticky top-0 z-10 bg-slate-900/95 text-slate-400 uppercase tracking-wider text-[9px]"},
            t.jsx("tr",{className:"border-b border-white/5"},
              t.jsx("th",{className:"px-2 py-1.5",children:"Waktu"}),
              t.jsx("th",{className:"px-2 py-1.5",children:"Pelaku"}),
              t.jsx("th",{className:"px-2 py-1.5",children:"Aksi"}),
              t.jsx("th",{className:"px-2 py-1.5",children:"Entitas"}),
              t.jsx("th",{className:"px-2 py-1.5",children:"Hasil"}),
              t.jsx("th",{className:"px-2 py-1.5",children:"Sumber"}))),
          t.jsx("tbody",{className:"divide-y divide-white/5 text-slate-300"},
            state.rows.length===0?t.jsx("tr",{children:t.jsx("td",{colSpan:6,className:"px-2 py-3 text-slate-500",children:state.loading?"Memuat…":"Belum ada jejak untuk filter ini."})}):state.rows.map(row=>t.jsx("tr",{key:row.id,className:"align-top"},
              t.jsx("td",{className:"px-2 py-1.5 whitespace-nowrap text-slate-400",children:String(row.created_at||"")}),
              t.jsx("td",{className:"px-2 py-1.5",children:(row.staff_name||"—")+(row.staff_role?" ("+row.staff_role+")":"")}),
              t.jsx("td",{className:"px-2 py-1.5",children:row.action||"—"}),
              t.jsx("td",{className:"px-2 py-1.5",children:[row.entity_type,row.entity_id].filter(Boolean).join(" · ")||"—"}),
              t.jsx("td",{className:"px-2 py-1.5"},t.jsx(Pill,{tone:row.outcome==="success"?"allow":"deny",children:row.outcome||"success"})),
              t.jsx("td",{className:"px-2 py-1.5 text-slate-400",children:row.source||"—"})))))),
      t.jsxs("div",{className:"flex items-center justify-between text-[10px] text-slate-400"},
        t.jsx("span",{children:state.total+" baris · halaman "+page+" / "+pages}),
        t.jsxs("div",{className:"flex gap-1"},
          t.jsx("button",{disabled:state.offset<=0,onClick:()=>load(Math.max(0,state.offset-PAGE_SIZE),state.filter),className:"rounded-md border border-white/10 px-2 py-1 disabled:opacity-40",children:"Sebelumnya"}),
          t.jsx("button",{disabled:state.offset+PAGE_SIZE>=state.total,onClick:()=>load(state.offset+PAGE_SIZE,state.filter),className:"rounded-md border border-white/10 px-2 py-1 disabled:opacity-40",children:"Berikutnya"}))),
    ]});
}

/* ===== Kesehatan sistem ===== */
function SystemHealthPanel({open}){
  const [state,setState]=f.useState({sections:{},notices:[],loading:false,error:null});
  const load=f.useCallback(async()=>{
    setState(prev=>({...prev,loading:true,error:null}));
    try{
      const res=await fetch("/api.php?action=system-health&t="+Date.now(),{cache:"no-store"});
      const json=await Vi(res);
      if(!res.ok||!json||!json.success){setState(prev=>({...prev,loading:false,error:(json&&json.error)||"Kesehatan sistem tidak dapat dibaca (kode "+res.status+")."}));return;}
      setState({sections:(json.data&&json.data.sections)||{},notices:(json.data&&json.data.notices)||[],loading:false,error:null});
    }catch(err){setState(prev=>({...prev,loading:false,error:"Gagal memuat kesehatan sistem."}));}
  },[]);
  f.useEffect(()=>{if(open)load();},[open]);
  if(!open)return null;
  const rows=Object.entries(state.sections||{});
  return t.jsx(Panel,{title:"Kesehatan Sistem",subtitle:"Ringkasan property, RBAC, keuangan, shift, klaster, dan integrasi.",
    children:[
      t.jsxs("div",{className:"flex items-center gap-2"},
        t.jsx("button",{onClick:load,className:"rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold text-slate-200",children:state.loading?"Memuat…":"Muat ulang"})),
      state.error?t.jsx("p",{className:"text-[11px] text-rose-300 border border-rose-500/30 bg-rose-500/10 rounded-lg px-2 py-1",children:state.error}):null,
      (state.notices||[]).map((n,i)=>t.jsx("p",{key:"notice-"+i,className:"text-[11px] text-amber-300 border border-amber-500/30 bg-amber-500/10 rounded-lg px-2 py-1",children:n})),
      t.jsx("div",{className:"grid grid-cols-1 sm:grid-cols-2 gap-2"},
        rows.map(([key,section])=>t.jsxs("div",{key:key,className:"rounded-xl border border-white/10 bg-slate-950/40 p-2 space-y-1"},
          t.jsxs("div",{className:"flex items-center justify-between gap-2"},
            t.jsx("p",{className:"text-[11px] font-bold text-slate-200",children:section.label||key}),
            t.jsx(Pill,{tone:section.status==="ok"?"allow":"neutral",children:section.status==="ok"?"OK":"TIDAK ADA"})),
          t.jsx("div",{className:"text-[10px] text-slate-400 space-y-0.5"},
            Object.entries(section.data||{}).map(([k,v])=>t.jsx("p",{key:k,className:"break-words",children:k+": "+(typeof v==="object"&&v!==null?JSON.stringify(v):String(v))})))))),
    ]});
}

/* ===== Ulasan tamu (staf) ===== */
function GuestReviewsPanel({open}){
  const [state,setState]=f.useState({rows:[],summary:null,bookings:[],loading:false,error:null,tab:'list'});
  const load=f.useCallback(async()=>{
    setState(prev=>({...prev,loading:true,error:null}));
    try{
      const res=await fetch("/api.php?action=guest-reviews",{cache:"no-store"});
      const json=await Vi(res);
      if(!res.ok||!json||!json.success){setState(prev=>({...prev,loading:false,error:(json&&json.error)||"Ulasan tidak dapat dibaca (kode "+res.status+")."}));return;}
      setState(prev=>({...prev,loading:false,error:null,rows:(json.data&&json.data.rows)||[],summary:(json.data&&json.data.summary)||null}));
    }catch(err){setState(prev=>({...prev,loading:false,error:"Gagal memuat ulasan."}));}
  },[]);
  const act=async(command,payload)=>{
    try{
      const res=await fetch("/api.php?action=guest-reviews",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(Object.assign({command},payload))});
      const json=await Vi(res);
      if(!res.ok||!json||!json.success){setState(prev=>({...prev,error:(json&&json.error)||"Aksi ulasan gagal."}));return null;}
      return json.data||{};
    }catch(err){setState(prev=>({...prev,error:"Gagal menjalankan aksi ulasan."}));return null;}
  };
  f.useEffect(()=>{
    if(!open)return;
    load();
    (async()=>{
      try{
        const res=await fetch("/api/bookings?limit=50",{cache:"no-store"});
        const json=await Vi(res);
        const rows=Array.isArray(json)?json:(Array.isArray(json&&json.data)?json.data:[]);
        setState(prev=>({...prev,bookings:rows}));
      }catch(err){}
    })();
  },[open]);
  if(!open)return null;
  const summary=state.summary||{};
  const recent=(state.bookings||[]).filter(b=>b&&b.id).slice(0,40);
  return t.jsx(Panel,{title:"Ulasan Tamu",subtitle:"Undang ulasan untuk kamar yang sudah checkout, moderasi, dan balas.",
    children:[
      t.jsxs("div",{className:"flex flex-wrap items-center gap-2"},
        t.jsx("button",{onClick:load,className:"rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold text-slate-200",children:state.loading?"Memuat…":"Muat ulang"}),
        t.jsx("span",{className:"text-[10px] text-slate-400",children:summary.total?summary.total+" ulasan · rata-rata "+(summary.average||0)+" · tampil "+summary.published+" · tersembunyi "+summary.hidden:"Belum ada ulasan"})),
      state.error?t.jsx("p",{className:"text-[11px] text-rose-300 border border-rose-500/30 bg-rose-500/10 rounded-lg px-2 py-1",children:state.error}):null,
      t.jsxs("div",{className:"space-y-2"},
        state.rows.length===0?t.jsx("p",{className:"text-[11px] text-slate-500",children:state.loading?"Memuat…":"Belum ada ulasan masuk."}):state.rows.map(row=>t.jsxs("div",{key:row.id,className:"rounded-xl border border-white/10 bg-slate-950/40 p-2 space-y-1"},
          t.jsxs("div",{className:"flex flex-wrap items-center gap-2"},
            t.jsx(Pill,{tone:row.published?"allow":"neutral",children:row.published?"TAMPIL":"TERSEMBUNYI"}),
            t.jsx("span",{className:"text-[10px] text-slate-400",children:(row.created_at||"")}),
            t.jsx("span",{className:"text-[11px] font-bold text-amber-300",children:(row.rating?row.rating+"/5":"")}),
            t.jsx("span",{className:"text-[11px] text-slate-200",children:(row.guest_name||row.room_number||"")})),
          row.comment?t.jsx("p",{className:"text-[11px] text-slate-300",children:row.comment}):null,
          row.reply?t.jsx("p",{className:"text-[11px] text-sky-300",children:"Balasan: "+row.reply}):null,
          t.jsxs("div",{className:"flex flex-wrap gap-1"},
            t.jsx("button",{onClick:async()=>{await act(row.published?"review-hide":"review-publish",{reviewId:row.id});load();},className:"rounded-md border border-white/10 px-2 py-0.5 text-[9px] font-bold text-slate-300",children:row.published?"Sembunyikan":"Tampilkan"}),
            t.jsx("button",{onClick:async()=>{const reply=window.prompt("Balasan untuk tamu:","");if(reply===null)return;await act("review-reply",{reviewId:row.id,reply:reply});load();},className:"rounded-md border border-white/10 px-2 py-0.5 text-[9px] font-bold text-slate-300",children:"Balas"}))))),
      t.jsxs("div",{className:"space-y-1"},
        t.jsx("p",{className:"text-[10px] uppercase tracking-wide text-slate-500",children:"Undang ulasan"}),
        recent.length===0?t.jsx("p",{className:"text-[11px] text-slate-500",children:"Belum ada reservasi untuk diundang."}):t.jsxs("div",{className:"flex flex-wrap items-center gap-2"},
          t.jsx("select",{id:"tamasya-review-booking",className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100"},
            recent.map(b=>t.jsx("option",{key:b.id,value:b.id,children:(b.roomNumber||b.room_number||"")+" · "+(b.guestName||b.guest_name||b.id)}))),
          t.jsx("button",{onClick:async()=>{
            const el=document.getElementById("tamasya-review-booking");
            const bookingId=el?el.value:"";
            if(!bookingId)return;
            const res=await act("review-invite",{bookingId:bookingId});
            if(res&&res.token){const link=new URL("../webpublic/review.html",window.location.href).href+"?token="+encodeURIComponent(res.token);window.prompt("Tautan undangan ulasan (kirim ke tamu):",link);}
          },className:"rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold text-slate-200",children:"Buat undangan"})))
    ]});
}

/* ===== Inspector hak akses ===== */
function PermissionInspectorPanel({open}){
  const [state,setState]=f.useState({data:null,loading:false,error:null,targetId:"",staffList:[]});
  const load=f.useCallback(async(staffId)=>{
    if(!staffId){setState(prev=>({...prev,data:null,error:null}));return;}
    setState(prev=>({...prev,loading:true,error:null}));
    try{
      const res=await fetch("/api.php?action=permission-inspector&staffId="+encodeURIComponent(staffId),{cache:"no-store"});
      const json=await Vi(res);
      if(!res.ok||!json||!json.success){setState(prev=>({...prev,loading:false,data:null,error:(json&&json.error)||"Hak akses tidak dapat diperiksa (kode "+res.status+")."}));return;}
      setState({data:json.data,loading:false,error:null,targetId:staffId});
    }catch(err){setState(prev=>({...prev,loading:false,error:"Gagal memeriksa hak akses."}));}
  },[]);
  // Panel mengambil daftar staf sendiri supaya tidak perlu props dari chunk staf.
  f.useEffect(()=>{
    if(!open)return;
    let cancelled=false;
    (async()=>{
      try{
        const res=await fetch("/api/staff?t="+Date.now(),{cache:"no-store"});
        const json=await Vi(res);
        if(cancelled)return;
        if(res.ok&&json&&Array.isArray(json)){setState(prev=>({...prev,staffList:json}));load(state.targetId||(json[0]&&json[0].id)||"");}
      }catch(err){}
    })();
    return()=>{cancelled=true;};
  },[open]);
  f.useEffect(()=>{if(open)load(state.targetId);},[state.targetId]);
  if(!open)return null;
  const data=state.data;
  return t.jsx(Panel,{title:"Inspector Hak Akses",subtitle:"Menjelaskan setiap keputusan izin: katalog, preset jabatan, override akun, atau fallback role.",
    children:[
      t.jsxs("div",{className:"flex flex-wrap items-center gap-2"},
        t.jsx("select",{value:state.targetId,onChange:e=>{setState(prev=>({...prev,targetId:e.target.value}));load(e.target.value);},className:"rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-[11px] text-slate-100"},
          t.jsx("option",{value:"",children:"— pilih akun —"}),
          (state.staffList||[]).map(st=>t.jsx("option",{key:st.id,value:st.id,children:(st.name||st.username)+" ("+(st.role||"")+")"}))),
        state.loading?t.jsx("span",{className:"text-[10px] text-slate-500",children:"Memeriksa…"}):null),
      state.error?t.jsx("p",{className:"text-[11px] text-rose-300 border border-rose-500/30 bg-rose-500/10 rounded-lg px-2 py-1",children:state.error}):null,
      data?t.jsxs("div",{className:"space-y-2"},
        t.jsxs("div",{className:"grid grid-cols-2 sm:grid-cols-4 gap-2"},
          t.jsx(Field,{label:"Akun",value:data.account.name}),
          t.jsx(Field,{label:"Role",value:data.account.role}),
          t.jsx(Field,{label:"Jabatan",value:data.account.jobTitle||"—"}),
          t.jsx(Field,{label:"Sumber paket",value:data.roleKey+" ("+data.roleKeySource+")"})),
        (data.warnings||[]).length?t.jsx("div",{className:"space-y-1"},
          (data.warnings||[]).map((w,i)=>t.jsx("p",{key:"warn-"+i,className:"text-[11px] text-amber-300 border border-amber-500/30 bg-amber-500/10 rounded-lg px-2 py-1",children:w}))):null,
        t.jsxs("div",{className:"flex flex-wrap gap-1"},
          Object.entries(data.summary||{}).map(([layer,count])=>t.jsx("span",{key:layer,className:"rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-[9px] text-slate-300",children:layer+": "+count}))),
        t.jsx("div",{className:"max-h-[280px] overflow-y-auto rounded-xl border border-white/5 bg-slate-950/40 divide-y divide-white/5"},
          (data.decisions||[]).map(dec=>t.jsxs("div",{key:dec.group+"-"+dec.key,className:"px-2 py-1.5 space-y-0.5"},
            t.jsxs("div",{className:"flex items-center gap-2"},
              t.jsx(Pill,{tone:dec.verdict?"allow":"deny",children:dec.verdict?"IZIN":"TOLAK"}),
              t.jsx("span",{className:"font-mono text-[10px] text-slate-200",children:dec.key}),
              t.jsx("span",{className:"text-[9px] text-slate-500",children:dec.group})),
            t.jsx("p",{className:"text-[10px] text-slate-400",children:dec.reason}))))):null,
    ]});
}

/** Panel governance: log audit + inspector hak akses. */
function TamasyaGovernancePanel(props){
  const [tab,setTab]=f.useState("audit");
  const [open,setOpen]=f.useState(false);
  return t.jsxs("div",{className:"space-y-3"},
    t.jsx("div",{className:"flex flex-wrap items-center gap-2"},
      t.jsx("button",{onClick:()=>setOpen(v=>!v),className:"rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] font-bold text-slate-200 hover:border-white/25",children:open?"Tutup Audit & Izin":"Buka Audit & Izin"}),
      open?t.jsxs("div",{className:"flex gap-1"},
        t.jsx("button",{onClick:()=>setTab("audit"),className:"rounded-lg px-2 py-1 text-[10px] font-bold "+(tab==="audit"?"bg-white/10 text-white":"text-slate-400"),children:"Log Audit"}),
        t.jsx("button",{onClick:()=>setTab("inspector"),className:"rounded-lg px-2 py-1 text-[10px] font-bold "+(tab==="inspector"?"bg-white/10 text-white":"text-slate-400"),children:"Inspector Izin"}),
        t.jsx("button",{onClick:()=>setTab("health"),className:"rounded-lg px-2 py-1 text-[10px] font-bold "+(tab==="health"?"bg-white/10 text-white":"text-slate-400"),children:"Kesehatan"}),
        t.jsx("button",{onClick:()=>setTab("reviews"),className:"rounded-lg px-2 py-1 text-[10px] font-bold "+(tab==="reviews"?"bg-white/10 text-white":"text-slate-400"),children:"Ulasan"})):null),
    open?(tab==="audit"?t.jsx(AuditLogPanel,{open:true}):tab==="inspector"?t.jsx(PermissionInspectorPanel,{open:true}):tab==="reviews"?t.jsx(GuestReviewsPanel,{open:true}):t.jsx(SystemHealthPanel,{open:true})):null);
}

export {TamasyaGovernancePanel};
