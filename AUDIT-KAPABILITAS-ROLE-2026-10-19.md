# AUDIT KAPABILITAS & ROLE — 2026-10-19

Dokumen hidup untuk menjawab dua hal: (1) di mana sistem izin masih "statis"
(daftar role keras) dan (2) peta jalan menuju centangan sebagai sumber utama.
Metode: pemindaian mekanis SELURUH `api/**/*.php` + `assets/**/*.js`
(pola `requireRoles`, `in_array` role, perbandingan `role===`, `hasCapability`,
`requireCapability`, owner read-only), ditambah verifikasi 55 suite
`scripts/verify-release.sh` + CI. Diperbarui setiap batch dikerjakan.

## 1. Arsitektur izin saat ini (fakta, bukan asumsi)

Tiga lapis yang sudah ada:

1. **Registry 19 capability** — `api/support/017_authorization_policy.php:84`:
   `approve_sensitive_actions, manage_sessions, resolve_sync_conflict,
   manage_tax_rules, manage_backup, reconcile_payments, correct_booking_audit,
   manage_room_access, perform_night_audit, perform_housekeeping,
   resolve_night_audit, manage_operational_settings, override_key_checkout,
   manage_public_website, sell_room_access, manage_stay_access,
   receive_payment_access, view_audit_log, view_guest_identity`.
2. **Penegakan** — `hasCapability($user,$cap,$fallbackRoles)` (017:134):
   centangan akun (kolom `staff.permissions` JSON) menang; bila tidak ada
   override → jatuh ke daftar fallback role. `requireCapability` (017:146)
   = versi HTTP 403+exit (web). Owner: hanya `view_*` yang true (read-only).
3. **UI editor** — `assets/chunks/staff.js` (checkbox 19 capability + tab +
   notifikasi, label Indonesia) + preset default per role di
   `assets/app-core.js` fungsi `Eh()`/`PA()` (frontend saja; server tetap
   penentu akhir).

Kesimpulan: fleksibilitas centangan sudah ada, MASALAHNYA ada dua —
(a) ±334 titik kode masih memakai daftar role keras alih-alih capabilitas,
(b) preset/peran masih berbasis nama role (tidak ada halaman kelola "role =
paket centangan").

## 2. Statistik pemindaian (19 Okt 2026)

- **334 cek role keras di 48 file** (pemindaian awal 19 Okt; setelah Batch
  1+2 `7f81438`+`a8f34e5` ±40 titik dikonversi ke centangan — daftar lengkap
  di §4; sisa yang keras = pemisahan tugas keuangan, HR/absensi, 112, dan
  kandidat yang butuh capability baru).
- Capability yang sudah ditegakkan (≥60 titik): `sell_room_access` (16),
  `manage_stay_access` (16), `perform_housekeeping` (8),
  `receive_payment_access` (6), `manage_room_access` (6),
  `view_guest_identity` (5), `perform_night_audit` (2),
  `override_key_checkout`, `manage_operational_settings`, `view_audit_log`,
  `manage_tax_rules`, `correct_booking_audit`, `manage_public_website`.
- **`resolve_sync_conflict` kini ditegakkan** di 010:218
  (`node-sync-conflict-resolve` → `requireCapability`; referensi "040:1185" di
  draf awal dokumen ini keliru — situs aslinya 010:218).
- Dinding role keras yang SUDAH diperbaiki: 050 (assert biaya), 080
  (handler beku), 030 ×2 (charge + nego), 040 (pindah kamar), 020 (route nego).
- **Batch 1+2 dieksekusi** (`7f81438`, `a8f34e5`) — rincian di §4: 080
  telegram (kas/shift/room/housekeeping/selisih kas), 044 multi-room, 090
  (blacklist, lost-found, maintenance, hold, shift web, smart-lock), 030:1802
  override booking blacklist, 110 po-status approved/received, 010 sync
  conflict. Night audit sengaja TIDAK dimigrasi (kontrak R16.4 dipin tes).

## 3. Yang SENGAJA tetap keras (by design — jangan diutak-atik tanpa keputusan)

Alasan: keamanan uang/identitas, kepatuhan audit, atau infrastruktur.

- **Identitas & sesi**: manajemen staf (050_auth_staff, editor = admin),
  staff savings approval (060, 020_identity), device/session (090:439/451),
  DB config & property setup (019), cluster status (010), channel komunikasi
  (075), receipt storage health (091), OTA cleanup (030), backup (090:1624).
- **Uang & audit**: PO create/link-payment & transisi lain (110_growth;
  `growth_enterprise_locked`), AP/folio/loyalty/CRM/adapter (112 — 48 titik),
  hapus transaksi historis (040:2505), katalog (100, 099), laporan canonical
  (095), fac (096 sebagian), POS minibar (065) update saja. (PO
  approve/receive + override selisih kas SUDAH jadi centangan
  `approve_sensitive_actions`; konflik sync SUDAH jadi `resolve_sync_conflict`.)
- **SDM**: koreksi absensi/clock-out (060_workforce, 058_biometric,
  015_biometric 218), cuti approve (`058_employee_self_service`), slip gaji.
- **HR/operasional pendukung**: assignment service = hanya untuk diri sendiri
  (090:969), absensi night-shift rules (090:1496). (Blacklist tamu 030:1802 +
  090:922 kini centangan `approve_sensitive_actions` fallback admin/manager;
  night-audit toggle tetap admin-only BY DESIGN — kontrak R16.4 dipin
  `tests/night-audit-mode-regression.php:17`.)
- **Enterprise/growth**: seluruh 112 + 110 + `growth_enterprise_locked`
  (78 titik) — suite terkunci, perubahan butuh persetujuan khusus.
- **Owner read-only**: 20 titik penegakan (`tamasyaIsOwnerRole`,
  `tamasyaEnforceOwnerReadOnly`, `tamasyaOwnerMutationDenied`) — final.

## 4. Kandidat jadi centangan (prioritas operasi harian)

Batch 1 — ✅ SELESAI `7f81438` (capability eksisting, tanpa skema):

| Lokasi | Hasil |
|---|---|
| 080:743 tombol "Reservasi Beberapa Kamar" | `sell_room_access` + `tamasyaHasRoomsWorkspaceAccess` |
| 044:27 `$write` multi-room Telegram | `sell_room_access` + workspace |
| 080:744–746 tombol Pusat Tugas/Kas & Shift | satu predikat `tamasyaCanOperateShift()` atau `receive_payment_access` |
| 080:6725 `/update_status` + 1024 gerbang roomRead | `manage_room_access` (+finance; owner read-only tetap) |
| 080:717–718 + 1336/1345 + 4753 tugas housekeeping | `perform_housekeeping` (klausa assignee dipertahankan) |
| 010:218 keputusan konflik sync | `resolve_sync_conflict` (situs asli; "040:1185" keliru) |
| 080:1302/4052/4084/4163/4172/4193/4200/4643/6388/7236/7247/7279/774 shift | satu predikat shift P (open/close/menu/pending/diagnostik) |

Batch 2 — ✅ SELESAI `7f81438`+`a8f34e5` (disetujui pemilik "kerjakan semuanya"):

| Lokasi | Hasil |
|---|---|
| 080:5126/5160 override selisih setoran kas | `approve_sensitive_actions` fallback admin/manager |
| 090:922 status blacklist + 030:1802 override booking blacklist | `approve_sensitive_actions` fallback admin/manager |
| 110:351 PO approve/receive | `approve_sensitive_actions` fallback admin/manager; closed tetap admin/manager/finance |
| 090 lost-found-secure/review pembatalan/hold open-release | `manage_room_access` fallback admin/manager |
| 090 shift open/close web + smart-lock manual-complete | `receive_payment_access` / `override_key_checkout` |
| 090:1193 night-audit toggle | TIDAK dimigrasi — by design dipin kontrak R16.4 |
| 095 laporan canonical, 017/018 inbox | ditunda — butuh capability BARU → Fase 3 (desain dulu) |

Catatan: situs pemisahan tugas keuangan yang sengaja tetap keras — 080:138
(telegram-clear), 806 (keyboard admin), 3348/3436/3579/3642 (pemasukan/
pengeluaran/laporan kas), 6922/6930/7003/7051/7113 (teks laporan), 110
create/link-payment, 112 (48 titik).

## 5. Peta jalan menuju "centangan sumber utama"

- **Fase 0 (selesai)**: perbaikan 5 dinding pada alur perpanjangan/biaya
  (`4b14d0b`, `38b7025`) + registry + tes regresi 16 cek.
- **Fase 1 (selesai)**: Batch 1+2 dikerjakan (`7f81438`, `a8f34e5`); tes
  diperluas 16 → 37 cek; fallback role dipertahankan (perilaku akun
  non-centangan TIDAK berubah); pesan penolakan tidak diubah. Sisa kandidat
  (095/017/018, maintenance 096) butuh capability baru → Fase 3.
- **Fase 2 (selesai — `a514025`)**: disetujui pemilik ("Setujui —
  implementasikan sekarang"), diimplementasikan sesuai §7: tabel
  `role_capability_presets` + migrasi `20261019_ROLE_CAPABILITY_PRESETS.sql`
  (splice `database_setup.sql` → 117 tabel), `api/support/021_role_presets.php`
  (seed cermin preset editor `app-core.js`; paritas `Eh`/`PA` terverifikasi
  untuk 9 jabatan), `api/routes/052_role_presets.php`
  (list/save/preview/apply admin-only, owner read-only, audit per akun +
  ringkasan, `confirm=true` + `updatedAt`), panel "Kelola Jabatan" di
  `assets/chunks/staff.js` via bridge `Qle` (`app-core.js` tetap
  byte-identik), suite `tests/role-preset-regression.php` (39 cek);
  verify-release 55 → 56 suite hijau; CI `39210729652` hijau; ZIP
  `tamasya-production-prod-20261019-1525.zip`. Default perilaku belum
  berubah sampai admin menekan "Terapkan".
- **Fase 3**: hapus bertahap cek keras Batch 2/3 per fitur, masing-masing
  dengan tes regresi + audit log, setelah Fase 2 stabil.

## 6. Aturan keselamatan (tidak boleh dilanggar)

1. Setiap perubahan kode lewat alur baku: `php -l` → tes terkait → checksum →
   `./scripts/verify-release.sh` → commit → push → CI → ZIP → HANDOFF.
2. Pola `function_exists('hasCapability')` + fallback daftar lama dipertahankan
   agar modul yang dimuat tanpa 017 (CLI/test) tidak berubah perilaku.
3. Akun tanpa centangan: perilaku harus IDENTIK dengan sebelum perubahan.
4. `growth_enterprise_locked`, `assets/app-core.js` (byte-identik), skema DB:
   hanya berubah dengan persetujuan eksplisit pemilik.
5. Setiap batch baru menambah cek di
   `tests/telegram-extension-capability-regression.php` atau suite baru.

## 7. Desain Fase 2 — "Kelola Jabatan" (disetujui & diimplementasikan `a514025`)

Prinsip: **017 `hasCapability` tidak diubah** (murni array, tanpa PDO — dipakai
banyak modul & test CLI). Paket jabatan = data + aksi eksplisit, bukan logika
baru di jalur izin. Nol perubahan perilaku sebelum pemilik menekan "Terapkan".

1. **Model data** — tabel baru `role_capability_presets` (`role` PK,
   `permissions` JSON, `updated_by`, `updated_at`), migrasi
   `migrations/20261019_ROLE_CAPABILITY_PRESETS.sql` + update
   `database_setup.sql` (116 → 117 tabel). Seed awal = nilai `Eh(role)` +
   `W_(role)` di app-core.js apa adanya (termasuk special-case finance
   `perform_night_audit=true` dari `PA()`); role `owner` TIDAK di-seed (paket
   tetap read-only) dan dikecualikan dari apply. Paritas seed vs `Eh()/W_()`
   dibuktikan tes statis.
2. **Semantik** — override akun (`staff.permissions`) tetap menang; paket
   berlaku lewat aksi **"Terapkan ke jabatan"** yang menulis override lengkap
   (desktopTabs+capabilities+telegramNotifications) ke tiap akun role
   tersebut (skip owner, skip baris korup — perbaiki dulu via repair). Karena
   `requireAuth` memuat `staff.*` segar per request (020_identity:1484),
   efeknya langsung pada request berikutnya tanpa login ulang.
   *Alasan bukan "hasCapability membaca preset":* situs pemanggil punya
   fallback berbeda per fitur (contoh nyata `manage_room_access`: 080:1024
   termasuk finance vs 090 lost-found hanya admin/manager) → preset global
   tunggal justru mengubah perilaku default. Auto-inherit dicatat sebagai
   kandidat Fase 3 per-fitur (setelah paket stabil & disetujui).
3. **Endpoint** — file baru `api/routes/052_role_presets.php` (+ include di
   `router.php`): `role-preset-list`, `role-preset-save` (validasi 19 cap +
   whitelist tab), `role-preset-apply` (dry-run diff → konfirmasi → batch
   update; audit log per akun + ringkasan). Guard `requireRoles(['admin'])`;
   owner otomatis ditolak `tamasyaEnforceOwnerReadOnly`.
4. **UI** — panel "Kelola Jabatan" di halaman Staf (`assets/chunks/staff.js`;
   file ini boleh berubah, hanya `app-core.js` yang byte-identik). Prefill
   editor staf beralih ke endpoint preset dengan fallback otomatis ke
   `Eh()/PA()` bila DB kosong/gagal; diff preview wajib sebelum apply.
5. **Tes & rilis** — suite baru `tests/role-preset-regression.php` (struktur,
   seed, guard endpoint, paritas preset) masuk daftar
   `scripts/verify-release.sh` (55 → 56 suite); alur rilis baku + ZIP baru.
6. **Dampak ke Fase 3** — capability baru (095 laporan → mis. `view_report`,
   017/018 inbox → mis. `view_support_inbox`, 096 fasilitas) cukup menambah
   registry 19 → 22 + checkbox di staff.js + seed preset DB. Catatan budget:
   sisa `app-core.js` 192 B tidak cukup untuk key preset baru → saat DB mati,
   prefill cap baru kosong (server tetap fallback role); bila pemilik ingin
   fallback 100%, perlu renegosiasi budget `app-core.js`.

**Keputusan yang diminta dari pemilik:** (a) setuju tabel + migrasi baru;
(b) setuju semantik "paket diterapkan lewat aksi Terapkan" (bukan auto-inherit
di `hasCapability`); (c) guard admin-only; (d) eksekusi sekarang atau tunda.
