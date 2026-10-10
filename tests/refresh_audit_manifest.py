from pathlib import Path
import hashlib
root=Path(__file__).resolve().parents[1]
manifest=root/'FILE_CHECKSUMS.sha256'
entries={}
for line in manifest.read_text().splitlines():
    if len(line)>66 and line[64:66]=='  ':
        entries[line[66:]]=line[:64]
new=[
'api/routes/091_receipt_storage_health.php','api/support/077_receipt_storage_health.php',
'tests/receipt-storage-health-regression.php','tests/telegram-key-status-contract-regression.php','tests/refresh_audit_manifest.py',
]
for name in new:
    if not (root/name).is_file(): raise SystemExit('missing '+name)
    entries[name]=''
for name in entries:
    source=root/name
    if not source.is_file(): raise SystemExit('listed file absent '+name)
    entries[name]=hashlib.sha256(source.read_bytes()).hexdigest()
manifest.write_text(''.join(f'{digest}  {name}\n' for name,digest in sorted(entries.items())))
print(f'MANIFEST_ENTRIES={len(entries)}')
