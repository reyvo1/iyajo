#!/usr/bin/env bash
# Create clean production zip for deployment
# Run from repo root: ./create-production-zip.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

# The release token must come from the single source of truth. A grep for
# "TAMASYA_BUILD_ID <value>" never matched the real definition
# (define('TAMASYA_BUILD_ID', '<value>')), so the zip silently fell back to a
# timestamp and shipped without the release version in its name.
VERSION=$(php -r 'require "release_contract.php"; echo TAMASYA_BUILD_ID;' 2>/dev/null | tr -d "'\"" | grep -E '^[A-Za-z0-9._-]+$' || echo "prod-$(date +%Y%m%d-%H%M)")
ZIP_NAME="tamasya-production-${VERSION}.zip"
STAGING_DIR="/tmp/tamasya-production-${VERSION}"

echo "=== Creating production zip: $ZIP_NAME ==="

# Clean previous
rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"

# Use git to get all tracked files (excludes .gitignore files automatically)
echo "Copying tracked files from git..."
git ls-files | while IFS= read -r file; do
    mkdir -p "$STAGING_DIR/$(dirname "$file")"
    cp "$file" "$STAGING_DIR/$file"
done

# Also copy untracked but needed files (migrations, deploy, etc.)
# migrations are tracked in git ls-files, but let's make sure
cp -r migrations "$STAGING_DIR/" 2>/dev/null || true
cp -r deploy "$STAGING_DIR/" 2>/dev/null || true
cp -r scripts "$STAGING_DIR/" 2>/dev/null || true

# Remove ONLY dev/test files from staging
echo "Removing dev/test files..."

# Remove tests folder entirely
rm -rf "$STAGING_DIR/tests"

# Remove .github workflows
rm -rf "$STAGING_DIR/.github"

# Remove documentation/audit files (keep only essential)
rm -f "$STAGING_DIR"/*.md
rm -f "$STAGING_DIR"/*.txt
rm -f "$STAGING_DIR"/AUDIT-*
rm -f "$STAGING_DIR"/HANDOFF-*
rm -f "$STAGING_DIR"/FINAL-*
rm -f "$STAGING_DIR"/GROWTH-*
rm -f "$STAGING_DIR"/OWNER-*
rm -f "$STAGING_DIR"/MODULE-*
rm -f "$STAGING_DIR"/MULTI-*
rm -f "$STAGING_DIR"/PRODUCTION-*
rm -f "$STAGING_DIR"/REPORT-*
rm -f "$STAGING_DIR"/TELEGRAM-*
rm -f "$STAGING_DIR"/UI-*
rm -f "$STAGING_DIR"/CANONICAL-*
rm -f "$STAGING_DIR"/CONSISTENCY-*
rm -f "$STAGING_DIR"/ENTERPRISE-*
rm -f "$STAGING_DIR"/FINANCE-*
rm -f "$STAGING_DIR"/R14_*
rm -f "$STAGING_DIR"/R4_*
rm -f "$STAGING_DIR"/RC1_*
rm -f "$STAGING_DIR"/HYBRID-*
rm -f "$STAGING_DIR"/POST-*
rm -f "$STAGING_DIR"/TAMASYA-*
rm -f "$STAGING_DIR"/TESTAMASYA-*
rm -f "$STAGING_DIR"/APPLY-*
rm -f "$STAGING_DIR"/MIGRATION-*
rm -f "$STAGING_DIR"/R16_*
# Arsip dokumentasi lama tidak perlu ikut paket rilis.
# Arsip dokumentasi lama tidak perlu ikut paket rilis. Dilakukan setelah
# seluruh berkas disalin, karena docs/ ikut terbawa dari git ls-files.
rm -rf "$STAGING_DIR"/docs/archive 2>/dev/null || true
rm -rf "$STAGING_DIR"/docs/*.bak 2>/dev/null || true

# Remove example env files (keep .env.example if needed for reference)
rm -f "$STAGING_DIR"/.env.*.example

# Remove checksum files
rm -f "$STAGING_DIR"/FILE_CHECKSUMS.sha256
rm -f "$STAGING_DIR"/*.sha256

# Remove package files
rm -f "$STAGING_DIR"/package*.json
rm -f "$STAGING_DIR"/playwright.config.mjs

# Remove test files from api (but keep the actual modules!). The pattern is anchored to
# a leading "test" so a future production file such as "latest-rooms.php" is never
# silently deleted by a loose glob.
find "$STAGING_DIR/api" -type f \( -name "test-*" -o -name "test_*" -o -name "*_test.php" -o -name "Test*.php" \) -delete 2>/dev/null || true

# Remove .mjs test files. Never use a recursive delete here: services/realtime/server.mjs
# and webpublic/scripts/apply-production-config.mjs are production files and a blanket
# `find -name "*.mjs" -delete` was silently removing the realtime service from the bundle.
find "$STAGING_DIR/tests" -name "*.mjs" -delete 2>/dev/null || true
rm -f "$STAGING_DIR"/playwright.config.mjs 2>/dev/null || true

# Remove any .py test files
find "$STAGING_DIR/tests" -name "*.py" -delete 2>/dev/null || true

# Never ship environment files. .env holds live production credentials and the target
# server already has its own; staging secrets must not travel inside the bundle.
rm -f "$STAGING_DIR/.env" "$STAGING_DIR/.env.*" 2>/dev/null || true
find "$STAGING_DIR" -maxdepth 2 -name ".env" -o -maxdepth 2 -name ".env.*" 2>/dev/null | while IFS= read -r leftover; do rm -f "$leftover"; done

# Panduan di dalam paket tidak boleh menyebut sha256 paket itu sendiri: nilainya
# berubah begitu panduannya ikut berubah. Baris itu diganti dengan penunjuk ke
# manifest isi paket.

# Manifest isi paket. Operator memakai berkas ini untuk memverifikasi bahwa
# hasil ekstraksi di server sama persis dengan paket yang dikirim, sebelum
# aplikasi dijalankan.
echo "Writing deploy manifest..."

# Panduan di dalam paket tidak boleh menyebut sha256 paket itu sendiri: nilainya
# berubah begitu panduannya ikut berubah. Yang ditulisInstead penunjuk ke
# manifest, dan jumlah berkas dihitung dari isi paket yang nyata. Pola heutama
# hanya menyasar baris tabel sha256 dan baris jumlah berkas, bukan seluruh tabel.
BUNDLE_FILE_COUNT="$(find "$STAGING_DIR" -type f ! -name "DEPLOY-SHA256SUMS.txt" | wc -l | tr -d ' ')"
if [ -f "$STAGING_DIR/deploy/DEPLOY-ID.md" ]; then
  awk -v count="$BUNDLE_FILE_COUNT" '
    /^\| SHA-256 paket \|/ { print "| SHA-256 paket | Lihat `DEPLOY-SHA256SUMS.txt` (sha256 tiap berkas) dan catatan pengiriman |"; next }
    /^\| Isi \| [0-9]+ berkas/ { print "| Isi | " count " berkas + `DEPLOY-SHA256SUMS.txt` |"; next }
    { print }
  ' "$STAGING_DIR/deploy/DEPLOY-ID.md" > "$STAGING_DIR/deploy/DEPLOY-ID.md.tmp" && mv "$STAGING_DIR/deploy/DEPLOY-ID.md.tmp" "$STAGING_DIR/deploy/DEPLOY-ID.md"
fi

( cd "$STAGING_DIR" && find . -type f ! -name "DEPLOY-SHA256SUMS.txt" -printf "%P\0" | sort -z | xargs -0 sha256sum > DEPLOY-SHA256SUMS.txt )

# Create zip
echo "Creating zip..."
cd /tmp
# Arsip lama harus dihapus dulu: zip -r memperbarui arsip yang ada, jadi
# berkas yang sudah tidak lagi ikut paket akan tetap tertinggal di dalamnya.
rm -f "$REPO_ROOT/$ZIP_NAME"
zip -r "$REPO_ROOT/$ZIP_NAME" "tamasya-production-${VERSION}" -x "*/.git/*" "*/node_modules/*" "*/.DS_Store" "*/Thumbs.db" > /dev/null

# Cleanup
rm -rf "$STAGING_DIR"

# Verify
echo ""
echo "=== Production Zip Ready ==="
echo "File: $REPO_ROOT/$ZIP_NAME"
echo "Size: $(du -h "$REPO_ROOT/$ZIP_NAME" | cut -f1)"
echo ""
echo "File count in zip:"
unzip -l "$REPO_ROOT/$ZIP_NAME" | tail -3
echo ""
echo "Deploy to production:"
echo "  1. Upload $ZIP_NAME to server"
echo "  2. Extract to new directory"
echo "  3. Run: php first_install.php (fresh DB) or php scripts/activate-growth-enterprise.php --apply (existing DB)"
echo "  4. Configure .env with production credentials"
echo "  5. Set APP_TIMEZONE, APP_ENCRYPTION_KEY, etc."