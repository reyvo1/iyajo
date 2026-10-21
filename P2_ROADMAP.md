# TAMASYA P2 — Arah Kerja Lanjutan (ditulis setelah R15 fasilitas dinamis)

Dokumen ini mencatat pekerjaan yang SENGAJA belum dikerjakan beserta urutan yang
disarankan. Setiap item P2 dimulai hanya setelah item sebelumnya hijau di CI dan
dioperasikan minimal satu siklus nyata.

## P2-1: Tenant billing (syarat mutlak "disewakan")

Tanpanya, struktur multi-properti tidak bisa ditagihkan. Desain yang disarankan:
- Paket harga per properti (flat +-meterai pemakaian bila perlu), invoice ke
  penyewa, penagihan, tenggang waktu, denda.
- Suspend HANYA mengunci login; data penyewa tidak boleh dihapus/diacak.
  Penghapusan data hanya lewat alur retensi UU PDP yang sudah disetujui.
- Uang penyewa TIDAK BOLEH lewat jalur kas hotel: tabel dan endpoint terpisah
  dari `transactions` milik tamu.
- Bukti wajib: UAT menagih, menunggak, suspend, bayar, dan buka kembali —
  plus adversarial test bahwa suspend tidak merusak data.

## P2-2: Onboarding mandiri + provisioning domain

- Pendaftaran tenant, verifikasi admin, pembuatan database properti, identitas
  properti, dan user admin pertama — tanpa operator.
- Provisioning domain/routing di infrastruktur target (lihat ENTERPRISE_IMPLEMENTATION_STATUS.md).

## P2-3: Uji isolasi tenant menyeluruh (adversarial)

- Setiap endpoint (termasuk webhook konektor baru dan modul fasilitas) diuji
  dengan kredensial tenant A untuk membaca/menulis data tenant B: harus 403
  atau kosong di semua kasus.
- Mulai setelah tenant billing ada, karena di situlah insentif penyalahgunaan
  muncul.

## P2-4: E-Faktur, load/DR, legal

- E-Faktur: butuh PKP + akses DJP + konsultan pajak (di luar kode).
- Load test representatif + autoscaling + DR drill di infrastruktur target.
- Naskah kebijakan privasi, persetujuan tamu, dan perjanjian pemrosesan data
  (UU PDP) — ranah legal, teknisnya (retensi, hapus, consent) sudah tersedia
  sebagian di kode.

## P2-5: Migrasi halaman vanilla ke SPA (tidak disarankan sekarang)

Tujuh halaman vanilla (growth/enterprise/pos/memo/setup/multi-property/webpublic)
sengaja dibiarkan. Migrasi hanya layak bila penambahan fitur di halaman tersebut
berulang kali menimbulkan bug ganda. Bila dimulai: satu halaman per rilis,
halaman lama dihapus hanya setelah yang baru hijau di CI + UAT + browser,
dan `webpublic/` tetap terpisah (publik tanpa auth).

## Prinsip yang tidak boleh dilanggar P2

1. Tidak ada jalur uang baru: semua posting lewat transaksi canonical.
2. Tidak ada auto-mutasi dari event eksternal tanpa approval eksplisit.
3. Setiap tabel baru masuk ketiga inventory (migrasi, installer, kontrak readiness)
   atau CI akan merah — lihat pelajaran R14.
4. Setiap perubahan JS yang menyentuh module graph wajib lolos probe Chromium
   sebelum push — lihat pelajaran duplicate React.


## Peta penomoran ke handoff 2026-10-21

`P2_ROADMAP.md` memakai penomoran P2 sendiri; handoff `docs/HANDOFF-ALUR-KERJA-2026-10-21.md`
memakai penomoran P2 untuk fitur baru. Yang sama refers ke:

| Handoff | Roadmap | Status |
|---|---|---|
| P2-5 tarif kontrak B2B | P2-7 (rencana) | selesai di `20261021-r21` |
| P2-6 ulasan tamu | P2-8 (rencana) | selesai di `20261021-r21` |
