# Matriks audit UI & backend (cabang) — 2026-10-22

Audit ini disusun sebagai **pohon keputusan**, bukan daftar. Setiap permukaan
UI diperiksa dengan cabang yang sama: *bisakah pengguna sampai ke sana? →
bisakah ia melihat bahwa ada? → apakah tombolnya bekerja? → apakah backend
melayani? → apakah datanya siap?*

Aturan main: **tidak ada klaim "beres" tanpa bukti eksekusi** (klik nyata,
respons HTTP nyata, atau uji fungsional terhadap MariaDB).

## Cabang 0 — Apakah libc-ui sampai ke user?

| Permukaan | Rute/pemicu | Syarat | Bukti | Status |
|---|---|---|---|---|
| Audit & Kendali (4 tab) | Tab **Karyawan** (grup SDM) → scroll bawah → kartu "Audit & Kendali" → tombol "Buka Audit & Kendali" | Listener Browser + tab selalu dirender | Uji browser: 4 aksi menjawab 200 | ✅ |
| Kelola Jabatan (Paket Izin) | Tab Karyawan, di atasnya | Role admin | Uji browser + 30 cek statis | ✅ |
| Panel tarif kontrak (kasir) | Tab **Kamar/Reservasi** → tombol **"Kontrak"** di pojok kanan bawah, tepat di atas tombol Laporan | Add-on dimuat sesuai rute + tombol tidak menimpa tombol lain | Uji browser: panel terpusat, respons 200, tidak tumpang tindih | ✅ |
| Tab **Kontrak B2B** + tabel kurs | Halaman **Growth Suite** → tab "Kontrak B2B" | Growth Suite aktif; skema opsional | Uji browser: kuotasi & kurs **POST 200** | ✅ |
| Paket izin jabatan (HQ) | `/hq/index.html` → tombol "Paket izin jabatan" | Konsol HQ (layanan terpisah) | Uji browser: halaman tanpa error skrip | ✅ |
| Ulasan tamu | `/webpublic/review.html` | Token undangan | Uji browser: halaman tersaji, token salah ditolak | ✅ |
| Ringkasan paket & kurs | Tombol **"Diagnostik"** di header | Sesi admin | Panel Diagnosis "Ringkasan Operasi" | ✅ |
| Format uang | Semua halaman | Helper `TamasyaCurrencyDisplay` | Uji browser POS + uji helper lokal (VM) | ✅ (penawar dipasang di 8 pemakai) |

## Cabang 1 — Apakah data di belakang siap?

| Data | Syarat | Gejala bila belum | Perbaikan | Status |
|---|---|---|---|---|
| Tabel tarif kontrak + kurs + kunci tarif | `migrations/20261021_WHOLESALE_RATES.sql` + `20261021_WHOLESALE_RATE_LOCK.sql` (ataukan `deploy/IMPORT-RBAC-PHPMYADMIN.sql`) | Tab kontrak kosong / pesan 409 | Tab tetap tampil + **instruksi impor**; add-on kasir juga memberi instruksi | ✅ |
| Tabel ulasan tamu | `20261021_GUEST_REVIEWS.sql` | Halaman ulasan kosong | Pesan jelas | ✅ |
| Tabel RBAC + jabatan | `20261019..20261021_*.sql` | Panel jabatan & audit kosong | Impor satu berkas | ✅ |
| Kolom `transactions.propertyAmount` | `20261021_TRANSACTION_PROPERTY_AMOUNT.sql` | Nilai konversi tak tersimpan | Impor satu berkas | ✅ |

## Cabang 2 — Apakah guard & kapabilitas benar?

| Aspek | Aturan | Bukti |
|---|---|---|
| Owner | Baca = Admin, tulis mustahil (3 lapis: capability, metode global, guard route) | `017_authorization_policy.php` + uji kontrak owner |
| Moderasi ulasan | Hanya admin/manajer (`manage_operational_settings`) | `087_guest_reviews.php:145` |
| Undangan ulasan | Boleh resepsionis (melayani tamu) | `087_guest_reviews.php:97` |
| Katalog `inactive` | DENY, juga untuk capability Owner | `017:171` |
| Kurs tanpa kurs | Transaksi ditolak, bukan dikarang | `tamasyaTransactionExchangeRate()` → null |
| Base amount | Tidak pernah diisi otomatis (NULL = pajak belum diketahui) | `025_financial_posting_authority.php` |

## Cabang 3 — Apakah bisa dibuktikan secara lokal?

| Bukti | Perintah | Status |
|---|---|---|
| Suite statis (116 perintah) | `bash /tmp/suite_full.sh` | ✅ hijau |
| Uji helper uang (Node VM) | `node tests/uat_local/ui_money_helper_regression.mjs` | ✅ 9/9 |
| Uji fungsional MariaDB (tarif kontrak, kurs) | `TAMASYA_CATALOG_UAT_DSN=… php tests/wholesale-contract-rate-mysql-uat.php` | ✅ 41 + 20 |
| Rehearsal paket rilis | `bash tests/uat_prd/verify_deploy_bundle.sh tamasya-production-20261021-r20.zip` | ✅ |
| Uji browser (klik nyata) | `npm run test:browser` | CI (butuh server UAT) |
| Audit dokumen vs kode | bandingkan klaim dokumen dengan `file:line` kode | ✅ (dipakai ulang, bukan dianggap final) |

## Kerangka yang dipakai untuk audit berikutnya

1. **Cari permukaan** (file UI + route) → catat syaratnya.
2. **Uji syaratnya** (data, kapabilitas, feature flag) satu per satu.
3. **Uji interaksinya** (klik/ketik nyata) dan **respons HTTP**-nya.
4. **Uji dokumen** yang diklaim terhadap kode, per klaim, dengan `file:line`.
5. **Catat bukti**; klaim tanpa bukti = belum selesai.

Aturan yang dipakai sejak awal dan tidak boleh dilanggar: satu tabel/kolom baru
wajib masuk ke migrasi, ke skema kanonik, ke berkas impor, dan ke readiness check;
satu endpoint baru wajib punya pemanggil UI atau dinyatakan sebagai API saja;
satu klaim dokumen wajib punya bukti `file:line`.
