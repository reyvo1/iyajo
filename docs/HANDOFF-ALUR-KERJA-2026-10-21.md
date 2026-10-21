# Konteks & Alur Kerja TAMASYA — handoff untuk sesi berikutnya

Rilis: `20261021-r15` · commit: `1e71fc4` · CI: **9/9 hijau** (run `39581013911`, 1522/1522 assertion UAT, 60/60 browser).
Dokumen ini adalah sumber konteks tunggal. **Baca ini dulu sebelum mengubah apa pun.**

---

## 1. Peta yang harus dikuasai — apa yang sudah beres

| Kode | Isi | Status |
|---|---|---|
| `api/routes/013_audit_logs.php` | Viewer log audit (read-only, capability `view_audit_log`, scope property, redaksi, facet, paginasi) | ✅ teruji |
| `api/routes/014_permission_inspector.php` | Inspector hak akses (verdict dari `hasCapability()` produksi + lapisan penentu) | ✅ teruji |
| `api/routes/086_system_health.php` | Kesehatan sistem terpadu (6 bagian, masing-masing tahan gagal sendiri) | ✅ teruji |
| `assets/chunks/governance.js` | Panel UI: 3 tab (Log Audit / Inspector Izin / Kesehatan) di tab Staff | ✅ teruji |
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

### 2.4 Multi-currency: fondasi sudah, display belum (lihat §4)

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

## 4. Yang BELUM selesai (urutan lanjutan)

### 4.1 Display multi-currency (P4-8 v2) — **terkunci budget**

Fondasi data sudah ada; **tampilan masih hardcode "Rp" (72 literal) & `id-ID` (48)**.
Diverifikasi: seluruh feature butuh **±570 byte** di graph eager → **budget sisa hanya 63 byte**.
Opsi (pilih satu, minta izin):
- **A** Naikkan budget `970.000` (keputusan PRD, jangan diam-diam).
- **B** Pindahkan formatters ke chunk **lazy** → render pertama masih IDR.
- **C** Server merender nominal (format di backend) → tidak ada byte frontend.

### 4.2 P2 — fitur baru
- **Wholesale / contract rate (B2B)**: belum ada sama sekali (0 file). Perlu tabel rate kontrak +ixerfe ke growth suite.
- **Review/feedback tamu**: belum ada (0 file).

### 4.3 P1-4 — RBAC terpusat di HQ
`hq/` tidak punya kendali RBAC (0 referensi `rbac_*`) → preset hanya per-property. Butuh perintah "push preset" dari HQ.

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
| Skema kanonik | `database_setup.sql` (126 tabel) |
| Migrasi baru | `migrations/20261021_LEGACY_ROLE_SETS_COMPLETE.sql`, `migrations/20261021_TRANSACTION_CURRENCY.sql` |
| CI | `.github/workflows/tamasya-enterprise-rc1-uat.yml` (source-gate 4× PHP, mysql-comprehensive-uat, mariadb-1011, prd-deployment, load-regression) |
| Paket deploy | `create-production-zip.sh` → `tamasya-production-20261021-r15.zip` |
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
