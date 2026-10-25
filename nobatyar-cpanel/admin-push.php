<?php
require __DIR__ . '/core.php';
require_schema();
header('Content-Type: application/json; charset=utf-8');
try {
    admin_require('dashboard');
    $a = admin_current();
    if (!in_array($a['role'], ['owner', 'manager'], true)) {
        throw new AuthError('دسترسی ندارید.', 403);
    }
    if (!is_post()) {
        throw new AuthError('روش نامعتبر است.', 405);
    }
    $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrfHeader)) {
        throw new AuthError('مهلت فرم پایان یافته است؛ صفحه را تازه کنید.', 419);
    }
    $data = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $op = $data['operation'] ?? '';
    if ($op === 'preferences') {
        $hour = number_range($data, 'summary_hour', 0, 23);
        $prefs = [];
        foreach (admin_notice_events() as $key => $label) {
            $prefs[$key] = !empty($data[$key]) ? 1 : 0;
        }
        db_transaction(function () use ($prefs, $a, $hour) {
            query(
                'INSERT INTO admin_notice_preferences(admin_id,preferences) VALUES(?,?) ON DUPLICATE KEY UPDATE preferences=VALUES(preferences)',
                [$a['id'], json_encode($prefs)],
            );
            setting_set('platform_summary_hour', (string) $hour);
            audit('admin.push_preferences');
        });
    } elseif ($op === 'off') {
        query('UPDATE admin_push_devices SET active=0 WHERE admin_id=?', [$a['id']]);
    } elseif ($op === 'subscribe') {
        if (!push_available() || empty($data['consent'])) {
            throw new AuthError('اعلان فعال نیست یا رضایت ثبت نشده است.');
        }
        $endpoint = (string) ($data['endpoint'] ?? '');
        $key = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $allowed = false;
        foreach (
            [
                'fcm.googleapis.com',
                'updates.push.services.mozilla.com',
                'push.services.mozilla.com',
                'web.push.apple.com',
                'notify.windows.com',
            ]
            as $domain
        ) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
            }
        }
        if (
            !$allowed ||
            !str_starts_with($endpoint, 'https://') ||
            parse_url($endpoint, PHP_URL_PORT) ||
            strlen($endpoint) > 1000 ||
            !preg_match('/^[A-Za-z0-9_-]{80,100}$/', $key) ||
            !preg_match('/^[A-Za-z0-9_-]{16,30}$/', $auth) ||
            strlen(b64url_decode($key)) !== 65 ||
            strlen(b64url_decode($auth)) !== 16
        ) {
            throw new AuthError('اطلاعات دستگاه معتبر نیست.');
        }
        query(
            'INSERT INTO admin_push_devices(admin_id,endpoint,endpoint_hash,p256dh,auth) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE admin_id=VALUES(admin_id),p256dh=VALUES(p256dh),auth=VALUES(auth),active=1',
            [$a['id'], $endpoint, hash('sha256', $endpoint), $key, $auth],
        );
    } else {
        throw new AuthError('عملیات نامعتبر است.');
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    echo json_encode(['error' => error_message($e)], JSON_UNESCAPED_UNICODE);
}
