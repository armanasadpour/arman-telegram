#!/usr/bin/env python3
"""
Local stand-in for the IBSng A1.24 XML-RPC server, used ONLY to test the PHP
client's wire format and the probe's control flow without a real IBSng.

Behaviour mirrored from the A1.24 source:
  * POST "/" ; one struct param with auth_name/auth_pass/auth_type
  * application errors -> Fault(1, "<text>")
  * dict keys of getUserInfo are str(user_id)
This is NOT proof of compatibility with a real IBSng; only the probe run
against the real server is.
"""
import sys
import itertools
from xmlrpc.server import SimpleXMLRPCServer, SimpleXMLRPCRequestHandler
from xmlrpc.client import Fault

ADMIN = ("system", "secret-pass")
GROUPS = {"test-group": {"group_id": 1, "group_name": "test-group", "comment": "",
                          "owner_id": 0, "owner_name": "system",
                          "attrs": {"rel_exp_date": "30", "rel_exp_date_unit": "Days",
                                    "multi_login": "1", "normal_charge": "vol-mb"}},
          "گروه-فارسی": {"group_id": 2, "group_name": "گروه-فارسی", "comment": "", "owner_id": 0,
                          "owner_name": "system", "attrs": {}}}
USERS = {}
NEXT_ID = itertools.count(1000)


def err(msg):
    raise Fault(1, msg)


class Handler(SimpleXMLRPCRequestHandler):
    rpc_paths = ("/",)


def dispatch(method, params):
    if len(params) != 1 or not isinstance(params[0], dict):
        err("Invalid parameters")
    p = dict(params[0])
    for k in ("auth_name", "auth_pass", "auth_type"):
        if k not in p:
            err("Invalid parameters")
    if p.pop("auth_type") != "ADMIN" or (p.pop("auth_name"), p.pop("auth_pass")) != ADMIN:
        err("Invalid Username or Password")

    def need(*keys):
        for k in keys:
            if k not in p:
                err("Incomplete request, argument %s not found" % k)

    if method == "admin.getAdminInfo":
        need("admin_username")
        return {"admin_id": 0, "username": "system", "name": "System", "deposit": "0.0",
                "creator_id": 0, "locked": False, "comment": ""}
    if method == "group.listGroups":
        return sorted(GROUPS)
    if method == "group.getGroupInfo":
        need("group_name")
        if p["group_name"] not in GROUPS:
            err("Group %s not found" % p["group_name"])
        return GROUPS[p["group_name"]]
    if method == "charge.listCharges":
        return ["vol-mb"]
    if method == "charge.listChargeRules":
        need("charge_name")
        return [{"rule_id": 1, "cpm": 0.0, "cpk": 0.0009765625, "start": "00:00:00", "end": "23:59:59",
                 "day_of_weeks": "Every Day", "bandwidth_limit_kbytes": -1, "ras": "_ALL_", "ports": ["_ALL_"]}]
    if method == "report.getOnlineUsers":
        need("normal_sort_by", "normal_desc", "voip_sort_by", "voip_desc", "conds")
        if not isinstance(p["conds"], dict):
            err("conds must be struct")
        return [[{"user_id": 7, "normal_username": "ali", "in_bytes": 1024, "out_bytes": 2048,
                  "login_time_epoch": 1700000000}], []]
    if method == "normal_user.checkNormalUsernameForAdd":
        need("normal_username", "current_username")
        return [u for u in USERS.values() if u["attrs"].get("normal_username") == p["normal_username"]] and ["exists"] or []
    if method == "user.addNewUsers":
        need("count", "credit", "owner_name", "group_name", "credit_comment")
        if p["group_name"] not in GROUPS:
            err("Group %s not found" % p["group_name"])
        ids = []
        for _ in range(int(p["count"])):
            uid = next(NEXT_ID)
            USERS[uid] = {"basic_info": {"user_id": uid, "credit": float(p["credit"]), "group_name": p["group_name"],
                                         "owner_name": p["owner_name"], "online_status": False}, "attrs": {}}
            ids.append(uid)
        return ids
    if method == "user.updateUserAttrs":
        need("user_id", "attrs", "to_del_attrs")
        uid = int(p["user_id"])
        if uid not in USERS:
            err("User with id %s not found" % uid)
        a = p["attrs"]
        if "normal_username" in a:
            for k in ("normal_password", "normal_generate_password", "normal_generate_password_len", "normal_save_usernames"):
                if k not in a:
                    err("Incomplete request, argument %s not found" % k)
        for k, v in a.items():
            if k in ("normal_generate_password", "normal_generate_password_len", "normal_save_usernames", "rel_exp_date_unit"):
                continue
            USERS[uid]["attrs"][k] = str(v)
        return ""  # IBSng returns "" when a handler returns None (handlers_manager.__returnResponse)
    if method == "user.getUserInfo":
        if "user_id" in p:
            uid = int(p["user_id"])
            if uid not in USERS:
                err("User with id %s not found" % uid)
            return {str(uid): USERS[uid]}
        if "normal_username" in p:
            for uid, u in USERS.items():
                if u["attrs"].get("normal_username") == p["normal_username"]:
                    return {str(uid): u}
            err("Normal username %s not found" % p["normal_username"])
        err("Incomplete request, argument user_id not found")
    if method == "user.changeCredit":
        need("user_id", "credit", "credit_comment")
        USERS[int(p["user_id"])]["basic_info"]["credit"] += float(p["credit"])
        return ""
    if method == "user.delUser":
        need("user_id", "delete_comment", "del_connection_logs", "del_audit_logs")
        USERS.pop(int(p["user_id"]), None)
        return ""
    err("Method %s not found" % method)


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 18235
    srv = SimpleXMLRPCServer(("127.0.0.1", port), requestHandler=Handler, logRequests=False, allow_none=False)
    srv.register_instance(type("D", (), {"_dispatch": staticmethod(dispatch)})())
    print("mock ibsng on", port, flush=True)
    srv.serve_forever()
