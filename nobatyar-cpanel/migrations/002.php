<?php
declare(strict_types=1);
// Additive, restartable migration. MySQL DDL commits implicitly.
function migrate_phase2(PDO $pdo): void
{
    if ((int) $pdo->query("SELECT GET_LOCK('nobatyar_phase2',10)")->fetchColumn() !== 1) {
        throw new RuntimeException('ارتقای دیگری در حال اجراست.');
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations(version INT PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        if ($pdo->query('SELECT version FROM schema_migrations WHERE version=2')->fetchColumn()) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS provinces(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL UNIQUE,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS specialties(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(190) NOT NULL UNIQUE,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $column = function (string $table, string $name, string $definition) use ($pdo): void {
            $q = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            );
            $q->execute([$table, $name]);
            if (!(int) $q->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
            }
        };
        $column('cities', 'province_id', 'INT UNSIGNED NULL');
        $column('cities', 'active', 'TINYINT NOT NULL DEFAULT 1');
        $column('doctors', 'specialty_id', 'INT UNSIGNED NULL');
        $column('doctors', 'mobile', 'VARCHAR(11) NULL UNIQUE');
        $column('doctors', 'medical_code', 'VARCHAR(30) NULL UNIQUE');
        $column('doctors', 'bio', 'TEXT NULL');
        $column('doctors', 'review_status', "VARCHAR(20) NOT NULL DEFAULT 'approved'");
        $column('doctors', 'review_note', 'TEXT NULL');
        $column('doctors', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS clinics(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,doctor_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,city_id INT UNSIGNED NOT NULL,address TEXT NOT NULL,phone VARCHAR(20) NOT NULL DEFAULT '',active TINYINT NOT NULL DEFAULT 1,is_primary TINYINT NOT NULL DEFAULT 0,legacy_doctor_id BIGINT UNSIGNED NULL UNIQUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX doctor_clinics(doctor_id,active),FOREIGN KEY(doctor_id) REFERENCES doctors(id),FOREIGN KEY(city_id) REFERENCES cities(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );
        $column('slots', 'clinic_id', 'BIGINT UNSIGNED NULL');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS request_limits(bucket VARCHAR(100) PRIMARY KEY,window_start BIGINT UNSIGNED NOT NULL,hits INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $pdo->beginTransaction();
        $pdo->exec('INSERT IGNORE INTO provinces(name) SELECT DISTINCT province FROM cities');
        $pdo->exec(
            'UPDATE cities c JOIN provinces p ON p.name=c.province SET c.province_id=p.id WHERE c.province_id IS NULL',
        );
        $pdo->exec('INSERT IGNORE INTO specialties(name) SELECT DISTINCT specialty FROM doctors');
        $pdo->exec(
            'UPDATE doctors d JOIN specialties s ON s.name=d.specialty SET d.specialty_id=s.id WHERE d.specialty_id IS NULL',
        );
        $pdo->exec(
            "INSERT INTO clinics(doctor_id,name,city_id,address,is_primary,legacy_doctor_id) SELECT d.id,'مطب اصلی',d.city_id,d.clinic_address,1,d.id FROM doctors d LEFT JOIN clinics c ON c.legacy_doctor_id=d.id WHERE c.id IS NULL",
        );
        $pdo->exec(
            'UPDATE slots s JOIN clinics c ON c.legacy_doctor_id=s.doctor_id SET s.clinic_id=c.id WHERE s.clinic_id IS NULL',
        );
        $pdo->commit();
        // Add constraints only once; rerunning after interrupted DDL is safe.
        foreach (
            [
                ['cities', 'fk_city_province', 'province_id', 'provinces'],
                ['doctors', 'fk_doctor_specialty', 'specialty_id', 'specialties'],
                ['slots', 'fk_slot_clinic', 'clinic_id', 'clinics'],
            ]
            as [$table, $key, $field, $target]
        ) {
            $q = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
            );
            $q->execute([$table, $key]);
            if (!(int) $q->fetchColumn()) {
                $pdo->exec(
                    "ALTER TABLE `$table` ADD CONSTRAINT `$key` FOREIGN KEY (`$field`) REFERENCES `$target`(id)",
                );
            }
        }
        $pdo->exec(
            "INSERT INTO settings (`key`,value) VALUES('app_version','0.2.0') ON DUPLICATE KEY UPDATE value=VALUES(value)",
        );
        $pdo->exec('INSERT IGNORE INTO schema_migrations(version) VALUES(2)');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->query("SELECT RELEASE_LOCK('nobatyar_phase2')");
    }
}
