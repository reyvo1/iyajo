#!/usr/bin/env bash
# Push to GitHub only if local verification passes
# Repo: https://github.com/reyvo1/iyajo

set -euo pipefail

REPO_URL="https://github.com/reyvo1/iyajo.git"
TASK_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$TASK_ROOT"

echo "=== Local verification ==="
if ! bash scripts/verify-release.sh; then
    echo "❌ Local verification FAILED — aborting push"
    exit 1
fi
echo "✅ All 41 regression suites PASSED"

echo "=== Git setup ==="
if [ ! -d .git ]; then
    git init
    git branch -M main
fi

if ! git remote get-url origin >/dev/null 2>&1; then
    git remote add origin "$REPO_URL"
else
    git remote set-url origin "$REPO_URL"
fi

echo "=== Commit & push ==="
git add .
git commit -m "R164 custody cleanup + fix revenue recognition logic + fix room access issue guard" || true
git push -u origin main

echo "=== Done ==="
echo "Next: Open GitHub Actions → Run 'TAMASYA Enterprise Full Complete UAT' workflow_dispatch"