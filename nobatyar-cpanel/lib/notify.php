<?php
declare(strict_types=1);

/**
 * Notification center for every role: in-app notifications (always kept), Web Push (opt-in with recorded
 * consent) and the user's delivery preferences per category. Transactional SMS for a booking's own
 * confirmation, cancellation or move are always sent; reminders, delay notices, waitlist offers and
 * center announcements follow the user's SMS preferences.
 */

function notification_categories(): array
{
    return [
        'booking' => 'تأیید، لغو و جابه‌جایی نوبت',
        'reminder' => 'یادآوری پیش از نوبت',
        'delay' => 'تأخیر پزشک و تغییر زمان تقریبی',
        'waitlist' => 'آزادشدن ظرفیت در صف انتظار',
        'announcement' => 'اطلاعیه مراکز درمانی',
        'general' => 'حساب، اشتراک و پیام‌های سامانه',
    ];
}

/** Categories whose SMS the user can switch off (others are transactional or have no SMS). */
function sms_optional_categories(): array
{
    return ['reminder', 'delay', 'waitlist', 'announcement'];
}

function notification_preferences(int $user): array
{
    $row = query('SELECT * FROM notification_preferences WHERE user_id=?', [$user])->fetch();
    $defaults = ['push_consent_at' => null, 'sms_consent_at' => null];
    foreach (notification_categories() as $cat => $label) {
        $defaults['push_' . $cat] = 1;
        if (in_array($cat, sms_optional_categories(), true)) {
            $defaults['sms_' . $cat] = 1;
        }
    }
    return $row ? array_intersect_key($row, $defaults) + $defaults : $defaults;
}

function save_notification_preferences(int $user, array $data): void
{
    $cols = [];
    foreach (notification_categories() as $cat => $label) {
        if (isset(notification_categories()[$cat])) {
            $cols['push_' . $cat] = !empty($data['push_' . $cat]) ? 1 : 0;
        }
        if (in_array($cat, sms_optional_categories(), true)) {
            $cols['sms_' . $cat] = !empty($data['sms_' . $cat]) ? 1 : 0;
        }
    }
    // Consent is recorded (or withdrawn) when the user confirms the SMS choices explicitly.
    $sms = array_sum(array_intersect_key($cols, array_flip(array_map(fn($c) => 'sms_' . $c, sms_optional_categories())))) > 0;
    query(
        'INSERT INTO notification_preferences(user_id,' . implode(',', array_keys($cols)) . ',sms_consent_at) VALUES(?' . str_repeat(',?', count($cols)) . ',' . ($sms ? 'NOW()' : 'NULL') . ') ON DUPLICATE KEY UPDATE ' .
            implode(',', array_map(fn($c) => "$c=VALUES($c)", array_keys($cols))) . ',sms_consent_at=' . ($sms ? 'COALESCE(sms_consent_at,NOW())' : 'NULL'),
        [$user, ...array_values($cols)],
    );
    audit('notify.preferences', $user);
}

/** Whether an optional SMS may go to this phone (users without an account keep the default). */
function sms_allowed_for(?int $user, string $category): bool
{
    if (!$user || !in_array($category, sms_optional_categories(), true)) {
        return true;
    }
    return (bool) notification_preferences($user)['sms_' . $category];
}

/** Creates an in-app notification and queues Web Push for the user's subscribed browsers. */
function notify_user(int $user, string $title, string $body, ?int $booking = null, string $category = 'general', string $url = ''): int
{
    if (!isset(notification_categories()[$category])) {
        $category = 'general';
    }
    query('INSERT INTO notifications(user_id,booking_id,title,body,category,url) VALUES(?,?,?,?,?,?)', [
        $user,
        $booking,
        mb_substr($title, 0, 190),
        $body,
        $category,
        $url !== '' ? mb_substr($url, 0, 255) : null,
    ]);
    $id = (int) db()->lastInsertId();
    push_enqueue($user, $id, $title, $body, $category, $url);
    return $id;
}

function push_available(): bool
{
    return setting('push_enabled', '1') === '1' && function_exists('openssl_pkey_derive') && setting('push_vapid_public', '') !== '';
}

function push_enqueue(int $user, int $notification, string $title, string $body, string $category, string $url): int
{
    if (!push_available()) {
        return 0;
    }
    $prefs = notification_preferences($user);
    if (!$prefs['push_consent_at'] || empty($prefs['push_' . $category])) {
        return 0;
    }
    $subs = query('SELECT id FROM push_subscriptions WHERE user_id=? AND active=1', [$user])->fetchAll(PDO::FETCH_COLUMN);
    $payload = json_encode([
        'title' => mb_substr($title, 0, 80),
        'body' => mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 160),
        'url' => $url !== '' ? $url : 'notifications.php',
        'tag' => 'n' . $notification,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($subs as $sid) {
        query("INSERT INTO push_messages(subscription_id,notification_id,payload,status,next_attempt_at) VALUES(?,?,?,'queued',NOW())", [$sid, $notification, $payload]);
    }
    if ($subs && PHP_SAPI !== 'cli') {
        push_dispatch_after_response();
    }
    return count($subs);
}

function push_dispatch_after_response(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            push_dispatch(20);
        } catch (Throwable $e) {
            error_log('nobatyar push dispatch: ' . $e->getMessage());
        }
    });
}

/**
 * Sends due push messages. 201/200 = delivered to the push service; 404/410 = the browser unsubscribed,
 * so the subscription is closed; 429/5xx and network errors retry with back-off (3 attempts).
 */
function push_dispatch(int $limit = 50, ?callable $transport = null): array
{
    $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'gone' => 0];
    $due = query(
        "SELECT m.id FROM push_messages m WHERE m.status='queued' AND (m.next_attempt_at IS NULL OR m.next_attempt_at<=NOW()) ORDER BY m.id LIMIT " . max(1, min(500, $limit)),
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($due as $id) {
        if (!query("UPDATE push_messages SET status='sending',attempts=attempts+1 WHERE id=? AND status='queued'", [$id])->rowCount()) {
            continue;
        }
        $m = query('SELECT m.*,s.endpoint,s.p256dh,s.auth,s.active FROM push_messages m JOIN push_subscriptions s ON s.id=m.subscription_id WHERE m.id=?', [$id])->fetch();
        if (!$m['active']) {
            query("UPDATE push_messages SET status='cancelled' WHERE id=?", [$id]);
            continue;
        }
        try {
            $http = webpush_send($m, $m['payload'], $transport);
        } catch (Throwable $e) {
            $http = 0;
            $error = error_message($e);
        }
        if ($http >= 200 && $http < 300) {
            query("UPDATE push_messages SET status='sent',http_status=?,sent_at=NOW(),error=NULL WHERE id=?", [$http, $id]);
            query('UPDATE push_subscriptions SET failures=0,last_used_at=NOW() WHERE id=?', [$m['subscription_id']]);
            $stats['sent']++;
        } elseif (in_array($http, [404, 410], true)) {
            query("UPDATE push_messages SET status='failed',http_status=?,error='اشتراک مرورگر منقضی شده است.' WHERE id=?", [$http, $id]);
            query('UPDATE push_subscriptions SET active=0 WHERE id=?', [$m['subscription_id']]);
            $stats['gone']++;
        } elseif ((int) $m['attempts'] < 3 && ($http === 0 || $http === 429 || $http >= 500)) {
            query("UPDATE push_messages SET status='queued',http_status=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),error=? WHERE id=?", [
                $http ?: null,
                5 * (int) $m['attempts'],
                mb_substr($error ?? 'سرویس اعلان پاسخ موقت داد.', 0, 250),
                $id,
            ]);
            $stats['retry']++;
        } else {
            query("UPDATE push_messages SET status='failed',http_status=?,error=? WHERE id=?", [$http ?: null, mb_substr($error ?? ('پاسخ ' . $http), 0, 250), $id]);
            query('UPDATE push_subscriptions SET failures=failures+1,active=IF(failures+1>=5,0,active) WHERE id=?', [$m['subscription_id']]);
            $stats['failed']++;
        }
        unset($error);
    }
    return $stats;
}

/** Stores the browser subscription after the user's explicit consent. */
function push_subscribe(int $user, array $data): void
{
    if (!push_available()) {
        throw new AuthError('اعلان مرورگر در این سامانه فعال نیست.', 503);
    }
    if (empty($data['consent'])) {
        throw new AuthError('برای دریافت اعلان مرورگر، رضایت خود را تأیید کنید.');
    }
    $endpoint = (string) ($data['endpoint'] ?? '');
    $p256dh = (string) ($data['keys']['p256dh'] ?? '');
    $auth = (string) ($data['keys']['auth'] ?? '');
    if (
        strlen($endpoint) > 1000 ||
        !preg_match('#^https://[a-z0-9.-]+(:[0-9]+)?/#i', $endpoint) ||
        !preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256dh) ||
        !preg_match('/^[A-Za-z0-9_-]{16,30}$/', $auth) ||
        strlen(b64url_decode($p256dh)) !== 65 ||
        strlen(b64url_decode($auth)) !== 16
    ) {
        throw new AuthError('اطلاعات اشتراک مرورگر نامعتبر است.');
    }
    throttle('push_subscribe', 20, 3600);
    query(
        'INSERT INTO push_subscriptions(user_id,endpoint,endpoint_hash,p256dh,auth,user_agent,active,failures) VALUES(?,?,?,?,?,?,1,0) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),p256dh=VALUES(p256dh),auth=VALUES(auth),user_agent=VALUES(user_agent),active=1,failures=0',
        [$user, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190)],
    );
    query('INSERT INTO notification_preferences(user_id,push_consent_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE push_consent_at=COALESCE(push_consent_at,NOW())', [$user]);
    audit('push.subscribe', $user);
}

function push_unsubscribe(int $user, string $endpoint = ''): void
{
    if ($endpoint !== '') {
        query('UPDATE push_subscriptions SET active=0 WHERE user_id=? AND endpoint_hash=?', [$user, hash('sha256', $endpoint)]);
    } else {
        query('UPDATE push_subscriptions SET active=0 WHERE user_id=?', [$user]);
        query('UPDATE notification_preferences SET push_consent_at=NULL WHERE user_id=?', [$user]);
    }
    audit('push.unsubscribe', $user);
}

function unread_notifications(int $user): int
{
    return (int) query('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL', [$user])->fetchColumn();
}

function mark_all_notifications_read(int $user): void
{
    query('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL', [$user]);
}

/* ---------- Visit reminders ---------- */

/** Reminder thresholds in hours, largest first (e.g. "24,2" → [24, 2]). */
function reminder_hours(): array
{
    $hours = array_values(array_unique(array_filter(array_map('intval', explode(',', digits(setting('reminder_hours', '24,2')))), fn($h) => $h >= 1 && $h <= 168)));
    rsort($hours);
    return $hours;
}

/**
 * Sends at most one reminder per threshold and booking. A booking that is already inside a smaller
 * threshold gets only the nearest reminder; a booking confirmed in the last hour gets none (its
 * confirmation was just sent).
 */
function send_visit_reminders(int $limit = 200): int
{
    $purchased = send_purchased_reminders($limit);
    $hours = reminder_hours();
    if (!$hours) {
        return $purchased;
    }
    $rows = query(
        "SELECT b.id,b.phone,b.patient_user_id,b.patient_name,b.confirmed_at,b.estimated_at,s.starts_at,s.doctor_id,d.subscription_until,d.name doctor,cl.name clinic,cl.address FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id JOIN clinics cl ON cl.id=s.clinic_id WHERE b.status='confirmed' AND s.starts_at>NOW() AND s.starts_at<=DATE_ADD(NOW(),INTERVAL ? HOUR) ORDER BY s.starts_at LIMIT " .
            max(1, min(1000, $limit)),
        [$hours[0]],
    )->fetchAll();
    $sent = 0;
    $now = time();
    foreach ($rows as $b) {
        $left = (strtotime($b['starts_at']) - $now) / 3600;
        $due = null;
        foreach ($hours as $h) {
            if ($left <= $h) {
                $due = $h;
            }
        }
        if ($due === null) {
            continue;
        }
        $fresh = $b['confirmed_at'] && strtotime($b['confirmed_at']) > $now - 3600;
        $first = true;
        foreach ($hours as $h) {
            if ($h < $due) {
                continue;
            }
            // Mark every passed threshold as handled; only the nearest one actually sends.
            $new = notice_once('reminder:' . $b['id'] . ':' . $h);
            if ($h !== $due || !$new || $fresh) {
                continue;
            }
            $when = jdate($b['starts_at'], true) . ($b['estimated_at'] ? ' (زمان تقریبی ' . fa_number(substr($b['estimated_at'], 11, 5)) . ')' : '');
            if ($b['patient_user_id']) {
                notify_user((int) $b['patient_user_id'], 'یادآوری نوبت ' . $b['doctor'], $when . ' • ' . $b['clinic'] . '، ' . $b['address'], (int) $b['id'], 'reminder', 'receipt.php?id=' . (int) $b['id']);
            }
            query('UPDATE bookings SET reminded_at=NOW() WHERE id=?', [$b['id']]);
            $sent++;
        }
    }
    return $sent + $purchased;
}

/** Marker used to send something exactly once (cron and request code alike). */
function notice_once(string $key): bool
{
    return query('INSERT IGNORE INTO notice_markers(dedupe_key) VALUES(?)', [mb_substr($key, 0, 160)])->rowCount() > 0;
}
