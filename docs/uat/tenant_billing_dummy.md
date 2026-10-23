# Tenant Billing (Dummy/Simulasi) — Kriteria Penerimaan

## Given/When/Then
- Given property aktif, When admin membuat invoice dummy, Then invoice tersimpan status issued.
- Given invoice issued, When tandai lunas (dummy), Then status paid dan tanggal bayar terisi.
- Given tenant suspended, When user login, Then login ditolak dengan pesan tenant suspended.
- Given tenant suspended, When reopen, Then login kembali bisa.
- Given tenant suspended, When akses data hotel via token lama, Then tetap ditolak/tidak ada perubahan data (suspend hanya mengunci login).
- Given invoice overdue, When suspend dijalankan, Then status tenant suspended.
