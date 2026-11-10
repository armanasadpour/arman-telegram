<?php
declare(strict_types=1);

namespace PM\Admin;

use PM\Infra\Database;
use PM\Infra\Migrator;

/**
 * Database backup as a (gzip-compressed) SQL script, generated in PHP because shared hosts rarely
 * allow exec(mysqldump). The database holds only ciphertext, public keys and metadata, so the
 * backup cannot reveal messages or files — but it does contain password hashes and settings sealed
 * with APP_KEY, so it must be stored as carefully as the server itself.
 */
final class Backup
{
    /** Volatile tables whose rows are useless (or harmful) after a restore. */
    private const SKIP_ROWS = ['rate_limits', 'job_locks', 'admin_sessions', 'ephemeral_envelopes'];

    public function __construct(private readonly Database $db, private readonly string $migrationsDir)
    {
    }

    public static function gzipAvailable(): bool
    {
        return function_exists('deflate_init');
    }

    /** @param callable(string):void $out */
    public function write(callable $out, bool $gzip): void
    {
        $ctx = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
        $emit = $ctx === null ? $out : static function (string $s) use ($out, $ctx): void {
            $c = deflate_add($ctx, $s, ZLIB_NO_FLUSH);
            if ($c !== '') {
                $out($c);
            }
        };
        $existing = array_map(static fn ($r) => (string)array_values($r)[0], $this->db->all('SHOW TABLES'));
        $tables = array_values(array_intersect((new Migrator($this->db, $this->migrationsDir))->ownTables(), $existing));

        $emit("-- Private messenger database backup\n-- Created (UTC): " . gmdate('Y-m-d H:i:s') . "\n"
            . "-- Contains ciphertext, public keys and metadata only. Restore into an EMPTY database (phpMyAdmin → Import).\n"
            . "-- Restoring also needs the original config/.env (APP_KEY) for sealed settings and admin TOTP.\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        foreach ($tables as $t) {
            $create = $this->db->one('SHOW CREATE TABLE `' . $t . '`');
            $emit("DROP TABLE IF EXISTS `$t`;\n" . array_values($create)[1] . ";\n\n");
            if (in_array($t, self::SKIP_ROWS, true)) {
                continue;
            }
            $pk = array_column(array_filter($this->db->all('SHOW KEYS FROM `' . $t . '`'), static fn ($k) => $k['Key_name'] === 'PRIMARY'), 'Column_name');
            $order = $pk === [] ? '' : ' ORDER BY `' . implode('`, `', $pk) . '`';
            for ($offset = 0; ; $offset += 500) {
                $rows = $this->db->all('SELECT * FROM `' . $t . '`' . $order . ' LIMIT 500 OFFSET ' . $offset);
                if ($rows === []) {
                    break;
                }
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $vals = [];
                foreach ($rows as $r) {
                    $vals[] = '(' . implode(', ', array_map([$this, 'literal'], $r)) . ')';
                }
                $emit("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
                if (count($rows) < 500) {
                    break;
                }
            }
            $emit("\n");
        }
        $emit("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- end of backup\n");
        if ($ctx !== null) {
            $out(deflate_add($ctx, '', ZLIB_FINISH));
        }
    }

    private function literal(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        $v = (string)$v;
        if ($v === '') {
            return "''";
        }
        // Binary (keys, ciphertext, hashes): hex literal; text: quoted and escaped.
        return mb_check_encoding($v, 'UTF-8') && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)
            ? $this->db->pdo->quote($v) : '0x' . bin2hex($v);
    }
}
