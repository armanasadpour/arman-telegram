<?php

/** Old tracking codes survive every upgrade: unchanged before step 19, then kept in legacy_tracking_code. */
function keep_code(PDO $p): bool
{
    $r = $p->query('SELECT * FROM bookings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    return $r['tracking_code'] === 'KEEP-CODE' || (($r['legacy_tracking_code'] ?? null) === 'KEEP-CODE' && preg_match('/^[1-9][0-9]{11}$/', $r['tracking_code']));
}
// CLI only. Creates and drops its own randomly named disposable database.
if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Set NOBATYAR_TEST=1 to run isolated database tests.\n");
}
require __DIR__ . '/../lib/version.php';
require __DIR__ . '/../lib/migrator.php';
load_migrations();
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASSWORD') ?: '';
$p = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$name = 'nobatyar_test_' . bin2hex(random_bytes(5));
$checks = 0;
function check($condition, string $name): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name);
    }
    $checks++;
    echo "PASS $name\n";
}
function schema(PDO $p, string $path): void
{
    foreach (explode(";\n", file_get_contents($path)) as $sql) {
        if (trim($sql) !== '') {
            $p->exec($sql);
        }
    }
}
try {
    $p->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $p->exec("USE `$name`");
    schema($p, __DIR__ . '/../migrations/001.sql');
    $p->exec(
        "INSERT INTO cities(id,province,name) VALUES(1,'هرمزگان','بستک'),(2,'هرمزگان','بندرلنگه'),(3,'فارس','لار')",
    );
    $p->exec(
        "INSERT INTO doctors(id,name,specialty,city_id,clinic_address,subscription_until,active) VALUES(1,'پزشک قدیمی','قلب',1,'آدرس قدیمی', '2099-01-01',1),(2,'پزشک غیرفعال','قلب',2,'آدرس دوم','2020-01-01',0)",
    );
    $p->exec("INSERT INTO slots(id,doctor_id,starts_at,capacity) VALUES(1,1,'2090-01-01 10:00:00',2)");
    $p->exec(
        "INSERT INTO bookings(id,slot_id,patient_name,phone,tracking_code) VALUES(1,1,'بیمار نمونه','09120000001','KEEP-CODE')",
    );
    $p->exec(
        "INSERT INTO subscriptions(doctor_id,starts_on,ends_on,amount) VALUES(1,'2026-01-01','2099-01-01',350000)",
    );
    $p->exec(
        "INSERT INTO payments(booking_id,amount,status,reference) VALUES(1,10000,'manual','KEEP-PAYMENT')",
    );
    $p->exec("INSERT INTO settings (`key`,value) VALUES('booking_fee','10000'),('booking_fee_enabled','0')");
    $before = [];
    foreach (['bookings', 'payments', 'subscriptions'] as $t) {
        $before[$t] = $p->query("SELECT * FROM $t")->fetchAll();
    }
    migrate_phase2($p);
    check(
        (int) $p->query('SELECT COUNT(*) FROM provinces')->fetchColumn() === 2,
        'distinct provinces migrated',
    );
    check(
        (int) $p->query('SELECT COUNT(*) FROM specialties')->fetchColumn() === 1,
        'specialties deduplicated',
    );
    check(
        (int) $p->query('SELECT COUNT(*) FROM clinics')->fetchColumn() === 2,
        'one legacy clinic per doctor',
    );
    check(
        $p->query('SELECT address FROM clinics WHERE doctor_id=1')->fetchColumn() === 'آدرس قدیمی',
        'legacy address preserved',
    );
    check((int) $p->query('SELECT clinic_id FROM slots WHERE id=1')->fetchColumn() > 0, 'legacy slot linked');
    foreach ($before as $t => $rows) {
        check($p->query("SELECT * FROM $t")->fetchAll() === $rows, "$t unchanged");
    }
    check(
        $p->query("SELECT value FROM settings WHERE `key`='booking_fee'")->fetchColumn() === '10000',
        'fee preserved',
    );
    check(
        (int) $p->query('SELECT active FROM doctors WHERE id=2')->fetchColumn() === 0,
        'inactive doctor preserved',
    );
    check(
        $p->query('SELECT mobile FROM doctors WHERE id=1')->fetchColumn() === null,
        'missing mobile not fabricated',
    );
    migrate_phase2($p);
    check(
        (int) $p->query('SELECT COUNT(*) FROM clinics')->fetchColumn() === 2,
        'second migration idempotent',
    );
    $p->exec('DELETE FROM schema_migrations');
    migrate_phase2($p);
    check(
        (int) $p->query('SELECT COUNT(*) FROM clinics')->fetchColumn() === 2,
        'resume after DDL idempotent',
    );
    try {
        $p->exec('DELETE FROM provinces WHERE id=1');
        check(false, 'foreign key');
    } catch (PDOException $e) {
        check($e->getCode() === '23000', 'referenced province cannot delete');
    }
    migrate_phase3($p);
    migrate_phase3($p);
    check(
        (int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=3')->fetchColumn() === 1,
        'phase3 repeatable upgrade',
    );
    check(
        $p->query('SELECT patient_user_id FROM bookings WHERE id=1')->fetchColumn() === null,
        'legacy booking not claimed by phone',
    );
    foreach ($before as $t => $rows) {
        $actual = $p->query("SELECT * FROM $t")->fetchAll();
        if ($t === 'bookings') {
            foreach ($actual as &$row) {
                unset($row['patient_user_id']);
            };
        }
        unset($row);
        check($actual === $rows, "phase3 $t unchanged");
    }
    migrate_phase4($p);
    migrate_phase4($p);
    check(
        (int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=4')->fetchColumn() === 1,
        'phase4 repeatable upgrade',
    );
    check(
        keep_code($p),
        'phase4 booking retained',
    );
    check(
        $p->query('SELECT ends_at FROM slots WHERE id=1')->fetchColumn() === '2090-01-01 10:15:00',
        'legacy duration fallback',
    );
    foreach (['payments', 'subscriptions'] as $t) {
        check($p->query("SELECT * FROM $t")->fetchAll() === $before[$t], "phase4 $t preserved");
    }
    migrate_phase6($p);
    migrate_phase6($p);
    check(
        (int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=6')->fetchColumn() === 1,
        'phase6 repeatable',
    );
    check(
        keep_code($p),
        'phase6 tracking preserved',
    );
    check(
        (int) $p->query('SELECT slot_capacity FROM clinics LIMIT 1')->fetchColumn() === 1,
        'default single capacity',
    );
    $p->exec("UPDATE bookings SET status='held',fee_toman=1200,expires_at='2090-01-01 09:00:00' WHERE id=1");
    $v6booking = $p->query('SELECT * FROM bookings WHERE id=1')->fetch();
    migrate_phase7($p);
    migrate_phase7($p);
    $v7booking = $p->query('SELECT * FROM bookings WHERE id=1')->fetch();
    check(
        array_intersect_key($v7booking, $v6booking) === $v6booking,
        'phase6 held booking and fee preserved',
    );
    check(
        (int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=7')->fetchColumn() === 1,
        'reception upgrade repeatable',
    );
    check(
        keep_code($p),
        'reception legacy tracking preserved',
    );
    check(
        $p->query("SELECT value FROM settings WHERE `key`='booking_fee'")->fetchColumn() === '10000',
        'reception preserves old fee',
    );
    // 0.7.0: geography import keeps existing cities, SMS secrets move from the plaintext file into encrypted settings.
    $smsFile = __DIR__ . '/../private/sms.php';
    file_put_contents(
        $smsFile,
        '<?php return ' .
            var_export(['enabled' => true, 'key' => 'ABCDEF0123456789ABCDEF', 'template' => 'oldOtp', 'daily_limit' => 321], true) .
            ';',
    );
    $p->exec("INSERT INTO settings (`key`,value) VALUES('delay_sms_template','oldDelay') ON DUPLICATE KEY UPDATE value='oldDelay'");
    $citiesBefore = $p->query('SELECT id,name,province_id FROM cities ORDER BY id')->fetchAll();
    migrate_v8($p);
    migrate_v8($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=8')->fetchColumn() === 1, 'v8 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM provinces')->fetchColumn() === 31, 'all 31 provinces present without duplicates');
    check((int) $p->query('SELECT COUNT(*) FROM cities')->fetchColumn() >= 1250, 'full city list imported');
    $citiesAfter = $p->query('SELECT id,name,province_id FROM cities WHERE id IN (1,2,3) ORDER BY id')->fetchAll();
    check($citiesAfter === $citiesBefore, 'existing city ids, names and provinces unchanged');
    check($p->query('SELECT latitude FROM cities WHERE id=1')->fetchColumn() !== null, 'existing city gained coordinates');
    check(
        (int) $p->query("SELECT COUNT(*) FROM cities WHERE name='بستک'")->fetchColumn() === 1,
        'matching city not duplicated',
    );
    check(!is_file($smsFile), 'plaintext SMS file removed');
    $stored = $p->query("SELECT value FROM settings WHERE `key`='secret.kavenegar_api_key'")->fetchColumn();
    check(
        $stored !== 'ABCDEF0123456789ABCDEF' && decrypt_secret($stored) === 'ABCDEF0123456789ABCDEF',
        'API key encrypted at rest and readable',
    );
    check(
        $p->query("SELECT template FROM sms_templates WHERE event='login'")->fetchColumn() === 'oldOtp' &&
            $p->query("SELECT template FROM sms_templates WHERE event='delay'")->fetchColumn() === 'oldDelay',
        'existing SMS templates carried over',
    );
    check($p->query("SELECT value FROM settings WHERE `key`='sms_daily_limit'")->fetchColumn() === '321', 'daily limit kept');
    check(
        keep_code($p),
        'v8 keeps legacy booking',
    );
    // 0.8.0: subscription tables are additive; existing subscription rows keep their values.
    $subsBefore = $p->query('SELECT id,doctor_id,starts_on,ends_on,amount,payment_status FROM subscriptions ORDER BY id')->fetchAll();
    migrate_v9($p);
    migrate_v9($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=9')->fetchColumn() === 1, 'v9 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn() === 4, 'four plans seeded once');
    check((int) $p->query('SELECT COUNT(*) FROM subscription_plans WHERE active=1 OR price_toman>0')->fetchColumn() === 0, 'seeded plans inactive and unpriced');
    check(
        $p->query('SELECT id,doctor_id,starts_on,ends_on,amount,payment_status FROM subscriptions ORDER BY id')->fetchAll() === $subsBefore,
        'existing subscription history unchanged',
    );
    check($p->query("SELECT kind FROM subscriptions WHERE id=1")->fetchColumn() === 'manual', 'legacy period marked manual');
    // 0.9.0: Zarinpal settings move into the encrypted gateway registry but stay disabled until verified.
    $payFile = __DIR__ . '/../private/payment.php';
    file_put_contents($payFile, "<?php return ['enabled'=>true,'merchant_id'=>'11111111-2222-3333-4444-555555555555','callback_url'=>'https://x.test/payment-callback.php'];");
    $p->exec("INSERT IGNORE INTO payment_intents(booking_id,authority,merchant_id,amount_toman,status) VALUES(1,'A" . str_repeat('7', 35) . "','m',1200,'verified')");
    $intentsBefore = $p->query('SELECT * FROM payment_intents ORDER BY id')->fetchAll();
    migrate_v10($p);
    migrate_v10($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=10')->fetchColumn() === 1, 'v10 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM payment_gateways')->fetchColumn() === 5, 'five gateways registered once');
    check(!is_file($payFile), 'plaintext payment file removed');
    $zp = $p->query("SELECT * FROM payment_gateways WHERE name='zarinpal'")->fetch();
    $cfg = json_decode($zp['config'], true);
    check(
        !str_contains($zp['config'], '11111111-2222') && decrypt_secret($cfg['merchant_id']) === '11111111-2222-3333-4444-555555555555',
        'merchant id encrypted at rest',
    );
    check((int) $zp['enabled'] === 0 && $zp['verified_at'] === null, 'imported gateway disabled until a real test payment');
    check(
        (int) $p->query("SELECT COUNT(*) FROM payment_gateways WHERE enabled=1")->fetchColumn() === 0,
        'no gateway active after upgrade',
    );
    check($p->query('SELECT * FROM payment_intents ORDER BY id')->fetchAll() === $intentsBefore, 'legacy payment intents unchanged');
    check(
        (int) $p->query("SELECT discount_toman FROM bookings WHERE id=1")->fetchColumn() === 0 &&
            keep_code($p),
        'bookings keep data with new discount columns',
    );
    check($p->query("SELECT value FROM settings WHERE `key`='booking_fee_refund_on_cancel'")->fetchColumn() === '1', 'refund policy default');
    // 0.10.0: centers are additive; existing clinics stay personal practices and plans stay doctor plans.
    $clinicsBefore = $p->query('SELECT id,doctor_id,name,address,active FROM clinics ORDER BY id')->fetchAll();
    $plansBefore = (int) $p->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn();
    migrate_v11($p);
    migrate_v11($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=11')->fetchColumn() === 1, 'v11 repeatable');
    check($p->query('SELECT id,doctor_id,name,address,active FROM clinics ORDER BY id')->fetchAll() === $clinicsBefore, 'existing clinics unchanged');
    check((int) $p->query('SELECT COUNT(*) FROM clinics WHERE center_id IS NOT NULL OR branch_id IS NOT NULL')->fetchColumn() === 0, 'existing clinics remain personal practices');
    check((int) $p->query("SELECT COUNT(*) FROM subscription_plans WHERE audience='doctor'")->fetchColumn() === $plansBefore, 'existing plans marked for doctors');
    check((int) $p->query("SELECT COUNT(*) FROM subscription_plans WHERE audience='center' AND active=0 AND price_toman=0")->fetchColumn() === 2, 'center plans seeded inactive and unpriced once');
    check((int) $p->query('SELECT COUNT(*) FROM staff_scopes WHERE center_id IS NOT NULL')->fetchColumn() === 0, 'existing secretary scopes untouched');
    // 0.11.0: notifications keep their rows; one VAPID key pair is generated and stored encrypted.
    $notesBefore = (int) $p->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    migrate_v12($p);
    $public = $p->query("SELECT value FROM settings WHERE `key`='push_vapid_public'")->fetchColumn();
    migrate_v12($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=12')->fetchColumn() === 1, 'v12 repeatable');
    check($p->query("SELECT value FROM settings WHERE `key`='push_vapid_public'")->fetchColumn() === $public && strlen((string) $public) === 87, 'VAPID public key generated once');
    $private = (string) $p->query("SELECT value FROM settings WHERE `key`='secret.push_vapid_private'")->fetchColumn();
    check(str_contains($private, ':') && strlen(base64_decode(strtr(decrypt_secret($private), '-_', '+/') . '=')) === 32, 'VAPID private key stored encrypted');
    check((int) $p->query("SELECT COUNT(*) FROM notifications WHERE category='general'")->fetchColumn() === $notesBefore, 'existing notifications kept as general');
    check((int) $p->query('SELECT COUNT(*) FROM bookings WHERE queue_number IS NOT NULL OR estimated_at IS NOT NULL')->fetchColumn() === 0, 'bookings gain empty queue fields');
    check($p->query("SELECT value FROM settings WHERE `key`='reminder_hours'")->fetchColumn() === '24,2', 'reminder default 24h and 2h');
    // 0.12.0: existing administrators become active owners; their password and audit history stay.
    $adminsBefore = $p->query('SELECT id,email,password_hash FROM admins ORDER BY id')->fetchAll();
    $auditBefore = (int) $p->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    migrate_v13($p);
    migrate_v13($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=13')->fetchColumn() === 1, 'v13 repeatable');
    check($p->query('SELECT id,email,password_hash FROM admins ORDER BY id')->fetchAll() === $adminsBefore, 'administrator credentials unchanged');
    check((int) $p->query("SELECT COUNT(*) FROM admins WHERE role<>'owner' OR active<>1")->fetchColumn() === 0, 'existing administrators become active owners');
    check((int) $p->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $auditBefore, 'activity log kept');
    check($p->query("SELECT value FROM settings WHERE `key`='site_name'")->fetchColumn() === 'نوبت‌یار', 'site name default');
    check((int) $p->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn() === 0 && (int) $p->query('SELECT COUNT(*) FROM site_banners')->fetchColumn() === 0, 'tickets and banners start empty');
    // 1.0.0: backup settings only.
    migrate_v14($p);
    migrate_v14($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=14')->fetchColumn() === 1, 'v14 repeatable');
    check($p->query("SELECT value FROM settings WHERE `key`='backup_auto'")->fetchColumn() === '0', 'automatic backup off by default');
    // 1.0.0-rc.2: homepage content tables; earlier hero text moves into the layout.
    $p->exec("INSERT INTO settings(`key`,value) VALUES('home_hero_title','عنوان قدیمی') ON DUPLICATE KEY UPDATE value=VALUES(value)");
    $doctorsBefore = $p->query('SELECT id,name,review_status FROM doctors ORDER BY id')->fetchAll();
    migrate_v15($p);
    migrate_v15($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=15')->fetchColumn() === 1, 'v15 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === 6, 'FAQ seeded once');
    check((int) $p->query("SELECT COUNT(*) FROM site_pages WHERE slug IN ('terms','privacy') AND published=0")->fetchColumn() === 2, 'terms and privacy start unpublished');
    check((int) $p->query('SELECT COUNT(*) FROM articles')->fetchColumn() === 0, 'no sample articles');
    check($p->query('SELECT id,name,review_status FROM doctors ORDER BY id')->fetchAll() === $doctorsBefore && (int) $p->query('SELECT COUNT(*) FROM doctors WHERE featured=1')->fetchColumn() === 0, 'doctors unchanged and not featured');
    check(str_contains((string) $p->query("SELECT value FROM settings WHERE `key`='home_layout'")->fetchColumn(), 'عنوان قدیمی'), 'old hero title kept in the layout');
    // 1.0.0-rc.3: first/last name split from the stored full name; nameless accounts stay nameless.
    $p->exec("INSERT INTO users(mobile,name) VALUES('09129990001','زهرا  احمدی نیک'),('09129990002','')");
    migrate_v16($p);
    migrate_v16($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=16')->fetchColumn() === 1, 'v16 repeatable');
    $split = $p->query("SELECT name,first_name,last_name FROM users WHERE mobile='09129990001'")->fetch();
    check($split['name'] === 'زهرا  احمدی نیک' && $split['first_name'] === 'زهرا' && $split['last_name'] === 'احمدی نیک', 'full name kept and split into first and last name');
    check($p->query("SELECT CONCAT(first_name,last_name) FROM users WHERE mobile='09129990002'")->fetchColumn() === '', 'nameless account not given a placeholder name');
    check($p->query("SELECT value FROM settings WHERE `key`='drawer_banner'")->fetchColumn() === '{}', 'menu banner off by default');
    // 1.0.0-rc.4: search cache and reviews start empty; gender and district start unknown.
    migrate_v17($p);
    migrate_v17($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=17')->fetchColumn() === 1, 'v17 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM reviews')->fetchColumn() === 0 && (int) $p->query('SELECT COUNT(*) FROM clinic_free_cache')->fetchColumn() === 0, 'no sample reviews or cached times');
    check((int) $p->query('SELECT COUNT(*) FROM doctors WHERE gender IS NOT NULL OR badge_text<>\'\'')->fetchColumn() === 0 && (int) $p->query("SELECT COUNT(*) FROM clinics WHERE district<>''")->fetchColumn() === 0, 'gender, badges and districts not guessed');
    // 1.0.0-rc.5: patient details and clinic notes start empty; no national ID collected by default.
    migrate_v18($p);
    migrate_v18($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=18')->fetchColumn() === 1, 'v18 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM users WHERE national_id_enc IS NOT NULL OR birth_year IS NOT NULL')->fetchColumn() === 0, 'no patient details invented');
    check($p->query("SELECT value FROM settings WHERE `key`='patient_id_mode'")->fetchColumn() === 'off', 'national ID not collected unless the administrator enables it');
    // 1.0.0-rc.7: tracking codes become unique 12-digit numbers; the old code is kept.
    $before = (int) $p->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    migrate_v19($p);
    $codes = $p->query('SELECT tracking_code FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
    migrate_v19($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=19')->fetchColumn() === 1 && $p->query('SELECT tracking_code FROM bookings')->fetchAll(PDO::FETCH_COLUMN) === $codes, 'v19 repeatable, numbers stay the same');
    check(count($codes) === $before && count(array_unique($codes)) === count($codes) && !array_filter($codes, fn($c) => !preg_match('/^[1-9][0-9]{11}$/', $c)), 'every booking gets a unique 12-digit number');
    check(keep_code($p) && (int) $p->query('SELECT COUNT(*) FROM bookings WHERE legacy_tracking_code IS NULL')->fetchColumn() === 0, 'old codes kept in legacy_tracking_code');
    // 1.0.0-rc.11: birth date, insurance and secretary patient details start empty; national ID now asked.
    $p->exec("UPDATE users SET birth_year=1365 WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM users) x)");
    migrate_v20($p);
    migrate_v20($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=20')->fetchColumn() === 1, 'v20 repeatable');
    check((int) $p->query('SELECT COUNT(*) FROM users WHERE birth_date IS NOT NULL OR insurance IS NOT NULL')->fetchColumn() === 0 && (int) $p->query('SELECT COUNT(*) FROM family_members WHERE insurance IS NOT NULL')->fetchColumn() === 0, 'no birth date or insurance invented (a birth year alone is not turned into a date)');
    check((int) $p->query('SELECT COUNT(*) FROM users WHERE birth_year=1365')->fetchColumn() === 1, 'existing birth year kept');
    check($p->query("SELECT value FROM settings WHERE `key`='patient_id_mode'")->fetchColumn() === 'required', 'national ID asked of every patient after the upgrade');
    // 1.0.0-rc.13: administrators get a unique mobile column, empty until each one registers a number.
    migrate_v21($p);
    migrate_v21($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=21')->fetchColumn() === 1 && (int) $p->query('SELECT COUNT(*) FROM admins WHERE mobile IS NOT NULL')->fetchColumn() === 0, 'v21 repeatable; no administrator mobile invented');
    // 1.0.0-rc.17: VAT columns (0 on existing rows) and subscription sales kept on after the upgrade.
    migrate_v22($p);
    migrate_v22($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=22')->fetchColumn() === 1 && (int) $p->query('SELECT COUNT(*) FROM bookings WHERE vat_toman<>0 OR vat_percent<>0')->fetchColumn() === 0, 'v22 repeatable; no VAT on existing bookings');
    check($p->query("SELECT value FROM settings WHERE `key`='subscriptions_enabled'")->fetchColumn() === '1', 'subscription sales stay on after the upgrade');
    // 1.0.0-rc.20: free plan + Pro — new columns with safe defaults; nothing is locked by the upgrade itself.
    migrate_v23($p);
    migrate_v23($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=23')->fetchColumn() === 1 && (int) $p->query('SELECT COUNT(*) FROM clinics WHERE plan_locked<>0')->fetchColumn() === 0 && (int) $p->query("SELECT COUNT(*) FROM doctors WHERE plan_choice<>'free'")->fetchColumn() === 0, 'v23 repeatable; existing doctors start on the free plan with nothing locked');
    // 1.0.0-rc.21: deletion and suspension columns; nobody is marked deleted or suspended by the upgrade.
    migrate_v24($p);
    migrate_v24($p);
    check((int) $p->query('SELECT COUNT(*) FROM schema_migrations WHERE version=24')->fetchColumn() === 1 && (int) $p->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL OR suspended_reason IS NOT NULL')->fetchColumn() === 0 && (int) $p->query('SELECT COUNT(*) FROM doctors WHERE deleted_at IS NOT NULL')->fetchColumn() === 0, 'v24 repeatable; nobody deleted or suspended by the upgrade');
    $upgraded = $p
        ->query(
            'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        )
        ->fetchAll();
    $p->exec("DROP DATABASE `$name`");
    $p->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $p->exec("USE `$name`");
    install_schema($p);
    $fresh = $p
        ->query(
            'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        )
        ->fetchAll();
    check($fresh === $upgraded, 'fresh and upgraded column definitions identical');
    check(
        (int) $p->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === SCHEMA_VERSION,
        'fresh schema version',
    );
    echo "SUCCESS $checks checks\n";
} finally {
    $p->exec("DROP DATABASE IF EXISTS `$name`");
}
