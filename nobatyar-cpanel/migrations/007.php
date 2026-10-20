<?php
declare(strict_types=1);
function phase7_columns(): array
{
    return [
        ['bookings', 'intake_patient_id', 'BIGINT UNSIGNED NULL'],
        ['bookings', 'created_by_user_id', 'BIGINT UNSIGNED NULL'],
        ['bookings', 'booking_channel', "VARCHAR(20) NOT NULL DEFAULT 'online'"],
        ['bookings', 'attendance_state', "VARCHAR(20) NOT NULL DEFAULT 'waiting'"],
        ['bookings', 'reception_fee_policy', 'VARCHAR(20) NULL'],
    ];
}
function migrate_phase7(PDO $p): void
{
    if ((int) $p->query("SELECT GET_LOCK('nobatyar_reception',10)")->fetchColumn() !== 1) {
        throw new RuntimeException('ارتقای دیگری در حال اجراست.');
    }
    try {
        if ($p->query('SELECT version FROM schema_migrations WHERE version=7')->fetchColumn()) {
            return;
        }
        foreach (explode(";\n", file_get_contents(__DIR__ . '/007.sql')) as $sql) {
            if (trim($sql) !== '') {
                $p->exec($sql);
            }
        }
        foreach (phase7_columns() as [$t, $c, $definition]) {
            $q = $p->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            );
            $q->execute([$t, $c]);
            if (!(int) $q->fetchColumn()) {
                $p->exec("ALTER TABLE `$t` ADD COLUMN `$c` $definition");
            }
        }
        $p->exec("UPDATE settings SET value='0.6.1' WHERE `key`='app_version'");
        $p->exec('INSERT IGNORE INTO schema_migrations(version) VALUES(7)');
    } finally {
        $p->query("SELECT RELEASE_LOCK('nobatyar_reception')");
    }
}
