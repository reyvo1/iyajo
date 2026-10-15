# HANDOFF - Catatan Perubahan (ditulis MINI AI AGEN)## 2026-09-05 23:26
- Keluhan: saya mengalami kendala transaksi manual backfill di pembayaran split dia tidak ke split pembayaranya jadi dia masuk 1 akun saja kan seharusnya dia masuk ke 2 akun kan di split pembayaranya tapi klw tr
- AI penulis: clario/glm-5.3
- Hasil patch:
```
@api/modules/finance/075_flexible_historical_backfill.php bar.1-188 (sekitar bar.28): AI: TIDAK BISA PATCH: Potongan kode yang disertakan (baris 1-188 dari 075_flexible_historical_backfill.php) hanya berisi hel
  [OK] @migrations/V137_FIX32_TX_SPLIT_PAYMENT_COLUMNS.sql : 501 char diganti 2078 char.
[UJI] sintaks OK (@api/modules/finance/025_financial_posting_authority.php).
  [OK] @api/modules/finance/025_financial_posting_authority.php : 1020 char diganti 1957 char.
  [DEP] @api/modules/finance/026_financial_mutation_authority.php memakai: tamasyaCanonicalTransactionColumns
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaPostFinancialTransaction
  [DEP] @api/modules/front_office/035_guest_security_deposits.php memakai: tamasyaPostFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaPostFinancialTransaction
  [DEP] @api/modules/growth_enterprise_locked/107_enterprise_completion.php memakai: tamasyaPostFinancialTransaction
@api/modules/finance/019_canonical_financial_semantics.php bar.1-173 (sekitar bar.13): AI: TIDAK BISA PATCH: Cuplikan `019_canonical_financial_semantics.php` ini hanya berisi klasifikasi semantik keuangan (detek
[UJI] sintaks OK (@api/modules/finance/026_financial_mutation_authority.php).
  [OK] @api/modules/finance/026_financial_mutation_authority.php : 139 char diganti 161 char.
[UJI] sintaks OK (@api/modules/finance/026_financial_mutation_authority.php).
  [OK] @api/modules/finance/026_financial_mutation_authority.php : 236 char diganti 2272 char.
[UJI] sintaks OK (@api/modules/finance/026_financial_mutation_authority.php).
  [OK] @api/modules/finance/026_financial_mutation_authority.php : 307 char diganti 406 char.
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaMutateFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaFillTransactionAllocationMetadata, tamasyaTouchTransactionAllocationMetadata
  [DEP] @api/routes/030_ota_cleanup_audit.php memakai: tamasyaDeleteFinancialTransactions, tamasyaMutateFinancialTransaction, tamasyaMutateFinancialTransactions, tamasyaUpdateBookingLinkedTransactionMetadata
  [DEP] @api/routes/040_transactions_sync.php memakai: tamasyaApplySyncedTransactionPatch, tamasyaDeleteFinancialTransactions, tamasyaEnsureTransactionDocumentNumber, tamasyaMutateFinancialTransaction
  [DEP] @api/routes/090_operations_communications.php memakai: tamasyaLockTransactionsForShift, tamasyaSetTransactionReconciliation
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaMutateFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaFillTransactionAllocationMetadata, tamasyaTouchTransactionAllocationMetadata
  [DEP] @api/routes/030_ota_cleanup_audit.php memakai: tamasyaDeleteFinancialTransactions, tamasyaMutateFinancialTransaction, tamasyaMutateFinancialTransactions, tamasyaUpdateBookingLinkedTransactionMetadata
  [DEP] @api/routes/040_transactions_sync.php memakai: tamasyaApplySyncedTransactionPatch, tamasyaDeleteFinancialTransactions, tamasyaEnsureTransactionDocumentNumber, tamasyaMutateFinancialTransaction
  [DEP] @api/routes/090_operations_communications.php memakai: tamasyaLockTransactionsForShift, tamasyaSetTransactionReconciliation
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaMutateFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaFillTransactionAllocationMetadata, tamasyaTouchTransactionAllocationMetadata
  [DEP] @api/routes/030_ota_cleanup_audit.php memakai: tamasyaDeleteFinancialTransactions, tamasyaMutateFinancialTransaction, tamasyaMutateFinancialTransactions, tamasyaUpdateBookingLinkedTransactionMetadata
  [DEP] @api/routes/040_transactions_sync.php memakai: tamasyaApplySyncedTransactionPatch, tamasyaDeleteFinancialTransactions, tamasyaEnsureTransactionDocumentNumber, tamasyaMutateFinancialTransaction
  [DEP] @api/routes/090_operations_communications.php memakai: tamasyaLockTransactionsForShift, tamasyaSetTransactionReconciliation
[UJI] sintaks OK (@api/routes/040_transactions_sync.php).
  [OK] @api/routes/040_transactions_sync.php : 749 char diganti 1706 char.
```

## 2026-09-10 - FINAL TRANSACTION / BACKFILL / TELEGRAM / SYNC HARDENING
- Scope: full remediation of manual/historical backfill, OTA settlement classification, normal Telegram money flows, canonical accounting, PBJT tax selection, shifts, refund/reversal, browser offline queue, and Primary/Standby replay safety.
- OTA is now fail-closed. Arbitrary guest/free-text labels are never treated as OTA. OTA receivable requires explicit OTA evidence; booking source remains provenance and never substitutes for the actual settlement account.
- Historical/backfill create/edit/delete/replay uses canonical transaction authority, stable full-economic replay checks, durable tombstones, explicit cash clearing, one-row split cash+bank snapshots, and historical shift exemption.
- Online reservation/checkout/backfill/offline split payments use the same one-row ledger shape. Refund and audit reversal preserve the original cash/bank legs.
- Telegram manual finance uses the same manual-finance policy as Web, has no direct transaction SQL writer, does not inherit a booking's old settlement account, and uses one canonical server-side tax authority. Processed Telegram replay is claimed before current tax rules are evaluated, so later tax-rule/catalog-label changes cannot corrupt an old successful retry.
- Accounting/journal and cash/shift/report readers use the same split/technical-account semantics. Piutang OTA is non-liquid; cash and bank legs are reported separately.
- Live tax rules fail closed: an explicit active rule is required, including explicit 0%/non-tax rules. Historical entries may remain explicitly unresolved when evidence is insufficient.
- Generic browser offline transaction sync only accepts new historical_import/manual entries; live/booking/system kinds must use canonical server workflows. Primary/Standby remains single-writer with signed replay, receipts, fencing/epoch/revision checks and uncertain-event handling.
- Database schema was not destructively changed by this remediation. database_setup.sql remains byte-identical to the supplied canonical schema (SHA-256 87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf).
- Final sandbox verification: main regression 166/166 PASS; tax+shift 17/17 PASS; transactionKind accounting matrix 100/100 PASS; PHP lint 97/97 PASS; JS/MJS lint 55/55 PASS; shell lint 2/2 PASS; configurator self-test PASS; unexpected transaction writer files 0; Telegram direct transaction SQL 0; Telegram booking-tax preview calls 0; Service Worker assets 47/47 present; app-core version graph 27/27 on 20260910-finance-sync-clean1.
- Environment limitation: this sandbox exposes PDO drivers=[] and no live MySQL/Telegram/two physical TAMASYA nodes, so live production DB/webhook/failover drills remain deployment/UAT checks rather than falsely claimed sandbox tests.

## P0 BACKFILL SPLIT PAYMENT FIX — 2026-09-10
- Previous final status revoked after live user test found Manual Backfill Split Payment could appear entirely as Tunai.
- Fixed dual settlement-selector ambiguity: when split is active, top-level `bankAccountId` is cleared/disabled; `splitTransferBankAccountId` is authoritative for transfer/QRIS leg.
- Fixed Finance/Report/Shift projections to use split cash and split transfer legs instead of gross amount.
- Added server fail-closed conflict guard for differing top-level/split transfer account selectors.
- Bumped Service Worker/core asset token to `20260910-split-backfill-p0fix1`.
- New dedicated P0 regression has 39 checks and fails the old release while passing the patched source.
- Pre-package runner: 20/20 suites PASS; integrity 22/22; lint PHP 97, JS/MJS 55, shell 2, JSON 5; configurator self-test PASS.
- No database schema/migration change in this P0 fix.
- Do not call final until regression is repeated from a fresh extraction of the produced ZIP.

## CONSISTENCY GUARD R1 — 2026-09-10
- Source continuation: tested P0 Split Backfill release; old revoked pre-P0 final was not reused.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1`.
- Added `consistency_guard_support.php` as the permanent server-side read-only integrity engine.
- Guard checks Split snapshot/account, journal projection/balance, live tax snapshots and rule references, historical Backfill tax review, Room/Extra allocations, shift cash, bank reconciliation, offline device queues, node outbox fingerprint/conflicts/revision, cluster fencing/lease, deep Primary/Standby dataset checksum during maintenance, and runtime API failures.
- Web System Health reads `consistency-guard-status`; endpoint is GET-only and Admin/Manager/Finance restricted.
- Telegram adds `/integritas` and `Akun & Diagnosa -> Consistency Guard`, with callback included in global fail-closed role classification. Telegram cash reporting is split-aware (Tunai vs Transfer/QRIS).
- `maintenance_cron.php` runs Guard with `deepCluster=true` and persists evidence only under Primary mutation lock into existing `data_integrity_issues` / `system_alerts`; no auto-fix of business data.
- Deep checksum uses the existing authenticated peer-status channel and deterministic replication-surface checksum. Local/peer mismatch without pending outbox is FAIL; pending synchronization or unreachable peer is WARNING.
- Database schema is unchanged from tested P0: database_setup.sql SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migration files unchanged.
- Pre-package verification after fixing Guard callback global role-map and build lineage: 23/23 suites PASS; main 166/166; Split P0 39/39; Guard injection 20/20; Telegram Guard 9/9; Guard integration 20/20; UI audit 22/22; integrity 22/22; PHP 98/98; JS/MJS 55/55; shell 2/2; JSON 5/5; configurator `ok=true`.
- Final ZIP must still be fresh-extracted and all gates rerun from that extraction before release.

## CONSISTENCY GUARD R2 FLEXIBLE MAINTENANCE — 2026-09-10
- Continuation source: fresh-extract verified Consistency Guard R1. Revoked pre-P0 release was not reused.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2`.
- Goal: Guard must work on cheap shared hosting without cron and remain compatible with future cPanel cron/VPS/local/offline/two-server deployments.
- Added portable `run-if-due` scheduler in `consistency_guard_support.php` using existing `runtime_request_events`; no schema/migration was added.
- Default cadence is light 30m, deep 12h, retry backoff 5m. Environment overrides are supported; cron is explicitly optional.
- Added authenticated stateless `consistency-guard-maintenance` POST trigger. It is excluded from the business mutation/outbox path and invokes the same Guard engine.
- Added browser `assets/flexible-maintenance-addon.js`: online + logged-in aware, fire-and-forget, periodic/visibility/online triggers. Server-side due state and advisory locking prevent browser-tab multiplication from becoming repeated scans.
- Node sync agent also calls the same scheduler; Standby/local-backup scans are read-only and never persist Guard issues or bump primary revision.
- Opportunistic Guard refuses to run inside an existing caller/business DB transaction (`caller_transaction_active`), so it cannot join/commit/rollback hotel transactions or couple scheduler writes to their lifecycle.
- `maintenance_cron.php` remains available as OPTIONAL full maintenance (backup + retention cleanup + deep Guard) when a deployment supports cron. It is no longer a Guard dependency.
- System Health exposes Auto Guard state, last automatic light/deep run and cron=optional. Telegram `/integritas` continues to expose Guard safely to Admin/Manager/Finance.
- Entire app-core/chunk/Service Worker runtime graph is normalized to the single R2 build token to avoid mixed-cache/duplicate module instances.
- Pre-package flexible runner: 25/25 suites PASS, all return codes 0. Flexible scheduler test 21/21 PASS; Standby scheduler 4/4 PASS; integrity graph 22/22 PASS.
- Pre-package lint: PHP 98/98, JS/MJS 56/56, shell 2/2, JSON 5/5; configurator self-test `ok=true`.
- Database compatibility retained: `database_setup.sql` SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migration files byte-identical to tested P0.
- Release rule remains strict: package, extract to a brand-new directory, rerun all 25 suites + lint/configurator/integrity/manifest/archive checks before calling final.


## CANONICAL REPORT ENGINE R3 — 2026-09-10
- Continuation source: fresh-extract verified Flex Consistency Guard R2.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`.
- Added server-side `canonical_report_support.php` and `api/routes/095_canonical_reports.php`; one canonical snapshot feeds PDF, Excel XLSX, CSV, JSON and email attachments.
- Official report output does not copy the application DOM and does not require Chrome/headless browser, Node.js, LibreOffice, or cron.
- Added 17 report types across finance, tax, journal, cash/bank, reservations/check-out, shift, reconciliation, Backfill/history, payroll, attendance, inventory, Night Audit, Housekeeping and audit log.
- PDF is a standalone A4 document and wraps long values without silent truncation. Excel is real OpenXML with multi-sheet output, frozen headers, filters and numeric accounting cells.
- Split Payment keeps separate cash and transfer/QRIS legs and selected transfer account in every canonical export.
- Legacy finance email now attaches canonical PDF + XLSX. New canonical email route defaults to PDF+XLSX and can add CSV, with a 12 MB safety cap for shared hosting.
- Every report carries Report ID, SHA-256 content checksum, period, build/user/property metadata and Consistency Guard state. Incomplete/truncated archive datasets fail closed.
- Existing quick browser-print paths remain available but are explicitly marked as quick print; official documents use the canonical report center.
- No database schema/migration changes. `database_setup.sql` stays SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migrations unchanged.
- Pre-package combined runner: 26/26 suites PASS; report-specific regression 33/33 PASS; lint PHP 100/100, JS/MJS 57/57, shell 2/2, JSON 5/5; configurator `ok=true`.
- Release still requires fresh extraction of the produced ZIP and repeat of all 26 suites + package checks before final naming.

## PRODUCTION FULL AUDIT R4 — 2026-09-10
- Continuation source: fresh-extract verified Canonical Report Engine R3; no rollback to older ZIP.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`.
- Added full-surface release audit across Dashboard, Front Office, Operasional, Keuangan, Laporan, SDM, Digital Website, Sistem, optional modules and Telegram.
- New audit currently passes 163/163 checks, including 71 frontend direct API actions and 120 generated Telegram callbacks; no orphan Telegram state or direct Telegram transaction SQL writer was found.
- Performance hardening: server-revision remains 30s but unchanged-revision full hotel-data fallback is 5m; Growth/Enterprise probes become visible-only 30s; Growth PMS link replaces unconditional 2.5s DOM scan with MutationObserver + 15s visible fallback. Cluster safety polling remains 15s/5s and was intentionally not weakened.
- Service Worker/core graph bumped to R4 cache v153 to prevent mixed R3/R4 browser code after deploy.
- Database schema unchanged: database_setup.sql SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migrations unchanged from R3.
- Browser E2E attempts were blocked by sandbox policy for localhost and file://. Do not claim live browser/MySQL/Telegram-provider/two-physical-server verification; these remain production UAT boundaries.
- Pre-package combined gate after R4 audit: 27/27 suites PASS. Final release still requires fresh-extract rerun plus syntax/configurator/manifest/database/hygiene/archive checks.


## R4.1 — Service Worker path / SPA rewrite hotfix — 2026-09-11
- Root cause confirmed from staging console: the requested URL was `database_verifity.php`, while the real utility is `database_verify.php`. The missing typo path was rewritten to `index.html`, so PWA registration incorrectly requested `/database_verifity.php/sw.js` and received HTML.
- SPA rewrite now returns HTTP 404 for missing file-like requests (`.php`, `.js`, `.css`, `.json`, `.pdf`, `.xlsx`, etc.) instead of serving `index.html`.
- Service Worker base resolution now uses URL semantics and works for root or subfolder installs.
- Correct verifier URL remains `database_verify.php`.
- No database/schema/migration changes.

## R4.2 — Maintenance Semantic Binding Bootstrap — 2026-09-11
- Fixed a deployment deadlock discovered on staging: API is correctly fail-closed while patch_level mismatches, but `release_hardening_finalize.php` can require Master Kategori semantic bindings that were previously only configurable inside the blocked app.
- Added `release_semantic_bindings.php`, protected by the existing Admin Web Tool secret + HTTPS gate. It validates database safety, property identity and canonical baseline before any change.
- The tool only binds `categories.system_key/is_system` to an explicitly selected active category, or explicitly creates a new property-owned category. It cannot finalize patch level, run DDL, or mutate bookings/transactions/journals/balances.
- Finalizer errors for missing semantic roles now point to the maintenance tool.
- Database schema remains unchanged; database_setup.sql and all 13 migrations are byte-identical to R4.1.
- Targeted safety checks 15/15 PASS; PHP lint 101/101, JS/MJS 57/57, shell 2/2, JSON 5/5; configurator self-test ok=true.

## 2026-10-11 - TELEGRAM ROOM PAYMENT STATUS MENU & BUG FIXES

### Perubahan Utama:

#### 1. Menu Baru: `room_payment_status_menu` (Telegram)
- **Fungsi**: Melihat status pembayaran per kamar (Lunas/Belum Lunas/Sebagian) dengan filter & pagination
- **Akses**: Admin, Manager, Receptionist, Finance
- **Lokasi**: Menu `Kamar & Tamu` → `💰 Status Bayar Kamar`
- **Filter**: Semua / Belum Lunas / Aktif / Reserved
- **Pagination**: 30 kamar per halaman (konsisten dengan picker lain)
- **Icon Status**: 
  - ✅ Lunas / ❌ Belum Bayar / ⚠️ Sebagian (payment)
  - 🔴🟢🛠️🧹🧽⚪ (status kamar)
  - 🔴🟢🟡⚪ (status booking: Aktif/Reserved/Maintenance/Kosong)

#### 2. Bug Fixes Critical:
- **Revenue Recognition Logic** (`019_canonical_financial_semantics.php`): Urutan cek `baseAmount+taxAmount` sebelum `taxSnapshotStatus` → revenue recognized benar untuk unresolved tax dengan baseAmount valid
- **Room Access Guard** (`091_room_access_issue.php`): Hapus cek redundant `current_booking_id` yang salah blokir key-issue valid
- **Check-in Timing** (`043_multi_room_reservations.php`, `040_rooms_checkout_housekeeping.php`): `current_booking_id` hanya di-set saat `key-issue`, bukan saat check-in
- **Telegram Payment Status Display**: Tambah ✅/❌/⚠️ di active booking picker (`080_telegram_webhook.php`)
- **Function Call Bug** (`080_telegram_webhook.php`): Fix `$buildTelegramRoomPaymentStatusMenu` variable call vs function name
- **Syntax Error** (`080_telegram_webhook.php`): Fix bracket nesting di try-catch block
- **Function Call Bug**: `$buildTelegramRoomPaymentStatusMenu` variable call di handler

#### 3. Error Handling Robust:
- Try-catch di dalam `buildTelegramRoomPaymentStatusMenu` function
- Error detail (message, file, line) dikirim ke user + log server
- Error handling di handler callback

#### 4. R164 Custody Cleanup:
- `tamasyaR164AssertCleanupCustodySafe` guard di `100_cleanup_tools.php` & `030_ota_cleanup_audit.php`
- Schema alignment migrasi `orphan_outstanding_room_key` & `room_key_custody_mismatch`

#### 5. Test Coverage:
- Unit test: `tests/r164-access-issue-guard-regression.php` (update expectation)
- Unit test: `tests/r164-custody-cleanup-regression.php` (7 test)
- Unit test: `tests/r164-custody-schema-migration-regression.php` (9 test)
- UAT Test: `tests/uat_rc1/scenario_telegram.py` - room_payment_status_menu UAT (filter, pagination, error handling)

### Test Results:
- **41 Regression Suites**: PASS (41/41)
- **UAT GitHub**: 1099/1099 assertions PASS
- **Syntax/Checksum**: PASS

### Files Changed:
- `api/routes/080_telegram_webhook.php` - Main Telegram webhook handler
- `api/modules/finance/019_canonical_financial_semantics.php` - Revenue recognition logic
- `api/support/091_room_access_issue.php` - Room access guard
- `api/modules/front_office/043_multi_room_reservations.php` - Check-in timing
- `api/modules/front_office/040_rooms_checkout_housekeeping.php` - Check-in timing
- `api/modules/finance/100_cleanup_tools.php` - R164 custody guard
- `api/routes/030_ota_cleanup_audit.php` - OTA cleanup with custody guard
- `api/support/080_schema_alignment.php` - Schema alignment R164
- `tests/uat_rc1/scenario_telegram.py` - UAT test for room_payment_status_menu
- `tests/r164-access-issue-guard-regression.php` - Updated expectation
- `tests/r164-custody-cleanup-regression.php` - New test file
- `tests/r164-custody-schema-migration-regression.php` - New test file
- `FILE_CHECKSUMS.sha256` - Updated checksums
- `release_contract.php` - Version bump

### Production Package:
- `tamasya-production-prod-20261011-0350.zip` (2.1 MB, 334 files)

## 2026-10-13 — FULL SURFACE COVERAGE + NEGOTIATION COLLECTED-FIGURE SEMANTICS + R13 UAT COMPLETION

### Satu (2026-10-13, 46 commit, agen utama):
- **Cakupan UAT RC1 diperluas masif**: 18 scenario baru — procurement (PO status machine + link payment), cluster admin & reconcile dengan split-brain guard, destructive purge, security access (session revoke/device disable/key recovery), catalog tax/key, cleanup reconciliation, lost & found custody, purge history & webhook removal, website CMS delete, enterprise loyalty, communication channels, biometric attendance, catalog download, booking surfaces (identity/receipts/transfer/settlement), operations centre (30 command), final surfaces, closing surfaces. Semua scenario diberi fixture bersama (`scenario_fixtures.py`: open shift, bank account, room release) supaya tidak bergantung pada efek samping suite lain.
- **Fix finance**: conflict PO payment & loyalty award kini 409 (`DomainException`), bukan 500; OTA disbursement cleanup mengunci baris (`lockRows`) sebelum journal-nya dihapus, menutup window pembacaan journal yang akan dihapus; duplicate link (po_id,transaction_id) ditolak sebagai conflict sebelum INSERT.
- **Semantik harga nego checkout** (fix inti): angka yang diketik resepsionis adalah **uang yang diserahkan guest sekarang**, bukan total booking baru. Uang yang sudah diterima tetap di shift-nya dan **ditambahkan kembali** ke total booking baru, sehingga tidak dipotong dua kali saat settlement. Kelima entry point diselaraskan: web preview (`020_hotel_booking.php`), web save + telegram nego save (helper `tamasyaApplyBookingNegotiatedPrice`), telegram nego amount, telegram checkout total open-ended. Batas ikut pindah ke sisa tagihan: di bawah kapasitas kamar yang belum dibayar atau di atas sisa tagihan tetap ditolak; komponen yang sudah lunas tidak ikut dipotong.
- **Test baru**: `tests/negotiation-settlement-characterisation.php` (mengikat arti angka yang ditagihkan cashier), `tests/money-precision-regression.php` (pembulatan 2 desimal di chokepoint + pin jam properti + larang BETWEEN pada kolom timestamp), `tests/financial-mutation-lock-regression.php` (semua pemanggil yang menghapus journal wajib `lockRows`). Ketiganya masuk `scripts/verify-release.sh` dan CI.
- **Hasil**: 41/41 suite lokal PASS; CI GitHub run 38411396849 **8/8 job GREEN** (PHP 8.2–8.5, MariaDB, full UAT, VPS/SaaS sim) pada `b8277ea`.

## 2026-10-14 — PENYELESAIAN TAMPILAN NEGOSIASI + PACKAGING R13.1
- Melanjutkan audit sesi 2026-10-13: logika uang server sudah benar dan teruji, tetapi **lapisan tampilan** belum mengikuti semantik baru sehingga menyesatkan operator.
- **Web UI** (`assets/chunks/booking-negotiation.js`): label kini "Uang yang dibayar tamu sekarang"; min/max pindah ke `minimumTotal - amountPaid` .. `totalAmount - amountPaid`; default = `balanceDue`; info menjelaskan total booking baru = angka dibayar + pembayaran lama (tetap).
- **Cache-busting mengikuti konvensi per-chunk** (build id tetap `20261008-r15`; tidak ada bump build global): `booking-negotiation.js` direferensikan ulang dengan token `20261014-nego-collected-now1` di `rooms.js` dan di precache `sw.js`; `rooms.js` direferensikan ulang dengan token yang sama di `app-core.js` dan precache; `app-core.js` sendiri mendapat token baru `20261014-nego-core1` di `index.html` dan precache `sw.js` (identitas index dan SW harus sama, lihat gate `regression.mjs`).
- **Telegram** (`api/routes/080_telegram_webhook.php`): instruksi nego (2454) kini menyebut batas Rp floor..ceiling semantik baru; pesan error nego amount (4695) menampilkan rentang nyata; konfirmasi (4708) menampilkan total booking baru = figure + pembayaran lama serta tagihan saat checkout, bukan "total final" dan "sisa" yang memotong uang lama dua kali.
- **Bersih-bersih**: dead code copy-paste (blok `if booking_id:` yang tidak pernah terdefinisi) dihapus dari `scenario_po_link_payment.py` dan `scenario_salary_correction.py`; `scenario_cluster_admin.py` dan `tcp_forwarder.py` yang terlewat kini masuk `FILE_CHECKSUMS.sha256`.
- **Packaging**: build id `20261014-nego-collected-now1`, ZIP `tamasya-production-prod-20261014-<HHMM>.zip`, diverifikasi dari ekstraksi segar sesuai aturan release (lihat laporan audit).

## 2026-10-14 (lanjutan) — R14: JAM SHIFT KONFIGURABEL + KONEKTOR PAYMENT/CHANNEL

Audit terhadap temuan "Channel Manager dan Payment Gateway masih fondasi" diselesaikan dalam tiga bagian. Semua menambah gate baru (44 suite sumber) dan dua scenario UAT live; tidak ada jalur yang boleh memposting uang secara otomatis.

### R14.1 — Jam shift dapat diatur (tidak lagi hardcoded 2x)
-êmpat akar: batas slot `pagi/siang/malam` di-hardcode dua kali dan tidak sama — web 07:00–15:00–23:00 (`operations.js`) vs pagar malam server 18:00–05:00 (`090_operations_communications.php`). Shift 20:00 bisa diklasifikasi "siang" di form tapi "malam" di server, sehingga gerbang Night Audit berbeda.
- **Perbaikan**: tiga kolom `shift_day_start` / `shift_siang_start` / `shift_malam_start` (default 07:00/15:00/23:00 = perilaku historis, tidak mengubah arti baris lama) + helper `tamasyaShiftBoundariesMinutes` / `tamasyaShiftSlotForMinutes` / `tamasyaDefaultShiftSlotForNow` di `060_shift_receipts.php`. Pembaca gagal-aman ke default bila nilai rusak/tidak berurutan, karena slot menentukan gerbang Night Audit.
- Web, server shift-open, klasifikasi malam legacy, dan `isNightShiftRow` kini membaca sumber yang sama. Simpan settings menolak urutan di luar (pagi < siang < malam) tanpa menyentuh baris tersimpan. UI menambah tiga prompt jam shift.
- Bukti: `tests/shift-hours-config-regression.php` (38) + `tests/uat_rc1/scenario_operational_settings.py` (16).

### R14.2 — Payment Gateway: fase konektor (tidak pernah auto-posting uang)
- Ditambah registry provider (`growth_payment_providers`, secret terenkripsi) dan endpoint publik `api.php?action=growth-payment-webhook` dengan HMAC-SHA256 atas `timestamp.body`, toleransi skew 5 menit, `hash_equals`, rate limit per provider+alamat, dan dedupe `provider_event_id`.
- Kegagalan autentikasi selalu 401 dengan pesan tunggal yang sama (tidak bisa dipakai untuk menebak provider mana yang ada). Status provider yang tidak dikenal DITOLAK, transisi intent satu arah (`created→provider_paid→refunded`; `failed/expired` tidak bisa dibayar lagi).
- Webhook hanya memindahkan status intent + mencatat event `signature_valid=1`, mengembalikan `financialMutation=false` dan `nextAction=settle_canonical_payment`. Uang tetap masuk lewat alur canonical — persis prinsip "tidak memposting uang otomatis" yang Anda temukan, sekarang dengan konektor nyata.
- Bukti: `tests/payment-connector-webhook-regression.php` (42) + `tests/uat_rc1/scenario_payment_connector.py` (21).

### R14.3 — Channel Manager: fase konektor (booking DRAFT, bukan otomatis)
- Registry `growth_channel_providers` + endpoint publik `api.php?action=growth-channel-webhook` dengan keamanan HMAC yang sama.
- Event `reservation_created` yang terverifikasi dan sudah dipetakan membuat **draft RESERVED** lewat `createCanonicalBookingWorkflow` (kode kanal `provider`): tanpa uang, tanpa check-in, `bookingSource` = alias OTA dikenal atau `ota-<kode>`, tarif divalidasi terhadap rate plan mapping, kamar dipilih dari tipe internal hasil mapping.
- Tipe kamar vendor yang belum dipetakan dicatat sebagai `unmapped` untuk staf, bukan ditebak. `externalId` yang sama tidak akan pernah membuat draft kedua. Event lain (perubahan/pembatalan) ditolak pada fase ini.
- Bukti: `tests/channel-connector-webhook-regression.php` (30) + `tests/uat_rc1/scenario_channel_connector.py` (19).

### Catatan migrasi & selisih skema
- Tiga migrasi additive baru: `20261014_SHIFT_HOURS_CONFIG.sql`, `20261014_PAYMENT_CONNECTOR_PHASE.sql`, `20261014_CHANNEL_CONNECTOR_PHASE.sql` (semuanya idempotent). Jalankan setelah backup terverifikasi, bukan pada DB HQ bersama.
- `growth_channel_events` di instalasi yang sudah ada memakai bentuk kolom berbeda dari definisi V137 (memiliki `error_message/received_at/processed_at`, tanpa `external_property_id`). Definisi V137 tidak pernah mengubah tabel yang sudah ada (`CREATE TABLE IF NOT EXISTS`), sehingga handler channel sengaja ditulis mengikuti bentuk yang benar-benar terpasang, bukan definisi V137.

## 2026-10-14 (lanjutan 2) — PERBAIKAN AKAR DARI DUA RUN CI MERAH

Push R14 pertama membuat CI merah dua kali. Keduanya diperbaiki di akar, bukan ditambal:

### Merah #1: `growth expectedTables mismatch 14 != 16`
- Akar: tiga inventory tabel growth harus bergerak bersama — file migrasi V137, daftar `$growthTables` di `optional_modules_install.php`, dan `tamasyaGrowthSchemaTables()`. Dua tabel registry konektor hanya ditambahkan ke V137.
- Perbaikan: kedua daftar disinkronkan ke 16, plus regression baru `tests/growth-schema-inventory-regression.php` (6) yang memaksa ketiga sumber tetap sama untuk selamanya. Tanpa ini, kontrak readiness bisa lolos padahal tabel provider belum ada — lalu gagal telat sebagai SQLSTATE di UAT.

### Merah #2: empat test browser Growth Suite
- Akar: satu baris render yang ditulis untuk panel provider channel memakai variabel `constCPL` yang tidak pernah dideklarasikan → ReferenceError di setiap bootstrap growth-suite → halaman Growth rusak total di browser sungguhan (test statis tidak menangkapnya karena tidak memuat module graph).
- Perbaikan: baris ditulis ulang dengan deklarasi eksplisit + `hasSecret` dihitung dari `secret_length` yang memang diekspos bootstrap. Pelajaran proses: setiap perubahan token/JS kini wajib lolos probe Chromium sebelum push.

### Pengerasan scenario + tanpa pelemahan UAT
- `scenario_operational_settings` ditulis ulang: memakai staf khusus dengan session sendiri untuk siklus shift (login/logout), tidak lagi menutup shift milik suite lain; release shift malam lewat Night Audit resmi; semua operation id unik per run. 19/19 dan aman dijalankan berulang.
- Command manual yang belum tercakup kini diuji: `channel-provider-list` GET, `channel-event-register` manual, `payment-event-register` manual (dalam scenario konektor masing-masing).
- Verifikasi tidak ada pelemahan: nol baris `expect` dihapus di seluruh session ini (satu label + satu token yang mengikuti perubahan kode, sisanya murni penambahan: 5 regression suite + 3 scenario UAT). Detail per baris dicatat dalam laporan audit sesi ini.
- Hasil akhir: gate lokal 45/45, run CI `38549766171` **8/8 job HIJAU** termasuk seluruh UAT browser dan ±1.800 assertion scenario yang dijalankan manual satu-per-satu di stack lokal.


## 2026-10-14 (lanjutan 3) — PERBAIKAN MENU NYASAR + P1 FASILITAS DINAMIS + PETA P2

### Audit menu: satu-satunya menu yang salah tempat diperbaiki
- Keluhan: pengaturan jam shift "nyasar" di dalam kartu "Kebijakan Check-out & Akses Kamar" (kontrol kunci) sehingga sulit ditemukan.
- Audit seluruh struktur menu (4 domain, 21 sub-nav operasi, kartu finance/rooms/SDM/sistem): trio night-audit, cleanup, dan kunci memakai pola mode–aksi–riwayat yang disengaja; journal/pajak/rekonsiliasi di Operasional adalah permukaan back-office yang memang dioperasikan dari sana. Satu-satunya yang benar-benar salah tempat adalah dialog Pengaturan global.
- Perbaikan: kartu baru "Pengaturan Operasional" (tepat sebelum "Shift & Tutup Kas") berisi ringkasan baca-saja (check-in/out, jam shift, toleransi, kas) + tombol Pengaturan yang sama; tombol dilepas dari kartu kunci. Dikunci regression (41 checks).

### P1 — Modul fasilitas dinamis (R15)
- Tipe dan unit adalah DATA: `facility_types` (model kapasitas open/quota/slot/room, model tarif visit/hour/package/member) + `facilities` (unit fisik) + `facility_bookings` (konsumsi kapasitas). Kolam/gym/karaoke/bar/spa dibuat lewat form, bukan coding.
- Harga dihitung server (visit×pax, per-jam dibulatkan ke atas, paket flat, member tercatat tanpa tagihan); klien tidak boleh mendikte uang. Slot/ruangan yang bertabrakan dan kuota habis ditolak 409; waktu dinormalisasi ke HH:MM:SS agar slot bersebelahan tidak salah dianggap tabrakan.
- Pemakaian menagih ke folio booking sebagai extra (`taxKind/allocationType=extra`, snapshot PBJT kanonik, `extra_service` income) dan lunas lewat alur pembayaran/checkout biasa. Tidak ada INSERT transaction di route fasilitas. Batal hanya untuk yang belum dibayar (yang lunas lewat refund); replay idempotent.
- UI di Operasional → grup daily, setelah shifts: sub-nav `facilities`, master untuk admin/manager, reservasi untuk resepsionis, Owner terkunci oleh kebijakan yang sama. Tanpa hooks di dalam view (mengikuti pola existing agar tidak melanggar aturan React).
- Bukti: `tests/facility-management-regression.php` (37) + `tests/uat_rc1/scenario_facility_management.py` (28). Baseline schema 113→116 tabel (tiga assertion jumlah diperbarui).
- Peta P2 (tenant billing → onboarding → isolasi adversarial → e-faktur/load/DR/legal) ditulis di `P2_ROADMAP.md` beserta prinsip yang tidak boleh dilanggar.
