<?php
declare(strict_types=1);
function migrate_phase4(PDO $p): void
{
    if ((int) $p->query("SELECT GET_LOCK('nobatyar_phase4',10)")->fetchColumn() !== 1) {
        throw new RuntimeException('ارتقای دیگری در حال اجراست.');
    }
    try {
        if ($p->query('SELECT version FROM schema_migrations WHERE version=4')->fetchColumn()) {
            return;
        }
        foreach (explode(";\n", file_get_contents(__DIR__ . '/004.sql')) as $sql) {
            if (trim($sql) !== '') {
                $p->exec($sql);
            }
        }
        foreach (phase4_columns() as [$table, $column, $definition]) {
            $q = $p->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            );
            $q->execute([$table, $column]);
            if (!(int) $q->fetchColumn()) {
                $p->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        }
        $p->exec(
            "ALTER TABLE bookings MODIFY status ENUM('confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'confirmed'",
        );
        // Legacy times retain their exact start, capacity, identifiers and bookings.
        $p->exec('UPDATE slots SET ends_at=DATE_ADD(starts_at,INTERVAL 15 MINUTE) WHERE ends_at IS NULL');
        if (
            (int) $p
                ->query(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='slots' AND INDEX_NAME='unique_slot'",
                )
                ->fetchColumn()
        ) {
            // Replacement index must exist before dropping the FK-supporting legacy index.
            if (
                !(int) $p
                    ->query(
                        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='slots' AND INDEX_NAME='slot_doctor_time'",
                    )
                    ->fetchColumn()
            ) {
                $p->exec('ALTER TABLE slots ADD INDEX slot_doctor_time(doctor_id,starts_at)');
            }
            $p->exec('ALTER TABLE slots DROP INDEX unique_slot');
        }
        $p->exec(
            "INSERT INTO settings (`key`,value) VALUES('app_version','0.4.0') ON DUPLICATE KEY UPDATE value=VALUES(value)",
        );
        $p->exec('INSERT IGNORE INTO schema_migrations(version) VALUES(4)');
    } finally {
        $p->query("SELECT RELEASE_LOCK('nobatyar_phase4')");
    }
}
function phase4_columns(): array
{
    return [
        ['doctors', 'photo_file', 'VARCHAR(80) NULL'],
        ['doctors', 'professional_title', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['doctors', 'education', "VARCHAR(1000) NOT NULL DEFAULT ''"],
        ['doctors', 'experience_years', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0'],
        ['clinics', 'default_duration', 'SMALLINT UNSIGNED NOT NULL DEFAULT 15'],
        ['clinics', 'gap_minutes', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0'],
        ['clinics', 'daily_capacity', 'SMALLINT UNSIGNED NOT NULL DEFAULT 50'],
        ['slots', 'ends_at', 'DATETIME NULL'],
        ['slots', 'service_id', 'BIGINT UNSIGNED NULL'],
        ['slots', 'service_name', "VARCHAR(190) NOT NULL DEFAULT 'ویزیت'"],
        ['slots', 'gap_minutes', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0'],
        ['slots', 'source', "VARCHAR(20) NOT NULL DEFAULT 'legacy'"],
        ['bookings', 'revision', 'INT UNSIGNED NOT NULL DEFAULT 1'],
        ['staff_scopes', 'can_manage_schedule', 'TINYINT NOT NULL DEFAULT 0'],
        ['staff_scopes', 'can_manage_bookings', 'TINYINT NOT NULL DEFAULT 0'],
        ['staff_scopes', 'can_view_history', 'TINYINT NOT NULL DEFAULT 0'],
    ];
}
