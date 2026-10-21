# ARSITEKTUR APLIKASI — Tamasya Hotel Operations Console (V137)

## 1. Gambaran besar

PHP = otoritas transaksi (PMS + keuangan). Satu database MySQL = satu hotel.
Frontend = modul JS per fitur di atas satu shell. Telegram bot = operasional
penuh (bukan sekadar notifikasi). HQ = agregat read-only beda database.

```
Browser/PWA ──HTTPS── api.php ── routes/ ── modules/ ── MySQL (1 hotel)
Telegram ──webhook── 080_telegram_webhook.php ──┘
Publik ── 015_public_website.php (tanpa sesi staf)
HQ ── snapshot HMAC ── hq/ (DB terpisah)
```

## 2. Backend: `api.php` → `routes/` → `modules/` → `support/`

- **`api.php`**: pintu tunggal (`?action=` / REST). Bootstrap fail-closed:
  PHP≥8.2, `APP_TIMEZONE`, identitas properti, kunci enkripsi, CORS/host,
  rate-limit, cek skema/klaster → dispatch ke `api/routes/`.
- **`api/routes/`** (28 file): batas HTTP per domain. Contoh:
  `020_hotel_booking.php` (booking/kamar/bayar/checkout/grup),
  `040_transactions_sync.php` (kas + sync offline),
  `050_auth_staff.php` (login + CRUD staf),
  `080_telegram_webhook.php` (bot operasional),
  `090_operations_communications.php` (ops-center),
  `095_canonical_reports.php` (laporan pdf/xlsx/csv),
  `065_pos_minibar.php` (POS), `060_workforce_inventory.php` (HRD+stok),
  `015_public_website.php` (publik tanpa login staf).
- **`api/modules/`**: logika bisnis per domain —
  `front_office/` (booking, checkout/housekeeping, grup),
  `finance/` (ledger, pajak, jurnal, posting authority),
  `hr_staff/`, `pos_inventory/`, `setup_admin/`, `comms/`,
  `growth_enterprise_locked/` (opsional, default-OFF).
- **`api/support/`**: fondasi — `017_authorization_policy.php`
  (role/capability/tab), `060_shift_receipts.php` (shift),
  `025/026_*_authority.php` (otoritas posting/mutasi),
  `070_data_projection_scope.php` (proyeksi baca per role).

## 3. Otorisasi (3 lapis, urutan penting)

1. **Role** = baseline (`admin/manager/receptionist/finance/.../owner`).
2. **Capability** = override per akun (`hasCapability`), bisa menambah ATAU
   mencabut. Kunci penjualan: `sell_room_access`,
   `manage_stay_access`, `receive_payment_access` (+ 16 kunci sensitif).
3. **Owner** = Admin untuk baca (semua capability Admin, `view_*` selalu boleh), tetapi **0 tulis** (semua metode selain GET/HEAD/OPTIONS ditolak global + guard route), ditegakkan di level fungsi.

`desktopTabs` mengatur tampil menu web; `telegramNotifications` hanya notif.
Telegram memakai 3 gerbang sinkron: keyboard dinamis + callback gate +
direct-command gate + validasi state-machine.

## 4. Frontend

- Shell: `index.html` → `assets/app-core.js` (default izin per role `Eh()`,
  tab `CA`, capability merge `PA()`), `app-shell.js`, vendor React.
- Chunk per fitur (`assets/chunks/`): `rooms` (kamar & tamu), `multi-room`
  (grup), `operations` (housekeeping/vacancy/patroli/tamu), `finance`,
  `staff` (+19 checkbox capability), `attendance/leaves/savings`,
  `inventory`, `report`, `telegram`, `support`, `website` (CMS),
  `dashboard`, `pos-minibar.js`, `growth-suite.js`.
- Halaman: `pos.html` (kasir), `growth-suite.html`, `enterprise-suite.html`,
  `property-setup.html` (wizard), `internal-memo.html`, `hq/` (terpisah).

## 5. Telegram (detail perintah: lihat PRD Produk §7)

Prinsip: semua mutasi ber-`operationId` (anti-ganda), grup privat saja yang
boleh transaksi (grup = agregat kamar), state percakapan kedaluwarsa +
revalidasi izin tiap langkah, KTP dicatat audit + rate-limit.

## 6. Operasional & rilis

- Instalasi: `deploy/provision_property.php` → import `database_setup.sql` →
  `first_install.php` → wizard `property-setup` → opsional modul Growth.
- Backup: `backup_now.php` + `maintenance_cron.php` (cron hosting),
  drill via `restore_drill_verify.php`. Offsite manual.
- Rilis: `FILE_CHECKSUMS.sha256` wajib cocok (file baru didaftar alfabetis),
  CI 9 job, `scripts/verify-release.sh`,
  `./create-production-zip.sh` (ZIP mengecualikan test/docs/.github).
- Batasan sadar-desain: 1 DB/hotel, single-writer, 1 booking = 1 kamar,
  grup = parent + N child atomik, shift 1 sesi open per (staf,tanggal,slot).
