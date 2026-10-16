# BATAS KERJA — Zona Aktif vs Zona Beku

Berlaku untuk SEMUA agen/sesi sampai pemilik mencabut secara eksplisit.
Tujuan: pekerjaan berikutnya (enterprise) tidak boleh disentuh sebelum
pekerjaan berjalan tuntas. Kalau ragu, TANYA pemilik dulu — jangan menebak.

## Zona AKTIF (boleh dikerjakan)

- Operasional hotel inti: `api/routes/080_telegram_webhook.php`,
  `api/routes/020_hotel_booking.php`, `api/modules/front_office/`,
  `api/modules/finance/`, `api/modules/hr_staff/`,
  `api/modules/pos_inventory/`, `api/modules/comms/`,
  `api/support/` (kecuali menambah tabel baru),
  `assets/chunks/` + `assets/app-core.js` (UI yang sudah ada),
  `pos.html`, `property-setup.html`, `internal-memo.html`.
- SaaS Fase 0–1: SOP, checklist instalasi, runner migrasi, monitor backup,
  offsite backup, vault kunci (lihat `HANDOFF-SAAS-FINAL-2026-10-16.md` §6).
- IoT Fase 0 saja: `hotel-bridge` + `HotelBridgeClient` sesuai
  `HANDOFF-IOT-BRIDGE-FASE0-2026-10-16.md` (belum mulai = boleh dirancang,
  eksekusi tunggu perintah).
- Dokumen di `docs/` (kecuali arsip), `tests/` terkait zona aktif,
  `FILE_CHECKSUMS.sha256` (wajib ikut tiap perubahan).

## Zona BEKU (JANGAN disentuh — baca boleh, ubah JANGAN)

- `api/modules/growth_enterprise_locked/` (Growth + Enterprise suite).
- `growth-suite.html`, `enterprise-suite.html`, `assets/growth-suite.js`
  dan chunk growth/enterprise lain.
- `hq/` (HQ agregat) — kecuali perbaikan bug kritis atas perintah pemilik.
- SaaS Fase 2–3: billing langganan, signup otomatis, konsol platform,
  SSO, tarif pusat, writeback HQ (semua masih OFF by design).
- IoT Fase 1+: kunci fisik, energy saver, sensor, kamera wajah, PIN
  otomatis, aplikasi tamu.
- Skema database baru / hapus tabel / ubah UNIQUE & FK tabel inti
  (`bookings`, `transactions`, `rooms`, `staff`, `shift_sessions`).
- Rewrite stack, ganti framework, pindah bahasa.

## Aturan penegakan

1. Sebelum menyentuh file, cek daftar di atas. File di Zona Beku =
   berhenti dan lapor ke pemilik, minta izin eksplisit per file.
2. Perintah pemilik yang membuka zona beku harus menyebut nama file/fitur
   ("buka kunci untuk X"). Pembukaan umum ("kerjakan semua") TIDAK berlaku.
3. Setelah zona beku dibuka dan selesai, agen menutupnya kembali dengan
   mencatat di file ini (tanggal + apa yang diubah).
4. Pelanggaran = kerjaan ditolak: revert commit yang menyentuh zona beku
   tanpa izin.

## Riwayat buka/tutup

- 2026-10-16: dokumen dibuat, semua zona beku dalam keadaan TERKUNCI.
