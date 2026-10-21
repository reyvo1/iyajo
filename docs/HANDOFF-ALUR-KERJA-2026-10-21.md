# Konteks & Alur Kerja TAMASYA — handoff untuk sesi berikutnya

Rilis: `20261021-r18` · commit: `HEAD` · CI: **9/9 hijau** (run terakhir + transkrip di repo).
Skema: **131 tabel** · suite lokal: **116 perintah** (harus `set -euo pipefail`).
Paket deploy: `tamasya-production-20261021-r18.zip` (377 berkas; sha256 paket ada di `deploy/DEPLOY-ID.md`) +
`deploy/DEPLOY-ID.md` + `deploy/IMPORT-RBAC-PHPMYADMIN.sql`.

Dokumen ini adalah sumber konteks tunggal. **Baca ini dulu sebelum mengubah apa pun.**
Kalau konteks sesi ini hilang, dokumen ini + `git log` + CI sudah cukup untuk
melanjutkan tanpa kehilangan apa pun.

### 0.1 Commit terakhir (semua hijau, urut dari yang terbaru)

| Commit | Isi |
|---|---|
| `0c174cd` | Panduan di dalam ZIP tidak lagi menyebut sha256 paketnya sendiri (mustahil); paket menyertakan `DEPLOY-SHA256SUMS.txt` (376 entri, 0 mismatch) |
| `f19771b` | Manifest checksum isi paket + panduan deploy (sha256, migrasi baru, langkah verifikasi) |
| `46beb61` | **P2-5 tarif wholesale/kontrak B2B**: 3 tabel, `api/routes/088_wholesale_rates.php`, migrasi, test 20 cek |
| `b75d12b` | **P1-4 UI HQ**: tombol "Paket izin jabatan" + daftar paket, terbitkan revisi, status penerapan per properti (test 27 cek) |
| `1c87f8c` | **P1-4 backend HQ**: paket jabatan kanonik + state per properti, apply/pull, cron pull, command 052 |
| `20ea66b` | **P2-6 ulasan tamu**: panel UI + halaman publik `webpublic/review.html` |
| `d99b411` | Dokumentasi status fitur ulasan tamu |

Run CI yang relevan: `39604075134` (HEAD), `39598954297` (wholesale),
`39597750603` (UI HQ), `39597303626` (backend HQ) — semuanya **success**.

---

## 1. Peta yang harus dikuasai — apa yang sudah beres

| Kode | Isi | Status |
|---|---|---|
| `api/routes/013_audit_logs.php` | Viewer log audit (read-only, capability `view_audit_log`, scope property, redaksi, facet, paginasi) | ✅ teruji |
| `api/routes/014_permission_inspector.php` | Inspector hak akses (verdict dari `hasCapability()` produksi + lapisan penentu) | ✅ teruji |
| `api/routes/086_system_health.php` | Kesehatan sistem terpadu (6 bagian, masing-masing tahan gagal sendiri) | ✅ teruji |
| `assets/chunks/governance.js` | Panel UI: 4 tab (Log Audit / Inspector Izin / Ulasan / Kesehatan) di tab Staff | ✅ teruji |
| `migrations/20261021_LEGACY_ROLE_SETS_COMPLETE.sql` | 3 daftar role statis → access group (+ grup `all_staff_no_finance`) | ✅ teruji |
| `migrations/20261021_TRANSACTION_CURRENCY.sql` | `transactions.currency` + `exchangeRate`, tabel `exchange_rates`, backfill | ✅ teruji |
| `api/support/012_domain_primitives.php` | Helper `tamasyaPropertyCurrency/Locale/TransactionCurrencies/TransactionCurrencyGuard` | ✅ teruji |
| `api/support/017_authorization_policy.php` | Kontrak Owner: baca = Admin, tulis mustahil | ✅ teruji |
| `api/modules/finance/025_financial_posting_authority.php` | Otoritas postinguhnique fill `currency`/`exchangeRate` (**JANGAN** fill `baseAmount`) | ✅ teruji |
| `tests/owner-read-contract-regression.php` | Kontrak Owner (35 cek) | ✅ |
| `tests/audit-log-viewer-regression.php` | Viewer + wiring UI (38 cek) | ✅ |
| `tests/permission-inspector-regression.php` | Inspector + wiring UI (32 cek) | ✅ |
| `tests/legacy-role-set-coverage.php` | Semua daftar role wajib terpetakan (18 cek) | ✅ |
| `tests/currency-foundation-regression.php` | Skema+helper+posting (24 cek) | ✅ |
| `tests/system-health-regression.php` | Health dashboard (19 cek) | ✅ |
| `api/routes/087_guest_reviews.php` | Ulasan tamu: undangan sekali pakai, penilaian, moderasi, rate limit | ✅ teruji |
| `api/routes/088_wholesale_rates.php` | Tarif kontrak B2B: paket per tanggal, kunci tarif saat reservasi | ✅ teruji |
| `hq/schema.sql` + `hq/core.php` + `hq/api.php` | HQ: paket jabatan kanonik (`hq_role_presets`) + state per properti (`hq_role_preset_state`) | ✅ teruji |
| `hq/app.js` + `hq/control.html` | UI HQ: panel paket izin jabatan (baca, terbitkan, status penerapan) | ✅ teruji |
| `webpublic/review.html` + `webpublic/assets/review.js` | Halaman ulasan publik (token sekali pakai) | ✅ teruji |
| `docs/RBAC-AUDIT-VERIFIKASI-2026-10-21.md` | Status F-01..F-11 vs laporan audit 2026-10-20 | ✅ |

---

## 2. Alur kerja inti (wajib paham sebelum ubah)

### 2.1 Resolusi hak akses (satu kebenaran)

`hasCapability(user, capability, fallbackRoles)` di `api/support/017_authorization_policy.php`:

1. **Owner** → baca memakai paket **Admin** (`tamasyaCap`/preset admin), `view_*` selalu boleh; **pada metode tulis capability = false**.
2. **Katalog `inactive`** → **DENY** (menang atas override & preset).
3. **Override akun** (`staff.permissions`) → menang atas preset.
4. **Preset jabatan/role** (`role_capability_presets`) → menang atas fallback.
5. **Fallback daftar role statis** → terakhir.

`hasDesktopTabAccess()` memakai urutan sama, Owner selalu boleh.
`requireRoles()` statis **tapi** kini dipetakan lewat `rbac_legacy_role_sets` → `rbac_access_groups` → `rbac_access_group_roles`, jadi bisa dikelola dari panel Kelola Izin. Grup nonaktif = **tolak** (bukan fallback).

### 2.2 Kontrak Owner (keputusan pemilik — jangan diubah tanpa izin)

> "Owner boleh melihat seperti Admin, tetapi tidak boleh mengubah apa pun."

- 3 lapis: **capability** (baca = Admin) + **metode** (semua non-GET/HEAD/OPTIONS diblokir global di `api.php` sebelum route jalan) + **route** (`tamasyaMultiRoomRequire`, `tamasyaTelegramChargeAssertRole` menolak Owner eksplisit).
- `readOnly=true` di payload; `telegramNotifications` Owner **dimatikan** (mengirim pesan = tindakan, bukan baca).
- Owner **TIDAK** punya preset jabatan (by design) — jangan dilaporkan sebagai "role tanpa preset".

### 2.3 Alur tulis uang (WAJIB tidak merusak)

`025_financial_posting_authority.php` → satu-satunya penulis transaksi kanonik.

- `currency` = mata uang property, `exchangeRate` = 1.
- **`baseAmount` TIDAK boleh diisi otomatis.** NULL = tanda **"pajak belum diketahui"** (`taxSnapshotStatus=unresolved`); pembeda ini dipakai `019_canonical_financial_semantics.php:535` dan jurnal suspense `2199`. Mengisi `baseAmount` merusak laporan kanonik (UAT gagal).

### 2.4 Multi-currency: fondasi + display + mesin kurs sudah (lihat §4)

---

## 3. Jebakan yang sudah ditemukan — jangan ulangi

| Jebakan | Gejala | Aturan |
|---|---|---|
| **Fungsi di dalam `if` tidak di-hoist** | `Call to undefined function` | Deklarasikan fungsi **di atas** pemakaian, atau `function_exists` + blok di atas |
| **`node --check` (script mode) tidak melihat redeclare modul** | Chunk rusak lolos CI | Uji dengan `vm.SourceTextModule` (semantik modul). Sudah ada di `tests/multi-room-ui-regression.mjs` |
| **Budget graph eager = 970.000 byte** | `initial static module graph … exceeds 970000` | **Jangan menaikkan budget.** Asset lazy (di dalam chunk) = 0 byte; asset eager (index.html/app-core/app-shell/CSS) = mahal |
| **`baseAmount` = "pajak nol"** | UAT tax-edge gagal | Lihat §2.3 |
| **Zona waktu skenario vs server** | "Tanggal check-in belum tiba" | Skenario harus pakai **tanggal server** (`APP_TIMEZONE`), bukan jam mesin CI |
| **Karakter asing menyatu** dari edit Python | Teks Mandarin/Inggris nyasar di komentar | Cek file hasil edit: `grep -P '[^\x00-\x7F]'` & baca ulang |
| **`requireRoles` menolak via `exit`** | Harness test mati | Uji resolver (`tamasyaRbacAccessGroupRoles`), bukan `requireRoles` |
| **Nomor "8.4" ambigu** | Salah job saat diagnosa | `select(.name|contains("Full"))`, bukan `contains("8.4")` |
| **Job dibatalkan (steps 14+ hilang)** | Pipelines "gagal" tanpa pesan | Re-run / trigger ulang; cek `steps` yang benar. Pernah jadi infra, bukan kode |

---

## 4. Status per item dan pekerjaan lanjutan

### 4.1 Display multi-currency (P4-8 v2) — **selesai untuk 90% permukaan, nol byte eager**

Implementasi **nol byte eager** (budget tetap di bawah 970.000 byte):
- `assets/chunks/app-shell.js` menyetel `window.TAMASYA_CURRENCY` satu kali dari session payload.
- Semua **chunk lazy** (rooms, finance, inventory, staff-detail, dashboard, …) memakai formatter lokal
  `tamasyaMoneyLabel` yang membaca global itu; `yr` di `feature-shared.js` (juga lazy) ikut aware.
- Literal "Rp " yang tersisa berada di `assets/currency-display.js` (helper format uang). Helper itu kini membaca
`window.TAMASYA_CURRENCY` + `window.TAMASYA_LOCALE`, jadi seluruh call site-nya ikut mata uang dan locale property.

Locale ikut property (`window.TAMASYA_LOCALE` dari `currentUser.locale`). **Antrian offline pun
currency-aware** (`window.TAMASYA_CURRENCY==="IDR"?"Rp ":...`) → tidak ada lagi permukaan "Rp" yang
hardcoded. Sisa v2 = konversi kurs sungguhan, lihat 4.4.

### 4.2 P2 — fitur baru

- **Review/feedback tamu** → **backend selesai** (`api/routes/087_guest_reviews.php`, tabel `guest_reviews` +
  `guest_review_invites`, migrasi `20261021_GUEST_REVIEWS.sql`, `tests/guest-reviews-regression.php` 17 cek).
  Alur: staf membuat undangan (token acak, kedaluwarsa 30 hari, sekali pakai) → tamu memberi rating 1..5 →
  staf moderasi (tampilkan/sembunyikan/balas). **UI sudah ada:** tab "Ulasan" di panel governance
  (`assets/chunks/governance.js`) + halaman publik `webpublic/review.html`.
- **Wholesale / contract rate (B2B)** → **server-side tuntas**:
  - Skema: `wholesale_rate_plans` / `_nights` / `_bookings` (migrasi `20261021_WHOLESALE_RATES.sql`
    + `20261021_WHOLESALE_RATE_LOCK.sql`), 131 tabel kanonik.
  - `api/modules/finance/031_wholesale_contract_rates.php`: kuotasi, aturan fail-closed, kunci tarif.
  - Integrasi alur pemesanan di `api/modules/finance/030_booking_finance.php` (titik yang dipakai
    web, Telegram, OTA, dan grup): paket dipilih lewat `wholesalePlanId`; **server yang menghitung
    nominal** (total browser ditolak), paket tidak berlaku → reservasi ditolak, bukan fallback tarif rak.
  - Kunci tarif di `wholesale_rate_bookings` (+ `nightly_breakdown`): perubahan harga paket tidak
    mengubah reservasi lama.
  - `api/routes/088_wholesale_rates.php`: `wholesale-rate-lookup` (kuotasi kasir),
    `wholesale-rate-bookings` (rekonsiliasi).
  - Uji: `tests/wholesale-rates-regression.php` (20) + `tests/wholesale-contract-rate-mysql-uat.php`
    (19 statis + 22 fungsional di MariaDB 10.11 → 41 cek).
  - Grup/kamar banyak: `api/modules/front_office/043_multi_room_reservations.php` meneruskan paket
    kontrak ke tiap kamar; paket dihitung ulang per kamar, jadi tipe kamar yang tidak cocok
    ditolak server.
  - UI: `assets/wholesale-contract-addon.js` (add-on, dimuat sesuai rute `rooms`/`reservations`
    lewat `assets/runtime-addon-loader.js`, **tidak** eager dan **tidak** di-precache SW):
    panel "Tarif kontrak" (buat/nonaktifkan paket, cari harga inap, daftar reservasi kontrak)
    + chip paket terpilih. Saat pemesanan dibuat, add-on menambah `wholesalePlanId` +
    `wholesaleCompanyName` ke permintaan **hanya bila** inap dan tipe kamar cocok dengan pilihan.
  - Tab Growth Suite: `growth-suite.html` (tombol "Kontrak B2B" + panel) + `assets/growth-suite.js`
    (`renderContract`, `loadContract`, aksi `contract-quote` di `api/routes/110_growth_suite.php`).
    Tab memakai kunci sesi yang sama dengan add-on, jadi paket yang dipilih di Growth Suite dipakai
    saat pemesanan dibuat. Server Growth Suite membaca skema kontrak dengan try/catch, jadi property
    lama yang belum memigrasi tidak ikut gagal.

### 4.3 P1-4 — RBAC terpusat di HQ → **backend + UI selesai**

- `hq/schema.sql`: `hq_role_presets` (paket kanonik per jabatan + revisi) + `hq_role_preset_state` (revisi terpasang per properti).
- `hq/core.php`: `tamasyaHqRolePresets/Save/Publish/State`.
- `hq/api.php`: aksi `rbac-presets` (baca), `rbac-preset-save`, `rbac-preset-publish` (tulis hanya `platform_admin`/`company_admin`), `rbac-preset-state`.
- `api/support/021_role_presets.php`: `tamasyaRolePresetApplyPayload()` (upsert, hanya jabatan kanonik, nilai dinormalisasi) + `tamasyaRolePresetPullFromHq()` (pull via token HQ).
- `api/routes/052_role_presets.php`: command `role-preset-apply` (admin) + `role-preset-state` (baca).
- `maintenance_cron.php`: menarik paket HQ tiap siklus; kegagalan tidak menghentikan cron.
- `hq/app.js` + `hq/index.html`: tombol "Paket izin jabatan" → daftar paket, terbitkan revisi, status penerapan per properti.
- `tests/hq-role-presets-regression.php` (30 cek).
- **Model = pull** (property menarik), bukan push: property tidak perlu menerima koneksi masuk dari HQ.

### 4.4 Mesin kurs transaksi (multi-mata uang v2) — selesai

- `api/support/012_domain_primitives.php`: `tamasyaTransactionExchangeRate($pdo, $base, $quote, $date)`
  → kurs terakhir pada atau sebelum tanggal; **null** bila tidak ada (tidak pernah mengarang kurs).
- `api/modules/finance/025_financial_posting_authority.php`: transaksi dalam mata uang asing wajib
  punya kurs (dari pemanggil atau `exchange_rates`); tanpa kurs → **ditolak**, bukan dicatat seolah
  mata uang property. Hasil konversi disimpan pada kolom baru `transactions.propertyAmount`.
- `baseAmount` **tetap** NULL = "pajak belum diketahui"; tidak dipakai untuk konversi.
- `api/routes/089_exchange_rates.php`: daftar kurs + simpan kurs (hanya admin/manajer). Endpoint ini dipakai
  kanal lain; antarmuka konsol memakai aksi `exchange-rate-save` di Growth Suite (helper yang sama).
- UI kurs: tab "Kontrak B2B" di Growth Suite (`growth-suite.html` + `assets/growth-suite.js`) ->
  tabel kurs harian + form catat kurs. Server Growth Suite memakai helper
  `tamasyaSaveExchangeRate()` yang sama dengan API kurs, jadi tidak ada dua aturan berbeda.
- `tamasyaTransactionCurrencyGuard()` menambah `convertedByCurrency` + `propertyCurrencyTotal`.
- Migrasi `20261021_TRANSACTION_PROPERTY_AMOUNT.sql`; uji `tests/exchange-rate-mysql-uat.php` (20 cek).

### 4.5 Rehearsal pra-produksi tanpa VPS (pengganti tahap B)

`tests/uat_prd/run_saas_runtime_smoke.sh` kini menutup dengan **rehearsal permukaan fitur**: enam
aksi baru (wholesale, exchange-rates, guest-reviews, audit-logs, permission-inspector,
system-health) harus terpasang di image produksi dan tetap berpintu tanpa sesi, lookup kontrak harus
menolak tanpa sesi, halaman ulasan publik harus tersaji, dan token ulasan ngawur harus ditolak.
Semua dijalankan di stack Docker sungguhan (api+api2+web+worker+MySQL) di GitHub Actions.

**Rehearsal paket deploy** (`tests/uat_prd/verify_deploy_bundle.sh`): memverifikasi ZIP yang
benar-benar dikirim ke server (bukan isi repo): manifest checksum cocok, berkas wajib fitur baru
ada, tidak ada rahasia/berkas uji, 149 berkas PHP dan 73 berkas JS valid. Dijalankan lokal dan di
CI (job PRD membangun ZIP lalu memverifikasinya).

**Importer database satu berkas** (`deploy/IMPORT-RBAC-PHPMYADMIN.sql`) dibangun ulang oleh
`scripts/build-import-sql.mjs` dari 11 migrasi, dengan LANGKAH 0 (tabel mana yang sudah ada) dan
langkah verifikasi akhir. `tests/import-sql-sync-regression.php` menjaga agar impor tidak pernah
ketinggalan migrasi.

**Tahap B (VPS sungguhan) menjadi opsional**, bukan penghalang: yang belum terbukti adalah hosting
milik pelanggan (DNS, TLS, backup, vendor VPS). Semua fitur sudah terbukti di lingkungan produksi
(image non-root, komposisi horizontal, migrasi, UAT penuh, dan ZIP yang dikirim) di GitHub Actions.

---

## 5. Aturan tak boleh dilanggar (dari pemilik)

1. **Jangan melemahkan UAT/assertion.** Assertion yang usang boleh **diperbarui** kalau perilakunya memang berubah, tapi tidak boleh dihapus/dilemahkan.
2. **Jangan menaikkan budget graph eager** tanpa izin.
3. **Setiap perubahan file** → `FILE_CHECKSUMS.sha256` harus diregenerasi:
   `git ls-files '*.php' '*.js' '*.css' '*.html' '*.json' '*.sql' '*.md' '*.mjs' | xargs sha256sum | sort -k2 > FILE_CHECKSUMS.sha256`
4. **Jalankan suite penuh dengan `set -e`** (runner lokal sebelumnya **tidak** stop saat gagal → sempat "lolos" palsu):
   `bash /tmp/suite_full.sh` (isi ulang dari blok "Source regression suites" di workflow, **dengan `set -euo pipefail`**).
5. **Perubahan frontend di bundle minified** → selalu cek `node --check` **dan** parse ESM **dan** graph eager.
6. Bahasa: **Bahasa Indonesia** untuk penjelasan & komentar.

---

## 6. Cara memverifikasi

```bash
# suite lokal (WAJIB set -e)
git ls-files '*.php' '*.js' '*.css' '*.html' '*.json' '*.sql' '*.md' '*.mjs' \
  | xargs sha256sum | sort -k2 > FILE_CHECKSUMS.sha256
bash /tmp/suite_full.sh          # 108 perintah

# parse ESM semua aset (penangkap SyntaxError chunk)
node --experimental-vm-modules --no-warnings -e '
 const vm=require("node:vm"),fs=require("node:fs"),g=require("node:child_process");
 for(const f of g.execSync("git ls-files \"assets/*.js\" \"assets/chunks/*.js\"").toString().trim().split("\n")){
  try{new vm.SourceTextModule(fs.readFileSync(f,"utf8"),{identifier:f});}
  catch(e){console.log("BROKEN",f,e.message);process.exit(1);}}
 console.log("semua aset parse ESM OK");'

# budget graph eager
node tests/prd-performance-regression.mjs | grep initial-static

# CI
git push origin main && gh run list --limit 1
```

---

## 7. Peta file inti

| Area | File |
|---|---|
| Inti otorisasi | `api/support/017_authorization_policy.php` |
| Preset & jabatan | `api/support/021_role_presets.php` |
| Katalog & access group | `api/support/022_dynamic_rbac.php` |
| Rute staf | `api/routes/050_auth_staff.php` |
| Rute Kelola Jabatan | `api/routes/052_role_presets.php` |
| Audit viewer / inspector / health | `api/routes/013_audit_logs.php`, `014_permission_inspector.php`, `086_system_health.php` |
| Otoritas posting uang | `api/modules/finance/025_financial_posting_authority.php` |
| Semantik pajak kanonik | `api/modules/finance/019_canonical_financial_semantics.php` |
| Proyeksi data utama | `api/support/070_data_projection_scope.php` |
| Panel UI governance | `assets/chunks/governance.js` (mount di `assets/chunks/staff.js` → `QleBridge`) |
| Format uang (display) | `assets/currency-display.js` |
| Skema kanonik | `database_setup.sql` (131 tabel) |
| Migrasi baru | `migrations/20261021_LEGACY_ROLE_SETS_COMPLETE.sql`, `migrations/20261021_TRANSACTION_CURRENCY.sql` |
| CI | `.github/workflows/tamasya-enterprise-rc1-uat.yml` (source-gate 4× PHP, mysql-comprehensive-uat, mariadb-1011, prd-deployment, load-regression) |
| Paket deploy | `create-production-zip.sh` → `tamasya-production-20261021-r18.zip` |
| Impor DB opsional | `deploy/IMPORT-RBAC-PHPMYADMIN.sql` (idempoten) |
| Panduan deploy | `deploy/DEPLOY-ID.md` |
| Laporan audit asli | `~/Desktop/LAPORAN-AUDIT-TOTAL-RBAC-2026-10-20.md` |

---

## 8. Ringkasan keputusan yang sudah diambil

- **Owner** = Admin untuk baca, 0 tulis (keputusan pemilik, 2026-10-21).
- **Katalog `inactive` = deny** dan berlaku juga untuk Owner (Owner mencerminkan Admin).
- **`baseAmount` NULL = pajak belum diketahui** — tidak boleh diisi.
- **Budget graph eager 970.000** — tidak dinaikkan; fitur baru harus lazy atau server-side.
- **Multi-currency**: fondasi data dulu, display menyusul (terkunci budget).
- **UAT**: asserting lama diperbarui **hanya** kalau perilakunya memang berubah, dengan justifikasi tertulis.
