#!/usr/bin/env python3
"""Adversarial tenant isolation skeleton. Requires two running property instances."""
import json, os, urllib.request, urllib.error
from pathlib import Path

BASE = Path(__file__).resolve().parent
LOG = BASE / "logs" / "tenant-isolation-results.json"
P1 = os.getenv("EFC_HQ_PROPERTY1_URL", "http://127.0.0.1:38190").rstrip("/")
P2 = os.getenv("EFC_HQ_PROPERTY2_URL", "http://127.0.0.1:38191").rstrip("/")
ADMIN = os.getenv("APP_BOOTSTRAP_ADMIN_USERNAME", "admin")
PASSWORD = os.getenv("APP_BOOTSTRAP_ADMIN_PASSWORD", "Tamasya-UAT-RC1-Only!")
results = []

def save():
    LOG.parent.mkdir(parents=True, exist_ok=True)
    LOG.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")

def check(name, ok, detail=None):
    results.append({"test": name, "pass": bool(ok), "details": detail})
    print("PASS" if ok else "FAIL", name, str(detail or "")[:500], flush=True)
    save()

def login(base, device):
    import urllib.request, json
    body = json.dumps({"username": ADMIN, "password": PASSWORD, "offlineSessionScopeId": "offline_" + device}).encode()
    req = urllib.request.Request(base + "/api.php?action=login", data=body, headers={"Content-Type": "application/json", "X-Device-ID": device}, method="POST")
    try:
        resp = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as e:
        resp = e
    data = json.loads(resp.read().decode())
    if resp.status != 200 or not data.get("token"):
        raise RuntimeError(f"login failed: {resp.status} {data}")
    return data["token"]

try:
    t1 = login(P1, "tenantiso-p1")
    t2 = login(P2, "tenantiso-p2")
    # Cross-property hotel-data must not expose the other property's data.
    for label, base, foreign_token in [("P1 token on P2", P2, t1), ("P2 token on P1", P1, t2)]:
        for path in ["/api.php?action=archive-data", "/api.php?action=attendance", "/api.php?action=bank-accounts", "/api.php?action=booking-audit-correction", "/api.php?action=booking-identity", "/api.php?action=booking-negotiated-price", "/api.php?action=booking-payments", "/api.php?action=booking-receipt-email", "/api.php?action=bookings", "/api.php?action=bookings-status", "/api.php?action=categories", "/api.php?action=categories-delete", "/api.php?action=categories-semantic-bind", "/api.php?action=categories-update", "/api.php?action=categories/delete", "/api.php?action=categories/semantic-bind", "/api.php?action=categories/update", "/api.php?action=chat-messages", "/api.php?action=config", "/api.php?action=data-cleanup-execute", "/api.php?action=data-cleanup-history", "/api.php?action=data-cleanup-mode", "/api.php?action=data-cleanup-preview", "/api.php?action=db-reset", "/api.php?action=download-backup", "/api.php?action=download-json-backup", "/api.php?action=download-schema", "/api.php?action=email-diagnostics", "/api.php?action=email-report", "/api.php?action=finance-ledger-page", "/api.php?action=gemini-test", "/api.php?action=group-report-email", "/api.php?action=guest-security-deposits", "/api.php?action=historical-backfill-review", "/api.php?action=hotel-data", "/api.php?action=inventory", "/api.php?action=inventory-maintenance", "/api.php?action=login", "/api.php?action=multi-room-bookings", "/api.php?action=notifications-read", "/api.php?action=operation-receipt-status", "/api.php?action=operations-center", "/api.php?action=ota-disbursements", "/api.php?action=ota-receivables", "/api.php?action=refresh-session", "/api.php?action=reporting-periods", "/api.php?action=room-transfers", "/api.php?action=rooms", "/api.php?action=runtime-diagnostics", "/api.php?action=salary-payment-correction", "/api.php?action=salary-slips", "/api.php?action=send-shift-report", "/api.php?action=staff", "/api.php?action=staff-savings", "/api.php?action=subcategories", "/api.php?action=subcategories-delete", "/api.php?action=subcategories-update", "/api.php?action=subcategories/delete", "/api.php?action=subcategories/update", "/api.php?action=sync", "/api.php?action=telegram-bot", "/api.php?action=telegram-callback", "/api.php?action=telegram-clear", "/api.php?action=telegram-delete-webhook", "/api.php?action=telegram-set-webhook", "/api.php?action=telegram-webhook", "/api.php?action=telegram-webhook-info", "/api.php?action=test-data-purge", "/api.php?action=test-data-purge-preview", "/api.php?action=transaction-allocation-void", "/api.php?action=transaction-booking-action", "/api.php?action=transaction-proof", "/api.php?action=transactions", "/api.php?action=verify-2fa", "/api.php?action=multi-property&command=snapshot-v2", "/api.php?action=multi-property&command=overview", "/api.php?action=multi-property&command=capabilities", "/api.php?action=multi-property&command=manifest", "/api.php?action=multi-property&command=summary-preview"]:
            req = urllib.request.Request(base + path, headers={"Authorization": "Bearer " + foreign_token, "X-Device-ID": "tenantiso-foreign"})
            try:
                resp = urllib.request.urlopen(req, timeout=30)
                body = resp.read().decode()
                check(label + " " + path + " returns non-200 or empty", False, {"status": resp.status, "body": body[:300]})
            except urllib.error.HTTPError as e:
                check(label + " " + path + " rejected", e.code in (401, 403, 404), {"status": e.code})
    # POST mutation must also be rejected cross-property.
    for label, base, foreign_token in [("P1 token on P2", P2, t1), ("P2 token on P1", P1, t2)]:
        data={"command":"queue-snapshot","from":"2026-01-01","to":"2026-01-02","operationId":"tenantiso-foreign-op"}.encode() if False else b'{"command":"queue-snapshot","from":"2026-01-01","to":"2026-01-02","operationId":"tenantiso-foreign-op"}'
        req=urllib.request.Request(base+"/api.php?action=multi-property", data=data, headers={"Authorization":"Bearer "+foreign_token,"Content-Type":"application/json","X-Device-ID":"tenantiso-foreign"}, method="POST")
        try:
            resp=urllib.request.urlopen(req, timeout=30)
            body=resp.read().decode()
            check(label+" POST multi-property rejected", False, {"status":resp.status,"body":body[:300]})
        except urllib.error.HTTPError as e:
            check(label+" POST multi-property rejected", e.code in (401,403,404,405,422), {"status":e.code})
except Exception as e:
    check("tenant isolation scenario bootstrap", False, str(e))
save()
print("Tenant isolation skeleton complete. Full adversarial coverage requires wiring + two running properties.")
