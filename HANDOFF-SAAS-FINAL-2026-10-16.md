# HANDOFF SAAS FINAL — Tamasya Hotel Operations Console

Tanggal: 2026-10-16. Session boleh berganti; agen berikutnya lanjut dari file ini.
Bahasa pemilik: Indonesia. Jawab singkat, konkret, tanpa basa-basi.

## 1. Status saat ini (HEAD `c6e975f`, CI 9/9 success, tree bersih)

Kerjaan terakhir (sudah push + ZIP):
- `7b21097` Menu Riwayat Tutup Shift + Owner read-only konsisten (Telegram).
- `8d08596` + checksum refresh.
- `d4ded49` Pemasukan transfer tampil di rincian Riwayat Shift.
- `5b75fe1` **Centangan per akun mengendalikan Jual/Inap/Panjar di Telegram & Web.**
- `c6e975f` Penyesuaian test kontrak key-status.
- ZIP produksi terbaru: `tamasya-production-prod-20261016-0000.zip` (347 files).

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
- Perubahan 17 Okt (finance bisa housekeeping): gate `perform_housekeeping` di
  `080_telegram_webhook.php` (×6 + keyboard dinamis), `090_operations_communications.php`
  (×2), `020_identity_access_audit.php`, `040_rooms_checkout_housekeeping.php`,
  `assets/app-core.js`, `assets/chunks/operations.js`, `assets/chunks/local-connect.js`.
- `tests/telegram-key-status-contract-regression.php` — cek gate disesuaikan
  dengan belahan sell/stay.
- `.github/workflows/tamasya-enterprise-rc1-uat.yml`, `scripts/verify-release.sh`
  — daftarkan test baru.
- `FILE_CHECKSUMS.sha256` — WAJIB refresh tiap ada file berubah atau CI
  merah (pelajaran: commit kode dan checksum boleh beda run; run yang lolos
  adalah yang membawa checksum baru).

## 4. Perintah baku setiap selesai kerja (urutan JANGAN dibalik)

1. `php -l` tiap file PHP yang diubah.
2. Minimal: `php tests/capability-sales-regression.php`,
   `php tests/owner-readonly-regression.php`,
   `php tests/telegram-shift-regression.php`,
   `php tests/multi-room-regression.php`,
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
