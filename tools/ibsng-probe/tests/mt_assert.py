"""Assertions on the probe's JSON report for the MikroTik mock run."""
import json
import sys

r = {x["id"]: x for x in json.load(open(sys.argv[1]))["results"]}


def ok(cond, msg):
    print(("ok   " if cond else "FAIL ") + msg)
    return cond


checks = [
    ok(r["mt.wg_peers"]["status"] == "pass", "wireguard rx/tx/last-handshake detected"),
    ok(r["mt.ppp_aaa"]["status"] == "pass", "ppp accounting + interim-update detected"),
    ok(r["mt.radius"]["status"] == "pass", "radius for ppp detected"),
    ok(r["mt.services"]["status"] == "warn", "unrestricted winbox flagged"),
    ok(r["mt.ppp_active"]["data"]["by_service"] == {"ovpn": 2, "l2tp": 1}, "sessions grouped by protocol"),
    ok(r["mt.resource"]["data"]["version"].startswith("7.7"), "RouterOS version read"),
]
sys.exit(0 if all(checks) else 1)
