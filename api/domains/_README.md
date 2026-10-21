# DOMAIN MAP — TAMASYA admin_app

Peta tanggung jawab modul (domain decomposition phase 1 - logical only).

ATURAN:
- File fisik TIDAK dipindah. Folder ini adalah MANIFEST/peta saja.
- Setiap domain punya file .md yang mencantumkan route + support miliknya.
- Perubahan kode WAJIB berada dalam batas domain yang disesuaikan.
- shared_core: support lintas-domain; perubahan butuh review lintas domain.
- growth_enterprise_locked: AKTIF (Growth ON). Gate pembuka sudah dibuka;
paket RBAC terpusat HQ dan tarif kontrak B2B sudah aktif di domain ini.

Dibuat: 2026-08-22 (clean-slate audit offline-ops-1)


## Route baru (rilis 20261021-r20)

| Route | Domain | Isi |
|---|---|---|
| `013_audit_logs.php` | audit | Penampil log audit enterprise |
| `014_permission_inspector.php` | audit | Inspektur hak akses |
| `086_system_health.php` | audit | Dasbor kesehatan sistem |
| `087_guest_reviews.php` | audit | Ulasan tamu (backend + halaman publik) |
| `088_wholesale_rates.php` | finance | Tarif kontrak B2B + kunci tarif |
| `089_exchange_rates.php` | finance | Kurs transaksi harian |
| `api/modules/finance/031_wholesale_contract_rates.php` | finance | Kuotasi tarif kontrak + aturan fail-closed |
