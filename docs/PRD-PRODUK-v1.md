# PRD PRODUK v1.0 — Tamasya Hotel Operations Console

Dokumen induk fitur per modul per 2026-10-16. Arsitektur di
`ARSITEKTUR-APLIKASI.md`, skema di `DATABASE.md`, induk arsitektur lama di
`PRD_TAMASYA.txt` (v1.0 skala). Status: mencerminkan kode yang berjalan.

## 1. Front Office — Kamar & Tamu

- Jual kamar langsung (check-in sekarang) & reservasi tanggal depan
  (`/jual_kamar`, `/jual <kamar> <nama>`, `/reservasi`). Kamar terisi boleh
  dipesan untuk periode tak bertabrakan (cek bentrok half-open per kamar).
- Grup 1 nama N kamar: reservasi grup & check-in grup, billing
  individual/master/split, tambah kamar, DP proporsional, detail + daftar
  grup. 1 booking = 1 kamar; status grup turunan (parsial didukung).
- Perpanjang, tambah layanan, pindah kamar (transfer + surcharge +
  version-check anti-timpahan), check-out (verifikasi kunci + ledger),
  batal + refund resmi. Edit umur booking aktif/reserved saja.
- Deposit jaminan (tagih/terima/refund/hangus + ledger), panjar berulang
  per ledger (tidak menimpa), nota thermal/invoice, status bayar per kamar.
- KTP tamu: foto tersimpan, akses dicatat audit + rate-limit.
- Akses tulis: `sell_room_access` (jual/reservasi/grup), `manage_stay_access`
  (inap/pindah/checkout), baseline role admin/manager/resepsionis.
  Baca daftar: +finance (+owner read-only).

## 2. Kas & Shift

- Buka shift (slot pagi/siang/malam/24 jam + kas awal + pendamping),
  tutup shift + rekonsiliasi (kas harapan vs aktual, selisih wajib
  keterangan; override manager; review bila di luar toleransi).
- Laci = TUNAI saja; transfer/QRIS tampil terpisah (non-tunai) +
  jumlah transaksi. Riwayat tutup shift (daftar + rincian + deposito).
- Operator: admin/manager/finance/resepsionis. Baca riwayat:
  +owner. Resepsionis hanya boleh tutup shift yang diikutinya.

## 3. Keuangan & Pajak

- Transaksi pemasukan/pengeluaran per kamar + kategori dinamis
  (`system_key` stabil), split tunai+transfer, pajak PBJT snapshot per
  aturan sumber, jurnal otomatis, tutup periode kas & pajak per bulan,
  backfill historis beraudit, alokasi folio, pencairan OTA + komisi.
- Tulis: admin/manager/finance. Koreksi audit: +finance via capability.
  Laporan kas: tunai vs transfer/QRIS terpisah.

## 4. Housekeeping, Kunci & Kamar Kosong

- Tugas housekeeping per kamar (1 aktif), status turunan operasional,
  lapor kamar kosong (tidak otomatis menjual ulang), verifikasi checkout.
- Kunci: mode physical/smart/hybrid; serah-terima via `manage_room_access`;
  PIN smart hanya di sesi web aman; bridge job ke provider.
- Akses: `perform_housekeeping` (baseline +cleaning +finance), kunci via capability.

## 5. Operasional Lapangan & Tamu

- Permintaan tamu (ambil → kerjakan → selesai + catatan),
  patroli (mulai/selesai-aman) + laporan insiden, akun & diagnosa Telegram,
  consistency guard (admin/manager/finance).
- Cuti karyawan (ajukan/riwayat/persetujuan manager), slip gaji sendiri.

## 6. POS/Minibar, Inventory, HRD & Gaji

- POS: produk/stok, jual ke kamar (masuk folio) atau langsung, void,
  delivery, HPP otomatis. Inventory: stok + maintenance aset.
- HRD: absensi (termasuk biometrik wajah/sidik), cuti, slip gaji + koreksi,
  tabungan karyawan, memorial internal (owner read-only).

## 7. Telegram (peta perintah)

Menu: 🏠 Utama; 🛏️ Kamar & Tamu (`/status_kamar /reservasi /jual_kamar
/perpanjang /layanan /pindah /checkout /kamar_kosong /cetak_nota /ktp
/reservasi_grup /checkin_grup`); 💰 Kas & Shift (`/panjar /pemasukan
/pengeluaran /laporan /buka_shift /tutup_shift /riwayat_shift`);
🧹 Operasional (`/housekeeping /hk_set /patroli_laporan /patrol
/lapor_insiden` + antrean tamu); 🗓️ Cuti (`/cuti`); 👤 Akun
(`/integritas /diagnosa_shift`); `/bind /logout /batal /ai_help`.
Aturan: chat privat untuk transaksi (grup = agregat kamar saja);
keyboard mengikuti capability akun; semua tombol mutasi digate ulang;
owner read-only di semua tombol.

## 8. Website Publik, Growth/Enterprise, HQ

- Publik: profil hotel, tipe kamar, promosi, permintaan reservasi
  (→ review staf → booking), chat bantuan. CMS: `manage_public_website`.
- Growth/Enterprise (opsional, default-OFF): KPI, grup korporat, folio,
  procurement/AP, CRM, adapter channel/payment (webhook HMAC hanya ubah
  status intent, tidak posting uang otomatis).
- HQ (DB terpisah): agregat snapshot bertanda-tangan per properti,
  read-only terhadap operasional cabang.

## 9. Matriks akses ringkas (T = tulis, B = baca, R/O = read-only)

| Fungsi | admin | manager | resepsionis | finance | HK/keamanan/dll | owner |
|---|---|---|---|---|---|---|
| Jual/Reservasi (+grup) | T | T | T | cap* | cap* | R/O |
| Inap/Pindah/Checkout | T | T | T | cap* | cap* | R/O |
| Panjar/Deposit | T | T | T | T | cap* | R/O |
| Kas/Shifts/Laporan | T | T | shift saja | T | — | B (kas/riwayat) |
| Housekeeping | T | T | T | cap* | T (HK) | — |
| KTP | T | T | T | cap* | keamanan T | B |
| Karyawan/gaji/setup | admin (+manager cuti) | | | | | R/O |

cap* = dibuka via centangan capability di menu Karyawan.
Web menegakkan aturan yang sama di API (`requireCapability`).

## 10. Non-goal v1 (sengaja belum ada)

Billing langganan SaaS, signup otomatis, superadmin platform, backup
termonitor pusat, migrasi fleet otomatis, kunci IoT fisik, kamera wajah
tamu, aplikasi tamu (lihat `HANDOFF-IOT-BRIDGE-FASE0-2026-10-16.md`).


## 8.1 Fitur yang sudah berjalan di kode (rilis 20261021-r19)

Dokumen ini menggambarkan produk. Fitur berikut sudah ada di kode dan teruji
otomatis (CI 9/9 + uji fungsional MariaDB + UAT browser):

- **RBAC dinamis**: registry role/capability/tab/notifikasi + access group; Owner
  membaca seperti Admin tetapi tidak dapat menulis (3 lapis: capability, metode
  global, guard route).
- **Jabatan dinamis** (tabel `staff_job_titles`) dengan base role kanonik.
- **Paket izin jabatan terpusat di HQ** (publish per properti, ditarik property).
- **Log audit enterprise** + **inspektur hak akses** + **dasbor kesehatan**.
- **Ulasan tamu** (undangan sekali pakai, moderasi, halaman publik).
- **Tarif kontrak B2B**: paket per tanggal, harga dihitung server, dikunci ke
  reservasi, tampil di panel kasir dan di Growth Suite.
- **Multi-mata uang**: penandaan mata uang, tampilan ikut property, kurs harian,
  dan konversi tersimpan pada kolom terpisah.
