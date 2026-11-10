<?php
declare(strict_types=1);

namespace PM\Infra;

use PM\Support\Clock;

final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> applied migration names */
    public function migrate(): array
    {
        // Many cPanel hosts create databases with a latin1 default; Persian text needs utf8mb4.
        try {
            $this->db->pdo->exec('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\PDOException) {
            // not permitted on some hosts: every table declares utf8mb4 explicitly anyway
        }
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME(3) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (self::split((string)file_get_contents($file)) as $stmt) {
                $this->db->pdo->exec($stmt); // DDL auto-commits in MySQL; migrations must be idempotent-safe
            }
            $this->db->run('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, Clock::db()]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return list<string> every table name created by the migrations (plus schema_migrations) */
    public function ownTables(): array
    {
        $names = ['schema_migrations'];
        foreach (glob($this->dir . '/*.sql') ?: [] as $f) {
            preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-z_]+)`?/i', (string)file_get_contents($f), $m);
            $names = array_merge($names, $m[1]);
        }
        return array_values(array_unique($names));
    }

    /** Drops the listed tables (used to clean up after a failed installation). */
    public function dropTables(array $tables): void
    {
        $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            if (preg_match('/^[a-z_]+$/', $t)) {
                $this->db->pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
        }
        $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Splits a plain SQL file (no procedures) on statement-terminating semicolons, ignoring comments and strings. */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote === null && $c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\') {
                    $buf .= $sql[++$i] ?? '';
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
