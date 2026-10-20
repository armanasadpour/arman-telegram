<?php
declare(strict_types=1);

/**
 * Support tickets between signed-in users (any role) and the support team. Text only — no attachments,
 * so no uploaded file can carry medical documents into the ticket system. Users see only their own
 * tickets; administrators need the "support" permission.
 */

function ticket_categories(): array
{
    return [
        'booking' => 'نوبت و رزرو',
        'payment' => 'پرداخت و استرداد',
        'account' => 'حساب کاربری و ورود',
        'doctor' => 'پنل پزشک و اشتراک',
        'center' => 'مرکز درمانی',
        'technical' => 'مشکل فنی',
        'general' => 'سایر موارد',
    ];
}

function ticket_status_labels(): array
{
    return ['open' => 'در انتظار پاسخ پشتیبانی', 'answered' => 'پاسخ داده شد', 'closed' => 'بسته‌شده'];
}

function ticket_priorities(): array
{
    return ['low' => 'کم', 'normal' => 'عادی', 'high' => 'فوری'];
}

function ticket_body(array $d): string
{
    $body = trim(strtr((string) ($d['body'] ?? ''), ['ي' => 'ی', 'ك' => 'ک', "\r\n" => "\n"]));
    if (mb_strlen($body) < 10 || mb_strlen($body) > 3000) {
        throw new RuntimeException('متن پیام باید بین ۱۰ تا ۳۰۰۰ نویسه باشد.');
    }
    return $body;
}

function ticket_create(int $user, array $d): int
{
    throttle('ticket_create', 6, 3600);
    $subject = field($d, 'subject', 190);
    if (mb_strlen($subject) < 4) {
        throw new RuntimeException('موضوع را کامل‌تر بنویسید.');
    }
    $category = (string) ($d['category'] ?? '');
    if (!isset(ticket_categories()[$category])) {
        throw new RuntimeException('دسته موضوع را انتخاب کنید.');
    }
    $body = ticket_body($d);
    $limit = setting_int('ticket_max_open', 3, 1, 20);
    return db_transaction(function () use ($user, $subject, $category, $body, $limit) {
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$user]);
        $open = (int) query("SELECT COUNT(*) FROM support_tickets WHERE user_id=? AND status<>'closed'", [$user])->fetchColumn();
        if ($open >= $limit) {
            throw new RuntimeException('تا پاسخ یا بسته‌شدن تیکت‌های باز قبلی، تیکت جدید ثبت نمی‌شود.');
        }
        query("INSERT INTO support_tickets(user_id,subject,category,status,last_reply_by,updated_at) VALUES(?,?,?,'open','user',NOW())", [$user, $subject, $category]);
        $id = (int) db()->lastInsertId();
        query('INSERT INTO support_messages(ticket_id,user_id,body) VALUES(?,?,?)', [$id, $user, $body]);
        audit('ticket.create', $id);
        return $id;
    });
}

function user_tickets(int $user): array
{
    return query('SELECT * FROM support_tickets WHERE user_id=? ORDER BY status=\'closed\',updated_at DESC LIMIT 100', [$user])->fetchAll();
}

/** Ticket with its messages; a user may only open their own ticket. */
function ticket_load(int $id, ?int $user = null): array
{
    $t = query('SELECT t.*,u.name user_name,u.mobile user_mobile FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id=?', [$id])->fetch();
    if (!$t || ($user !== null && (int) $t['user_id'] !== $user)) {
        throw new AuthError('تیکت پیدا نشد.', 404);
    }
    $t['messages'] = query(
        'SELECT m.*,a.name admin_name FROM support_messages m LEFT JOIN admins a ON a.id=m.admin_id WHERE m.ticket_id=? ORDER BY m.id',
        [$id],
    )->fetchAll();
    return $t;
}

function ticket_user_reply(int $user, int $id, array $d): void
{
    throttle('ticket_reply', 30, 3600);
    $body = ticket_body($d);
    db_transaction(function () use ($user, $id, $body) {
        $t = query('SELECT * FROM support_tickets WHERE id=? AND user_id=? FOR UPDATE', [$id, $user])->fetch();
        if (!$t) {
            throw new AuthError('تیکت پیدا نشد.', 404);
        }
        query('INSERT INTO support_messages(ticket_id,user_id,body) VALUES(?,?,?)', [$id, $user, $body]);
        // A reply reopens a closed ticket so it returns to the support queue.
        query("UPDATE support_tickets SET status='open',last_reply_by='user',updated_at=NOW(),closed_at=NULL WHERE id=?", [$id]);
        audit('ticket.user_reply', $id);
    });
}

function ticket_user_close(int $user, int $id): void
{
    $n = query("UPDATE support_tickets SET status='closed',closed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND status<>'closed'", [$id, $user])->rowCount();
    if (!$n) {
        throw new AuthError('تیکت پیدا نشد یا قبلاً بسته شده است.', 404);
    }
    audit('ticket.user_close', $id);
}

/** Support reply: the user gets an in-app notification (and push, if enabled) linking to the ticket. */
function ticket_admin_reply(int $id, array $d): string
{
    $body = ticket_body($d);
    $close = !empty($d['close']);
    $t = db_transaction(function () use ($id, $body, $close) {
        $t = query('SELECT * FROM support_tickets WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$t) {
            throw new RuntimeException('تیکت پیدا نشد.');
        }
        query('INSERT INTO support_messages(ticket_id,admin_id,body) VALUES(?,?,?)', [$id, (int) $_SESSION['admin_id'], $body]);
        query(
            "UPDATE support_tickets SET status=?,last_reply_by='admin',updated_at=NOW(),closed_at=IF(?=1,NOW(),NULL),assigned_admin_id=COALESCE(assigned_admin_id,?) WHERE id=?",
            [$close ? 'closed' : 'answered', $close ? 1 : 0, (int) $_SESSION['admin_id'], $id],
        );
        audit('ticket.reply', $id);
        return $t;
    });
    notify_user((int) $t['user_id'], 'پاسخ پشتیبانی', 'به تیکت «' . mb_substr($t['subject'], 0, 60) . '» پاسخ داده شد.', null, 'general', 'support.php?id=' . $id);
    return 'پاسخ ثبت شد و به کاربر اطلاع داده شد.';
}

function ticket_admin_update(int $id, array $d): string
{
    $status = (string) ($d['status'] ?? '');
    $priority = (string) ($d['priority'] ?? '');
    if (!isset(ticket_status_labels()[$status]) || !isset(ticket_priorities()[$priority])) {
        throw new RuntimeException('وضعیت یا اولویت معتبر نیست.');
    }
    $assign = (int) ($d['assigned_admin_id'] ?? 0);
    if ($assign && !query('SELECT id FROM admins WHERE id=? AND active=1', [$assign])->fetch()) {
        throw new RuntimeException('مدیر انتخاب‌شده فعال نیست.');
    }
    $n = query(
        'UPDATE support_tickets SET status=?,priority=?,assigned_admin_id=?,closed_at=IF(?=\'closed\',COALESCE(closed_at,NOW()),NULL),updated_at=NOW() WHERE id=?',
        [$status, $priority, $assign ?: null, $status, $id],
    )->rowCount();
    if (!$n && !query('SELECT id FROM support_tickets WHERE id=?', [$id])->fetch()) {
        throw new RuntimeException('تیکت پیدا نشد.');
    }
    audit('ticket.update', $id, $status . '/' . $priority);
    return 'تیکت به‌روز شد.';
}

function admin_tickets_data(): array
{
    if (!empty($_GET['ticket'])) {
        try {
            return ['ticket' => ticket_load((int) $_GET['ticket']), 'admins' => query('SELECT id,name,email FROM admins WHERE active=1 ORDER BY name,email')->fetchAll()];
        } catch (AuthError $e) {
            return ['ticket' => null, 'notFound' => true];
        }
    }
    $status = (string) ($_GET['status'] ?? 'active');
    $category = (string) ($_GET['category'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ['1=1'];
    $args = [];
    if ($status === 'active') {
        $where[] = "t.status<>'closed'";
    } elseif (isset(ticket_status_labels()[$status])) {
        $where[] = 't.status=?';
        $args[] = $status;
    } else {
        $status = 'all';
    }
    if (isset(ticket_categories()[$category])) {
        $where[] = 't.category=?';
        $args[] = $category;
    } else {
        $category = '';
    }
    if ($q !== '') {
        $where[] = '(t.subject LIKE ? OR t.id=? OR u.mobile=?)';
        array_push($args, '%' . $q . '%', (int) digits(ltrim($q, '#')), digits($q));
    }
    $sql = ' FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE ' . implode(' AND ', $where);
    $total = (int) query('SELECT COUNT(*)' . $sql, $args)->fetchColumn();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [
        'ticket' => null,
        'filters' => ['status' => $status, 'category' => $category, 'q' => $q],
        'total' => $total,
        'page' => $page,
        'rows' => query(
            "SELECT t.*,u.name user_name,u.mobile user_mobile,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) messages" .
                $sql .
                " ORDER BY t.status='closed',FIELD(t.priority,'high','normal','low'),t.updated_at DESC LIMIT 30 OFFSET " .
                ($page - 1) * 30,
            $args,
        )->fetchAll(),
        'counts' => query('SELECT status,COUNT(*) n FROM support_tickets GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}
