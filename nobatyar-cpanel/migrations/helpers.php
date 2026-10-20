<?php
declare(strict_types=1);

/**
 * Small, restartable DDL helpers shared by every migration.
 * MySQL commits DDL implicitly, so each helper checks information_schema first and can be re-run safely.
 */

function mig_lock(PDO $p, string $name): void
{
    if ((int) $p->query("SELECT GET_LOCK('nobatyar_$name',10)")->fetchColumn() !== 1) {
        throw new RuntimeException('ارتقای دیگری در حال اجراست.');
    }
}

function mig_unlock(PDO $p, string $name): void
{
    $p->query("SELECT RELEASE_LOCK('nobatyar_$name')");
}

function mig_applied(PDO $p, int $version): bool
{
    $p->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations(version INT PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    );
    $q = $p->prepare('SELECT version FROM schema_migrations WHERE version=?');
    $q->execute([$version]);
    return (bool) $q->fetchColumn();
}

function mig_mark(PDO $p, int $version, string $appVersion): void
{
    $p->exec(
        "INSERT INTO settings (`key`,value) VALUES('app_version'," .
            $p->quote($appVersion) .
            ') ON DUPLICATE KEY UPDATE value=VALUES(value)',
    );
    $q = $p->prepare('INSERT IGNORE INTO schema_migrations(version) VALUES(?)');
    $q->execute([$version]);
}

function mig_has_column(PDO $p, string $table, string $column): bool
{
    $q = $p->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
    );
    $q->execute([$table, $column]);
    return (int) $q->fetchColumn() > 0;
}

function mig_column(PDO $p, string $table, string $column, string $definition): void
{
    if (!mig_has_column($p, $table, $column)) {
        $p->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function mig_has_index(PDO $p, string $table, string $index): bool
{
    $q = $p->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
    );
    $q->execute([$table, $index]);
    return (int) $q->fetchColumn() > 0;
}

function mig_index(PDO $p, string $table, string $index, string $definition): void
{
    if (!mig_has_index($p, $table, $index)) {
        $p->exec("ALTER TABLE `$table` ADD $definition");
    }
}

function mig_foreign_key(PDO $p, string $table, string $name, string $definition): void
{
    $q = $p->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
    );
    $q->execute([$table, $name]);
    if (!(int) $q->fetchColumn()) {
        $p->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    }
}

/** Runs a file of CREATE TABLE IF NOT EXISTS / INSERT IGNORE statements separated by ";\n". */
function mig_sql_file(PDO $p, string $path): void
{
    foreach (explode(";\n", (string) file_get_contents($path)) as $sql) {
        if (trim($sql) !== '') {
            $p->exec($sql);
        }
    }
}

function mig_setting_default(PDO $p, string $key, string $value): void
{
    $q = $p->prepare('INSERT IGNORE INTO settings (`key`,value) VALUES(?,?)');
    $q->execute([$key, $value]);
}
