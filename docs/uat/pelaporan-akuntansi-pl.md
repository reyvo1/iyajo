# UAT Pelaporan & Akuntansi — P&L dan Paritas Client/Server

Modul: Pusat Laporan & Arsip → tab Laba Rugi (P&L), dan policy akuntansi kanonik (`assets/canonical-business-policy.js`).

## Tujuan
Menyelaraskan perilaku browser dengan server (`api/modules/finance/019_canonical_financial_semantics.php`) dan menambah rincian per kategori/subkategori pada Laba Rugi, tanpa mengubah aturan pemakaian (Pemakaian tetap 1 peran sistem = 1 kategori).

## Kriteria penerimaan
1. `recognizedRevenue` browser = server: split base+tax yang valid mengambil preseden atas `taxSnapshotStatus`; resit unresolved tanpa split → 0; `not_applicable` → gross.
2. Bucket `room` browser = server: transaksi dengan `bookingId`/`booking_id` terisi juga masuk bucket `room` (selain kind/sourceEntity yang sudah ada).
3. P&L tetap menampilkan bucket semantik (Kamar, POS, Layanan Tamu, Lain-lain) dan tidak menghapus aturan pemakaian.
4. Aturan pemakaian tidak dilemahkan; test yang ada tetap hijau.
5. Perubahan tidak menyentuh DB produksi.

## Catatan
Rincian per kategori/subkategori pada P&L ditambahkan sebagai langkah berikutnya dengan probe browser; data per kategori sudah tersedia di memo `re` (category→subcategory) pada `assets/chunks/report.js`.
