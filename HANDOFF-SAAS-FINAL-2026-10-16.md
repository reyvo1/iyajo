# HANDOFF SAAS FINAL — Tamasya Hotel Operations Console

Tanggal: 2026-10-16. Session boleh berganti; agen berikutnya lanjut dari file ini.
Bahasa pemilik: Indonesia. Jawab singkat, konkret, tanpa basa-basi.

## 1. Status saat ini (rilis kode `1add609` = isi ZIP 1845; commit dokumen di atasnya; CI success, tree bersih)

Kerjaan terakhir (sudah push + ZIP):
- `1add609` **Fix mobile R16 (lanjutan audit kosmetik 18 Okt) — arahan pemilik
  "selesaikan semuanya"**: (1) panel Aksi/Tambah Kamar kini **bottom-sheet** di
  <lg — class `tamasya-room-detail-sheet` dipasang kondisional di `rooms.js` +
  CSS fixed 82dvh/safe-area/animasi/prefers-reduced-motion di
  `responsive-polish.css`; (2) halaman **Operasional <640px**: grup aksi jadi
  strip geser-horizontal agar panel konten yang dibuka tetap dekat tombol
  (sebelumnya ~1000px di bawah). Playwright before/after: sheet y152 h692,
  tombol tutup berfungsi, desktop tetap kolom sidebar statis (x956, tidak
  berubah); strip daily 1582px scrollable, panelTop terlihat; overflowX 0.
  Anggaran graf modul statis sempat terlampaui +1637B → komentar ROOT7/8
  (`ui-core.css`) + komentar legacy `responsive-polish.css` dipadatkan
  (fungsional tidak berubah); graf akhir **969883/970000**. Bump aset
  `?v=20261019-mobile-sheet1` (chunk `rooms.js`, `responsive-polish.css`,
  `app-core.js`/`index.html`/`pos.html`/`sw.js`) — **deviasi pertama dari
  "app-core byte-identik" sejak Fase 3: HANYA string versi chunk rooms.js**
  (disengaja utk cache-bust; `app-core.js?v=20261014-nego-core1` sendiri tetap
  agar pin tes UAT tidak putus). FILE_CHECKSUMS regen; verify-release 56 suite
  hijau; CI `39238467267` hijau; ZIP `tamasya-production-prod-20261019-1845.zip`.
  Bonus (arahan pemilik): **DB dump produksi** `tamasya-production-db-20261019.sql`
  (117 tabel + 5 trigger, admin sementara `Tamasya-Import!2026`,
  property "Nama Hotel Anda") untuk tes server produksi — detail di bullet
  "Deliverable DB" di bawah.
- `ad8b311` + `db89177` **Fase 3 audit kapabilitas — sisa Batch 2/3 (arahan
  pemilik "lanjut sampe final")**: 3 capability baru → registry 19 → 22 +
  centangan/label di panel Kelola Jabatan (`staff.js`; `app-core.js` tetap
  byte-identik): `view_reports` (095 laporan kanonik, 3 command; fallback
  admin/manager/finance), `view_support_inbox` (017/018 kotak masuk publik;
  fallback admin/manager/receptionist; nama sengaja ber-prefix `view_` —
  kontrak UAT "owner bisa membaca" & owner tetap read-only), `manage_facilities`
  (HANYA 2 situs simpan config 096; baca & booking 096 sengaja tetap keras).
  Seed preset 9 jabatan = cermin fallback; `tamasyaRolePresetBackfill`
  menambal baris lama 19 → 22 kunci saat dibaca; migrasi
  `migrations/20261019_ROLE_CAPABILITY_PRESETS_FASE3.sql` (JSON_MERGE_PATCH
  ber-guard, idempoten, edit admin tak tertimpa) + `database_setup.sql`
  disinkronkan (paritas seed terbukti). Tes: role-preset 39 → 50 cek,
  facility 37; verify-release 56 suite hijau. CI `ad8b311` merah di UAT
  8.4/8.5 (owner GET support 403 karena capability non-`view_`) → `db89177`
  rename `manage_public_inbox` → `view_support_inbox`; CI `39222461546`
  hijau (UAT 1522/1522). ZIP `tamasya-production-prod-20261019-1633.zip`.
  Perilaku akun tanpa centangan TIDAK berubah (fallback daftar role lama).
  Dokumen ini + AUDIT diperbarui di commit dokumen setelah `db89177`
  (`5a77ff8` dst.; ZIP tidak memuat kedua dokumen — hanya `docs/archive/`).
- `a514025` **Fase 2 "Kelola Jabatan" (disetujui pemilik "Setujui —
  implementasikan sekarang"; desain §7 `AUDIT-KAPABILITAS-ROLE-2026-10-19.md`)**:
  tabel `role_capability_presets` + migrasi `20261019_ROLE_CAPABILITY_PRESETS.sql`
  (splice `database_setup.sql` → 117 tabel), `api/support/021_role_presets.php`
  (registry 19 cap/15 tab/6 notif, seed default per jabatan — cermin preset editor
  `app-core.js`, paritas `Eh`/`PA` terverifikasi 9 jabatan, normalize/coerce,
  buildOverride, accountDiff), `api/routes/052_role_presets.php`
  (list/save/preview/apply, admin-only, owner read-only, audit per akun +
  ringkasan, `confirm=true` + `updatedAt` optimistik), panel "Kelola Jabatan"
  di `assets/chunks/staff.js` lewat bridge `Qle` (`app-core.js` tetap
  byte-identik), suite `tests/role-preset-regression.php` (39 cek).
  Pin diperbarui: manifest modularisasi + 117 tabel (py/CI/smoke). Perilaku
  default TIDAK berubah sampai admin menekan "Terapkan" (paket = data + aksi
  eksplisit; `hasCapability` 017 tidak disentuh). verify-release 55 → 56 suite
  hijau; CI `39210729652` hijau (rerun 1× flake Playwright mobile POS — desktop
  & API lulus di run sama); ZIP `tamasya-production-prod-20261019-1525.zip`.
- `a8f34e5` + `7f81438` **Migrasi cek role statis → centangan capability
  (Batch 1+2 dari `AUDIT-KAPABILITAS-ROLE-2026-10-19.md`)** atas arahan pemilik
  "kerjakan semuanya". Semua pesan penolakan & alur dipertahankan; akun tanpa
  centangan berperilaku identik (fallback daftar role lama). Cakupan:
  `080` Telegram (tombol & gerbang kas/shift → `receive_payment_access`;
  baca kamar `/update_status` + gerbang roomRead → `manage_room_access`, owner
  read-only tetap; tombol/handler/state layanan tamu → `perform_housekeeping`;
  override selisih kas → `approve_sensitive_actions`; `/buka_shift`,
  `/tutup_shift`, menu, pending state, diagnostik → satu predikat
  `tamasyaCanOperateShift()||centangan receive_payment_access`), `044` `$write`
  multi-room → `sell_room_access`+workspace, `090` (lost-found-secure, review
  pembatalan maintenance, hold open/release → `manage_room_access`; shift
  open/close → `receive_payment_access`; smart-lock manual-complete →
  `override_key_checkout`; status blacklist 090:922 + override booking
  blacklist 030:1802 → `approve_sensitive_actions`), `110` po-status
  approved/received → `approve_sensitive_actions`, `010`
  node-sync-conflict-resolve → `resolve_sync_conflict` (satu-satunya capability
  yang tadinya belum ditegakkan di titik mana pun; referensi audit lama
  "040:1185" keliru — situs asli 010:218, sudah dikoreksi di dokumen audit).
  **Night audit tetap admin-only by design** (kontrak R16.4 dipin
  `tests/night-audit-mode-regression.php`; sengaja tidak dimigrasi). Pemisahan
  tugas keuangan (pemasukan/pengeluaran manual, laporan kas, po-create/link)
  sengaja tetap keras. Tes `telegram-extension-capability-regression.php`
  16 → 37 cek; verify-release 55 suite hijau; CI `39201844092` +
  `39202966117` hijau; ZIP `tamasya-production-prod-20261019-1318.zip`.
- `38b7025` **Audit total kelas bug beku perpanjangan: 3 dinding role keras +
  1 route web kini capability-aware** (respon pertanyaan pemilik "apakah hanya
  situ salahnya"). Setelah assert 050 diperbaiki, masih ada dinding role keras
  di jalur yang sama sehingga peran tercantang *lolos gate menu* tapi *ditolak
  saat aksi* (fitur macet, walau tidak lagi 500):
  (1) `applyCanonicalTelegramBookingChargeWorkflow`
  (`api/modules/finance/030_booking_finance.php`) — commit biaya canonical
  layanan/perpanjangan menolak `'Peran akun tidak berhak menambah biaya
  booking.'`; (2) `tamasyaApplyBookingNegotiatedPrice` (030) — tolak harga
  nego; (3) `finalizeTelegramRoomTransfer`
  (`api/modules/front_office/040_rooms_checkout_housekeeping.php`) — tolak
  pindah kamar; (4) route web `booking-negotiated-price`
  (`api/routes/020_hotel_booking.php`) — gate tab Kamar sudah capability tapi
  cek keras di dalam handler menolak. Semua kini `hasCapability(...,
  'manage_stay_access', ['admin','manager','receptionist'])` dengan fallback
  daftar lama bila helper tak dimuat; pesan tolak dipertahankan; owner
  read-only tetap ditolak. Titik panggil Telegram relevan diverifikasi SUDAH
  di dalam try/catch (layanan_payment 080:6152, transfer_confirmation
  080:6297, transfer callback 080:3105) → tidak ada titik 500 baru. Test
  `tests/telegram-extension-capability-regression.php` diperluas 11 → 16 cek.
  **Night audit TIDAK terkait**: toggle `night_audit_enabled` di 090 admin-only
  by design (tidak bisa OFF bila ada run `open`), Telegram tidak punya fitur
  night audit, dan `assertShiftNightAuditReady` (060) dipanggil dari jalur
  ber-try/completed-then-reset (080:4279, 080:5186) + pesan error web 090:299 —
  tidak ada jalur freeze.
- `AUDIT-KAPABILITAS-ROLE-2026-10-19.md` — **inventaris lengkap izin** (334
  cek role keras di 48 file + 19 registry capability + by-design vs kandidat +
  peta jalan fase 0–3). Dibuat atas permintaan pemilik saat mengeluh "role
  masih statis". Batch 1+2 dieksekusi pada `7f81438`+`a8f34e5` (lihat butir di
  atas); sisa kandidat butuh capability baru → Fase 3 (desain dulu).
- `4b14d0b` **Fix beku Telegram saat perpanjangan kamar oleh role tercantang**
  (laporan produksi: finance/role centangan klik "1 malam" → bot diam total).
  Akar: gate masuk alur perpanjangan sudah capability-aware
  (`manage_stay_access`), tetapi `tamasyaTelegramChargeAssertRole`
  (`api/modules/comms/050_integrations_telegram_mail.php`) masih daftar role
  keras `admin/manager/receptionist` → melempar `RuntimeException` untuk role
  tercantang; handler state `waiting_for_extend_nights`
  (`api/routes/080_telegram_webhook.php`) satu-satunya tanpa try/catch →
  exception tembus ke catch luar → HTTP 500 → Telegram retry → sesi beku.
  Fix dua lapis: (1) assert kini `hasCapability(...,'manage_stay_access',…)`
  dengan fallback daftar role bila `hasCapability` tak dimuat; (2) handler
  jumlah malam dibungkus try/catch → selalu membalas pesan yang bisa
  ditindaklanjuti + tombol batal. Audit total: satu-satunya throw role keras
  yang tersisa di 050 adalah baris fallback assert; gate menu/callback/command
  langsung/notifikasi seluruhnya capability-aware dan menolak dengan balasan
  (bukan 500). Test baru `tests/telegram-extension-capability-regression.php`
  (11 cek: behavior finance/keamanan/receptionist/owner ± centang + penjaga
  statis 050/080; diperluas ke 16 cek oleh audit `38b7025`).
- `8de8d50` **Email nota tersimpan otomatis ke booking**: kirim sukses dari web
  (nota/grup) atau Telegram kini menulis `bookings.guestEmail` (normalisasi
  lower/trim, batas kolom varchar(100), tulis hanya bila berubah) → prefill
  form web + tombol "✉️ Kirim ke" Telegram muncul untuk berikutnya. Test
  `tests/receipt-email-regression.php` 40 → 43 cek.
- `e5e0f88` **Status kirim email jujur + bukti akar masalah** (tindak lanjut
  keluhan "status terkirim tapi tidak masuk"): semua pesan sukses kini
  menampilkan jalur nyata `(SMTP)` atau `(PHP mail())` — web nota, web laporan
  grup, Telegram nota ×2, Telegram laporan grup. `sendSmtpMail` sekarang:
  verifikasi balasan MAIL FROM (tolak + pesan jelas bila envelope sender
  ditolak), dan `error_log('[smtp] DATA ditolak server: …')` saat server
  menolak di tahap DATA (sebelumnya gagal SENYAP). `tamasyaSendReceiptEmail`
  mencatat `[receipt-email] SMTP tidak berhasil (host=…), fallback PHP mail()`
  tiap kali jalur fallback dipakai. Cara pakai untuk diagnosis: kirim nota →
  lihat teks jalur di notifikasi → cek cPanel Metrics/Errors untuk baris
  `[smtp]`/`[receipt-email]`, dan cPanel Track Delivery untuk nasib akhir
  pesan. Test `tests/receipt-email-regression.php` naik 36 → 40 cek.
- `40fd223` **Seragamkan warna Housekeeping = kuning** sesuai keputusan pemilik:
  kartu kamar (sudah kuning sejak awal) + chip "Housekeeping / QC" + titik
  legend + donut dashboard kini semua amber/kuning; Servis/Blocker tetap merah.
- `8f741a3` **Fix dot legend Housekeeping/QC tak terlihat** (rule `.bg-cyan-400`
  tidak pernah di-generate di `app-core.css` — bug bundle CSS, bukan warna) +
  test regresi `tests/cosmetic-legend-dots-regression.php`; komentar FIX37
  dipadatkan agar budget graph aset 970.000 B tetap lolos (kini 969.808).
- `b3f04d0` **Fix nota email tidak sampai** (SMTP base64 wrap RFC 5321 +
  fallback exception + test sink loopback; CI 9/9 hijau).
- `9e42e0e` + `d7e9bb6` Refresh `FILE_CHECKSUMS.sha256` (hanya file
  git-tracked, exclude self) — dua CI merah 18 Okt karena checksum basi.
- `4881b09` Fix warna servis/bloker → rose (rooms.js + dashboard.js) +
  audit kosmetik (catatan lengkap di bawah).
- `1037684` **Kirim Nota / Laporan Grup via email resmi** (web + Telegram).
- `7b21097` Menu Riwayat Tutup Shift + Owner read-only konsisten (Telegram).
- `8d08596` + checksum refresh.
- `d4ded49` Pemasukan transfer tampil di rincian Riwayat Shift.
- `5b75fe1` **Centangan per akun mengendalikan Jual/Inap/Panjar di Telegram & Web.**
- `c6e975f` Penyesuaian test kontrak key-status.
- `afe0866` Housekeeping: role `finance` masuk baseline (`perform_housekeeping`).
- `2abfb86` **Centangan jual/inap tembus tab Kamar** (helper 017, 070 ×2, 043
  tulis, 080 fallback, UI `app-shell.js` + `rooms.js`).
- `0bb5ba6` Perbaikan insiden CI: koma→titik koma di `assets/chunks/app-shell.js`
  (bundel gagal boot, semua UAT browser merah) + gerbang ESM di
  `scripts/verify-release.sh`.
- ZIP produksi terbaru: `tamasya-production-prod-20261019-1845.zip` (378 entri,
  berisi fix mobile R16 `1add609` + Fase 3 kapabilitas `db89177`+`ad8b311` + Fase 2 Kelola Jabatan
  `a514025` + migrasi capability Batch 1+2
  `7f81438`+`a8f34e5` + audit total
  capability `38b7025` + fix beku perpanjangan `4b14d0b` + email tersimpan
  otomatis + semua di bawahnya). ZIP lama (…1633, …1525, …1318, …1305, …1137, …1101, …2032,
  …1952, …1907, …1738, …1630, …1349, …1835, dst.) usang, jangan dipakai.
- Deliverable DB (arahan pemilik "biar tinggal import"): `tamasya-production-db-20261019.sql`
  di Desktop mesin dev (189K; tanpa data transaksi; tanpa DEFINER; 5 trigger
  disimpan). Admin: `s_afa60c9ce5495be0649a9070` (username `admin`), password
  sementara `Tamasya-Import!2026`, `require_password_change=1`; property
  "Nama Hotel Anda" (`property-01`/`PROPERTY01`); 9 preset jabatan ikut.
  Langkah pemilik: import via phpMyAdmin → login → ganti password → Wizard
  Setup. Tervalidasi import di MariaDB low-priv + login E2E.
- Fakta database (audit 18 Okt): `database_setup.sql` = 116 CREATE TABLE,
  SHA-256 `604f7d466b4e07de4e6f854c2f03a7609b52a08e5cf8b94efacf186aabfc263e`;
  `migrations/` = 23 file (Fase 3 `db89177` menambah 1 migrasi data; tanpa
  tabel baru). (Per `a514025`: kini **117 CREATE TABLE** —
  `+role_capability_presets`; SHA berubah, cek `FILE_CHECKSUMS.sha256` /
  `sha256sum database_setup.sql`.) Dokumen rilis lama (HANDOFF.md P0/R3,
  CANONICAL_REPORT_ENGINE.md, PRODUCTION_FULL_AUDIT_R4.md) yang menulis
  "13 migration" / SHA `87bebefe…` adalah catatan historis rilis saat itu,
  bukan keadaan sekarang.
- Perubahan 18 Okt (commit terbaru): **Kirim Nota / Laporan Grup via email
  resmi** dari pratinjau nota web, bot Telegram, dan detail grup web. Tahap 1
  tanpa lampiran PDF: email HTML resmi, angka identik nota thermal / layar
  grup (PBJT + extras), semua teks pemakai di-escape. Gate web:
  `sell_room_access` ATAU `manage_stay_access` (baseline admin/manager/
  resepsionis) + workspace Kamar; owner/finance tetap read-only. Telegram:
  tombol ✉️ di pratinjau nota & `/cetak_nota`, state
  `waiting_for_receipt_email`, callback `email_receipt*` (tamu) dan
  `mr_report*` (grup, gate jual). Web: tombol + panel di dialog nota
  (`booking-receipt.js`) dan form laporan di detail grup (`multi-room.js`).
  Audit wajib per kirim (event id hash `receipt_email_*` /
  `group_report_email_*`, email penerima di-hash, metode SMTP/PHP mail).
  Test baru: `php tests/receipt-email-regression.php` (kini 43 cek, termasuk
  fallback exception SMTP, jalur kirim di pesan sukses, log penolakan
  server, dan penyimpanan otomatis email ke booking).

- **Perbaikan 18 Okt (nota email tidak terkirim padahal laporan keuangan masuk):**
  akar masalah di klien SMTP `sendSmtpMail`: jalur TANPA lampiran mengirim HTML
  nota sebagai SATU baris panjang (4.794 oktet) sehingga melanggar batas baris
  RFC 5321 (998 oktet) dan ditolak server ketat (mis. Exim ≥4.95 / cPanel:
  "message has lines too long for transport"); jalur laporan keuangan selalu
  ber-lampiran → MIME base64 76 kolom → lolos (sebab "laporan masuk, nota
  tidak"). Fix: `Content-Transfer-Encoding: base64` + `chunk_split(…, 76)`
  untuk semua jalur tanpa lampiran (nota web/Telegram, laporan grup, CRM
  campaign, EmailAdapter); `tamasyaSendReceiptEmail` kini menangkap exception
  SMTP lalu fallback `PHP mail()` (paritas alur laporan canonical). Test baru
  `tests/smtp-line-length-regression.php` — sink SMTP loopback, membuktikan
  tiap baris wire ≤998 oktet + base64 round-trip tanpa kehilangan data;
  didaftarkan di CI workflow + `scripts/verify-release.sh` (kini 54 suite).

- **Perbaikan 18 Okt (titik legend Housekeeping/QC "hilang"):** class
  `bg-cyan-400` dipakai `rooms.js` tetapi rule-nya tidak pernah ada di
  `assets/app-core.css` (emerald/indigo/rose ada) → dot transparan di semua
  build. Fix awal: rule `.bg-cyan-400{…}`; lalu di `40fd223` titik legend
  diubah ke `bg-amber-400` karena warna housekeeping diseragamkan **kuning**
  (keputusan pemilik 18 Okt): kartu kamar memang sudah kuning (amber) sejak
  awal, kini chip "Housekeeping / QC" + titik legend + donut dashboard ikut
  kuning (`#f59e0b`). Servis/Blocker = rose/merah sebagai kode legenda statis
  (bukan alarm — merah hanya menyala di kartu kamar yang memang ada blocker).
  Test regresi `tests/cosmetic-legend-dots-regression.php` (8 cek) memastikan
  tiap dot legend punya rule CSS nyata; didaftarkan di CI + verify-release
  (54 suite).

- **Perubahan 18 Okt (audit kosmetik + fix warna servis/bloker):**
  Dashboard & Denah Kamar: kartu servis/maintenance → **rose/merah**,
  badge "Servis / Maintenance" + "Operasional / Blocker" + "L&F / Blocker"
  + legend dot → rose (`bg-rose-500/5 hover:bg-rose-500/10 border-rose-500/20 text-rose-400`),
  housekeeping kuning/amber (diseragamkan penuh di `40fd223`); nomor kamar
  `shrink-0 whitespace-nowrap` + parent `gap-2` (fix wrap "40/5"); donut chart
  "Alokasi Status Kamar" `R: "Servis / Blocker" color #f43f5e`
  (Housekeeping `#f59e0b` sejak `40fd223`).
  File: `assets/chunks/rooms.js` + `assets/chunks/dashboard.js`.
  Verifikasi: `node --check` SYNTAX_OK, Playwright before/after screenshots.
  Audit mobile (390×844): charts render, tables scroll, room grid stack OK;
  **gap mobile = SELESAI (R16, commit `1add609`)**: (1) panel Aksi/Tambah
  Kamar di <lg kini bottom-sheet fixed (82dvh + safe-area + animasi; class
  `tamasya-room-detail-sheet` dipasang kondisional di `rooms.js`) — Playwright:
  tap kartu → sheet tampil (y152 h692), tombol tutup berfungsi, desktop tetap
  kolom sidebar statis (x956); (2) halaman Operasional <640px: grup aksi jadi
  strip geser-horizontal (`responsive-polish.css`) agar panel konten yang
  dibuka tetap dekat tombol (sebelumnya ~1000px di bawah tombol); nav domain
  sendiri sudah sehat (fixed z120 opaque). Anggaran graf modul statis sempat
  +1637B di atas ceiling → komentar ROOT7/8 (`ui-core.css`) + komentar legacy
  `responsive-polish.css` dipadatkan; graf akhir 969883/970000, verify 56 suite
  hijau.
  "Perlu perhatian" panel amber biarkan (mixed alerts, per-item coloring
  butuh refactor minified code).

Cara pakai di Telegram (sudah terbukti jalan): centang 3 capability baru di
menu Karyawan untuk akun tsb → tekan 🏠 Menu Utama → tombol muncul.
Server harus sudah menjalankan ZIP terbaru.

## 2. Capability baru (JANGAN digabung, tetap terpisah)

| Key | Label UI | Membuka |
|---|---|---|
| `sell_room_access` | Jual Kamar & Reservasi (Telegram & Web) | Jual, Reservasi, grup (`mr_*`) |
| `manage_stay_access` | Perpanjang, Pindah & Check-out (Telegram & Web) | Perpanjang, Layanan, Pindah, Checkout |
| `receive_payment_access` | Terima Panjar/DP (Telegram & Web) | Panjar (`panjar_menu`, `dp_*`, `/panjar`) |
| `view_reports` | Lihat & Kirim Laporan Kanonik | 095 laporan kanonik (fallback admin/manager/finance) |
| `view_support_inbox` | Kelola Kotak Masuk Publik (Bantuan & Reservasi) | 017/018 inbox publik (fallback admin/manager/receptionist) |
| `manage_facilities` | Kelola Master Fasilitas (Tipe & Unit) | Simpan config 096 saja (fallback admin/manager) |

Aturan: role = baseline; centang true = tambah akses;
centang false eksplisit = cabut akses; **owner selalu read-only**
(`hasCapability` owner hanya lolos `view_*`, preset sesi memaksa).
Baseline `perform_housekeeping` kini termasuk role `finance` (permintaan pemilik
17 Okt 2026; gate Telegram/web/klien disesuaikan). Yang tidak diubah:
`view_guest_identity`, `manage_room_access`, + 13 lainnya.

## 3. File yang disentuh fitur capability (jika regresi, cek ini dulu)

- `api/support/017_authorization_policy.php` — preset owner (+3 key).
- `assets/app-core.js` — default `Eh()` per role.
- `assets/chunks/staff.js` — 3 checkbox (cari `sell_room_access`).
- `api/routes/080_telegram_webhook.php` — keyboard dinamis
  (`$keyboardForTelegramRole`), split gate `sell/stay/roomRead`, direct gate
  `$requiredDirectCap`, state machine (`waiting_mr_*`, `dp_*`, catch-all),
  inline `guest_ops_menu` (`$canSellOps/$canStayOps`), `cash_shift_menu` panjar.
- `api/routes/020_hotel_booking.php` — `room-transfers`, `bookings`
  POST/PUT, `booking-payments`, `guest-security-deposits`, `bookings-status`.
- `api/modules/front_office/043_multi_room_reservations.php` —
  `tamasyaMultiRoomRequire` write = sell cap + tab Kamar.
- `tests/capability-sales-regression.php` — 35 cek (termasuk workspace Kamar
  dan kontrak sumber gate Telegram).
- `tests/multi-room-regression.php` — require `017` dipindah ke atas
  (wajib, kalau tidak fatal `hasCapability()` undefined).
- Perubahan 17 Okt lanjutan (capability kamar tembus UI+API): helper baru
  `tamasyaHasRoomsWorkspaceAccess()` di `api/support/017_authorization_policy.php`
  (centangan `sell_room_access`/`manage_stay_access` mengalahkan
  `desktopTabs.rooms=false`; owner & baseline admin/manager/resepsionis tetap;
  menu Kamar eksplisit tetap dihormati bila tidak ada centangan). Dipakai di
  `api/support/070_data_projection_scope.php` (×2, notifikasi + proyeksi data)
  dan `tamasyaMultiRoomRequire` (043, jalur tulis).
- `api/routes/080_telegram_webhook.php` — fallback jual/inap dibersihkan
  (`finance`,`owner` dihapus dari dua gate callback; keyboard dinamis tetap
  jalur utama centangan).
- `assets/chunks/app-shell.js` — 4 memo capability (sell/stay/panjar/kelola
  kamar) diteruskan sebagai prop ke `assets/chunks/rooms.js` (10 gate tombol +
  baris kunci + peluncur reservasi grup); tab Kamar disisipkan ke navigasi
  saat centangan sell/stay aktif (tanpa menambah `assets/app-core.js` supaya
  anggaran grafik awal 970 KB tetap terjaga).
- Perbaikan 17 Okt (insiden CI): penyisipan splice navigasi di
  `assets/chunks/app-shell.js` sempat memakai koma di dalam deklarasi `const`
  sehingga bundel gagal boot (semua UAT browser merah), sementara gerbang lama
  `node --check` memberi false-pass untuk file ESM. Kini koma diganti titik
  koma (ukuran byte tetap) dan `scripts/verify-release.sh` memakai parser modul
  (`node --input-type=module --check`) untuk file ber-`import`/`export`.
- Perubahan 17 Okt (finance bisa housekeeping): gate `perform_housekeeping` di
  `080_telegram_webhook.php` (×6 + keyboard dinamis), `090_operations_communications.php`
  (×2), `020_identity_access_audit.php`, `040_rooms_checkout_housekeeping.php`,
  `assets/app-core.js`, `assets/chunks/operations.js`, `assets/chunks/local-connect.js`.
- `tests/telegram-key-status-contract-regression.php` — cek gate disesuaikan
  dengan belahan sell/stay.
- `.github/workflows/tamasya-enterprise-rc1-uat.yml`, `scripts/verify-release.sh`
  — daftarkan test baru.
- Perubahan 18 Okt (nota/laporan via email): `api/support/060_shift_receipts.php`
  (`tamasyaBookingReceiptEmailContent`, `tamasyaGroupReportEmailContent`,
  `tamasyaSendReceiptEmail`, `tamasyaSendBookingReceiptEmail`,
  `tamasyaSendGroupReportEmail`), `api/routes/020_hotel_booking.php` (action
  `booking-receipt-email`/`group-report-email`), `api/routes/080_telegram_webhook.php`
  (`email_receipt:`, `email_receipt_send:`, state `waiting_for_receipt_email`),
  `api/modules/front_office/044_multi_room_telegram.php` (`mr_report:`,
  `mr_report_send:`), `assets/chunks/booking-receipt.js`,
  `assets/chunks/multi-room.js`, `tests/receipt-email-regression.php` +
  registrasi di `scripts/verify-release.sh`.
- Fix 18 Okt (RFC 5321): `api/modules/comms/050_integrations_telegram_mail.php`
  (`sendSmtpMail` → base64 + `chunk_split` 76 kolom untuk jalur tanpa lampiran),
  `api/support/060_shift_receipts.php` (try/catch exception SMTP → fallback
  PHP mail), `tests/smtp-line-length-regression.php` (baru; sink SMTP loopback),
  registrasi di CI workflow + `scripts/verify-release.sh` (kini 54 suite).
- Fix 18 Okt (dot legend + warna housekeeping): `assets/app-core.css` (+ rule
  `.bg-cyan-400{…}`; komentar FIX37 dipadatkan agar budget aset tetap),
  `assets/chunks/rooms.js` (chip dirty + titik legend → amber `40fd223`),
  `assets/chunks/dashboard.js` (donut Housekeeping → `#f59e0b`),
  `tests/cosmetic-legend-dots-regression.php` (baru, 8 cek, ekspektasi amber),
  registrasi di CI workflow + `scripts/verify-release.sh`.
- Fix 18 Okt (status kirim jujur, `e5e0f88`): `api/routes/020_hotel_booking.php`
  + `api/routes/080_telegram_webhook.php` (pesan sukses memuat `$result['method']`),
  `api/modules/comms/050_integrations_telegram_mail.php` (MAIL FROM diverifikasi;
  log `[smtp] DATA ditolak server`), `api/support/060_shift_receipts.php`
  (log penggunaan fallback PHP mail), `tests/receipt-email-regression.php`
  (+4 cek, kini 43 dengan persistensi email `8de8d50`).
- Fix 19 Okt (beku perpanjangan role tercantang, `4b14d0b`):
  `api/modules/comms/050_integrations_telegram_mail.php`
  (`tamasyaTelegramChargeAssertRole` → `hasCapability` + fallback role),
  `api/routes/080_telegram_webhook.php` (state `waiting_for_extend_nights`
  dibungkus try/catch + balasan bisa ditindaklanjuti),
  `tests/telegram-extension-capability-regression.php` (baru, 11 cek),
  registrasi di CI workflow + `scripts/verify-release.sh`.
- Audit 19 Okt gelombang 2 (`38b7025`): `api/modules/finance/030_booking_finance.php`
  (charge workflow + nego → `hasCapability` + fallback),
  `api/modules/front_office/040_rooms_checkout_housekeeping.php`
  (`finalizeTelegramRoomTransfer` → `hasCapability` + fallback),
  `api/routes/020_hotel_booking.php` (route nego web → `hasCapability`),
  `tests/telegram-extension-capability-regression.php` 11 → 16 cek.
  Temuan by-design yang TIDAK diubah: blacklist override admin/manager
  (030:1802 + 090:922), assignment service self-only (090:969), absensi
  workforce admin/manager (060), leave-request approve, loyalty/adapters
  enterprise admin/manager (112), email resmi leadership-lease (095:44).
- `FILE_CHECKSUMS.sha256` — WAJIB refresh tiap ada file berubah atau CI
  merah (pelajaran: commit kode dan checksum boleh beda run; run yang lolos
  adalah yang membawa checksum baru).

## 4. Perintah baku setiap selesai kerja (urutan JANGAN dibalik)

1. `php -l` tiap file PHP yang diubah.
2. Minimal: `php tests/capability-sales-regression.php`,
   `php tests/owner-readonly-regression.php`,
   `php tests/telegram-shift-regression.php`,
   `php tests/multi-room-regression.php`,
   `php tests/receipt-email-regression.php`,
   `php tests/smtp-line-length-regression.php`,
   `php tests/cosmetic-legend-dots-regression.php`,
   `php tests/telegram-extension-capability-regression.php`,
   `php tests/telegram-key-status-contract-regression.php`.
3. `sha256sum` file berubah → update `FILE_CHECKSUMS.sha256` (tambah entri
   alfabetis untuk file baru) → `sha256sum -c FILE_CHECKSUMS.sha256`.
4. Commit + `git push origin main` (pesan gaya `fix(scope): ...` /
   `feat(scope): ...` / `chore(release): refresh checksum ...`).
5. Tunggu CI `TAMASYA Enterprise Full Complete UAT` hijau:
   `gh run list --branch main --limit 1`, lalu
   `gh run view <id> --json jobs --jq ...`. Kalau merah, ambil job id yang
   failure → `gh run view --log-failed --job=<dbId>` → perbaiki → ulangi.
6. `./create-production-zip.sh` → ZIP `tamasya-production-prod-<date>.zip`
   → verifikasi isi (`unzip -p ... | grep -c <penanda>`) → berikan ke pemilik
   untuk upload (ZIP tidak ikut push, di-ignore git).
7. JANGAN auto-commit/push tanpa diminta di luar alur ini; JANGAN ubah skema
   DB tanpa migrasi + persetujuan pemilik.

## 5. Arsitektur (fakta, bukan asumsi)

- Single-hotel matang: 1 DB + 1 stack `.env` per hotel. TIDAK ada tenant_id
  di tabel inti. 2 hotel dalam 1 DB = tabrakan UNIQUE + bocor data.
- HQ (`hq/`) = agregat read-only (snapshot HMAC), bukan control-plane SaaS.
- TIDAK ADA: billing langganan, signup otomatis, superadmin platform,
  scheduler backup bawaan, fleet-migrasi, rotasi kunci terpusat.
- Instalasi per hotel = teknisi manual (provision bundle → import →
  `first_install.php` → wizard property-setup).

## 6. Roadmap FINAL sampai siap SaaS (kerjakan berurutan)

Fase 0 — layak jual managed-hosting (1–2 minggu):
- [ ] SOP operasional + kontrak SLA jujur + pricing sheet (lihat §7).
- [ ] Checklist instalasi teknisi + skrip verifikasi pasca-instal per hotel.
- [ ] Folder serah-terima per hotel: backup terakhir + bukti restore drill + checksum rilis.

Fase 1 — operasi 10 hotel (1–2 bulan):
- [ ] Runner migrasi berurutan + verifikasi `source_checksum` per hotel.
- [ ] Monitor backup pusat (baca `backup_runs` + umur file, alarm Telegram).
- [ ] Offsite backup immutable + kebijakan retensi.
- [ ] Vault KMS untuk `APP_ENCRYPTION_KEY` per hotel + prosedur rotasi.

Fase 2 — SaaS beneran (3–6 bulan):
- [ ] Provisioning semi-otomatis → self-signup.
- [ ] Billing (plan/subscription/invoice/gateway, trial→aktif→tangguh).
- [ ] Konsol platform (kesehatan, MRR/churn, audit lintas tenant, impersonate berjejak).
- [ ] Uji isolasi adversarial + DPA/penghapusan data churn.

Fase 3 — scale grup:
- [ ] SSO, tarif pusat, writeback HQ (sengaja OFF hari ini, biarkan OFF).

## 7. Harga jual (keputusan, dalam Rupiah)

Sewa per properti per bulan: Starter ≤20 kamar Rp499rb (+setup 3jt);
Standar ≤50 kamar Rp899rb–1,2jt (+setup 5jt); Grup/Enterprise Rp2–3,5jt
(+setup 10jt). Diskon tahunan: bayar 10 untuk 12 bulan.
Jual putus LISENSI PAKAI (tanpa source): Rp45–85jt/properti + maintenance
tahunan 15–20%.
Jual putus + SOURCE CODE FINAL: **Rp250jt** (ruang nego 200–300jt),
lihat §8. Jangan lepas source di bawah Rp200jt.

## 8. Paket source code final Rp250jt — isi paket

Termasuk: seluruh source + `migrations/` + skrip deploy + `FILE_CHECKSUMS` +
dokumen arsitektur & SOP + training 5 hari + garansi bug 3 bulan + update
kritis 1 tahun + 1x pendampingan instalasi hotel pertama pembeli.
Definisi jujur "source frontend" (2026-10-16, PENTING untuk due diligence):
UI adalah React TERKOMPILASI (`assets/chunks/*.js` + `vendor-react.js`);
file sumber `.jsx/.tsx` TIDAK ada di repo (tidak ada pipeline build
frontend). Edit kecil terbukti bisa langsung di bundle; rebuild dari
sumber bukan bagian paket ini kecuali disepakati terpisah (rujukan:
konsultasi frontend-bundle 2026-10-16).
Tidak termasuk: hosting/infra, custom fitur baru, migrasi data hotel lama
(dihitung terpisah), support on-site di luar training.
Syarat lisensi: pakai untuk perusahaan pembeli sendiri, dilarang jual-ulang/
sub-lisensi source; modul pihak ketiga tetap ikut lisensinya masing-masing.
Escrow: source diserahkan setelah pelunasan 100% (DP 50% saat tanda tangan).

## 9. Konteks pemilik

Pemilik non-teknis, operasional hotel. Prioritas: semua fungsi bisa dibuka
via centangan per akun (keuangan bisa bertindak kayak resepsionis saat
mendesak); owner read-only; tombol Telegram harus muncul otomatis setelah
dicentang + tekan Menu Utama. Target pasar: banyak hotel (SaaS), dua model:
sewa dan putus (+source). Selalu lapor dulu sebelum coding kecuali diminta
langsung; setelah fix → push → tunggu CI hijau → buat ZIP.
