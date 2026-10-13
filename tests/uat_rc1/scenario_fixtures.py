"""Shared fixtures for the scenarios this suite added.

Every booking that touches money needs an open cash shift, and the suites that run
earlier in this job close theirs. Without this each scenario had to rediscover that
by failing, so the rule lives here once.
"""
from client import request
from scenario_core import db


def ensure_open_shift(operation):
    """Return the id of an open shift, opening one if the job left none.

    Returns None when a shift cannot be opened, so the caller can report the real
    reason instead of a later symptom.
    """
    rows = db("SELECT id,shift_time FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC")
    if rows:
        return rows[0]['id']
    status, body = request('operations-center', 'POST', {
        'command': 'shift-open',
        'openingCash': 0,
        'shiftTime': 'siang',
        'notes': 'UAT shift opened by a scenario fixture',
    }, operation)
    rows = db("SELECT id,shift_time FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC")
    if rows:
        return rows[0]['id']
    return None


def release_rooms(operation, *booking_ids):
    """Cancel the bookings a scenario held so the next one can book a room.

    Rooms are a fixed, small fixture and every scenario in this job books one. A
    scenario that keeps its booking starves whatever runs behind it, which surfaces
    as 'no available room' far away from the cause.
    """
    for booking_id in booking_ids:
        if not booking_id:
            continue
        try:
            request('bookings-status', 'POST',
                    {'id': booking_id, 'status': 'cancelled'}, operation)
        except Exception:
            pass
