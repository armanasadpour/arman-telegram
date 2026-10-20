<?php
declare(strict_types=1);

/** Notification center for every role: list, read state, delivery preferences and browser push. */

require __DIR__ . '/core.php';
require_schema();
if (!user_now()) {
    redirect('login.php?next=notifications.php');
}
$u = require_user();
$error = '';
if (is_post()) {
    verify_csrf();
    try {
        $action = field($_POST, 'action', 30);
        if ($action === 'read') {
            read_notification(positive_id($_POST['id'] ?? 0));
        } elseif ($action === 'read_all') {
            mark_all_notifications_read((int) $u['id']);
        } elseif ($action === 'preferences') {
            save_notification_preferences((int) $u['id'], $_POST);
            flash('تنظیمات اعلان ذخیره شد.');
        } elseif ($action === 'push_off') {
            push_unsubscribe((int) $u['id']);
            flash('اعلان مرورگر برای همه دستگاه‌های شما غیرفعال شد.');
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
        redirect('notifications.php' . (isset($_GET['category']) ? '?category=' . rawurlencode((string) $_GET['category']) : ''));
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$category = (string) ($_GET['category'] ?? '');
$rows = my_notifications();
if (isset(notification_categories()[$category])) {
    $rows = array_values(array_filter($rows, fn($n) => ($n['category'] ?? 'general') === $category));
}
layout_start('اعلان‌ها');
render('public/notifications', [
    'rows' => $rows,
    'category' => $category,
    'prefs' => notification_preferences((int) $u['id']),
    'devices' => query('SELECT id,user_agent,created_at,last_used_at FROM push_subscriptions WHERE user_id=? AND active=1 ORDER BY id DESC', [$u['id']])->fetchAll(),
    'pushAvailable' => push_available(),
    'unread' => unread_notifications((int) $u['id']),
    'error' => $error,
]);
layout_end();
