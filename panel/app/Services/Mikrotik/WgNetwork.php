<?php

namespace App\Services\Mikrotik;

use RuntimeException;

/** IPv4 network of a WireGuard interface ("10.20.0.1/22") and free-address allocation. */
final class WgNetwork
{
    public function __construct(public readonly int $network, public readonly int $prefix, public readonly ?int $server = null) {}

    /** "10.20.0.1/22" (interface address) or "10.20.0.0/22". */
    public static function parse(string $cidr): self
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($cidr), $m) || ($ip = ip2long($m[1])) === false || (int) $m[2] < 16 || (int) $m[2] > 30) {
            throw new RuntimeException('شبکه WireGuard باید IPv4 با پیشوند ۱۶ تا ۳۰ باشد (مثلاً 10.20.0.1/22).');
        }
        $prefix = (int) $m[2];
        $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $net = $ip & $mask;

        return new self($net, $prefix, $ip !== $net ? $ip : null);
    }

    public function cidr(): string
    {
        return long2ip($this->network).'/'.$this->prefix;
    }

    public function contains(string $ip): bool
    {
        $l = ip2long($ip);
        $mask = (-1 << (32 - $this->prefix)) & 0xFFFFFFFF;

        return $l !== false && ($l & $mask) === $this->network;
    }

    public function size(): int
    {
        return (1 << (32 - $this->prefix)) - 2;
    }

    /** Lowest free host address, skipping network, broadcast, the server and $used. @param array<string,bool> $used ip => true */
    public function allocate(array $used): string
    {
        $broadcast = $this->network + (1 << (32 - $this->prefix)) - 1;
        for ($a = $this->network + 1; $a < $broadcast; $a++) {
            $ip = long2ip($a);
            if ($a !== $this->server && ! isset($used[$ip])) {
                return $ip;
            }
        }
        throw new RuntimeException('آدرس آزاد در شبکه WireGuard '.$this->cidr().' باقی نمانده است.');
    }
}
