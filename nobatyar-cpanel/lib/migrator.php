<?php
declare(strict_types=1);

/**
 * Ordered list of database migrations. Fresh installs and upgrades run the same list,
 * so a new installation and an upgraded one always end with identical schemas.
 * Version 5 was never shipped and is intentionally absent.
 */
function migration_steps(): array
{
    return [
        2 => ['002.php', 'migrate_phase2'],
        3 => ['003.php', 'migrate_phase3'],
        4 => ['004.php', 'migrate_phase4'],
        6 => ['006.php', 'migrate_phase6'],
        7 => ['007.php', 'migrate_phase7'],
        8 => ['008.php', 'migrate_v8'],
        9 => ['009.php', 'migrate_v9'],
        10 => ['010.php', 'migrate_v10'],
        11 => ['011.php', 'migrate_v11'],
        12 => ['012.php', 'migrate_v12'],
        13 => ['013.php', 'migrate_v13'],
        14 => ['014.php', 'migrate_v14'],
        15 => ['015.php', 'migrate_v15'],
        16 => ['016.php', 'migrate_v16'],
        17 => ['017.php', 'migrate_v17'],
        18 => ['018.php', 'migrate_v18'],
        19 => ['019.php', 'migrate_v19'],
        20 => ['020.php', 'migrate_v20'],
        21 => ['021.php', 'migrate_v21'],
        22 => ['022.php', 'migrate_v22'],
        23 => ['023.php', 'migrate_v23'],
        24 => ['024.php', 'migrate_v24'],
        25 => ['025.php', 'migrate_v25'],
        26 => ['026.php', 'migrate_v26'],
        27 => ['027.php', 'migrate_v27'],
        28 => ['028.php', 'migrate_v28'],
        29 => ['029.php', 'migrate_v29'],
    ];
}

function load_migrations(): void
{
    require_once __DIR__ . '/../migrations/helpers.php';
    foreach (migration_steps() as [$file]) {
        require_once __DIR__ . '/../migrations/' . $file;
    }
}

/** Applies every missing step in order and returns the versions that ran. */
function run_migrations(PDO $pdo): array
{
    load_migrations();
    $ran = [];
    foreach (migration_steps() as $version => [, $function]) {
        if (!mig_applied($pdo, $version)) {
            $function($pdo);
            $ran[] = $version;
        }
    }
    return $ran;
}

function schema_version(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function schema_is_current(): bool
{
    static $current = null;
    if ($current === null) {
        $current = schema_version(db()) >= SCHEMA_VERSION;
    }
    return $current;
}

/** Fresh installation: base tables from 001.sql followed by every migration. */
function install_schema(PDO $pdo): void
{
    load_migrations();
    mig_sql_file($pdo, __DIR__ . '/../migrations/001.sql');
    run_migrations($pdo);
}
