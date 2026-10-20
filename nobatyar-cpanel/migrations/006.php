<?php
declare(strict_types=1);
function phase6_columns(): array
{
    return [
        ['clinics', 'slot_capacity', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1'],
        ['bookings', 'family_member_id', 'BIGINT UNSIGNED NULL'],
        ['bookings', 'expires_at', 'DATETIME NULL'],
        ['bookings', 'fee_toman', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['bookings', 'confirmed_at', 'DATETIME NULL'],
    ];
}
function migrate_phase6(PDO $p): void
{
    if ((int) $p->query("SELECT GET_LOCK('nobatyar_phase6',10)")->fetchColumn() !== 1) {
        throw new RuntimeException('ارتقای دیگری در حال اجراست.');
    }
    try {
        if ($p->query('SELECT version FROM schema_migrations WHERE version=6')->fetchColumn()) {
            return;
        }
        foreach (explode(";\n", file_get_contents(__DIR__ . '/006.sql')) as $sql) {
            if (trim($sql) !== '') {
                $p->exec($sql);
            }
        }
        foreach (phase6_columns() as [$t, $c, $definition]) {
            $q = $p->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            );
            $q->execute([$t, $c]);
            if (!(int) $q->fetchColumn()) {
                $p->exec("ALTER TABLE `$t` ADD COLUMN `$c` $definition");
            }
        }
        $p->exec(
            "ALTER TABLE bookings MODIFY status ENUM('confirmed','cancelled','completed','no_show','held','expired') NOT NULL DEFAULT 'confirmed'",
        );
        $p->exec("UPDATE settings SET value='0.6.0' WHERE `key`='app_version'");
        $p->exec('INSERT IGNORE INTO schema_migrations(version) VALUES(6)');
    } finally {
        $p->query("SELECT RELEASE_LOCK('nobatyar_phase6')");
    }
}
