<?php
declare(strict_types=1);

/**
 * 0.7.0 — completes phase 2 (geography, specialties, doctor status, clinic location)
 * and phase 3 (encrypted SMS credentials, configurable OTP, SMS log and templates).
 * Additive and restartable: existing rows are never deleted or overwritten.
 */

require_once __DIR__ . '/../lib/crypto.php';

function v8_columns(): array
{
    return [
        ['provinces', 'sort_order', 'INT NOT NULL DEFAULT 0'],
        ['provinces', 'phone_code', 'VARCHAR(4) NULL'],
        ['cities', 'sort_order', 'INT NOT NULL DEFAULT 0'],
        ['cities', 'latitude', 'DECIMAL(9,6) NULL'],
        ['cities', 'longitude', 'DECIMAL(9,6) NULL'],
        ['cities', 'is_capital', 'TINYINT NOT NULL DEFAULT 0'],
        ['specialties', 'level', "VARCHAR(20) NOT NULL DEFAULT 'specialty'"],
        ['specialties', 'parent_id', 'INT UNSIGNED NULL'],
        ['specialties', 'sort_order', 'INT NOT NULL DEFAULT 0'],
        ['specialties', 'icon', 'VARCHAR(40) NULL'],
        ['specialties', 'image_file', 'VARCHAR(80) NULL'],
        ['specialties', 'description', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['specialties', 'deleted_at', 'DATETIME NULL'],
        ['clinics', 'latitude', 'DECIMAL(9,6) NULL'],
        ['clinics', 'longitude', 'DECIMAL(9,6) NULL'],
        ['doctors', 'status_changed_at', 'DATETIME NULL'],
        ['users', 'city_id', 'INT UNSIGNED NULL'],
        ['otp_challenges', 'ip_hash', 'CHAR(64) NULL'],
        ['otp_challenges', 'verified_at', 'BIGINT UNSIGNED NULL'],
    ];
}

function migrate_v8(PDO $p): void
{
    mig_lock($p, 'v8');
    try {
        if (mig_applied($p, 8)) {
            return;
        }
        ensure_app_key();
        mig_sql_file($p, __DIR__ . '/008.sql');
        foreach (v8_columns() as [$table, $column, $definition]) {
            mig_column($p, $table, $column, $definition);
        }
        mig_index($p, 'otp_challenges', 'otp_ip', 'INDEX otp_ip(ip_hash,created_at)');
        mig_index($p, 'cities', 'city_order', 'INDEX city_order(province_id,active,sort_order)');
        mig_index($p, 'specialties', 'specialty_order', 'INDEX specialty_order(active,sort_order)');
        mig_foreign_key($p, 'specialties', 'fk_specialty_parent', 'FOREIGN KEY (parent_id) REFERENCES specialties(id)');
        mig_foreign_key($p, 'users', 'fk_user_city', 'FOREIGN KEY (city_id) REFERENCES cities(id)');
        foreach (
            [
                'otp_ttl_seconds' => '180',
                'otp_resend_seconds' => '60',
                'otp_max_attempts' => '5',
                'otp_phone_hourly' => '5',
                'otp_ip_15min' => '10',
                'otp_ip_distinct_hourly' => '5',
                'sms_enabled' => '0',
                'sms_daily_limit' => '500',
                'sms_max_attempts' => '3',
                'sms_dev_mode' => '0',
                'nearby_radius_km' => '60',
                'map_tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                'map_attribution' => '© OpenStreetMap',
                'subscription_warning_days' => '7',
            ]
            as $key => $value
        ) {
            mig_setting_default($p, $key, $value);
        }
        v8_import_geography($p);
        v8_import_sms_config($p);
        mig_mark($p, 8, '0.7.0');
    } finally {
        mig_unlock($p, 'v8');
    }
}

/** Adds the official province/city list; names that already exist are kept and only gain missing coordinates. */
function v8_import_geography(PDO $p): void
{
    $data = json_decode((string) file_get_contents(__DIR__ . '/data/iran-geo.json'), true);
    if (!is_array($data['provinces'] ?? null)) {
        throw new RuntimeException('فایل داده استان‌ها و شهرها خراب است.');
    }
    $provinces = $data['provinces'];
    $collator = class_exists('Collator') ? new Collator('fa_IR') : null;
    usort($provinces, fn($a, $b) => $collator ? $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));
    $insertProvince = $p->prepare('INSERT IGNORE INTO provinces(name) VALUES(?)');
    $provinceRow = $p->prepare('SELECT id,sort_order FROM provinces WHERE name=?');
    $updateProvince = $p->prepare(
        'UPDATE provinces SET phone_code=COALESCE(phone_code,?),sort_order=IF(sort_order=0,?,sort_order) WHERE id=?',
    );
    $insertCity = $p->prepare(
        'INSERT IGNORE INTO cities(province,name,province_id,latitude,longitude,is_capital,sort_order) VALUES(?,?,?,?,?,?,?)',
    );
    $updateCity = $p->prepare(
        'UPDATE cities SET latitude=COALESCE(latitude,?),longitude=COALESCE(longitude,?),is_capital=IF(is_capital=0,?,is_capital),province_id=COALESCE(province_id,?),sort_order=IF(sort_order=0,?,sort_order) WHERE province=? AND name=?',
    );
    $p->beginTransaction();
    try {
        foreach ($provinces as $index => $province) {
            $insertProvince->execute([$province['name']]);
            $provinceRow->execute([$province['name']]);
            $row = $provinceRow->fetch(PDO::FETCH_ASSOC);
            $updateProvince->execute([$province['phone'] ?: null, ($index + 1) * 10, $row['id']]);
            $cities = $province['cities'];
            usort($cities, function ($a, $b) use ($collator) {
                if ($a[1] !== $b[1]) {
                    return $b[1] <=> $a[1]; // provincial capital first
                }
                return $collator ? $collator->compare($a[0], $b[0]) : strcmp($a[0], $b[0]);
            });
            foreach ($cities as $order => [$name, $capital, $lat, $lng]) {
                $insertCity->execute([$province['name'], $name, $row['id'], $lat, $lng, $capital, ($order + 1) * 10]);
                $updateCity->execute([$lat, $lng, $capital, $row['id'], ($order + 1) * 10, $province['name'], $name]);
            }
        }
        // Entries created by the administrator before 0.7.0 and absent from the official list go last.
        $p->exec('UPDATE provinces SET sort_order=100000+id WHERE sort_order=0');
        $p->exec('UPDATE cities SET sort_order=100000+id WHERE sort_order=0');
        $p->commit();
    } catch (Throwable $e) {
        $p->rollBack();
        throw $e;
    }
}

/**
 * Moves the plaintext private/sms.php of 0.3–0.6 into encrypted settings and deletes the file.
 * The previous OTP template becomes the "login" template and the delay template keeps working.
 */
function v8_import_sms_config(PDO $p): void
{
    $template = $p->prepare(
        'INSERT INTO sms_templates(event,template,enabled) VALUES(?,?,?) ON DUPLICATE KEY UPDATE template=IF(template=\'\',VALUES(template),template)',
    );
    $file = dirname(__DIR__) . '/private/sms.php';
    if (is_file($file)) {
        $old = require $file;
        if (is_array($old)) {
            $set = $p->prepare('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            if (!empty($old['key'])) {
                $set->execute(['secret.kavenegar_api_key', encrypt_secret((string) $old['key'])]);
            }
            $set->execute(['sms_enabled', !empty($old['enabled']) ? '1' : '0']);
            $set->execute(['sms_daily_limit', (string) max(1, (int) ($old['daily_limit'] ?? 500))]);
            if (!empty($old['template'])) {
                $template->execute(['login', (string) $old['template'], 1]);
            }
        }
        if (!@unlink($file)) {
            // Credentials are now encrypted in the database; an undeletable file is reported on the SMS page.
            error_log('nobatyar: private/sms.php could not be removed after migration');
        }
    }
    $delay = $p->query("SELECT value FROM settings WHERE `key`='delay_sms_template'")->fetchColumn();
    if (is_string($delay) && $delay !== '') {
        $template->execute(['delay', $delay, 1]);
    }
}
