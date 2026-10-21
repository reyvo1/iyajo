/**
 * TAMASYA: add-on tarif kontrak B2B (wholesale).
 *
 * Panel tarif kontrak untuk kasir dan kepala housekeeping:
 *   1. Paket: membuat dan menonaktifkan paket tarif kontrak.
 *   2. Kuotasi: mencari paket yang berlaku untuk satu rentang inap beserta
 *      harga per tanggal dan totalnya.
 *   3. Paket terpilih: ketika kasir memilih sebuah paket, pemesanan berikutnya
 *      memakai harga paket. Harga yang dipakai dihitung server, bukan diketik
 *      di browser.
 *   4. Reservasi kontrak: daftar reservasi yang memakai harga kontrak.
 *
 * Add-on ini tidak menyentuh bundle antarmuka yang sudah diperkecil. Ia
 * menambah permintaan tariff ke API saat pemesanan dibuat, sehingga harga
 * kontrak berlaku di semua kanal: web, kasir, Telegram, dan grup.
 */
(() => {
  'use strict';
  if (window.__TAMASYA_WHOLESALE_CONTRACT_INSTALLED__) return;
  window.__TAMASYA_WHOLESALE_CONTRACT_INSTALLED__ = true;

  const token = () => sessionStorage.getItem('hotel_session_token') || '';
  const apiBase = () => {
    const active = sessionStorage.getItem('tamasya_active_api_url');
    if (active) return active;
    const runtime = window.TAMASYA_RUNTIME_CONFIG || {};
    return runtime.VITE_TAMASYA_ONLINE_API_URL || runtime.VITE_TAMASYA_LOCAL_API_URL || new URL('./api.php', location.href).toString();
  };
  const isStaff = () => sessionStorage.getItem('hotel_logged_in') === 'true' && token() !== '';

  const money = (value, currency) => {
    try {
      return new Intl.NumberFormat('id-ID', { style: 'currency', currency: currency || 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0));
    } catch (_) {
      return `${currency || 'IDR'} ${Number(value || 0).toLocaleString('id-ID')}`;
    }
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

  async function api(action, options = {}) {
    const method = options.method || 'GET';
    const url = new URL(apiBase(), location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('_ts', String(Date.now()));
    if (options.command) url.searchParams.set('command', options.command);
    const headers = {
      Accept: 'application/json',
      Authorization: `Bearer ${token()}`,
      'X-Device-ID': localStorage.getItem('hotel_device_id') || 'wholesale-contract-addon',
      'X-App-Version': 'V137-WHOLESALE-CONTRACT',
    };
    const scope = sessionStorage.getItem('hotel_offline_hotel_scope');
    if (scope) headers['X-Tamasya-Hotel-Scope'] = scope;
    const init = { method, headers, credentials: 'same-origin', cache: 'no-store' };
    if (options.body) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    const response = await fetch(url, init);
    let payload = null;
    try { payload = await response.json(); } catch (_) { payload = null; }
    if (!response.ok || !payload || payload.success === false) {
      const message = payload?.error || payload?.message || `Permintaan tarif kontrak gagal (${response.status}).`;
      throw new Error(message);
    }
    return payload.data || {};
  }

  /* ===== 1. Penyimpanan paket terpilih ===== */
  const SELECTION_KEY = 'tamasya_wholesale_contract_selection';
  let selection = null;
  try { selection = JSON.parse(sessionStorage.getItem(SELECTION_KEY) || 'null') || null; } catch (_) { selection = null; }

  const saveSelection = (value) => {
    selection = value || null;
    try {
      if (selection) sessionStorage.setItem(SELECTION_KEY, JSON.stringify(selection));
      else sessionStorage.removeItem(SELECTION_KEY);
    } catch (_) { /* penyimpanan browser penuh: pilihan tetap berlaku di sesi ini */ }
    renderSelection();
  };

  const renderSelection = () => {
    const chip = document.getElementById('tamasya-wholesale-selection');
    if (!chip) return;
    if (!selection) { chip.hidden = true; chip.innerHTML = ''; return; }
    chip.hidden = false;
    chip.innerHTML = `<span>Paket kontrak <b>${escapeHtml(selection.planCode)}</b> &middot; ${escapeHtml(selection.checkIn)} &rarr; ${escapeHtml(selection.checkOut)} &middot; ${escapeHtml(money(selection.total, selection.currency))}</span>`;
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = 'Hapus';
    clear.style.cssText = 'margin-left:10px;padding:4px 9px;border-radius:8px;border:1px solid #7fb3e0;background:transparent;color:#dceaf8;cursor:pointer;font:600 12px system-ui';
    clear.addEventListener('click', () => saveSelection(null));
    chip.appendChild(clear);
  };

  /* ===== 2. Penyisipan paket ke pemesanan ===== */
  const readJsonBody = async (input, init) => {
    if (init && typeof init.body === 'string') {
      try { return JSON.parse(init.body); } catch (_) { return null; }
    }
    if (!init || init.body === undefined) {
      try {
        if (input instanceof Request) return JSON.parse(await input.clone().text());
      } catch (_) { return null; }
    }
    return null;
  };

  const isBookingMutation = (url, method) => {
    if (method !== 'POST' || !url) return false;
    const path = String(url.pathname || '');
    if (!/(?:^|\/)api(?:\.php|\/|$)/i.test(path)) return false;
    const action = url.searchParams.get('action') || '';
    return action === 'bookings' || action === 'multi-room-bookings';
  };

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async function tamasyaFetchWithWholesaleContract(input, init = {}) {
    const request = input instanceof Request ? input : null;
    const method = String(init?.method || request?.method || 'GET').toUpperCase();
    let url = null;
    try { url = new URL(input instanceof Request ? input.url : String(input || ''), location.href); } catch (_) { url = null; }
    if (!isBookingMutation(url, method)) return nativeFetch(input, init);

    const body = await readJsonBody(input, init || {});
    if (!body || typeof body !== 'object' || Array.isArray(body)) return nativeFetch(input, init);
    if (body.wholesalePlanId) return nativeFetch(input, init);
    if (!selection) return nativeFetch(input, init);

    const checkIn = String(body.checkIn || '').trim();
    const checkOut = String(body.checkOut || '').trim();
    const roomType = String(body.roomType || '').trim();
    const sameStay = checkIn === selection.checkIn && checkOut === selection.checkOut;
    const sameRoom = !selection.roomType || !roomType || selection.roomType.toLowerCase() === roomType.toLowerCase();
    if (!sameStay || !sameRoom) {
      notify(`Paket kontrak ${selection.planCode} tidak dipakai: tidak cocok dengan inap ${checkIn || '?'} → ${checkOut || '?'}.`);
      return nativeFetch(input, init);
    }

    const payload = { ...body, wholesalePlanId: selection.planId, wholesaleCompanyName: selection.companyName || '' };
    const nextInit = { ...(init || {}) };
    if (init && typeof init.body === 'string') {
      nextInit.body = JSON.stringify(payload);
    } else {
      nextInit.body = JSON.stringify(payload);
      nextInit.method = 'POST';
      nextInit.headers = new Headers(init?.headers || request?.headers || {});
      nextInit.headers.set('Content-Type', 'application/json');
    }
    return nativeFetch(input, nextInit);
  };

  /* ===== 3. Pemberitahuan ===== */
  const notify = (message) => {
    let box = document.getElementById('tamasya-wholesale-notice');
    if (!box) {
      box = document.createElement('div');
      box.id = 'tamasya-wholesale-notice';
      box.style.cssText = 'position:fixed;z-index:2147483000;right:16px;bottom:74px;max-width:420px;padding:11px 14px;border-radius:12px;background:#3b2f12;color:#ffe9b8;border:1px solid #b98b2f;box-shadow:0 8px 24px rgba(0,0,0,.35);font:600 13px system-ui';
      document.body.appendChild(box);
    }
    box.textContent = message;
    box.hidden = false;
    setTimeout(() => { box.hidden = true; }, 7000);
  };

  /* ===== 4. Panel tarif kontrak ===== */
  const panelStyle = 'position:fixed;z-index:2147483001;right:16px;bottom:74px;width:min(760px,calc(100vw - 32px));max-height:min(78vh,900px);overflow:auto;background:#0f1c2e;color:#e8f0f8;border:1px solid #2f4a6f;border-radius:14px;box-shadow:0 18px 48px rgba(0,0,0,.45);font:13px system-ui;padding:16px';

  const buildPanel = () => {
    if (document.getElementById('tamasya-wholesale-panel')) return;
    const panel = document.createElement('section');
    panel.id = 'tamasya-wholesale-panel';
    panel.hidden = true;
    panel.style.cssText = panelStyle;
    panel.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
        <h2 style="margin:0;font-size:16px">Tarif kontrak B2B</h2>
        <div style="display:flex;gap:8px">
          <button type="button" data-act="refresh" style="padding:6px 10px;border-radius:8px;border:1px solid #3c6ea5;background:#14304d;color:#dceaf8;cursor:pointer">Muat ulang</button>
          <button type="button" data-act="close" style="padding:6px 10px;border-radius:8px;border:1px solid #3c6ea5;background:transparent;color:#dceaf8;cursor:pointer">Tutup</button>
        </div>
      </div>
      <div style="margin-top:12px;display:grid;gap:14px">
        <div>
          <h3 style="margin:0 0 6px;font-size:14px">Cari paket untuk satu inap</h3>
          <div style="display:flex;flex-wrap:wrap;gap:6px" data-role="quote-form">
            <input data-role="q-checkin" type="date" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8" title="Check-in">
            <input data-role="q-checkout" type="date" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8" title="Check-out">
            <input data-role="q-roomtype" type="text" placeholder="Tipe kamar" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;min-width:130px">
            <input data-role="q-company" type="text" placeholder="Nama perusahaan" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;min-width:150px">
            <button type="button" data-act="quote" style="padding:6px 12px;border-radius:8px;border:1px solid #3c6ea5;background:#1d4d7d;color:#fff;cursor:pointer">Cari harga</button>
          </div>
          <div data-role="quote-result" style="margin-top:8px"></div>
        </div>
        <div>
          <h3 style="margin:0 0 6px;font-size:14px">Paket tersimpan</h3>
          <div data-role="plans" style="max-height:210px;overflow:auto"></div>
          <details style="margin-top:8px">
            <summary style="cursor:pointer">Buat paket baru</summary>
            <div data-role="plan-form" style="margin-top:8px;display:grid;gap:6px">
              <div style="display:flex;flex-wrap:wrap;gap:6px">
                <input data-role="p-code" type="text" placeholder="Kode" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;width:120px">
                <input data-role="p-name" type="text" placeholder="Nama paket" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;flex:1;min-width:160px">
                <input data-role="p-company" type="text" placeholder="Perusahaan" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;min-width:140px">
                <input data-role="p-roomtype" type="text" placeholder="Tipe kamar (opsional)" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;min-width:150px">
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:6px">
                <input data-role="p-from" type="date" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8" title="Masa berlaku mulai">
                <input data-role="p-to" type="date" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8" title="Masa berlaku sampai">
                <input data-role="p-min" type="number" min="1" value="1" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;width:86px" title="Minimal malam">
                <input data-role="p-max" type="number" min="1" value="30" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;width:86px" title="Maksimal malam">
                <input data-role="p-cutoff" type="number" min="0" value="0" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;width:86px" title="Cut off hari">
                <input data-role="p-currency" type="text" value="IDR" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;width:80px" title="Mata uang">
              </div>
              <textarea data-role="p-nights" rows="3" placeholder="Harga per tanggal, satu baris satu tanggal:&#10;2026-01-01=700000" style="padding:6px 8px;border-radius:8px;border:1px solid #35597f;background:#0b1626;color:#e8f0f8;font:12px ui-monospace,monospace"></textarea>
              <button type="button" data-act="save-plan" style="justify-self:start;padding:7px 14px;border-radius:8px;border:1px solid #3c6ea5;background:#1d4d7d;color:#fff;cursor:pointer">Simpan paket</button>
            </div>
          </details>
        </div>
        <div>
          <h3 style="margin:0 0 6px;font-size:14px">Reservasi yang memakai harga kontrak</h3>
          <div data-role="locked" style="max-height:180px;overflow:auto"></div>
        </div>
      </div>`;
    document.body.appendChild(panel);
    panel.addEventListener('click', (event) => {
      const target = event.target.closest('[data-act]');
      if (!target) return;
      const act = target.getAttribute('data-act');
      if (act === 'close') { panel.hidden = true; return; }
      if (act === 'refresh') { refreshPanel().catch((error) => notify(error.message)); return; }
      if (act === 'quote') { runQuote().catch((error) => notify(error.message)); return; }
      if (act === 'save-plan') { savePlan().catch((error) => notify(error.message)); return; }
      if (act === 'use-plan') { usePlan(target.getAttribute('data-plan')); return; }
      if (act === 'toggle-plan') { togglePlan(target.getAttribute('data-plan'), target.getAttribute('data-status')).catch((error) => notify(error.message)); }
    });
  };

  const buildButton = () => {
    if (document.getElementById('tamasya-wholesale-button')) return;
    const button = document.createElement('button');
    button.id = 'tamasya-wholesale-button';
    button.type = 'button';
    button.textContent = 'Tarif kontrak';
    button.style.cssText = 'position:fixed;z-index:2147483000;right:16px;bottom:16px;padding:9px 14px;border-radius:12px;border:1px solid #3c6ea5;background:#14304d;color:#dceaf8;box-shadow:0 8px 24px rgba(0,0,0,.35);font:600 12px system-ui;cursor:pointer';
    button.addEventListener('click', () => {
      const panel = document.getElementById('tamasya-wholesale-panel');
      if (!panel) return;
      panel.hidden = !panel.hidden;
      if (!panel.hidden) refreshPanel().catch((error) => notify(error.message));
    });
    document.body.appendChild(button);

    const chip = document.createElement('div');
    chip.id = 'tamasya-wholesale-selection';
    chip.hidden = true;
    chip.style.cssText = 'position:fixed;z-index:2147483000;right:16px;bottom:56px;padding:8px 12px;border-radius:10px;background:#123457;color:#e8f0f8;border:1px solid #4b7caf;box-shadow:0 8px 24px rgba(0,0,0,.35);font:600 12px system-ui;display:flex;align-items:center';
    document.body.appendChild(chip);
  };

  const field = (panel, role) => panel.querySelector(`[data-role="${role}"]`);

  const refreshPanel = async () => {
    const panel = document.getElementById('tamasya-wholesale-panel');
    if (!panel) return;
    const plansBox = field(panel, 'plans');
    const lockedBox = field(panel, 'locked');
    plansBox.textContent = 'Memuat…';
    const [plans, locked] = await Promise.all([
      api('wholesale-rates'),
      api('wholesale-rates', { command: 'wholesale-rate-bookings' }).catch(() => ({ rows: [] })),
    ]);
    const rows = Array.isArray(plans.rows) ? plans.rows : [];
    if (!rows.length) {
      plansBox.innerHTML = '<p style="opacity:.75">Belum ada paket tarif kontrak.</p>';
    } else {
      plansBox.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="text-align:left;opacity:.7"><th>Kode</th><th>Nama</th><th>Perusahaan</th><th>Tipe</th><th>Berlaku</th><th>Status</th><th></th></tr></thead>
        <tbody>${rows.map((row) => `<tr>
          <td><b>${escapeHtml(row.code)}</b></td>
          <td>${escapeHtml(row.name)}</td>
          <td>${escapeHtml(row.company_name || '—')}</td>
          <td>${escapeHtml(row.room_type || 'semua')}</td>
          <td>${escapeHtml(row.valid_from)} → ${escapeHtml(row.valid_to)}</td>
          <td>${escapeHtml(row.status)}</td>
          <td><button type="button" data-act="toggle-plan" data-plan="${escapeHtml(row.id)}" data-status="${escapeHtml(row.status)}" style="padding:3px 8px;border-radius:7px;border:1px solid #3c6ea5;background:transparent;color:#dceaf8;cursor:pointer">${row.status === 'active' ? 'Nonaktifkan' : 'Aktifkan'}</button></td>
        </tr>`).join('')}</tbody></table>`;
    }
    const lockedRows = Array.isArray(locked.rows) ? locked.rows : [];
    lockedBox.innerHTML = lockedRows.length
      ? `<table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr style="text-align:left;opacity:.7"><th>Reservasi</th><th>Paket</th><th>Perusahaan</th><th>Inap</th><th>Total</th></tr></thead>
          <tbody>${lockedRows.map((row) => `<tr>
            <td><code>${escapeHtml(row.booking_id)}</code></td>
            <td>${escapeHtml(row.plan_code || row.plan_id)}</td>
            <td>${escapeHtml(row.company_name || '—')}</td>
            <td>${escapeHtml(row.stay_from)} → ${escapeHtml(row.stay_to)}</td>
            <td>${escapeHtml(money(row.total, row.currency))}</td>
          </tr>`).join('')}</tbody></table>`
      : '<p style="opacity:.75">Belum ada reservasi yang memakai harga kontrak.</p>';
  };

  const runQuote = async () => {
    const panel = document.getElementById('tamasya-wholesale-panel');
    const result = field(panel, 'quote-result');
    const criteria = {
      checkIn: field(panel, 'q-checkin').value,
      checkOut: field(panel, 'q-checkout').value,
      roomType: field(panel, 'q-roomtype').value,
      companyName: field(panel, 'q-company').value,
    };
    result.textContent = 'Mencari…';
    const data = await api('wholesale-rates', { method: 'POST', command: 'wholesale-rate-lookup', body: { command: 'wholesale-rate-lookup', ...criteria } });
    const applicable = Array.isArray(data.applicable) ? data.applicable : [];
    const others = Array.isArray(data.candidates) ? data.candidates.filter((row) => !row.applicable) : [];
    if (!applicable.length) {
      result.innerHTML = `<p style="opacity:.8">Tidak ada paket yang berlaku untuk inap tersebut.</p>` +
        (others.length ? `<ul style="opacity:.7;font-size:12px">${others.map((row) => `<li>${escapeHtml(row.planCode)}: ${escapeHtml(row.reason)}</li>`).join('')}</ul>` : '');
      return;
    }
    result.innerHTML = applicable.map((row) => `<div style="border:1px solid #2f5a86;border-radius:10px;padding:9px 11px;margin-bottom:8px">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div>
          <b>${escapeHtml(row.planName)}</b> (${escapeHtml(row.planCode)})${row.companyName ? ` &middot; ${escapeHtml(row.companyName)}` : ''}
          <div style="opacity:.75;font-size:12px">${escapeHtml(row.validFrom)} → ${escapeHtml(row.validTo)} &middot; ${row.nightsCount} malam &middot; ${escapeHtml(money(row.nightlyPrice, row.currency))} per malam</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <b style="font-size:15px">${escapeHtml(money(row.total, row.currency))}</b>
          <button type="button" data-act="use-plan" data-plan="${escapeHtml(row.planId)}" style="padding:6px 12px;border-radius:8px;border:1px solid #3c6ea5;background:#1d4d7d;color:#fff;cursor:pointer">Pakai paket ini</button>
        </div>
      </div>
      <div style="margin-top:6px;font-size:11px;opacity:.7">${(row.nights || []).map((night) => `${escapeHtml(night.stayDate)}: ${escapeHtml(money(night.price, row.currency))}`).join(' &middot; ')}</div>
    </div>`).join('');
  };

  const usePlan = async (planId) => {
    const panel = document.getElementById('tamasya-wholesale-panel');
    const criteria = {
      checkIn: field(panel, 'q-checkin').value,
      checkOut: field(panel, 'q-checkout').value,
      roomType: field(panel, 'q-roomtype').value,
      companyName: field(panel, 'q-company').value,
      planId,
    };
    const data = await api('wholesale-rates', { method: 'POST', command: 'wholesale-rate-lookup', body: { command: 'wholesale-rate-lookup', ...criteria } });
    const quote = data.quote;
    if (!quote || !quote.applicable) {
      notify(`Paket tidak bisa dipakai: ${quote?.reason || 'tidak berlaku'}`);
      return;
    }
    saveSelection({
      planId: quote.planId,
      planCode: quote.planCode,
      companyName: quote.companyName,
      roomType: quote.roomType,
      checkIn: criteria.checkIn,
      checkOut: criteria.checkOut,
      total: quote.total,
      currency: quote.currency,
    });
    notify(`Paket kontrak ${quote.planCode} dipilih. Harga ${money(quote.total, quote.currency)} akan dipakai saat pemesanan.`);
  };

  const savePlan = async () => {
    const panel = document.getElementById('tamasya-wholesale-panel');
    const nights = String(field(panel, 'p-nights').value || '')
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => {
        const [stayDate, price] = line.split('=');
        return { stayDate: String(stayDate || '').trim(), price: Number(String(price || '0').replace(/[^\d.]/g, '')) };
      })
      .filter((row) => /^\d{4}-\d{2}-\d{2}$/.test(row.stayDate) && row.price > 0);
    const body = {
      command: 'wholesale-rate-save',
      code: field(panel, 'p-code').value.trim(),
      name: field(panel, 'p-name').value.trim(),
      companyName: field(panel, 'p-company').value.trim(),
      roomType: field(panel, 'p-roomtype').value.trim(),
      validFrom: field(panel, 'p-from').value,
      validTo: field(panel, 'p-to').value,
      minNights: Number(field(panel, 'p-min').value || 1),
      maxNights: Number(field(panel, 'p-max').value || 30),
      cutOffDays: Number(field(panel, 'p-cutoff').value || 0),
      currency: field(panel, 'p-currency').value.trim() || 'IDR',
      nights,
    };
    await api('wholesale-rates', { method: 'POST', command: 'wholesale-rate-save', body });
    notify('Paket tarif kontrak disimpan.');
    await refreshPanel();
  };

  const togglePlan = async (planId, status) => {
    const next = status === 'active' ? 'inactive' : 'active';
    await api('wholesale-rates', { method: 'POST', command: 'wholesale-rate-status', body: { command: 'wholesale-rate-status', planId, status: next } });
    notify(`Paket ${next === 'active' ? 'diaktifkan' : 'dinonaktifkan'}.`);
    await refreshPanel();
  };

  const start = () => {
    if (!isStaff()) return;
    buildPanel();
    buildButton();
    renderSelection();
  };

  addEventListener('load', () => setTimeout(start, 900));
  if (document.readyState === 'complete') setTimeout(start, 300);
})();
