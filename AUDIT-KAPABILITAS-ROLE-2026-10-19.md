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

- **334 cek role keras tersisa di 48 file** (dari 478 baris temuan).
- Capability yang sudah ditegakkan (≥60 titik): `sell_room_access` (16),
  `manage_stay_access` (16), `perform_housekeeping` (8),
  `receive_payment_access` (6), `manage_room_access` (6),
  `view_guest_identity` (5), `perform_night_audit` (2),
  `override_key_checkout`, `manage_operational_settings`, `view_audit_log`,
  `manage_tax_rules`, `correct_booking_audit`, `manage_public_website`.
- **`resolve_sync_conflict` terdaftar + ada checkbox di UI, tetapi BELUM
  ditegakkan di satu titik pun** (kandidat cepat: 040:1185).
- Dinding role keras yang SUDAH diperbaiki: 050 (assert biaya), 080
  (handler beku), 030 ×2 (charge + nego), 040 (pindah kamar), 020 (route nego).

## 3. Yang SENGAJA tetap keras (by design — jangan diutak-atik tanpa keputusan)

Alasan: keamanan uang/identitas, kepatuhan audit, atau infrastruktur.

- **Identitas & sesi**: manajemen staf (050_auth_staff, editor = admin),
  staff savings approval (060, 020_identity), device/session (090:439/451),
  DB config & property setup (019), cluster status (010), channel komunikasi
  (075), receipt storage health (091), OTA cleanup (030), backup (090:1624).
- **Uang & audit**: PO approve/receive (110_growth 351; growth_enterprise_locked),
  AP/folio/loyalty/CRM/adapter (112 — 48 titik), konflik sync transient
  (040:1185 — kandidat capability), override selisih kas (080:5126/5160),
  hapus transaksi historis (040:2505), katalog (100, 099), laporan canonical
  (095), fac (096 sebagian), POS minibar (065) update saja.
- **SDM**: koreksi absensi/clock-out (060_workforce, 058_biometric,
  015_biometric 218), cuti approve (`058_employee_self_service`), slip gaji.
- **HR/operasional pendukung**: assignment service = hanya untuk diri sendiri
  (090:969), blacklist tamu = admin/manager (030:1802, 090:922),
  night-audit toggle = admin (090:1196), absensi night-shift rules (090:1496).
- **Enterprise/growth**: seluruh 112 + 110 + `growth_enterprise_locked`
  (78 titik) — suite terkunci, perubahan butuh persetujuan khusus.
- **Owner read-only**: 20 titik penegakan (`tamasyaIsOwnerRole`,
  `tamasyaEnforceOwnerReadOnly`, `tamasyaOwnerMutationDenied`) — final.

## 4. Kandidat jadi centangan (prioritas operasi harian)

Batch 1 — pakai capability yang SUDAH ada (kode saja, tanpa skema, tes ringan):

| Lokasi | Sekarang | Usulan |
|---|---|---|
| 080:743 tombol "Reservasi Beberapa Kamar" | hard admin/manager/receptionist | `sell_room_access` (backend multiroom 043 sudah capability-aware — tombolnya yang tertinggal) |
| 044:27 `$write` multi-room Telegram | hard admin/manager/receptionist | `sell_room_access`/`manage_stay_access` (selaras `tamasyaMultiRoomRequire`) |
| 080:744 tombol Pusat Tugas/Shift | hard list + `tamasyaShiftOperatorRoles()` duplikat | `tamasyaCanOperateShift()` (satu sumber) |
| 080:6723 menu Status Kamar | hard admin/manager/receptionist | `manage_room_access` |
| 080:717–718 tombol tugas housekeeping | hard admin/manager/receptionist | `perform_housekeeping` (klausa "assignee sendiri" dipertahankan) |
| 040:1185 keputusan konflik sync | hard admin/manager | `resolve_sync_conflict` (capability sudah ada, belum dipakai) |

Batch 2 — perlu keputusan pemilik (menyentuh uang/identitas):

| Lokasi | Sekarang | Usulan |
|---|---|---|
| 080:5126/5160 override selisih setoran kas | admin/manager | `approve_sensitive_actions`? |
| 090:922 blacklist tamu | admin/manager | `approve_sensitive_actions`? atau tetap |
| 110:351 PO approve/receive | admin/manager | `approve_sensitive_actions`? |
| 095 laporan canonical | admin/manager/finance | capability baru `view_report`? |
| 017/018 inbox publik (support/reservasi) | admin/manager/receptionist | capability baru `view_support_inbox`? |

Batch 3 — capability baru (butuh registry + UI + default + tes):
maintenance/facility ops (096), inbox, laporan, dsb.

## 5. Peta jalan menuju "centangan sumber utama"

- **Fase 0 (selesai)**: perbaikan 5 dinding pada alur perpanjangan/biaya
  (`4b14d0b`, `38b7025`) + registry + tes regresi 16 cek.
- **Fase 1 (kode saja — menunggu prioritas pemilik)**: kerjakan Batch 1 di
  atas, satu batch satu commit dengan tes; ganti duplikasi daftar
  (`tamasyaShiftOperatorRoles`, fallback yang tersebar) dengan helper 017.
  Perilaku akun non-centangan TIDAK berubah (fallback role dipertahankan).
- **Fase 2 (butuh persetujuan — menyentuh skema DB & UI)**: halaman
  "Kelola Jabatan" (role = paket preset 19 capability + tab + notifikasi),
  disimpan di tabel/JSON setting baru; `hasCapability` membaca preset ketika
  akun tidak punya override. Data awal = preset default hari ini sehingga
  perilaku identik. UI baru di chunk terpisah — `app-core.js` byte-identik
  (budget 969.808/970.000 B) tidak boleh dibesarkan tanpa renegosiasi budget.
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
