# Audit Refactor RBAC & Lapisan Statis TAMASYA

Tanggal: 2026-10-20
Status: audit selesai; Sprint A #1 (`daily_summary`), refactor Dynamic RBAC 2026-10-21, dan grid izin per-akun dinamis sudah diimplementasikan dan teruji; batas full arbitrary menu tetap didokumentasikan
Cakupan: backend `api/`, frontend `assets/`, skema `database_setup.sql`, migrasi, dan metadata rilis.

## 1. Ringkasan eksekutif

- Sistem saat ini sudah punya fondasi capability yang baik di `017_authorization_policy.php`, tetapi belum menjadi satu-satunya sumber otorisasi.
- Masih ada **180 panggilan `requireRoles()` di 22 file** dan **142 panggilan `hasCapability()`/`requireCapability()` di 22 file**. Dua model ini hidup berdampingan.
- Ada tiga sumber kebenaran yang berbeda: base role 9 nilai, capability 23 kunci, dan daftar tab/role di frontend. Tidak ada tabel `roles`/`capabilities` yang menjadi registry kanonik.
- Frontend masih menyimpan role dan permission statis di `app-core.js`, `navigation-registry.js`, dan beberapa addon. UI bisa menampilkan tombol yang berbeda dari keputusan server.
- Sprint A #1 sudah mendaftarkan `daily_summary` ke registry/UI/seed/migrasi (capability ke-23); default-nya mengikuti fallback route 090: `admin`, `manager`, `receptionist` terbuka, role lain tertutup.
- Skema belum punya foreign key role/capability. Preset jabatan disimpan sebagai JSON `text`, dan penerapan preset menulis override penuh per akun, bukan pewarisan role.
- Migrasi dan metadata rilis belum satu jalur: baseline hanya mencatat 5 migrasi lama, migrasi SQL 2026 tidak menulis `schema_migrations`, dan `TAMASYA_BUILD_ID` masih `20261008-r15`.
- Rekomendasi: jangan mulai dari UI. Mulai dari **inventaris kontrak akses + schema expand + policy layer**, lalu shadow mode, baru cutover UI. Full RBAC harus menjaga owner read-only, pemisahan tugas finance, night audit, dan aturan sesi manager.

## 2. Fakta arsitektur saat ini

### 2.1 Tiga lapis izin

1. **Capability** — `api/support/017_authorization_policy.php:134` (`hasCapability`) membaca override `staff.permissions.capabilities`; jika tidak ada, jatuh ke `fallbackRoles`. `requireCapability()` di baris 146 hanya membungkus 403 + exit.
2. **Desktop tab** — `hasDesktopTabAccess()` di baris 156 memakai `staff.permissions.desktopTabs`, lalu fallback role. Ini dipakai untuk visibilitas menu dan sebagian proyeksi data.
3. **Role keras** — `requireRoles()` di baris 107 dan perbandingan `in_array($role, [...])` langsung di route. Ini yang paling banyak dan paling sulit diaudit.

Owner adalah kasus khusus: `tamasyaSessionPermissions()` baris 78 memberi semua tab, tetapi hanya capability `view_*` yang true; `tamasyaEnforceOwnerReadOnly()` baris 95 menolak mutasi. `requireRoles()` juga membiarkan owner lolos GET. Ini invariant yang harus dipertahankan.

### 2.2 Registry dan data role

- `api/support/021_role_presets.php:17-19` masih memakai daftar 9 role kanonik statis:
  `admin, manager, finance, receptionist, cleaning_service, keamanan, koki, tukang_kebun, lain_lain`.
- `tamasyaJobTitleSystemDefaults()` baris 23-35 menduplikasi label dan base role yang sama.
- `tamasyaCapabilityRegistry()` baris 136-138 berisi 23 capability. `tamasyaDesktopTabRegistry()` baris 142-144 berisi 15 tab.
- Pemindaian menemukan 23 nama capability yang benar-benar dipanggil; `daily_summary` (dipakai `api/routes/090_operations_communications.php:1478`) sekarang sudah terdaftar di registry/UI dan punya migrasi `20261020_DAILY_SUMMARY_CAPABILITY.sql`.
- Tabel `role_capability_presets` (`database_setup.sql:2218`) menyimpan `permissions` sebagai `text`, primary key `role`, tanpa FK.
- Tabel `staff_job_titles` (`database_setup.sql:2243`) menyimpan `base_role` sebagai varchar, tanpa FK ke tabel role.
- Tabel `staff` (`database_setup.sql:2181`) menyimpan `role varchar(20)`, `job_title varchar(30)`, dan `permissions text`; tidak ada `role_id` dan tidak ada FK role.
- Baseline berisi 125 tabel (setelah 7 tabel Dynamic RBAC) dan hanya 10 foreign key. Registry role/capability tidak memiliki integritas referensial.

### 2.3 Titik statis backend

Hasil pemindaian teks (bukan jumlah endpoint final):

| Pola | Jumlah |
|---|---:|
| `requireRoles()` | 180 call site di 22 file |
| `hasCapability()`/`requireCapability()` | 142 call site di 22 file |
| Nama capability unik | 23 (semua terdaftar) |
| Baris dengan literal role di PHP | 678 baris; 1.764 kemunculan; 49 file |
| Perbandingan role langsung | ±106 baris di 16 file |
| Literal role di JS | 96 baris; 542 kemunculan; 42 file |

Angka literal role bukan jumlah cek otorisasi: banyak yang berupa label, seed, pesan, atau data tampilan. Angka ini dipakai untuk menunjukkan luasnya duplikasi, bukan untuk menghitung endpoint.

Hotspot `requireRoles()`:

| File | Jumlah |
|---|---:|
| `api/routes/112_enterprise_completion.php` | 44 |
| `api/routes/090_operations_communications.php` | 27 |
| `api/routes/110_growth_suite.php` | 22 |
| `api/routes/040_transactions_sync.php` | 13 |
| `api/routes/100_catalog_downloads.php` | 10 |
| `api/routes/075_communication_channels.php` | 10 |
| `api/routes/010_cluster_status.php` | 9 |
| `api/routes/030_ota_cleanup_audit.php` | 8 |

Dari 176 call yang berhasil diparse, kombinasi yang mendominasi: 53 `admin/manager/finance`, 44 `admin`, 40 `admin/manager`, 11 `admin/manager/finance/owner`, 8 `admin/manager/receptionist`. Artinya sebagian besar aturan bisa dipetakan ke sedikit capability, tetapi saat ini ditulis ulang di banyak tempat.

Contoh aturan yang bercampur:

- `api/routes/090_operations_communications.php:444`: manager tidak boleh mencabut sesi Admin/Manager lain. Ini aturan relasi aktor-target, bukan sekadar daftar role.
- `api/routes/062_employee_self_service.php:10-12`: `$canApproveLeave = in_array($staffRole, ['admin','manager'], true)`.
- `api/routes/112_enterprise_completion.php:37,44,52,55,64-69`: `requireRoles` dan `in_array($role, [...])` bercampur dengan tab check.
- `api/routes/110_growth_suite.php:260-265`: `$canProcurementRead`, `$canChannelRead`, `$canPaymentRead` dihitung dari role + tab.
- `api/support/090_security_cluster_smartlock.php:156-159`: finance selalu boleh night audit, lalu fallback capability untuk role lain.
- `api/support/070_data_projection_scope.php:846-861`: visibilitas finance memakai `hasDesktopTabAccess` untuk `finance`/`report` atau capability `correct_booking_audit`; notifikasi publik memakai tab `support`.
- `api/routes/090_operations_communications.php:1478`: `daily_summary` sudah memakai capability terdaftar; fallback route dipertahankan lewat seed/migrasi Sprint A #1.

### 2.4 Titik statis frontend

- `assets/app-core.js` memuat `db` 15 tab, `PK` (staff/config/db_config hanya admin/owner; support admin/manager/receptionist/owner), `CA` (role ke daftar tab), dan `_K` dengan special case owner serta override `manage_public_website`.
- `assets/navigation-registry.js` memakai `roles: Object.freeze([...])` untuk module dock: memo, POS, growth, enterprise. Logika `tamasyaModuleDockState()` juga mengecek role dan tab.
- `assets/chunks/staff.js:3` mengimpor `PK`, `CA`, `_K`, `PA`, `W_`, `db` dari `../app-core.js?v=20261014-nego-core1`; file ini juga memuat fallback label `rpStaticLabel`, `rpSystemKeys`, `rpBaseOf`, `rpAccountPermissions`, dan `rpAllowed`, tetapi grid izin per-akun sekarang dirender dari katalog `role-preset-list`.
- `assets/guest-center.js:6-8` memakai `MUTATION_ROLES`, `GUEST_SERVICE_ROLES`, dan `MONEY_ROLES`.
- `assets/finance-category-usage-picker.js:2` memakai `ROLE_OPTIONS` statis.
- `assets/growth-suite.js`, `assets/enterprise-suite.js`, `assets/universal-catalog-workspace.js`, dan `assets/operations.js` juga memuat daftar role/capability sendiri.

Konsekuensi: perubahan izin di server tidak otomatis mengubah UI. Gejala yang mungkin muncul adalah tombol tampil tetapi server 403, atau menu hilang padahal API mengizinkan. Ini sudah terlihat pada riwayat perbaikan permission double-encoded.

### 2.5 Skema, migrasi, dan metadata rilis

- `role_capability_presets` di-seed 9 role (owner tidak di-seed) pada `database_setup.sql:2227-2236`.
- `staff_job_titles` di-seed 9 jabatan sistem pada baris 2256-2265. Baseline sudah memuat tabel ini; tidak ada masalah “tabel belum ada” pada baseline saat ini.
- Migrasi `migrations/20261019_STAFF_JOB_TITLES.sql:5-12` menyatakan desain sengaja: `staff.role` tetap base role agar seluruh enforcement lama tidak berubah; `staff.job_title` hanya label. Ini adalah technical debt yang eksplisit, bukan bug tak sengaja.
- `migrations/20261019_ROLE_CAPABILITY_PRESETS_FASE3.sql` menambah 3 capability dengan `JSON_MERGE_PATCH` dan guard idempoten.
- `migrations/20261020_DAILY_SUMMARY_CAPABILITY.sql` menambahkan capability ke-23 dengan pola `JSON_MERGE_PATCH` + guard `IS NULL`; hanya 9 role statis yang di-update, baris jabatan dinamis ditambal saat dibaca oleh `tamasyaRolePresetRows()`.
- `schema_release_state` di `database_setup.sql:2910-2912` masih menandai patch `V137_STABLE_HARDENED_R7_..._20260815`.
- `schema_migrations` hanya memiliki 5 baris baseline (R6, R7, FIX17, FIX18, FIX19). Migrasi SQL 2026 tidak menulis ledger; `api/support/080_schema_alignment.php` punya mekanisme insert sendiri untuk migrasi runtime.
- `release_contract.php:21` memakai patch `V137_P0_SPLIT_BACKFILL_CONSISTENCY_GUARD_R1_FLEX_MAINTENANCE_R2_20260910`; `TAMASYA_BUILD_ID` baris 24 masih `20261008-r15`.
- `api/router.php` memakai daftar require manual berurutan (baris 8-63). `api/domains/autoload_domains.php` adalah compatibility loader; `api/support/020_identity_access_audit.php` tidak ada, sehingga `api/modules/hr_staff/020_identity_access_audit.php` dimuat lewat `MIGRATION_MAP.json:16`. File 105/107 dipetakan ke `growth_enterprise_locked` (`MIGRATION_MAP.json:36,38`), jadi bukan otomatis dead code.

## 3. Temuan dan prioritas

### Prioritas 0 — harus selesai sebelum full RBAC

1. **Dua model otorisasi berjalan bersamaan.** `requireRoles` dan capability sama-sama menjadi keputusan akhir. Tidak ada cara aman untuk mengetahui apakah suatu endpoint mengikuti role, capability, atau tab tanpa membaca kodenya.
2. **Aturan keamanan tersebar di route.** `112`, `090`, `110`, `040`, `080`, `065`, `050` memuat kombinasi role, tab, capability, dan aturan relasi. Perubahan satu role bisa membuka atau menutup akses di banyak tempat tanpa terdeteksi.
3. **Override per akun bisa usang.** `role-preset-apply` menulis override penuh ke `staff.permissions` (`api/routes/052_role_presets.php:142-165`). Akun dengan override lama tidak otomatis mengikuti paket baru; capability baru bisa jatuh ke fallback role, sehingga semantiknya campuran.
4. **UI adalah sumber keputusan kedua.** `app-core.js`/`navigation-registry.js`/`staff.js` dapat menampilkan akses yang tidak sama dengan server. Ini bukan sekadar kosmetik; ini menyulitkan audit dan menimbulkan salah paham operator.

### Prioritas 1 — sumber kebenaran dan maintainability

1. **Registry role tidak kanonik.** 9 role ada di 021, app-core, navigation registry, staff.js, Telegram label, dan seed database. Tidak ada tabel `roles`.
2. **Tidak ada halaman/endpoint pengelolaan role.** Route 052 mengelola jabatan dan preset, bukan role/capability. Menambah role atau capability masih memerlukan perubahan kode + seed.
3. **Job title dan role tercampur.** `staff_job_titles.base_role` tidak bisa diubah setelah dibuat; jabatan sistem tidak bisa di-rename/dihapus dari UI. Ini membatasi fleksibilitas, tetapi juga mencegah orphan.
4. **`daily_summary` sudah terdaftar (Sprint A #1).** Registry, UI, seed, migrasi, dan test sudah selaras. Bundel import final 12 migrasi sudah diuji pada DB simulasi (lama dan R19); sisa pekerjaan hanya mengimpor bundel itu ke DB produksi setelah backup.
5. **Role literal besar.** 1.764 kemunculan di 49 file PHP dan 542 di 42 file JS. Sebagian label, tetapi tetap menyulitkan pencarian dan refactor.

### Prioritas 2 — integritas skema dan rilis

1. Tidak ada FK dari `staff.role`, `staff_job_titles.base_role`, atau `role_capability_presets.role` ke registry role.
2. `permissions` masih `text` JSON, bukan kolom terstruktur; pernah terjadi double-encoding dan sekarang ada fungsi repair/normalisasi.
3. Ledger migrasi tidak mencerminkan seluruh migrasi SQL 2026; metadata rilis (`schema_release_state`, `TAMASYA_PATCH_LEVEL`, `TAMASYA_BUILD_ID`) tidak konsisten dengan tanggal R19.
4. Router dan compatibility loader memakai daftar/peta manual; ini bukan blocker, tetapi menambah titik yang harus diperiksa saat refactor.

## 4. Target desain RBAC

### 4.1 Prinsip

- Server adalah satu-satunya sumber keputusan izin.
- Default deny; akses diberikan oleh capability, bukan nama role.
- Role adalah paket capability; job title adalah metadata HR, bukan penentu akses.
- Owner adalah singleton read-only, bukan role biasa yang bisa diedit.
- Aturan kontekstual (aktor-target, finance separation, night audit, approval) menjadi policy function bernama, bukan array role di route.
- Semua keputusan dapat diuji dengan matriks aktor × aksi × resource.

### 4.2 Model data yang disarankan

```text
roles
  id, code (unique), name, is_system, is_owner, is_active, version

capabilities
  code (PK), category, description, risk_level, is_active

role_capabilities
  role_id FK roles.id, capability_code FK capabilities.code,
  allowed, scope_json, PRIMARY KEY(role_id, capability_code)

staff
  role_id FK roles.id, role (legacy mirror selama transisi), ...
  job_title_id FK staff_job_titles.id (opsional)

staff_capability_overrides
  staff_id FK staff.id, capability_code FK capabilities.code,
  allowed, scope_json, reason, updated_by, updated_at
```

Catatan migrasi:

- `staff.role` dan `staff.permissions` tetap dipertahankan sebagai compatibility mirror sampai cutover selesai.
- `staff_job_titles.base_role` diganti `base_role_id` setelah backfill; label tetap untuk tampilan.
- `role_capability_presets.permissions` dinormalisasi ke `role_capabilities`; JSON hanya dipakai sebagai export/backup.
- Tambahkan `roles` seed untuk 9 role lama + `owner` sebagai `is_owner=1`, bukan baris yang bisa dihapus.

### 4.3 Runtime policy

Satu pintu:

```php
authorize($staff, 'sell_room_access', [
    'scope' => 'property',
    'resource' => $booking,
    'action' => 'book',
]);
```

`route_policy.php` menyimpan peta eksplisit:

```php
'hotel-data' => ['capability' => 'view_rooms', 'scope' => 'property'],
'booking-create' => ['capability' => 'sell_room_access', 'scope' => 'property'],
'role-preset-apply' => ['capability' => 'manage_roles', 'owner' => 'deny'],
```

Yang perlu dipertahankan sebagai policy khusus:

- owner read-only;
- manager tidak boleh mencabut sesi Admin/Manager lain;
- finance tidak boleh kehilangan akses night audit karena memegang shift kas malam;
- approval sensitif dan pemisahan tugas finance;
- night audit toggle tetap admin-only sesuai kontrak R16.4;
- route `view_*` tetap dapat dibaca owner.

### 4.4 Session dan API

Endpoint login/session mengembalikan manifest lengkap dari server:

```json
{
  "role": "manager",
  "capabilities": ["view_rooms", "sell_room_access"],
  "tabs": ["dashboard", "rooms"],
  "notifications": ["bookings"],
  "readOnly": false,
  "policyVersion": "..."
}
```

UI hanya membaca manifest ini. Tidak ada `PK`/`CA`/`ROLE_OPTIONS`/`roles` array di frontend.

### 4.5 Frontend

- Hapus role map dari `app-core.js`, `navigation-registry.js`, `staff.js`, `guest-center.js`, `finance-category-usage-picker.js`, dan addon lain.
- Ganti dengan capability/tab dari session manifest.
- Label role/jabatan diambil dari API, bukan string statis.
- Chunk dan `app-core.js` saat ini tidak memiliki build pipeline; perubahan harus memperhitungkan versi query, checksum, dan test performa. Jangan mengubah `app-core.js` pada fase compatibility tanpa rencana cutover.

### 4.6 Migrasi dan rilis

- Satu ledger migrasi untuk SQL dan runtime; setiap migrasi menulis `schema_migrations`.
- `schema_release_state` dan `TAMASYA_PATCH_LEVEL`/`TAMASYA_BUILD_ID` harus konsisten dengan rilis.
- Tambahkan FK setelah backfill dan setelah data invalid dibersihkan.
- Simpan backup dan rollback script per fase.

## 5. Rencana migrasi bertahap

| Fase | Fokus | Output | Gate |
|---|---|---|---|
| 0 | Inventaris dan kontrak | Daftar semua action/route, matriks role × capability, snapshot perilaku | Tidak ada perubahan perilaku; test matrix disetujui |
| 1 | Schema expand | Tabel roles/capabilities/role_capabilities/overrides, backfill 9 role + 23 capability | Dual-read legacy + baru; data lama valid |
| 2 | Policy layer | `authorize()`, `route_policy.php`, shadow mode | Log perbedaan allow/deny; tidak ada eskalasi |
| 3 | Konversi backend | Ganti `requireRoles` per domain, mulai 112/090/110/040/080/065/050 | Test domain + matriks akses hijau |
| 4 | Konversi sisa | 020/030/060/075/095/096/100/115 + modul HR/growth | `requireRoles` di route = 0 |
| 5 | Frontend manifest | Session capability, hapus static maps | UI = server; test UI/Playwright |
| 6 | Hardening | FK, hapus kolom legacy, satukan migrasi, hapus alias | Full suite + rollback drill |

Urutan ini sengaja menempatkan frontend di akhir. Membalik urutannya akan membuat UI menjadi sumber kebenaran sementara dan menambah risiko lockout.

## 6. Prioritas kerja konkret

### Sprint A — pengamanan dan inventaris

1. ~~Tambahkan `daily_summary` ke registry atau hapus pemakaiannya.~~ **Selesai:** registry/UI/seed/migrasi/test diperbarui; default `admin/manager/receptionist=true`, role lain `false`, override akun tetap menang, owner tetap `false`.
2. Inventaris semua action di `api/routes` dan `api/support` ke tabel policy.
3. Tambahkan test snapshot untuk 112/090/110/040/080/065/050.
4. Audit ulang aturan owner, finance, night audit, dan manager session.
5. Pastikan tidak ada route GET yang punya side effect sebelum membuka owner read-only.

### Sprint B — schema dan policy

1. Buat tabel role/capability dan backfill dari 9 role + 23 capability.
2. Tambahkan `staff.role_id` dan `staff_capability_overrides`; dual-write.
3. Buat `authorize()` dan `route_policy.php`.
4. Jalankan shadow mode: legacy dan policy baru dihitung, selisih dicatat.
5. Tambahkan CI guard yang menolak `requireRoles`/array role baru di route.

### Sprint C — cutover backend

1. Konversi 112, 090, 110, 040, 080, 065, 050.
2. Konversi 020, 030, 060, 075, 095, 096, 100, 115.
3. Hapus fallback role setelah semua akun punya capability eksplisit.
4. Pertahankan policy khusus sebagai fungsi bernama.

### Sprint D — frontend dan pembersihan

1. Ganti static maps dengan session manifest.
2. Hapus duplikasi label di `staff.js`/Telegram.
3. Normalisasi preset ke `role_capabilities`.
4. Tambahkan FK dan hapus kolom legacy.
5. Konsolidasikan migrasi dan metadata rilis.

## 7. Risiko dan rollback

| Risiko | Mitigasi |
|---|---|
| Lockout admin/owner | Uji matriks role; owner bypass read-only; emergency admin path teruji |
| Eskalasi izin | Shadow mode; audit log; default deny; test negatif |
| Data permission korup | Backup; normalisasi; repair function; validasi JSON |
| UI dan server berbeda | Session manifest; feature flag; test UI |
| Migrasi gagal di tengah | Transaksi; idempoten; backup; rollback script |
| Perubahan app-core merusak cache | Version query; checksum; test performa; cutover bertahap |

Rollback:

- `RBAC_POLICY_MODE=legacy|shadow|enforce`.
- Dual-write `staff.role` + `staff.role_id`, `staff.permissions` + override table.
- Simpan snapshot keputusan akses sebelum cutover.
- Jika terjadi regresi, kembalikan mode ke `legacy` tanpa rollback data.

## 8. Kriteria sukses

- 0 `requireRoles()` di route dan support yang aktif.
- 0 daftar role statis di frontend untuk keputusan akses.
- 100% action memiliki policy eksplisit.
- Semua capability di registry digunakan atau sengaja dihapus.
- Matriks akses lulus untuk 9 role + owner + minimal 2 role kustom.
- Owner read-only, night audit, finance separation, dan manager session tetap sesuai kontrak.
- `./scripts/verify-release.sh` dan 58 suite tetap hijau.
- Tidak ada 403 regresi pada akun tanpa override.

## 9. Keputusan yang diperlukan

1. **Apakah role kustom diizinkan?** Rekomendasi: ya, dengan `roles.is_system` dan `owner` singleton; role kustom tidak boleh menghapus owner.
2. **Apakah job title menjadi role?** Rekomendasi: tidak. Job title tetap metadata HR; akses mengikuti role/capability.
3. **Apakah override per akun dipertahankan?** Rekomendasi: ya, tetapi terstruktur dan diaudit; default dari role, override hanya pengecualian.
4. **Apakah semua role lama dipertahankan sebagai system role?** Rekomendasi: ya, untuk kompatibilitas; role baru ditambahkan setelah policy stabil.
5. **Apakah scope multi-property masuk fase ini?** Rekomendasi: minimal `property_id` di policy, tetapi enforcement penuh setelah RBAC dasar stabil.
6. **Apakah frontend tetap tanpa build pipeline?** Rekomendasi: pertahankan dulu; refactor UI dilakukan dengan chunk manual dan test yang ada.

## 10. Lampiran bukti

| Path | Bukti |
|---|---|
| `api/support/017_authorization_policy.php:78-86` | owner session permissions, semua tab, hanya `view_*` |
| `api/support/017_authorization_policy.php:107-116` | `requireRoles` |
| `api/support/017_authorization_policy.php:134-162` | capability dan desktop tab |
| `api/support/021_role_presets.php:17-35` | 9 role statis dan label |
| `api/support/021_role_presets.php:136-144` | registry 23 capability dan 15 tab |
| `migrations/20261020_DAILY_SUMMARY_CAPABILITY.sql` | migrasi idempoten capability ke-23 (9 role statis; dinamis via backfill baca) |
| `api/routes/052_role_presets.php:18` | guard admin-only |
| `api/routes/052_role_presets.php:142-165` | apply preset menulis `staff.permissions` per akun |
| `api/routes/062_employee_self_service.php:10-12` | approver leave hardcoded |
| `api/routes/090_operations_communications.php:444` | aturan sesi manager |
| `api/routes/090_operations_communications.php:1478` | `daily_summary` terdaftar via Sprint A #1 |
| `api/routes/112_enterprise_completion.php:37-69` | role + tab + capability bercampur |
| `api/routes/110_growth_suite.php:260-265` | flag akses dari role + tab |
| `api/support/070_data_projection_scope.php:846-861,893` | proyeksi finance/notifikasi |
| `api/support/090_security_cluster_smartlock.php:156-159` | finance night audit bypass |
| `database_setup.sql:2181-2212` | `staff` tanpa `role_id`/FK |
| `database_setup.sql:2218-2236` | `role_capability_presets` text JSON |
| `database_setup.sql:2243-2265` | `staff_job_titles` tanpa FK |
| `database_setup.sql:2910-2919` | marker rilis dan 5 migrasi baseline |
| `release_contract.php:12-24` | release/patch/build id |
| `api/router.php:8-63` | daftar route manual |
| `api/domains/MIGRATION_MAP.json:16,36,38` | module path mapping |
| `assets/app-core.js` | `PK`, `CA`, `db`, `_K` statis |
| `assets/navigation-registry.js` | role arrays di module dock |
| `assets/chunks/staff.js:3` | impor simbol role dari app-core |
| `assets/guest-center.js:6-8` | role sets statis |
| `assets/finance-category-usage-picker.js:2` | `ROLE_OPTIONS` statis |
| `scripts/verify-release.sh` | checksum, syntax, 58 suite |
| `AUDIT-KAPABILITAS-ROLE-2026-10-19.md` | audit baseline sebelumnya |

## 11. Implementasi Dynamic RBAC (2026-10-21)

- `api/support/022_dynamic_rbac.php` menjadi satu-satunya pembaca registry role,
  capability, tab, notifikasi, preset role, dan access group dari DB. Semua
  lookup memakai cache per-request dan gagal-tertutup; tanpa PDO hasilnya
  `null`/kosong sehingga call site memakai fallback statis.
- `021_role_presets.php` memuat `022` dan menjadikan lima registry dinamis
  (role, jabatan, capability, tab, notifikasi) dengan fallback kompatibel.
- `017_authorization_policy.php`: `requireRoles()` memakai
  `rbac_legacy_role_sets` → `rbac_access_groups`; `hasCapability()` dan
  `hasDesktopTabAccess()` membaca preset role DB sebelum fallback;
  `tamasyaSessionPermissions()` menerima PDO dan mengembalikan permission
  efektif non-owner.
- `052_role_presets.php` menambah command `rbac-catalog-list`,
  `rbac-catalog-save`, `rbac-catalog-delete`, `rbac-access-group-save`, dan
  `rbac-access-group-delete` (admin-only, audit, revision bump, transaksi).
- `assets/chunks/staff.js` mengambil label katalog dari respons
  `role-preset-list`; grid izin per-akun (15 tab, 23 capability, 6 notifikasi)
  sekarang dirender dari `rpTabLabels`/`rpCapLabels`/`rpNotifLabels`, bukan
  blok checkbox hardcoded. Lock `Uo(role,key)`, lock website
  `manage_public_website`, dan default `savings !== false` dipertahankan.
- `assets/app-core.js` `_K` mengonsumsi `desktopTabs` efektif lebih dulu lalu
  fallback role; `app-shell.js` memakai hasil `_K` untuk menu dan
  `window.TAMASYA_NAVIGATION`. Ini berlaku untuk 15 tab yang punya
  komponen/route React. `assets/navigation-registry.js` tetap mendefinisikan
  domain/label statis; tab arbitrary baru tidak otomatis punya halaman.
- Migrasi `20261021_DYNAMIC_RBAC.sql` idempoten: 10 role, 23 capability,
  15 tab, 6 notifikasi, 14 access group, 48 group-role, 14 legacy role set.
  Diuji pada DB kosong hasil `database_setup.sql` dan pada `tamasya_test`.
- Regresi: `tests/dynamic-rbac-regression.php` (58 cek, tanpa DB/server),
  `role-preset` (67), `owner-readonly` (38); total gate rilis 58 suite.
  Uji UI Playwright pada DB dev: grid 44 checkbox, label `Kamar & Sewa`
  berasal dari katalog DB, dan lock website bereaksi saat capability
  `manage_public_website` dimatikan/dipulihkan.

## 12. Catatan metodologi

- Pemindaian menggunakan `grep`/Python dan PHP tokenizer karena `rg` tidak tersedia di host ini.
- Angka `requireRoles` dihitung dari token `T_STRING` diikuti `(`, bukan komentar atau definisi fungsi.
- Angka capability dihitung dari pemanggilan `hasCapability`/`requireCapability` dengan nama literal; pemanggilan dengan variabel tidak masuk hitungan.
- Angka literal role mencakup label, seed, pesan, dan data; bukan semuanya keputusan akses.
- Audit ini belum menjalankan migrasi pada DB produksi atau uji beban baru; bundel import final sudah diuji pada DB simulasi lokal.
