<?php

namespace App\RouterOs;

use InvalidArgumentException;

final class Paths
{
    /** Only lowercase menu paths like "interface/wireguard/peers" are ever sent. */
    public static function check(string $path): string
    {
        $path = trim($path, '/');
        if (! preg_match('#^[a-z0-9][a-z0-9\-]*(/[a-z0-9][a-z0-9\-]*)*$#', $path)) {
            throw new InvalidArgumentException('Invalid RouterOS path');
        }

        return $path;
    }

    /** Remove secrets (passwords, private keys, IPsec secrets) from RouterOS output. */
    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match('/secret|password|private-key|preshared|passphrase/i', $k)) {
                $data[$k] = '***';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }

        return $data;
    }

    /** @return list<array<string,string>> */
    public static function rows(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        return array_is_list($json) ? array_values(array_filter($json, 'is_array')) : [$json];
    }
}
