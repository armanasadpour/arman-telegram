<?php

namespace App\Services\Mikrotik;

use App\Models\MtAccount;
use App\Models\MtDevice;

/** Client .conf for a WireGuard account (contains the private key: shown only to authorised users). */
final class WgConfig
{
    public static function build(MtAccount $a, ?MtDevice $dev = null): string
    {
        $g = $a->group;
        $d = $dev ?? $a;
        $lines = ['[Interface]', 'PrivateKey = '.$d->wg_private_key, 'Address = '.$d->wg_address.'/32'];
        if ($g->dns) {
            $lines[] = 'DNS = '.$g->dns;
        }
        if ($g->wg_mtu) {
            $lines[] = 'MTU = '.$g->wg_mtu;
        }
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = '.$g->wg_server_public_key;
        if ($d->wg_preshared_key) {
            $lines[] = 'PresharedKey = '.$d->wg_preshared_key;
        }
        $host = $g->endpoint();
        $lines[] = 'Endpoint = '.(str_contains($host, ':') ? '['.$host.']' : $host).':'.$g->wg_listen_port;
        $lines[] = 'AllowedIPs = '.($g->wg_allowed_ips ?: '0.0.0.0/0, ::/0');
        if ($g->wg_keepalive) {
            $lines[] = 'PersistentKeepalive = '.$g->wg_keepalive;
        }

        return implode("\n", $lines)."\n";
    }

    public static function filename(MtAccount $a, ?MtDevice $dev = null): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_-]/', '', $a->username), 0, 12).($dev ? '-'.$dev->idx : '').'.conf';
    }
}
