"""Tanggal operasional server untuk skenario UAT.

Container CI berjalan dengan zona waktu UTC, sedangkan PHP aplikasi memakai
`APP_TIMEZONE` (Asia/Makassar di CI, zona waktu hotel di produksi). Server
menolak check-in bila tanggal check-in lebih besar dari `date('Y-m-d')` milik
PHP (`api/modules/front_office/042_operational_lifecycle_invariants.php`) dan
menolak pembuatan reservasi bila tanggal check-in sudah lewat.

Dulu skenario memakai `datetime.date.today()` (UTC). Selama jendela 8 jam
setiap hari di mana zona server sudah bergeser ke hari berikutnya, tanggal
"besok" versi skenario sudah(gen) menjadi dua hari di depan versi server, dan
check-in ditolak dengan "Tanggal check-in belum tiba". Skenario harus memakai
tanggal server, bukan tanggal mesin test.
"""

import os
from datetime import datetime, timedelta

APP_TZ = os.environ.get('APP_TIMEZONE') or 'Asia/Makassar'


def server_today():
    """Tanggal hari ini menurut zona waktu aplikasi."""
    try:
        from zoneinfo import ZoneInfo
        return datetime.now(ZoneInfo(APP_TZ)).date()
    except Exception:
        return datetime.now().astimezone().date()


def server_day(offset=0):
    """Tanggal server digeser sejumlah hari (offset bisa negatif)."""
    return server_today() + timedelta(days=offset)
