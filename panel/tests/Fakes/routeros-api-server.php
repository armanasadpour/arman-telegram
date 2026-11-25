<?php

// Minimal RouterOS API (8728/8729) emulator for tests: login + "/<menu>/print" from a JSON fixture.
// Usage: php routeros-api-server.php <port> <fixture.json> [cert.pem] [http]
//   cert => TLS (api-ssl); "http" => answer the REST API (https://…/rest/<menu>) instead of the binary API.
[$_, $port, $fixture] = $argv;
$cert = ($argv[3] ?? '') !== '' ? $argv[3] : null;
$http = ($argv[4] ?? '') === 'http';
$ctx = stream_context_create($cert ? ['ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'allow_self_signed' => true]] : []);
$srv = stream_socket_server(($cert ? 'tls' : 'tcp').'://127.0.0.1:'.$port, $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (! $srv) {
    fwrite(STDERR, "listen failed: $es\n");
    exit(1);
}
fwrite(STDOUT, "ready\n");

function rd($c, $n)
{
    $o = '';
    while (strlen($o) < $n) {
        $x = fread($c, $n - strlen($o));
        if ($x === false || $x === '') {
            return null;
        } $o .= $x;
    }

return $o;
}
function rlen($c)
{
    $b = rd($c, 1);
    if ($b === null) {
        return null;
    } $x = ord($b);
    if ($x < 0x80) {
        return $x;
    } if ($x < 0xC0) {
        return (($x & 0x3F) << 8) | ord(rd($c, 1));
    }
    if ($x < 0xE0) {
        $b = rd($c, 2);

        return (($x & 0x1F) << 16) | (ord($b[0]) << 8) | ord($b[1]);
    }
    if ($x < 0xF0) {
        $b = rd($c, 3);

        return (($x & 0x0F) << 24) | (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
    }

    return unpack('N', rd($c, 4))[1];
}
function enc($l)
{
    if ($l < 0x80) {
        return chr($l);
    } if ($l < 0x4000) {
        return pack('n', $l | 0x8000);
    } if ($l < 0x200000) {
        return substr(pack('N', $l | 0xC00000), 1);
    }

return pack('N', $l | 0xE0000000);
}
function sentence($c)
{
    $w = [];
    while (true) {
        $l = rlen($c);
        if ($l === null) {
            return null;
        } if ($l === 0) {
            return $w;
        } $w[] = rd($c, $l);
    }
}
function send($c, array $words)
{
    $b = '';
    foreach ($words as $w) {
        $b .= enc(strlen($w)).$w;
    } fwrite($c, $b."\0");
}

while ($conn = @stream_socket_accept($srv, -1)) {
    $data = json_decode(file_get_contents($fixture), true);
    if ($http) {
        $head = '';
        while (($line = fgets($conn)) !== false && $line !== "\r\n") {
            $head .= $line;
        }
        preg_match('#^GET /rest/([a-z0-9/\-]+)#', $head, $m);
        preg_match('#Authorization: Basic (\S+)#i', $head, $a);
        $menu = $m[1] ?? '';
        if (base64_decode($a[1] ?? '') !== $data['user'].':'.$data['password']) {
            $st = 401;
            $body = '{"error":401,"message":"Unauthorized"}';
        } elseif (! array_key_exists($menu, $data['menus'])) {
            $st = 400;
            $body = '{"error":400,"message":"no such command"}';
        } else {
            $st = 200;
            $rows = $data['menus'][$menu];
            $body = json_encode(in_array($menu, ['system/resource', 'system/identity', 'system/license', 'interface/l2tp-server/server'], true) ? ($rows[0] ?? new stdClass) : $rows);
        }
        fwrite($conn, "HTTP/1.1 $st X\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
        fclose($conn);

        continue;
    }
    $authed = false;
    while (($s = sentence($conn)) !== null) {
        $cmd = array_shift($s);
        $attrs = [];
        foreach ($s as $w) {
            if ($w[0] === '=') {
                $p = strpos($w, '=', 1);
                $attrs[substr($w, 1, $p - 1)] = substr($w, $p + 1);
            }
        }
        if ($cmd === '/login') {
            if (($attrs['name'] ?? '') === $data['user'] && ($attrs['password'] ?? '') === $data['password']) {
                $authed = true;
                send($conn, ['!done']);
            } else {
                send($conn, ['!trap', '=message=invalid user name or password (6)']);
                send($conn, ['!done']);
            }

            continue;
        }
        if (! $authed) {
            send($conn, ['!fatal', 'not logged in']);
            break;
        }
        $menu = preg_replace('#^/|/print$#', '', $cmd);
        if (! array_key_exists($menu, $data['menus'])) {
            send($conn, ['!trap', '=message=no such command prefix']);
            send($conn, ['!done']);

            continue;
        }
        foreach ($data['menus'][$menu] as $row) {
            $w = ['!re'];
            foreach ($row as $k => $v) {
                $w[] = '='.$k.'='.$v;
            } send($conn, $w);
        }
        send($conn, ['!done']);
    }
    fclose($conn);
}
