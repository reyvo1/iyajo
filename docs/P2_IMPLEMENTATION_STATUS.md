# P2 Implementation Status — Continuation Doc

Dibuat untuk melanjutkan pekerjaan lintas sesi chat.

## Status saat ini
- GitHub source gate hijau setelah perbaikan checksum XSS.
- Test XSS canonical report center ditambahkan dan dihubungkan ke source gate.
- Fitur P2 yang belum diimplementasikan (lihat P2_ROADMAP.md):
  1. Tenant billing (invoice, suspend, reopen) — butuh provider pembayaran.
  2. Onboarding/provisioning mandiri — butuh infra domain/routing.
  3. Uji isolasi tenant adversarial — bisa dikerjakan di kode.
  4. E-Faktur, load/DR, legal — butuh PKP/DJP/konsultan + target infra.
  5. Migrasi 7 halaman vanilla ke SPA — satu halaman per rilis.

## Yang bisa dikerjakan di kode tanpa infra
- Tenant isolation regression (adversarial) untuk endpoint yang sudah ada.
- Guard regression untuk mencegah query tanpa filter property_id.
- Migrasi halaman vanilla bertahap (growth dulu), dengan feature flag dan test.

## Prinsip keamanan
- Tidak menyentuh jalur uang core.
- Semua perubahan incremental, satu per rilis.
- Tidak melemahkan UAT; tambah test, jangan hapus.

## Next session checklist
1. Pilih satu endpoint tenant-sensitive, tambahkan test adversarial.
2. Jalankan source gate lokal.
3. Tambah dokumentasi hasil.
4. Commit kecil per modul.
