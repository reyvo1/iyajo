#!/usr/bin/env python3
"""Fail closed for missing mandatory evidence; never convert WARNING into PASS.
Source gate is not a production readiness gate: verify log-derived risks separately.
"""
import json, pathlib, sys
r=pathlib.Path(__file__).resolve().parents[1]
m=json.loads((r/'tests/uat-r164-required-commands.json').read_text())
w=(r/'.github/workflows/tamasya-enterprise-rc1-uat.yml').read_text()
if len(m['required'])!=54 or len(set(m['required']))!=54:
    sys.exit('FAIL historical UAT suite cardinality changed')
for cmd in m['required']:
    if cmd not in w:
        sys.exit('FAIL missing historical UAT command: '+cmd)
for cmd in ['php tests/durable-history-retention-regression.php', 'php tests/receipt-compaction-cursor-regression.php', 'node tests/uat-command-preflight.mjs']:
    if cmd not in w:
        sys.exit('FAIL missing mandatory P1 source regression: '+cmd)
for file in ['tests/telegram-update-retention-regression.php','tests/r164-critical-path-contracts.mjs','tests/r164-release-evidence-gate.py','tests/r164-canonical-key-issue-regression.php','tests/r164-big-milestone-storage-safety-regression.php','tests/r164-room-access-return-regression.php']:
    if not (r/file).is_file():sys.exit('FAIL missing P1/P2 regression: '+file)
    runner=('php ' if file.endswith('.php') else 'node ' if file.endswith('.mjs') else 'python3 ')+file
    if w.count(runner) != 1:
        sys.exit('FAIL regression not executed: '+file)
if w.count('ref: ${{ github.sha }}') != 4 or w.count('test "$(git rev-parse HEAD)" = "$GITHUB_SHA"') != 4:
    sys.exit('FAIL GitHub workflow checkout provenance is not pinned and asserted for every job')
print('PASS 54 preserved, P1/P2 regressions, canonical key issuance and 4 checkout SHA gates; tax/real Telegram/physical Hybrid remain separate release signoff')
