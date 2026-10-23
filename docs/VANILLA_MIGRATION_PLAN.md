# Vanilla Page Migration Plan (P2-5)

Migrasi halaman vanilla ke SPA satu per rilis. Halaman lama dihapus hanya setelah yang baru hijau di CI + UAT + browser.

Urutan saran:
1. growth-suite.html (paling sering berubah)
2. enterprise-suite.html
3. pos.html
4. internal-memo.html
5. property-setup.html
6. multi-property-foundation.html
7. webpublic/ (tetap terpisah, publik tanpa auth)

Checklist per halaman:
- Buat komponen SPA baru.
- Pasang feature flag.
- Tambah test browser desktop/mobile.
- Tambah UAT scenario assertions.
- Hijau di CI + UAT + browser sebelum hapus yang lama.
- Update release manifest checksum.

Pilot saat ini:
- growth-suite-spa.html (redirect ke growth-suite.html jika flag off)
- feature flag growthSuiteSpaEnabled
- module dock card growthSpa (showWhenLocked: false)
- service worker cache ditambahkan
