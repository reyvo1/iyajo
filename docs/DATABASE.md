# DATABASE — Tamasya Hotel Operations Console (V137)

Baseline: `V137_FRESH_CANONICAL_MULTI_HOTEL`. Satu database = satu hotel
(DB-per-properti; tidak ada `tenant_id` di tabel inti).
Sumber: `database_setup.sql` (131 tabel) + `migrations/`.

## Konvensi umum

- PK umumnya `id` string (`tm_*`, `sr_*`, `grp_op_*`, dsb). Uang `DECIMAL(15,2).
- Idempotensi: `operationId`/`operation_id` UNIQUE di tabel tulis
  (booking, transaksi, alokasi, deposit, shift report, cuti, gaji).
- `bookings.id`, `transactions.id`, `staff.id` adalah kunci relasi utama.
- `rooms.number` (bukan id) dipakai sebagai penghubung operasional.
- `rooms.status` = proyeksi turunan (otoritas = lifecycle booking + holds).

## 1. Identitas & properti

| Tabel | Kunci | Catatan |
|---|---|---|
| `property_settings` | `property_id` UQ, `property_code` UQ | Identitas root 1 baris (`system_default`) |
| `hotel_operational_settings` | 1 baris | Jam check-in/out, jam shift, toleransi selisih, syarat shift/kunci/audit |
| `config` | 1 baris `system_default` | Token Telegram/Gemini/SMTP terenkripsi, saldo awal, `server_revision` |

## 2. Kamar & booking tamu

| Tabel | Kunci / relasi |
|---|---|
| `rooms` | `number` UQ; `type/price/status/floor/version` |
| `room_operational_holds` | `room_number → rooms.number`; hold blocker operasional |
| `bookings` | Inti: tamu (`guestName/Phone/Email`), inap (`roomNumber`, `checkIn/Out`, `scheduled*/actual*`, `stayMode/isOpenEnded`), uang (`totalAmount/discountAmount/amountPaid/balanceDue`), status (`status/paymentStatus`), sumber & metode bayar + split-payment, kunci (`keyControlStatus/accessMode physical|smart|hybrid`), deposito, closure finansial |
| `guest_profiles` | Identitas + blacklist + retensi (snapshot di booking) |
| `guest_security_deposits` | 1:1 `booking_id`; tagih/terima/refund/hangus |
| `guest_security_deposit_ledger` | Per gerakan deposito; `shift_session_id`, `transaction_id` |
| `guest_service_requests` | Permintaan tamu per kamar/booking + PIC + status |
| `facilities`, `facility_types`, `facility_bookings` | Fasilitas dinamis + booking fasilitas (tamu inap/walk-in) |

## 3. Keuangan (sumber kebenaran uang)

| Tabel | Kunci / relasi |
|---|---|
| `transactions` | `operationId` UQ, `documentNumber` UQ; `type/bankAccountId/transactionKind`, pajak snapshot, `shiftSessionId → shift_sessions.id`, `bookingId`, split-payment |
| `transaction_allocations` | Jembatan folio booking ↔ transaksi |
| `journal_entries` / `journal_lines` | Jurnal akuntansi per transaksi |
| `categories` / `subcategories` | Master; `system_key` = makna akuntansi stabil |
| `bank_accounts` | Kas/bank/QRIS; dirujuk transaksi & booking |
| `tax_rules` | Tarif per pola sumber × jenis transaksi |
| `financial_reporting_periods` | Tutup periode kas & pajak per bulan |
| `historical_backfill_adjustments` | Koreksi periode lama beraudit |
| `ota_disbursements` / `ota_disbursement_items` | Pencairan OTA + alokasi per booking |

## 4. Shift & kas laci

| Tabel | Kunci / relasi |
|---|---|
| `shift_sessions` | Kasir+pendamping, tanggal+slot, `opening/expected/actual/variance`, `cash_income/cash_expense` (TUNAI saja), status open/closed |
| `shift_reports` | 1:1 sesi; + `digitalRevenue` (transfer/QRIS), `transactionsCount` |
| `night_audit_runs` / `night_audit_items` | Pemeriksaan malam per kamar + selisih |

Aturan: `cash_income` = kaki tunai (laci); non-tunai TIDAK masuk laci,
tampil terpisah di laporan/Riwayat Shift.

## 5. Staff, HRD, gaji

`staff` (username UQ, role, Telegram binding, 2FA, capabilities JSON) ←
`attendance`, `salary_slips`, `staff_leave_requests`,
`staff_savings_accounts/ledger/requests`, `user_sessions`,
`staff_biometric_profiles`, `biometric_device_users`.

### 5.1 RBAC dinamis (registry + access-group bridge)

Tujuh tabel aditif menjaga kompatibilitas `staff.role` (9 base role),
`role_capability_presets`, dan `staff_job_titles`:

| Tabel | Peran |
|---|---|
| `rbac_roles` | Registry 10 role (9 base + `owner`) beserta urutan dan status |
| `rbac_capabilities` | Registry 23 capability; label, urutan, aktif |
| `rbac_desktop_tabs` | Registry 15 tab desktop |
| `rbac_notification_groups` | Registry 6 grup notifikasi Telegram |
| `rbac_access_groups` | Grup akses semantik (14 grup sistem) |
| `rbac_access_group_roles` | Anggota base role per grup |
| `rbac_legacy_role_sets` | Pemetaan `requireRoles()` literal → grup akses |

Permission role tetap disimpan di `role_capability_presets`; tabel katalog
hanya metadata. `rbac_legacy_role_sets` menjadi jembatan `requireRoles()`:
role set tidak termapping/inactive = fallback ke array call site, grup ada
tanpa anggota = deny. Migrasi: `migrations/20261021_DYNAMIC_RBAC.sql`.

## 6. Inventory & POS/minibar

`inventory` + `inventory_maintenance`; `pos_categories` → `pos_products`
→ `pos_sales` (+ `pos_sale_items`) → `pos_stock_movements`;
penjualan kamar tertaut via `pos_sales.booking_id`; HPP via
`cogs_transaction_id → transactions.id`.

## 7. Operasional lapangan

`housekeeping_tasks` (1 aktif per kamar), `room_access_control`
(PK `room_number`, mode kunci, booking pemegang),
`room_key_events`, `smart_lock_jobs`, `room_vacancy_reports`,
`maintenance_tickets`, `lost_found_items`, `operational_incidents`,
biometrik: `biometric_devices/device_users/verifications`.

## 8. Telegram & komunikasi

`telegram_bindings` (1:1 user↔staf), `telegram_binding_codes`,
`telegram_messages` (arsip + command), `telegram_update_log`
(idempotensi), `communication_channels/outbox/inbox/*` (multi-channel).

## 9. Audit, website publik, enterprise

`audit_logs` (jejak mutasi + `operation_id`), `activity_logs`,
`approval_requests`, `security_events`; website: `public_site_settings`,
`public_room_types/promotions/media`, `public_reservation_requests`
(→ `linked_booking_id`), `public_support_conversations/messages`;
sinkronisasi node: `node_sync_*`, `request_operation_receipts`;
Growth/Enterprise opsional: `growth_*` (grup reservasi, folio, procurement).

## Diagram relasi inti (teks)

```
staff ──telegram_bindings── telegram user
  │  (buka/tutup)
shift_sessions ──shift_reports (1:1)
  │ shiftSessionId
transactions ⇄ bookings (bookingId) ⇄ rooms (roomNumber)
  │ allocations      │ deposits/ledger
journal_entries/lines│ facility_bookings, service_requests,
                     │ pos_sales, vacancy_reports
```


## 10. Tabel & kolom baru (rilis 20261021-r19)

| Tabel / kolom | Isi |
|---|---|
| `wholesale_rate_plans` | Paket tarif kontrak: perusahaan/channel, tipe kamar, masa berlaku, min/maks malam, cut-off, pajak, sarapan |
| `wholesale_rate_nights` | Harga kontrak per tanggal dalam satu paket |
| `wholesale_rate_bookings` | Kunci tarif kontrak yang dipakai sebuah reservasi (per tanggal, lewat `nightly_breakdown`) |
| `guest_reviews` + `guest_review_invites` | Ulasan tamu: undangan sekali pakai, penilaian, moderasi, balasan |
| `exchange_rates` | Kurs harian antara mata uang property dan mata uang asing |
| `transactions.currency` / `exchangeRate` / `propertyAmount` | Mata uang nominal, kurs yang dipakai, dan nilainya dalam mata uang property. `baseAmount` tetap NULL = "pajak belum diketahui" |
| `rbac_roles`, `rbac_capabilities`, `rbac_desktop_tabs`, `rbac_notification_groups`, `rbac_access_groups`, `rbac_access_group_roles`, `rbac_legacy_role_sets` | Registry RBAC dinamis + pemetaan daftar role lama ke access group |
| `staff_job_titles` | Jabatan dinamis; setiap jabatan punya `base_role` dari role kanonik |
| `audit_logs` | Jejak audit enterprise (viewer, filter, redaksi) |
| `tamasya_catalog_items`, `tamasya_catalog_rates`, `tamasya_catalog_transaction_lines` | Katalog universal (fitur opsional). Tabelnya dibuat oleh `migrations/20261009_R15_UNIVERSAL_CATALOG_ADDITIVE.sql` + `R16_2_CATALOG_TRANSACTION_SNAPSHOTS.sql` dan **tidak** ikut `database_setup.sql` maupun berkas impor. Pasang lewat `scripts/activate-growth-enterprise.php` bila katalog universal diaktifkan |
