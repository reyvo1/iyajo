#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT="${TAMASYA_MOCK_PORT:-19238}"
LOG="$(mktemp)"
export TAMASYA_TEST_LOG_FILE="$LOG" TELEGRAM_API_BASE_URL="http://127.0.0.1:$PORT"
php -S "127.0.0.1:$PORT" "$HERE/fixtures/telegram-format-mock.php" >"${LOG}.server" 2>&1 &
PID=$!
cleanup() { kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; rm -f "$LOG" "${LOG}.server"; }
trap cleanup EXIT
for i in $(seq 1 35); do if curl -fsS --max-time 1 "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then break; fi; sleep .1; done
php "$HERE/telegram-markdown-regression.php"
