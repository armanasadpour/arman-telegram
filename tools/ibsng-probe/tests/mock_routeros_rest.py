#!/usr/bin/env python3
"""
Local stand-in for the RouterOS 7 REST API (HTTPS + Basic auth), used ONLY to
test the probe's TLS pinning, auth handling, redaction and parsing.
Response shapes follow the MikroTik REST manual (menus return JSON lists,
single-settings menus return one object, values are strings).
Usage: mock_routeros_rest.py PORT CERTFILE KEYFILE
"""
import base64
import json
import ssl
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

USER, PASS = "panel-ro", "mt-secret-pass"
DATA = {
    "system/resource": {"version": "7.7 (stable)", "board-name": "x86", "architecture-name": "x86",
                        "cpu-count": "2", "cpu-load": "7", "free-memory": "164691968",
                        "total-memory": "1006632960", "uptime": "6d13h38m44s"},
    "system/license": {"software-id": "ABCD-1234", "nlevel": "6", "features": ""},
    "ip/service": [
        {".id": "*1", "name": "telnet", "port": "23", "disabled": "true", "address": ""},
        {".id": "*2", "name": "www-ssl", "port": "443", "disabled": "false", "address": "10.0.0.5/32", "certificate": "rest"},
        {".id": "*3", "name": "winbox", "port": "8291", "disabled": "false", "address": ""},
    ],
    "radius": [{".id": "*1", "address": "10.0.0.2", "service": "ppp", "secret": "radius-shared-secret",
                "disabled": "false", "authentication-port": "1812", "accounting-port": "1813", "timeout": "1s"}],
    "ppp/aaa": {"use-radius": "true", "accounting": "true", "interim-update": "1m"},
    "interface/ovpn-server/server": {"enabled": "true", "port": "1194", "protocol": "udp", "mode": "ip",
                                     "auth": "sha256", "cipher": "aes256-gcm", "certificate": "ovpn-server",
                                     "require-client-certificate": "false", "default-profile": "ovpn"},
    "interface/l2tp-server/server": {"enabled": "true", "use-ipsec": "required", "ipsec-secret": "l2tp-psk-secret",
                                     "authentication": "mschap2", "default-profile": "l2tp", "max-sessions": "unlimited"},
    "interface/wireguard": [{".id": "*5", "name": "wg-users", "listen-port": "51820", "mtu": "1420",
                             "private-key": "cHJpdmF0ZS1rZXktc2hvdWxkLW5ldmVyLWxlYWs=",
                             "public-key": "c2VydmVyLXB1YmxpYy1rZXk=", "disabled": "false", "running": "true"}],
    "interface/wireguard/peers": [{".id": "*a", "interface": "wg-users", "public-key": "cGVlcg==",
                                   "preshared-key": "cHNrLXNob3VsZC1uZXZlci1sZWFr", "allowed-address": "10.66.0.2/32",
                                   "rx": "123456789", "tx": "987654321", "last-handshake": "1m12s", "disabled": "false"}],
    "ppp/active": [{".id": "*1", "name": "u1", "service": "ovpn"}, {".id": "*2", "name": "u2", "service": "l2tp"},
                   {".id": "*3", "name": "u3", "service": "ovpn"}],
}


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        want = "Basic " + base64.b64encode(f"{USER}:{PASS}".encode()).decode()
        if self.headers.get("Authorization") != want:
            return self._send(401, {"error": 401, "message": "Unauthorized"})
        path = self.path.split("?")[0]
        if not path.startswith("/rest/") or path[6:] not in DATA:
            return self._send(400, {"error": 400, "message": "Bad Request", "detail": "no such command"})
        self._send(200, DATA[path[6:]])


if __name__ == "__main__":
    port, cert, key = int(sys.argv[1]), sys.argv[2], sys.argv[3]
    srv = HTTPServer(("127.0.0.1", port), H)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert, key)
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    print("mock routeros on", port, flush=True)
    srv.serve_forever()
