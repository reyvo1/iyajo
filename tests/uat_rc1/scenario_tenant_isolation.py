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
        for path in ["/api.php?action=hotel-data", "/api.php?action=multi-property&command=snapshot-v2", "/api.php?action=multi-property&command=overview", "/api.php?action=multi-property&command=capabilities", "/api.php?action=multi-property&command=manifest", "/api.php?action=multi-property&command=summary-preview", "/api.php?action=bookings", "/api.php?action=transactions", "/api.php?action=finance-report", "/api.php?action=report", "/api.php?action=staff", "/api.php?action=config", "/api.php?action=db_config", "/api.php?action=rooms", "/api.php?action=reservations", "/api.php?action=inventory", "/api.php?action=telegram", "/api.php?action=operations", "/api.php?action=attendance", "/api.php?action=leaves", "/api.php?action=savings", "/api.php?action=staff-management", "/api.php?action=support", "/api.php?action=local_connect", "/api.php?action=db_config", "/api.php?action=owner-readonly", "/api.php?action=audit-log", "/api.php?action=website", "/api.php?action=finance", "/api.php?action=report-center", "/api.php?action=canonical-report", "/api.php?action=growth-suite", "/api.php?action=enterprise-suite", "/api.php?action=pos", "/api.php?action=internal-memo", "/api.php?action=property-setup", "/api.php?action=multi-property-foundation"]:
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
