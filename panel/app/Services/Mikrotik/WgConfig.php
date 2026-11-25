<?php

namespace App\Services\Mikrotik;

use App\Models\MtAccount;

/** Client .conf for a WireGuard account (contains the private key: shown only to authorised users). */
final class WgConfig
{
    public static function build(MtAccount $a): string
    {
        $g = $a->group;
        $lines = ['[Interface]', 'PrivateKey = '.$a->wg_private_key, 'Address = '.$a->wg_address.'/32'];
        if ($g->dns) {
            $lines[] = 'DNS = '.$g->dns;
        }
        if ($g->wg_mtu) {
            $lines[] = 'MTU = '.$g->wg_mtu;
        }
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = '.$g->wg_server_public_key;
        if ($a->wg_preshared_key) {
            $lines[] = 'PresharedKey = '.$a->wg_preshared_key;
        }
        $host = $g->endpoint();
        $lines[] = 'Endpoint = '.(str_contains($host, ':') ? '['.$host.']' : $host).':'.$g->wg_listen_port;
        $lines[] = 'AllowedIPs = '.($g->wg_allowed_ips ?: '0.0.0.0/0, ::/0');
        if ($g->wg_keepalive) {
            $lines[] = 'PersistentKeepalive = '.$g->wg_keepalive;
        }

        return implode("\n", $lines)."\n";
    }

    public static function filename(MtAccount $a): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $a->username).'.conf';
    }
}
