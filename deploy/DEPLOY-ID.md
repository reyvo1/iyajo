# Deploy TAMASYA - Rilis 20261021-r18

Paket ini berisi perbaikan RBAC (audit F-01..F-11), perbaikan chunk-load yang
sebelumnya membuat Halaman UAT gagal, dan fitur baru: penampil log audit,
inspektur izin, dasbor kesehatan sistem, dukungan multi-mata uang, ulasan
tamu, paket izin jabatan terpusat di HQ, serta tarif wholesale/kontrak B2B.

| Paket | `tamasya-production-20261021-r18.zip` |
|---|---|
| SHA-256 paket | `e9e8b7f3a37efda7d9f50e6d0ce0128899b4d4d17070a2ce9c680e4b457bad70` |
| Isi | 356 berkas + `DEPLOY-SHA256SUMS.txt` |

## 1. Isi paket

| Isi | Keterangan |
|---|---|
| `assets/`, `api/`, `hq/`, `webpublic/`, `services/` | Kode aplikasi |
| `migrations/` | Seluruh migrasi SQL (arsip, jalankan lewat phpMyAdmin bila perlu) |
| `deploy/IMPORT-RBAC-PHPMYADMIN.sql` | Impor database RBAC (idempoten) |
| `database_setup.sql` | Skema penuh (131 tabel) untuk instalasi DB baru |
| `DEPLOY-SHA256SUMS.txt` | Manifest isi paket: `sha256sum -c DEPLOY-SHA256SUMS.txt` setelah ekstraksi |

## 2. Backup DULU

phpMyAdmin -> pilih database -> Export -> SQL. Jangan lanjut tanpa backup.

## 3. Impor database (hanya bila perlu)

Buka `deploy/IMPORT-RBAC-PHPMYADMIN.sql`, salin ke phpMyAdmin -> tab SQL -> Go.
Berkas ini memuat seluruh migrasi (11 berkas) sekaligus: RBAC,-ulasan tamu, tarif kontrak, dan
kurs mata uang. Langkah pertama menampilkan tabel mana yang sudah ada, langkah terakhir memverifikasi
hasilnya. Amankan dijalankan berulang kali.
File ini aman dijalankan berulang kali dan tidak menimpa paket izin yang
sudah Anda simpan sendiri di UI "Kelola Jabatan".

Migrasi fitur baru (jika database Anda sudah ada):
`migrations/20261021_GUEST_REVIEWS.sql` (ulasan tamu),
`migrations/20261021_WHOLESALE_RATES.sql` (paket tarif kontrak B2B), dan
`migrations/20261021_WHOLESALE_RATE_LOCK.sql` (kunci tarif kontrak pada reservasi), dan
`migrations/20261021_TRANSACTION_PROPERTY_AMOUNT.sql` (nilai transaksi dalam mata uang property).
Kelimanya idempoten. Migrasi lain (mis. katalog universal `20261009_R15_UNIVERSAL_CATALOG_ADDITIVE.sql`) tidak ikut berkas ini dan dipasang terpisah lewat `scripts/activate-growth-enterprise.php`. Tanpa migrasi, tab terkait akan memberi pesan jelas
bahwa skema belum tersedia, bukan error fatal.

Cek cepat apakah perlu: bagian LANGKAH 0 di file SQL menampilkan tabel mana
yang sudah ada. Semua sudah ada (angka 1) -> tidak wajib impor, tapi aman.

## 4. Upload & ekstrak

```
unzip tamasya-production-20261021-r18.zip
cd tamasya-production-20261021-r18
```

Arahkan document root web server ke folder ini.

## 5. Konfigurasi

Buat `.env` sendiri di server (paket sengaja tidak membawa `.env` maupun
`.env.example`; contoh isian ada di `.env.local.example` dan
`.env.online.example` di repositori) lalu isi:

- `APP_ENV=production`
- `APP_ENCRYPTION_KEY` (32+ karakter, acak)
- `APP_TIMEZONE=Asia/Jakarta`
- kredensial database (`db_credentials.php` juga tidak ikut paket)

## 6. Periksa konfigurasi

```
php preflight_check.php
php database_verify.php
```

## 7. Permissions

```
chown -R www-data:www-data uploads
chmod 750 uploads
chmod 640 .env db_credentials.php
```

## 8. Cache browser (WAJIB dibaca)

Rilis ini memakai token versi baru (`20261021-r18`). Server Anda masih menjalankan
versi sebelum `20261021-r18`, jadi **tanpa langkah ini browser akan tetap memakai aset lama** dari
cache service worker dan fitur baru tidak akan terlihat:

1. Login sebagai Admin -> buka Tab Kamar -> tombol "Tarif kontrak" harus muncul.
2. Bila tombol tidak muncul: logout, lalu tekan Ctrl+Shift+R (hard refresh).
3. Masih belum? DevTools -> Application -> Service Workers -> "Unregister", lalu
   refresh dua kali. Cache service worker memakai nama cache ber-token versi, jadi
   setelah registrasi ulang seluruh aset diunduh ulang.

## 9. Cache browser (lama)

Rilis ini memakai token versi baru (`20261021-r18`), jadi service worker
mengunduh ulang aset secara otomatis. Bila browser masih menampilkan versi
lama: hard refresh (Ctrl+Shift+R) atau increment "Unregister service worker"
di DevTools -> Application -> Service Workers.

## 10. Verifikasi manual setelah deploy

1. Login sebagai Admin -> menu "Kelola Jabatan" harus tampil.
2. Buka Tab Kamar, Finance, Operations, Staff -> tidak boleh error console.
3. Login sebagai Finance -> Tab Staff dan Config harus tertutup.
4. Login sebagai Owner -> semua tombol tulis harus nonaktif.
5. Jalankan regression server (opsional, butuh PHP CLI):
   `php tests/regression.php` bila Anda ikut menyalin folder `tests/`.
6. Verifikasi integritas paket: `sha256sum -c DEPLOY-SHA256SUMS.txt`
   (jalankan dari dalam folder hasil ekstraksi).
7. HQ: buka `hq/index.html` -> tombol "Paket izin jabatan" untuk melihat
   paket kanonik, menerbitkan revisi, dan status penerapan per properti.
8. Tarif kontrak: buka tab Kamar -> tombol "Tarif kontrak" atau tab Growth Suite ->
   "Kontrak B2B" untuk membuat paket, mencari harga per inap, dan memilih paket
   yang dipakai saat pemesanan.
9. Kurs mata uang: tab "Kontrak B2B" di Growth Suite mencatat kurs harian
   (server: aksi `exchange-rate-save`). Transaksi dalam mata uang lain ditolak
   bila kurs belum ada.

## 11. Verifikasi paket sebelum dikirim ke server

Jalankan dari repositori (paket rilis tidak menyertakan folder `tests/`):

```
bash tests/uat_prd/verify_deploy_bundle.sh tamasya-production-20261021-r18.zip
```
Memeriksa ZIP yang akan dikirim: manifest checksum cocok, berkas wajib ada, tidak ada rahasia,
dan seluruh PHP/JS valid.
