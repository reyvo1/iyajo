import {f,t} from '../app-core.js?v=20261021-core1';
import {Oc} from './app-shared.js?v=20261021-r16';
const money=value=>window.TamasyaCurrencyDisplay.formatNumber(value);
// The entered figure is what the guest hands over now; cash received earlier stays
// closed in its own shift and is added back to reach the new booking total. The
// bounds therefore live on the unpaid portion: the unpaid room capacity that can
// absorb a discount up to the balance still owed.
const money2=value=>Math.round((Number(value)||0)*100)/100;
async function requestPrice(bookingId,method='GET',payload=null){
  const suffix=payload&&method==='GET'?`&finalTotal=${encodeURIComponent(payload.finalTotal)}`:'';
  const response=await Oc(`/api/booking-negotiated-price?id=${encodeURIComponent(bookingId)}${suffix}`,{
    method,cache:'no-store',headers:{'Content-Type':'application/json'},
    ...(method==='POST'?{body:JSON.stringify({...payload,bookingId})}:{})
  });
  const result=await response.json();
  if(!response.ok||result.success!==true)throw new Error(result.error||'Harga nego belum dapat diproses.');
  return result;
}
export function TamasyaBookingNegotiation({booking,role,onSaved,onBusy}){
  const [quote,setQuote]=f.useState(null),[amount,setAmount]=f.useState(''),[reason,setReason]=f.useState(''),
    [preview,setPreview]=f.useState(null),[busy,setBusy]=f.useState(false),[error,setError]=f.useState('');
  f.useEffect(()=>{setQuote(null);setAmount('');setReason('');setPreview(null);setError('');},[booking.id,booking.totalAmount,booking.amountPaid]);
  if(!['admin','manager','receptionist'].includes(role)||booking.isOpenEnded||Number(booking.totalAmount)<=Number(booking.amountPaid||0))return null;
  async function run(action){setBusy(true);onBusy(true);setError('');try{await action();}catch(e){setError(e.message||'Gagal memproses harga nego.');}finally{setBusy(false);onBusy(false);}}
  const inputClass='w-full min-w-0 rounded-lg border border-white/15 bg-slate-950 px-3 py-2 text-sm text-slate-100';
  return t.jsxs('div',{className:'rounded-xl border border-amber-500/25 bg-amber-500/5 p-3 space-y-3',children:[
    t.jsx('button',{type:'button',disabled:busy,className:'text-sm font-bold text-amber-300 disabled:opacity-50',onClick:()=>run(async()=>{
      const result=await requestPrice(booking.id);if(result.quote.roomBalance<=0)throw new Error('Biaya kamar/perpanjangan sudah lunas.');
      setQuote(result.quote);setAmount(String(result.quote.balanceDue));setPreview(null);
    }),children:busy?'Memproses…':'Tetapkan Harga Nego Sebelum Bayar'}),
    quote&&t.jsxs('div',{className:'space-y-3',children:[
    t.jsx('p',{className:'text-xs text-slate-300',children:`Total lama Rp ${money(quote.totalAmount)} · Sudah dibayar Rp ${money(quote.amountPaid)} (tetap, tidak dipotong lagi). Sisa layanan Rp ${money(quote.extraBalance)} tetap. Total booking baru = uang yang dibayar sekarang + Rp ${money(quote.amountPaid)}.`}),
    t.jsxs('label',{className:'block space-y-1 text-xs text-slate-300',children:[t.jsx('span',{children:'Uang yang dibayar tamu sekarang, termasuk PBJT (Rp)'}),t.jsx('input',{type:'number',min:Math.max(0.01,money2(quote.minimumTotal-quote.amountPaid)),max:money2(quote.totalAmount-quote.amountPaid),step:'0.01',value:amount,disabled:busy,className:inputClass,onChange:e=>{setAmount(e.target.value);setPreview(null);}})]}),
      t.jsxs('label',{className:'block space-y-1 text-xs text-slate-300',children:[t.jsx('span',{children:'Alasan / kesepakatan harga nego'}),t.jsx('textarea',{value:reason,minLength:5,disabled:busy,className:inputClass,onChange:e=>{setReason(e.target.value);setPreview(null);}})]}),
      t.jsx('p',{className:'text-xs text-slate-400',children:'Potongan hanya untuk biaya kamar/perpanjangan yang belum dibayar. Menyimpan harga tidak mencatat penerimaan uang.'}),
      preview&&t.jsx('p',{className:'text-xs text-amber-200 break-words',children:`Potongan Rp ${money(preview.discountAmount)} · PBJT final Rp ${money(preview.vatAmount)} · Sisa dibayar Rp ${money(preview.totalAmount-quote.amountPaid)}.`}),
      t.jsxs('div',{className:'flex flex-wrap gap-2',children:[
        t.jsx('button',{type:'button',disabled:busy||reason.trim().length<5||!Number.isFinite(Number(amount))||Number(amount)<=0||Number(amount)<Math.max(0.01,money2(quote.minimumTotal-quote.amountPaid))||Number(amount)>=money2(quote.totalAmount-quote.amountPaid),className:'rounded-lg bg-amber-500/20 px-3 py-2 text-xs font-bold text-amber-200 disabled:opacity-50',onClick:()=>run(async()=>{
          if(!preview){const result=await requestPrice(booking.id,'GET',{finalTotal:Number(amount)});if(result.quote.quoteToken!==quote.quoteToken)throw new Error('Tagihan berubah. Buka ulang Harga Nego.');setPreview(result.preview);return;}
          const result=await requestPrice(booking.id,'POST',{finalTotal:Number(amount),reason:reason.trim(),quoteToken:quote.quoteToken});
          setQuote(null);setPreview(null);await onSaved(result.booking);
        }),children:preview?'Konfirmasi & Simpan Harga Nego':'Periksa Harga Final'}),
        t.jsx('button',{type:'button',disabled:busy,className:'rounded-lg bg-white/5 px-3 py-2 text-xs text-slate-300',onClick:()=>{setQuote(null);setPreview(null);},children:'Batalkan Nego'})
      ]})
    ]}),error&&t.jsx('p',{role:'alert',className:'text-xs text-rose-300 break-words',children:error})
  ]});
}
