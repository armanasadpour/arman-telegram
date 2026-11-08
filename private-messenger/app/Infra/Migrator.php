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
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME(3) NOT NULL) ENGINE=InnoDB');
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
