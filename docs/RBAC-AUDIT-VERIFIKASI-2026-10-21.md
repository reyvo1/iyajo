# Verifikasi Remediasi Audit Total RBAC

Rujukan: `LAPORAN-AUDIT-TOTAL-RBAC-2026-10-20.md` (F-01..F-11).
Rilis: `20261021-rbac-r1` · Commit: `758d136` + perbaikan F-03 residual.
CI: 9/9 job sukses, 1522/1522 assertion UAT, 60/60 browser test.

## 1. Status per temuan

| Temuan | Severity | Status | Bukti implementasi |
|---|---|---|---|
| F-01 Owner read-only dilanggar via `GET role-preset-save` | HIGH | **TERPERBAIKI** | `api/routes/052_role_presets.php`: `$readOnlyCommands` + `$isMutationCommand`; `tamasyaEnforceOwnerReadOnly($staff,$action,$isMutation)` bersifat command-aware; command mutasi wajib POST (405). `api/support/017_authorization_policy.php`: `tamasyaOwnerMutationDenied($user,?bool $isMutation)`. |
| F-02 Deaktivasi capability/tab fail-open | HIGH | **TERPERBAIKI** | `017`: `tamasyaRbacCatalogKeyState(...) === 'inactive'` → `return false`, diperiksa **sebelum** override akun dan sebelum fallback role, pada `hasCapability()` dan `hasDesktopTabAccess()`. State: `active` / `inactive` (deny) / `missing` (key tidak ada → fallback, benar untuk instalasi lama) / `unavailable` (tabel tidak ada → fallback, fail-open yang disengaja & terdokumentasi). |
| F-03 Override lama bertahan saat role/jabatan berubah | MEDIUM | **TERPERBAIKI** | `api/routes/050_auth_staff.php`: `$titleActuallyChanged` dihitung dari perubahan `role` ATAU `job_title`; override akun di-reset ke `NULL` bila `permissions` tidak dikirim eksplisit. `NULL` membuat akun kembali mengikuti preset jabatan. |
| F-04 `staff` GET tanpa route guard; data sensitif via tab `leaves` | MEDIUM | **TERPERBAIKI** | `050_auth_staff.php`: scope `leave` → `requireRoles(['admin','manager'])` + `requireDesktopTabAccess('leaves')`; GET scope penuh → `requireDesktopTabAccess('staff',['admin','owner'])` (wajib punya tab `staff`); mutasi → `requireRoles(['admin'])`. Proyeksi `salary`/`permissions`/`telegramChatId`/`twoFactorActive` hanya untuk `admin`/`owner`. |
| F-05 Pajak: backend dinamis, frontend statis | MEDIUM | **TERPERBAIKI** | `assets/chunks/operations.js`: `taxAllowed=tamasyaHasCapability("manage_tax_rules",["admin","manager"])`; `Hn=tamasyaHasCapability("perform_night_audit",[…])` (paksa `finance` dihapus); `action:taxAllowed?` dan `children:taxAllowed&&`. |
| F-06 Gate frontend masih statis | MEDIUM | **TERPERBAIKI (sebagian, lihat §3)** | 44 gerbang `tamasyaHasCapability(...)` pada 10 chunk untuk 13 capability. `currentPermissions` berasal dari `tamasyaSessionPermissions()` (preset DB + override akun), jadi frontend mengikuti preset. Peta role statis (`CA`, `W_`, `Eh`, `PK`, `Uo`) tetap ada **sebagai fallback**, bukan sebagai sumber kebenaran. |
| F-07 Job title bukan role keamanan baru | MEDIUM | **TERDOKUMENTASI** | `api/support/022_dynamic_rbac.php`:/base role baru tidak bolehvia insert manual ke `rbac_roles`; `api/routes/052_role_presets.php` menolak base role di luar 9 jabatan kanonik. |
| F-08 Preset notifikasi Telegram belum dipakai | LOW | **TERPERBAIKI** | `api/modules/comms/050_integrations_telegram_mail.php`: `$permissionSource = tamasyaRbacEffectivePermissions($staff,$pdo)`; override yang rusak JSON tidak pernah jatuh ke default role STUDI (fail-closed). |
| F-09 Public actions perlu review | INFO | **SPOT-CHECK SAJA (lihat §4)** | Tidak ditemukan lubang terbuka pada sampel yang diperiksa. Audit penuh tetap direkomendasikan. |
| F-10 `gemini-test`/`email-diagnostics` | INFO | **BUKAN TEMUAN** | Sesuai laporan: sudah POST + role admin eksplisit. |
| F-11 UAT aman lulus | INFO | **TERVERIFIKASI** | CI 9/9 hijau, 1522/1522 assertion UAT, 60/60 browser test, tidak ada test yang menyentuh data produksi. |

## 2. Temuan baru dari audit ulang

### N-01 — SISAAN F-03: jabatan kustom mengunci akun pada salinan preset (MEDIUM, **SUDAH DIPERBAIKI**)

Gejala: saat jabatan/role berubah, kode lama menulis **salinan preset** ke `staff.permissions` untuk jabatan kustom. Salinan itu tersimpan sebagai **override akun**, dan override menang atas preset. Akibatnya akun tersebut **tidak lagi mengikuti perubahan berikutnya** di panel "Kelola Jabatan" — akun terkunci selamanya pada paket saat Perpindahan jabatan.

Bukti (diuji di MySQL/MariaDB dengan `staff_job_titles` + `role_capability_presets` nyata):

```
staff.permissions = NULL     -> view_reports = true   (ikut preset, benar)
staff.permissions = SNAPSHOT -> view_reports = false  (terkunci, salah)
```

Perbaikan: `api/routes/050_auth_staff.php` kini selalu menulis `NULL` saat jabatan/role berubah dan `permissions` tidak dikirim eksplisit. Override per akun tetap bisa ditulis eksplisit melalui kolom `permissions`.

Assertion `tests/role-preset-regression.php` diperketat: sekarang **menolak** pola `json_encode(tamasyaRolePresetDefaultFor($jobTitle,$role))` dan mensyaratkan `$params[] = null;`.

### N-02 — Owner melewati pemeriksaan katalog `inactive` (INFO, perilaku yang disengaja)

`hasCapability()`/`hasDesktopTabAccess()` mengembalikan hak Owner (`view_*` / semua tab) **sebelum** status katalog diperiksa. Akibatnya menonaktifkan capability di katalog tidakmemo Owner.

Ini konsisten dengan model "Owner = superuser read-only" (§3.3 presedensi) dan bukan celah escalasi, tetapi harus disadari saat mengelola katalog. Keputusan eksplisit: **katalog `inactive` tidak berlaku untuk Owner.**

### N-03 — Presedensi di §3.3 laporan lama sudah tidak akurat (dokumentasi)

Presedensi yang berlaku sekarang:

`hasCapability()` / `hasDesktopTabAccess()`:
1. Owner → `view_*` saja (atau semua tab) — **tidak**-see katalog.
2. Katalog `inactive` → **DENY** (kemenangan atas override dan fallback).
3. Override akun (`staff.permissions`).
4. Preset jabatan/role (`role_capability_presets`).
5. Fallback array role statis.

Langkah 2 adalah tambahan dari F-02 dan belum ada di teks laporan 2026-10-20.

### N-04 — Nomor baris bukti di laporan lama sudah bergeser (dokumentasi)

Semua perbaikan di atas menggeser nomor baris yang dikutip sebagai bukti di §5 dan §6. Nomor baris lama tidak lagi valid; acuan yang stabil adalah nama fungsi dan file.

### N-05 — Remediasi F-05/F-06 sempat merusak lima lazy chunk (HIGH, **SUDAH DIPERBAIKI**)

Penambahan helper capability pada 12 chunk_MINIFIED menyebabkan 5 `SyntaxError` di lingkup modul ES: deklarasi ganda (`ma`, `j/k/N/w`, `taxAllowed`), sisipan yang menggigit `const`, dan satu `&&` yang salah ketik menjadi `?`. Gejalanya bukan error PHP, melainkan chunk gagal dimuat → `tamasyaRecoverChunkLoad()` → `location.reload()` → UAT gagal dengan "Execution context was destroyed".

Perbaikan: helper di-rename ke `tamasyaHasCapability` (nama unik), deklarasi ganda dihapus, `staff.js` dikembalikan ke sumber pra-audit, dan `&&` dipulihkan. `tests/multi-room-ui-regression.mjs` kini **memulihkan** link-check ES-module asli dan menambah guard: seluruh modul aset harus parse sebagai ES module, helper tidak boleh dipakai sebelum diinisialisasi, dan setiap komponen capability-gated wajib menerima `currentPermissions` di setiap render site.

## 3. Cakupan F-06 secara jujur

Yang **sudah** capability-based (44 gerbang, 13 capability, 10 chunk): `view_reports`, `manage_operational_settings`, `reconcile_payments`, `view_guest_identity`, `perform_housekeeping`, `manage_tax_rules`, `view_audit_log`, `resolve_night_audit`, `perform_night_audit`, `manage_room_access`, `manage_facilities`, `manage_backup`, `correct_booking_audit`.

Yang **masih** memakai peta role statis sebagai sumber kebenaran utama:
- `assets/app-core.js`: `CA` (role→tab), `PK`/`Uo` (tab→role, 4 tab sensitif), `Eh`/`PA` (paket default per role).
- `assets/chunks/app-shell.js`: `Lt`/`Sa`/`Fn` di `operations.js`, `rpRoleLabels` di `staff.js`.

Peta statis tersebut kini acting sebagai **fallback** (`_K(role, O.desktopTabs, O.capabilities)` memakai `O.desktopTabs` dari server lebih dulu), sehingga preset tetap governs. Yang belum tuntas: **tab `staff`, `config`, `db_config`, `support` masih dikunci daftar role hardcoded** (`PK`), dan `Eh`/`PA` masih menyisakan paket default statis per role. Keduanya disengaja sebagai pengaman, tetapiESTRUKTUR Anyaman role statis belum dihapus total.

## 4. F-09 spot-check (bukan audit penuh)

Public action yang diperiksa beserta pengawannya:

| Action | Pengawan |
|---|---|
| `telegram-webhook` | secret token + `hash_equals` |
| `growth-payment-webhook` | signature provider + rate limit per provider |
| `growth-channel-webhook` | signature channel + rate limit per channel |
| `communication-webhook`, `node-cluster-*` | `tamasyaVerify…` (verifikasi node/klaster) |
| `biometric-device-webhook` | `tamasyaBiometricAuthenticateDevice()` (HMAC perangkat), 401 bila gagal |
| `public-reservation-request` | POST only + `tamasyaPublicRequireAllowedOrigin()` + rate limit |
| `public-help-chat` | token + rate limit |

Tidak ditemukan endpoint publik yang menulis tanpa pengawan pada sampel ini. Audit penuh 25 public action tetap direkomendasikan.

## 5. Verifikasi lain pada rilis ini

- **Zona waktu UAT:** container CI memakai UTC, PHP memakai `APP_TIMEZONE` (Asia/Makassar). Skenario UAT kini memakai tanggal server (`tests/uat_rc1/server_time.py`), bukan jam mesin. Ini yang menggagalkan Full UAT pada jendela 8 jam per hari.
- **Cache aset:** versi rilis dinaikkan ke `20261021-rbac-r1`; token React core tetap terpisah (`20261021-rbac-core1`) agar service worker tidak memprecache dua identitas React.
- **Database:** 125 tabel, seluruh kolom/index/FK identik dengan baseline; 9 tabel RBAC ter-seed penuh; nilai preset sudah memuat `view_reports`, `daily_summary`, `view_support_inbox`, `manage_facilities`.
