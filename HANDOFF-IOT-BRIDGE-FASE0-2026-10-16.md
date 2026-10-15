# HANDOFF IOT-BRIDGE FASE 0 — Tamasya × IoT Platform

Tanggal: 2026-10-16. Status: RANCANGAN siap eksekusi, belum ada kode.
Repo hotel: `/home/ivo/Desktop/testamasya-main` (remote `reyvo1/iyajo`, branch `main`).
Repo IoT: `/home/ivo/Desktop/program/iot` (remote `reyvo1/iot-platform`, branch `main`).
Prinsip (dari `docs/ROADMAP-SMART-HOTEL.md` repo IoT): integrasi lewat
API/queue; kedua sistem TIDAK berbagi database. Satu fase = satu deliverable
yang bisa didemokan. Hardware terakhir, software dulu.

## 1. Tujuan Fase 0 (2–3 minggu, TANPA hardware)

Pipa event nyala dua arah memakai simulator:
Tamasya check-in/out → event tercatat di dashboard IoT;
IoT → ACK/status balik ke audit Tamasya.
Definisi selesai: check-in dummy di Tamasya muncul sebagai event di IoT
dashboard, dan status perangkat simulator terlihat di sisi hotel.
Hotel non-IoT tetap aman (mode sandbox = log saja).

## 2. Kontrak endpoint (sisi IoT, modul baru `hotel-bridge`)

- `POST /bridge/hotel/events` — terima SATU event per request.
  Auth: API-key per properti hotel (header `X-Hotel-Key`) + HMAC-SHA256
  signature (header `X-Hotel-Signature`, timestamp `X-Hotel-Timestamp`,
  toleransi ±300 detik, anti-replay via `event_id` unik).
  Body (JSON):
  `{event_id, property_id, type, occurred_at, booking_id, room_number,
    guest_name?, check_in?, check_out?, actor, source(web|telegram), meta?}`
  `type` Fase 0: `check_in | check_out | extend | room_change | cancel`.
  Respons: `{received:true, event_id}` untuk duplikat juga 200 + flag
  `duplicate:true` (idempoten, tidak proses ulang).
- Tabel baru `bridge_events(event_id PK, property_id, type, payload_json,
  status, created_at)` — duplikat `event_id` = ACK tanpa proses ulang.
- Ikuti pola modul `src/webhook/` repo IoT: SATU implementasi fungsi
  signing (jangan tulis HMAC kedua), secret tidak pernah keluar di respons
  list (lihat `webhook.controller.ts:194`).
- Kirim command ke perangkat via `MqttService.publish(topic, message)`
  (`src/mqtt/mqtt.service.ts:35`) — Fase 0 cukup ke simulator.

## 3. Konvensi topik MQTT Fase 0 (Mosquitto, sandbox lokal)

- Command: `hotel/{property_id}/room/{number}/cmd` —
  payload `{cmd:"activate"|"deactivate", booking_id, event_id, ts}`.
- Status: `hotel/{property_id}/room/{number}/status` —
  payload `{state:"active"|"idle", event_id?, ts}`.
- Simulator: skrip Node subscribe `hotel/+/room/+/cmd`, log, balas status.
  (Acuan pola: `SIMULATED_HARDWARE_LIVE_SERVICE` — simulasi tidak pernah
  dilabeli bukti fisik.)

## 4. Sisi Tamasya (kode BARU, kecil, fail-closed)

- File baru mis. `api/support/0XX_hotel_bridge.php`: class/fungsi
  `HotelBridgeClient::sendEvent($pdo,$event)` — baca URL+key+secret dari
  `config`/`ENV`; **kalau URL kosong → tulis log saja, return ok**
  (hotel non-IoT tidak error, tidak blokir check-in).
- Hook (POST-COMMIT saja, jangan di dalam transaksi booking):
  - check-in/check-out: `api/routes/020_hotel_booking.php` aksi
    `bookings-status` (setelah commit sukses).
  - extend/pindah: alur PUT `bookings` / `room-transfers` (setelah commit).
  - Telegram: `finalizeTelegramShiftReport` TIDAK disentuh; hook di
    `tamasyaIssueRoomAccess` (`api/support/091_room_access_issue.php`)
    untuk penerbitan kunci saja bila diperlukan Fase 1+.
- `event_id` deterministik: `tms_{booking_id}_{type}_{occurred_at}` agar
  retry aman. Kirim async best-effort (curl timeout 3 detik); gagal kirim =
  log + tandai, BUKAN gagalkan check-in tamu.
- Kunci enkripsi/secret bridge disimpan via `config` terenkripsi
  (`enc:v1:`) seperti `telegram_bot_token`, tidak di-hardcode.

## 5. Kriteria terima (wajib hijau sebelum Fase 1)

1. Check-in dummy → baris `bridge_events` + tampil di dashboard IoT.
2. Kirim event sama 2x → respons `duplicate:true`, tidak ada efek ganda.
3. Signature salah / timestamp basi → 401/409, tidak tersimpan.
4. URL bridge kosong → check-in tetap sukses + ada baris log.
5. Broker MQTT mati → check-in tetap sukses; event ditandai belum terkirim
   (antrean; replay manual Fase 0, otomatis Fase 1).
6. Test baru dua sisi: PHP `tests/hotel-bridge-regression.php`
   (idempotency + sandbox) dan Jest sisi IoT; daftarkan di CI +
   `FILE_CHECKSUMS.sha256` (aturan repo hotel: file baru wajib masuk
   checksum alfabetis atau gate merah).

## 6. Bukan bagian Fase 0 (jangan dikerjakan dulu)

Kunci fisik/RFID, card encoder, energy saver, sensor, kamera wajah, PIN
tamu otomatis, aplikasi tamu, billing bridge, writeback IoT→booking.
Kebijakan privasi data wajah + anti-spoofing + fallback offline wajib
diputuskan SEBELUM fase kamera (lihat konsultasi 2026-10-16).

## 7. Perintah baku repo hotel (agen baru WAJIB ikut)

`php -l` → test terkait → update `FILE_CHECKSUMS.sha256` → commit →
`git push origin main` → tunggu CI `TAMASYA Enterprise Full Complete UAT`
hijau (cek `gh run list/view`; kalau merah ambil `--log-failed`) →
`./create-production-zip.sh` → serahkan ZIP (tidak ikut push).
Repo IoT: JANGAN push/deploy/cloud-rerun tanpa instruksi eksplisit pemilik
(kebijakan `CURRENT-STATE.md`: LOCAL WORK ONLY sampai ada perintah baru).

## 8. Referensi silang

- Hotel: `HANDOFF-SAAS-FINAL-2026-10-16.md` (konteks bisnis + harga),
  `api/support/091_room_access_issue.php` (penerbitan kunci + bridge job),
  `api/support/060_shift_receipts.php` (pola idempotency `operationId`).
- IoT: `docs/ROADMAP-SMART-HOTEL.md` (Fase 1–5), `docs/KOMERCIAL-RINGKAS.md`,
  `src/mqtt/mqtt.service.ts`, `src/webhook/`, `src/public-api/`,
  `src/camera/` (ONVIF, untuk fase kamera nanti).
