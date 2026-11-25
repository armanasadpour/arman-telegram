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

    /** RouterOS row ids look like "*1F". */
    public static function id(string $id): string
    {
        if (! preg_match('/^\*[0-9A-Fa-f]{1,16}$/', $id)) {
            throw new InvalidArgumentException('Invalid RouterOS id');
        }

        return $id;
    }

    /** @param array<string,mixed> $attrs @return array<string,string> */
    public static function attrs(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $k => $v) {
            if (! is_string($k) || ! preg_match('/^[a-z0-9][a-z0-9\-]*$/', $k)) {
                throw new InvalidArgumentException('Invalid RouterOS attribute');
            }
            $v = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
            if (str_contains($v, "\0") || str_contains($v, "\n") || str_contains($v, "\r")) {
                throw new InvalidArgumentException('Invalid RouterOS value');
            }
            $out[$k] = $v;
        }

        return $out;
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
