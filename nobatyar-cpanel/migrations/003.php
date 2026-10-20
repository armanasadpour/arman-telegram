<?php
declare(strict_types=1);
function migrate_phase3(PDO $pdo): void
{
    if ((int) $pdo->query("SELECT GET_LOCK('nobatyar_phase3',10)")->fetchColumn() !== 1) {
        throw new RuntimeException('ارتقای دیگری در حال اجراست.');
    }
    try {
        if ($pdo->query('SELECT version FROM schema_migrations WHERE version=3')->fetchColumn()) {
            return;
        }
        foreach (explode(";\n", file_get_contents(__DIR__ . '/003.sql')) as $sql) {
            if (trim($sql) !== '') {
                $pdo->exec($sql);
            }
        }
        $s = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='patient_user_id'",
        );
        if (!(int) $s->fetchColumn()) {
            $pdo->exec('ALTER TABLE bookings ADD COLUMN patient_user_id BIGINT UNSIGNED NULL');
        }
        if (
            !(int) $pdo
                ->query(
                    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND CONSTRAINT_NAME='fk_booking_user'",
                )
                ->fetchColumn()
        ) {
            $pdo->exec(
                'ALTER TABLE bookings ADD CONSTRAINT fk_booking_user FOREIGN KEY(patient_user_id) REFERENCES users(id)',
            );
        }
        $pdo->exec(
            "INSERT INTO settings (`key`,value) VALUES('app_version','0.3.0') ON DUPLICATE KEY UPDATE value=VALUES(value)",
        );
        $pdo->exec('INSERT IGNORE INTO schema_migrations(version) VALUES(3)');
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('nobatyar_phase3')");
    }
}
