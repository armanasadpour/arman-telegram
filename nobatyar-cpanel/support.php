<?php
declare(strict_types=1);

/** Support tickets for every signed-in role: list, new ticket, conversation, reply and close. */

require __DIR__ . '/core.php';
require_schema();
if (!user_now()) {
    redirect('login.php?next=' . rawurlencode('support.php' . (isset($_GET['id']) ? '?id=' . (int) $_GET['id'] : '')));
}
$u = require_user();
$uid = (int) $u['id'];
$id = (int) ($_GET['id'] ?? 0);
$error = '';
if (is_post()) {
    verify_csrf();
    try {
        $action = field($_POST, 'action', 30);
        if ($action === 'ticket_new') {
            $id = ticket_create($uid, $_POST);
            flash('تیکت ثبت شد؛ پاسخ پشتیبانی در همین صفحه و در اعلان‌ها به شما اطلاع داده می‌شود.');
        } elseif ($action === 'ticket_reply') {
            ticket_user_reply($uid, positive_id($_POST['id'] ?? 0), $_POST);
            $id = (int) $_POST['id'];
            flash('پیام شما ثبت شد.');
        } elseif ($action === 'ticket_close') {
            ticket_user_close($uid, positive_id($_POST['id'] ?? 0));
            $id = (int) $_POST['id'];
            flash('تیکت بسته شد.');
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
        redirect('support.php?id=' . $id);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$ticket = null;
if ($id) {
    try {
        $ticket = ticket_load($id, $uid);
    } catch (AuthError $e) {
        http_response_code(404);
        $error = $error ?: $e->getMessage();
    }
}
layout_start($ticket ? 'تیکت #' . fa_number($ticket['id']) : 'پشتیبانی');
render('public/support', [
    'ticket' => $ticket,
    'tickets' => user_tickets($uid),
    'contact' => site_contact(),
    'error' => $error,
    'post' => $error ? $_POST : [],
]);
layout_end();
