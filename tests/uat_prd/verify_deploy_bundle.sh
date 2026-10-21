#!/usr/bin/env bash
# Rehearsal paket deploy: memverifikasi ZIP yang benar-benar dikirim ke server,
# bukan isi repo. Ini bukti pra-produksi yang bisa dijalankan tanpa VPS.
#
# Pemeriksaan:
#   1. ZIP ada dan bukan file kosong.
#   2. Isi paket cocok dengan manifest checksum (tidak ada berkas berubah).
#   3. Berkas wajib fitur baru ada di dalam paket.
#   4. Tidak ada rahasia (env, kredensial) atau berkas uji di dalam paket.
#   5. Seluruh PHP yang dikirim bisa diurai, dan JS-nya sintaksnya valid.
#
# Paket: node scripts/build-import-sql.mjs && ./create-production-zip.sh
# Uji   : bash tests/uat_prd/verify_deploy_bundle.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
ZIP="${1:-tamasya-production-20261021-r15.zip}"
[[ -f "$ZIP" ]] || { echo "FAIL paket deploy tidak ditemukan: $ZIP"; exit 1; }
[[ -s "$ZIP" ]] || { echo "FAIL paket deploy kosong: $ZIP"; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK"
APP="$(find "$WORK" -maxdepth 1 -mindepth 1 -type d | head -1)"
[[ -d "$APP" ]] || { echo "FAIL paket tidak berisi folder aplikasi"; exit 1; }

# 1. Manifest checksum.
[[ -f "$APP/DEPLOY-SHA256SUMS.txt" ]] || { echo "FAIL paket tidak membawa manifest checksum"; exit 1; }
( cd "$APP" && sha256sum -c DEPLOY-SHA256SUMS.txt >/dev/null ) || { echo "FAIL isi paket tidak cocok dengan manifest checksum"; exit 1; }
echo "PASS isi paket cocok dengan manifest checksum"

# 2. Berkas wajib fitur baru.
for rel in api/routes/013_audit_logs.php api/routes/014_permission_inspector.php \
  api/routes/086_system_health.php api/routes/087_guest_reviews.php api/routes/088_wholesale_rates.php \
  api/routes/089_exchange_rates.php api/modules/finance/031_wholesale_contract_rates.php \
  hq/schema.sql hq/core.php hq/app.js webpublic/review.html assets/wholesale-contract-addon.js \
  migrations/20261021_WHOLESALE_RATES.sql migrations/20261021_WHOLESALE_RATE_LOCK.sql \
  migrations/20261021_TRANSACTION_PROPERTY_AMOUNT.sql deploy/IMPORT-RBAC-PHPMYADMIN.sql \
  database_setup.sql; do
  [[ -f "$APP/$rel" ]] || { echo "FAIL berkas wajib tidak ada di paket: $rel"; exit 1; }
done
echo "PASS seluruh berkas wajib fitur baru ada di paket"

# 3. Tidak ada rahasia atau berkas uji.
if find "$APP" \( -name '.env' -o -name '.env.*' -o -name 'db_credentials.php' \) -print -quit | grep -q .; then
  echo "FAIL paket memuat berkas rahasia"; exit 1
fi
if [[ -d "$APP/tests" ]]; then echo "FAIL paket memuat folder tests"; exit 1; fi
echo "PASS paket tidak memuat rahasia atau berkas uji"

# 4. PHP yang dikirim bisa diurai.
php_failed=0
while IFS= read -r file; do
  php -l "$file" >/dev/null 2>&1 || { echo "FAIL sintaks PHP: ${file#$APP/}"; php_failed=1; }
done < <(find "$APP" -name '*.php' -not -path '*/vendor/*')
[[ "$php_failed" -eq 0 ]] || exit 1
php_count=$(find "$APP" -name '*.php' -not -path '*/vendor/*' | wc -l | tr -d ' ')
echo "PASS ${php_count} berkas PHP valid"

# 5. JS yang dikirim sintaksnya valid.
js_failed=0
while IFS= read -r file; do
  node --check "$file" >/dev/null 2>&1 || { echo "FAIL sintaks JS: ${file#$APP/}"; js_failed=1; }
done < <(find "$APP" -name '*.js' -not -path '*/node_modules/*' -not -path '*/vendor/*')
[[ "$js_failed" -eq 0 ]] || exit 1
js_count=$(find "$APP" -name '*.js' -not -path '*/node_modules/*' -not -path '*/vendor/*' | wc -l | tr -d ' ')
echo "PASS ${js_count} berkas JS valid"

echo "PASS rehearsal paket deploy: manifest, berkas wajib, tanpa rahasia, PHP dan JS valid"
sha256sum "$ZIP" | awk '{print "INFO paket_sha256=" $1}'
