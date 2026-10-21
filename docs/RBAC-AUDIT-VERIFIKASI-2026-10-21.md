# Verifikasi Remediasi Audit Total RBAC

Rujukan: `LAPORAN-AUDIT-TOTAL-RBAC-2026-10-20.md` (F-01..F-11).
Rilis: `20261021-r18` · Commit: `HEAD` + perbaikan F-03 residual.
CI: 9/9 job sukses, (jumlah assertion UAT fluctuates per run; rujuk run CI terakhir), 50/50 browser test (25 uji x 2 proyek).

## 1. Status per temuan

| Temuan | Severity | Status | Bukti implementasi |
|---|---|---|---|
| F-01 Owner read-only dilanggar via `GET role-preset-save` | HIGH | **TERPERBAIKI** | `api/routes/052_role_presets.php`: `$readOnlyCommands` + `$isMutationCommand`; `tamasyaEnforceOwnerReadOnly($staff,$action,$isMutation)` bersifat command-aware; command mutasi wajib POST (405). `api/support/017_authorization_policy.php`: `tamasyaOwnerMutationDenied($user,?bool $isMutation)`. |
| F-02 Deaktivasi capability/tab fail-open | HIGH | **TERPERBAIKI** | `017`: `tamasyaRbacCatalogKeyState(...) === 'inactive'` → `return false`, diperiksa **sebelum** override akun dan sebelum fallback role, pada `hasCapability()` dan `hasDesktopTabAccess()`. State: `active` / `inactive` (deny) / `missing` (key tidak ada → fallback, benar untuk instalasi lama) / `unavailable` (tabel tidak ada → fallback, fail-open yang disengaja & terdokumentasi). |
| F-03 Override lama bertahan saat role/jabatan berubah | MEDIUM | **TERPERBAIKI** | `api/routes/050_auth_staff.php`: `$titleActuallyChanged` dihitung dari perubahan `role` ATAU `job_title`; override akun di-reset ke `NULL` bila `permissions` tidak dikirim eksplisit. `NULL` membuat akun kembali mengikuti preset jabatan. |
| F-04 `staff` GET tanpa route guard; data sensitif via tab `leaves` | MEDIUM | **TERPERBAIKI** | `050_auth_staff.php`: scope `leave` → `requireRoles(['admin','manager'])` + `requireDesktopTabAccess('leaves')`; GET scope penuh → `requireDesktopTabAccess('staff',['admin','owner'])` (wajib punya tab `staff`); mutasi → `requireRoles(['admin'])`. Proyeksi `salary`/`permissions`/`telegramChatId`/`twoFactorActive` hanya untuk `admin`/`owner`. |
| F-05 Pajak: backend dinamis, frontend statis | MEDIUM | **TERPERBAIKI** | `assets/chunks/operations.js`: `taxAllowed=tamasyaCap("manage_tax_rules",["admin","manager"])`; `Hn=tamasyaCap("perform_night_audit",[…])` (paksa `finance` dihapus); `action:taxAllowed?` dan `children:taxAllowed&&`. |
| F-06 Gate frontend masih statis | MEDIUM | **TERPERBAIKI (sebagian, lihat §3)** | 45 gerbang `tamasyaCap(...)` pada 10 chunk untuk 13 capability. `currentPermissions` berasal dari `tamasyaSessionPermissions()` (preset DB + override akun), jadi frontend mengikuti preset. Peta role statis (`CA`, `W_`, `Eh`, `PK`, `Uo`) tetap ada **sebagai fallback**, bukan sebagai sumber kebenaran. |
| F-07 Job title bukan role keamanan baru | MEDIUM | **TERDOKUMENTASI** | `api/support/022_dynamic_rbac.php`:/base role baru tidak bolehvia insert manual ke `rbac_roles`; `api/routes/052_role_presets.php` menolak base role di luar 9 jabatan kanonik. |
| F-08 Preset notifikasi Telegram belum dipakai | LOW | **TERPERBAIKI** | `api/modules/comms/050_integrations_telegram_mail.php`: `$permissionSource = tamasyaRbacEffectivePermissions($staff,$pdo)`; override yang rusak JSON tidak pernah jatuh ke default role STUDI (fail-closed). |
| F-09 Public actions perlu review | INFO | **SELESAI (lihat §4)** | Seluruh 27 public action diaudit: setiap yang menulis punya tanda tangan/secret/origin allow-list/rate limit. Tidak ada lubang terbuka. |
| F-10 `gemini-test`/`email-diagnostics` | INFO | **BUKAN TEMUAN** | Sesuai laporan: sudah POST + role admin eksplisit. |
| F-11 UAT aman lulus | INFO | **TERVERIFIKASI** | CI 9/9 hijau, (jumlah assertion UAT fluctuates per run; rujuk run CI terakhir), 50/50 browser test (25 uji x 2 proyek), tidak ada test yang menyentuh data produksi. |

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

Perbaikan: helper di-rename ke `tamasyaCap` (nama unik), deklarasi ganda dihapus, `staff.js` dikembalikan ke sumber pra-audit, dan `&&` dipulihkan. `tests/multi-room-ui-regression.mjs` kini **memulihkan** link-check ES-module asli dan menambah guard: seluruh modul aset harus parse sebagai ES module, helper tidak boleh dipakai sebelum diinisialisasi, dan setiap komponen capability-gated wajib menerima `currentPermissions` di setiap render site.

### N-06 — Tiga test RBAC utama tidak pernah dijalankan CI (MEDIUM, **SUDAH DIPERBAIKI**)

Audit ulang terhadap `.github/workflows/tamasya-enterprise-rc1-uat.yml` menunjukkan **10 file test di `tests/` tidak dieksekusi** oleh source regression suite, termasuk:

- `tests/dynamic-rbac-regression.php` (Dynamic RBAC)
- `tests/role-preset-regression.php` (Role preset / Kelola Jabatan)
- `tests/rbac-e2e-regression.php` (E2E RBAC Flow)

Artinya regression F-01..F-08 **tidak pernah diperiksa CI** — itulah alasan bug sisaan F-03 (N-01) bisa lolos tanpa terdeteksi.

Perbaikan: ketiganya kini menjadi bagian source regression suite (bersama `smtp-diagnostics-regression.php`, `negotiation-settlement-characterisation.php`, dua `r164-custody-*`, dan `owner-read-contract-regression.php`). Jumlah perintah source suite naik dari 95 menjadi 103.

`tests/owner-read-contract-regression.php` dijalankan dua kali: di source suite (35 cek sumber) dan di job MariaDB dengan skema kanonik penuh untuk uji perilaku nyata (41 cek).

Yang tetap di luar CI karena memang butuh layanan eksternal (bukan kelalaian):
- `tests/telegram-markdown-regression.php` — memanggil API Telegram sungguhan.
- `tests/catalog-price-browser-local.py` — butuh browser lokal.
- `tests/refresh_audit_manifest.py` — utilitas pemeliharaan, bukan test.

## 3. Cakupan F-06 secara jujur

Yang **sudah** capability-based (45 gerbang, 13 capability, 10 chunk): `view_reports`, `manage_operational_settings`, `reconcile_payments`, `view_guest_identity`, `perform_housekeeping`, `manage_tax_rules`, `view_audit_log`, `resolve_night_audit`, `perform_night_audit`, `manage_room_access`, `manage_facilities`, `manage_backup`, `correct_booking_audit`.

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

Tidak ditemukan endpoint publik yang menulis tanpa pengawan pada sampel ini. Audit penuh 27 public action tetap direkomendasikan.

## 4b. Kontrak Owner: "melihat seperti Admin, read-only"

Putusan pemilik: Owner boleh melihat **sama seperti Admin**, tetapi **tidak boleh mengubah apa pun**.

Implementasi:
- `api/support/017_authorization_policy.php` → `hasCapability()` untuk Owner mengambil capability dari **preset Admin** (mengikuti status katalog), dengan lantai aman: semua capability `view_*` selalu boleh even tanpa PDO.
- `tamasyaSessionPermissions()` menyusun payload Owner dari preset Admin: seluruh tab aktif, capability = capability Admin, `readOnly = true`, notifikasi Telegram dimatikan (mengirim pesan adalah tindakan, bukan pembacaan).
- Larangan menulis **tidak** berasal dari penurunan capability, melainkan dari penjaga metode: `api.php` memanggil `tamasyaEnforceOwnerReadOnly()` sebelum route dispatch, sehingga setiap metode selain GET/HEAD/OPTIONS ditolak untuk Owner. Semua jalur tulis juga mewajibkan POST.
- `assets/owner-readonly-policy.js` menutup tombol/lapis tulis di sisi UI. Pencocokan label aksi tulis sekarang memakai **AWAL kata** (`\\w*`), sehingga "Tambahkan", "Terapkan", "Terbitkan", "Naikkan" ikut terdeteksi; sebelumnya hanya cocok pada kata utuh.

Konsekuensi yang disepakati: katalog `inactive` berlaku **sama** untuk Admin dan Owner (Owner tidak lagi punya pengecualian), karena keduanya adalah tampilan dari preset yang sama.

## 4c. Audit penuh F-09 (27 public action)

| Action | Pengawan |
|---|---|
| `status`, `ping` | tanpa autentikasi, hanya status baca |
| `login`, `verify-2fa`, `refresh-session` | POST + rate limit + secret/token |
| `telegram-webhook` | secret token + `hash_equals` |
| `node-sync-snapshot` | tanda tangan node (HMAC) |
| `node-cluster-peer-status`, `node-cluster-control` | tanda tangan node + POST + auth |
| `node-cluster-telegram-webhook`, `node-cluster-telegram-delivery`, `node-cluster-channel-webhook` | `tamasyaVerifyNodeRequest()` + POST + kesesuaian cluster/primary |
| `communication-webhook` | `verifyInbound()` adapter + POST + provider Telegram dikecualikan + klaim event idempoten |
| `growth-payment-webhook`, `growth-channel-webhook` | autentikasi tanda tangan provider + rate limit + POST + 401 fail-closed |
| `public-site-config`, `public-bootstrap`, `public-room-types`, `public-room-availability`, `public-promotions`, `public-reservation-request`, `public-contact`, `public-help-chat`, `public-help-chat-sync` | POST + origin allow-list + rate limit + property ready |
| `biometric-device-webhook` | autentikasi perangkat (HMAC) + POST + 401 |

Tidak ada endpoint publik yang menulis tanpa pengawan. Regresi ini dijaga `tests/owner-read-contract-regression.php` (35 cek sumber + perilaku DB).

## 5. Verifikasi lain pada rilis ini

- **Zona waktu UAT:** container CI memakai UTC, PHP memakai `APP_TIMEZONE` (Asia/Makassar). Skenario UAT kini memakai tanggal server (`tests/uat_rc1/server_time.py`), bukan jam mesin. Ini yang menggagalkan Full UAT pada jendela 8 jam per hari.
- **Cache aset:** versi rilis dinaikkan ke `20261021-r18`; token React core tetap terpisah (`20261021-core1`) agar service worker tidak memprecache dua identitas React. Panjang token sengaja dijaga sama dengan rilis sebelumnya supaya budget "initial static module graph" (970.000 byte) tidak tergerus oleh bump versi.
- **Budget aset static:** 969.929 / 970.000 byte (slack 71 byte). Helper capability frontend dinamai `tamasyaCap` (bukan `tamasyaHasCapability`) supaya 45 pemanggilan tidak menambah ukuran bundel yang dimuat awal.
- **Database:** 125 tabel, seluruh kolom/index/FK identik dengan baseline; 9 tabel RBAC ter-seed penuh; nilai preset sudah memuat `view_reports`, `daily_summary`, `view_support_inbox`, `manage_facilities`.


### 4d. Public action tambahan: `public-review`

`public-review` (aksi `087_guest_reviews.php`) bersifat publik: tamu memakai
token undangan sekali pakai untuk memberi penilaian. Penawasan: token di
verifikasi, hanya bisa dipakai satu kali, punya kedaluwarsa, dan jawaban
tamu disimpan sebagai data ulasan (bukan data roam). Karena menulis ke
database, aksi ini ikut daftar public action yang diaudit pada bagian ini.
