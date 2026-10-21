/* Format uang tampilan: ikut mata uang & locale property. */
(function(root){'use strict';
const C=v=>String(v==null?'':v).trim();
const cur=()=>{const c=C(root.TAMASYA_CURRENCY).toUpperCase();return /^[A-Z]{3}$/.test(c)?c:'IDR';};
const loc=()=>{const c=C(root.TAMASYA_LOCALE);return /^[a-z]{2}(-[A-Za-z]{2,8})?$/.test(c)?c:'id-ID';};
const cache={};
const fmt=(l,p)=>(cache[l+p]||(cache[l+p]=new Intl.NumberFormat(l,p?{minimumFractionDigits:2,maximumFractionDigits:2}:{maximumFractionDigits:0})));
function num(v,p){if(v==null||v===''||!Number.isFinite(Number(v)))return '—';let t=fmt(loc(),p).format(Number(v));if(p)t=t.replace(/,00$/,'');return t==='-0'?'0':t;}
function rup(v,p){const t=num(v,p);if(t==='—')return t;const c=cur();return (c==='IDR'?'Rp ':c+' ')+t;}
root.TamasyaCurrencyDisplay=Object.freeze({formatNumber:num,formatRupiah:rup,formatNumberExact:v=>num(v,true),formatRupiahExact:v=>rup(v,true)});
})(window);
