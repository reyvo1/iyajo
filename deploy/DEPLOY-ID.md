# Deploy TAMASYA - Rilis 20261021-r15

Paket ini berisi perbaikan RBAC (audit F-01..F-11), perbaikan chunk-load yang
sebelumnya membuat Halaman UAT gagal, dan fitur baru: penampil log audit,
inspektur izin, dasbor kesehatan sistem, dukungan multi-mata uang, ulasan
tamu, paket izin jabatan terpusat di HQ, serta tarif wholesale/kontrak B2B.

| Paket | `tamasya-production-20261021-r15.zip` |
|---|---|
| SHA-256 paket | `bbc20d4646103a2f7d500860e55c724e0a0bb6796693a1f1cf2038f57ce180a8` |
| Isi | 374 berkas + `DEPLOY-SHA256SUMS.txt` |

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
File ini aman dijalankan berulang kali dan tidak menimpa paket izin yang
sudah Anda simpan sendiri di UI "Kelola Jabatan".

Migrasi fitur baru (jika database Anda sudah ada):
`migrations/20261021_GUEST_REVIEWS.sql` (ulasan tamu),
`migrations/20261021_WHOLESALE_RATES.sql` (paket tarif kontrak B2B), dan
`migrations/20261021_WHOLESALE_RATE_LOCK.sql` (kunci tarif kontrak pada reservasi).
Keduanya idempoten. Tanpa migrasi, tab terkait akan memberi pesan jelas
bahwa skema belum tersedia, bukan error fatal.

Cek cepat apakah perlu: bagian LANGKAH 0 di file SQL menampilkan tabel mana
yang sudah ada. Semua sudah ada (angka 1) -> tidak wajib impor, tapi aman.

## 4. Upload & ekstrak

```
unzip tamasya-production-20261021-r15.zip
cd tamasya-production-20261021-r15
```

Arahkan document root web server ke folder ini.

## 5. Konfigurasi

Salin `.env.example` menjadi `.env` (tidak ikut dalam paket, karena berisi
kredensial produksi Anda) lalu isi:

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

## 8. Cache browser

Rilis ini memakai token versi baru (`20261021-r15`), jadi service worker
mengunduh ulang aset secara otomatis. Bila browser masih menampilkan versi
lama: hard refresh (Ctrl+Shift+R) atau increment "Unregister service worker"
di DevTools -> Application -> Service Workers.

## 9. Verifikasi manual setelah deploy

1. Login sebagai Admin -> menu "Kelola Jabatan" harus tampil.
2. Buka Tab Kamar, Finance, Operations, Staff -> tidak boleh error console.
3. Login sebagai Finance -> Tab Staff dan Config harus tertutup.
4. Login sebagai Owner -> semua tombol tulis harus nonaktif.
5. Jalankan regression server (opsional, butuh PHP CLI):
   `php tests/regression.php` bila Anda ikut menyalin folder `tests/`.
6. Verifikasi integritas paket: `sha256sum -c DEPLOY-SHA256SUMS.txt`
   (jalankan dari dalam folder hasil ekstraksi).
7. HQ: buka `hq/control.html` -> tombol "Paket izin jabatan" untuk melihat
   paket kanonik, menerbitkan revisi, dan status penerapan per properti.
8. Tarif kontrak: buka tab Kamar -> tombol "Tarif kontrak" untuk membuat paket,
   mencari harga per inap, dan memilih paket yang dipakai saat pemesanan.
