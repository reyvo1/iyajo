# HANDOFF SAAS FINAL — Tamasya Hotel Operations Console

Tanggal: 2026-10-16. Session boleh berganti; agen berikutnya lanjut dari file ini.
Bahasa pemilik: Indonesia. Jawab singkat, konkret, tanpa basa-basi.

## 1. Status saat ini (HEAD `4b14d0b`, CI success, tree bersih)

Kerjaan terakhir (sudah push + ZIP):
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
  statis 050/080).
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
- ZIP produksi terbaru: `tamasya-production-prod-20261019-1101.zip` (374 files,
  berisi fix beku perpanjangan role tercantang + email tersimpan otomatis +
  semua di bawahnya: fix nota email + dot legend + warna kuning housekeeping +
  jalur kirim jujur (SMTP/PHP mail) + log penolakan SMTP). ZIP lama (…2032,
  …1952, …1907, …1738, …1630, …1349, …1835, dst.) usang, jangan dipakai.
- Fakta database (audit 18 Okt): `database_setup.sql` = 116 CREATE TABLE,
  SHA-256 `604f7d466b4e07de4e6f854c2f03a7609b52a08e5cf8b94efacf186aabfc263e`;
  `migrations/` = 21 file. Dokumen rilis lama (HANDOFF.md P0/R3,
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
  **gap mobile**: room detail bottom-sheet TIDAK ADA (klik room tidak buka
  apapun — desktop pakai sidebar), Operasional nav items campur konten.
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
