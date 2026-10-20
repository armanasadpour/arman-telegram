<?php
declare(strict_types=1);

/**
 * Site content managed from the admin panel: general settings (name, support contacts, footer),
 * homepage hero text and notice, homepage banners, and platform-wide announcements delivered as
 * in-app notifications (plus Web Push for users who opted in). Announcements never send SMS.
 */

/** Site name with a safe fallback before installation/upgrade. */
function site_name(): string
{
    try {
        return setting('site_name', 'نوبت‌یار') ?: 'نوبت‌یار';
    } catch (Throwable $e) {
        return 'نوبت‌یار';
    }
}

/** Contact and footer settings for the public footer; empty before installation. */
function site_contact(): array
{
    try {
        return [
            'phone' => setting('support_phone'),
            'email' => setting('support_email'),
            'hours' => setting('support_hours'),
            'footer' => setting('footer_text'),
            'fab' => setting('support_fab', '1') === '1',
        ];
    } catch (Throwable $e) {
        return ['phone' => '', 'email' => '', 'hours' => '', 'footer' => '', 'fab' => false];
    }
}

/** Links typed by the administrator: a relative page of this site or an https:// address. */
function safe_link(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('~^https://[^\s<>"\']+$~i', $url) || preg_match('~^[a-z0-9][a-z0-9_\-./]*\.php(\?[a-z0-9_\-=&%.]*)?$~i', $url)) {
        return mb_substr($url, 0, 255);
    }
    throw new RuntimeException('پیوند باید نشانی یکی از صفحه‌های همین سایت (مثل centers.php) یا با https:// شروع شود.');
}

function site_logo_url(): string {
    try { $file=setting('site_logo','');return $file!==''?home_image_url($file):''; } catch(Throwable $e){return '';}
}
function site_brand_logo(string $class='brand-icon'): string {
    $url=site_logo_url();return $url?'<img class="site-custom-logo '.esc($class).'" src="'.esc($url).'" alt="'.esc(site_name()).'">':icon('logo',$class);
}
function general_settings_save(array $d,array $files=[]): void
{
    $name = field($d, 'site_name', 60);
    $phone = digits(trim((string) ($d['support_phone'] ?? '')));
    if ($phone !== '' && !preg_match('/^[0-9\-+ ]{4,20}$/', $phone)) {
        throw new RuntimeException('شماره پشتیبانی معتبر نیست.');
    }
    $email = trim((string) ($d['support_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('ایمیل پشتیبانی معتبر نیست.');
    }
    $logo=setting('site_logo','');
    if(!empty($d['remove_logo']))$logo='';
    if(isset($files['site_logo']) && ($files['site_logo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $logo=store_cover_image($files['site_logo'],'home',1200,480,true,true);
    settings_save([
        'site_logo'=>$logo,
        'site_name' => $name,
        'support_phone' => $phone,
        'support_email' => mb_substr($email, 0, 190),
        'support_hours' => field($d, 'support_hours', 120, false),
        'footer_text' => field($d, 'footer_text', 300, false),
        'ticket_max_open' => (string) number_range($d, 'ticket_max_open', 1, 20),
        'support_fab' => empty($d['support_fab']) ? '0' : '1',
    ]);
    audit('settings.general');
}

/** Site-wide notice bar of the homepage (hero texts are part of the homepage layout since 1.0). */
function home_content_save(array $d): void
{
    settings_save(['home_notice' => field($d, 'home_notice', 300, false)]);
    audit('content.home');
}

/* ---------- Banners ---------- */

function banner_save(array $d, array $files): string
{
    $id = (int) ($d['id'] ?? 0);
    $row = $id ? query('SELECT * FROM site_banners WHERE id=?', [$id])->fetch() : null;
    if ($id && !$row) {
        throw new RuntimeException('بنر پیدا نشد.');
    }
    $title = field($d, 'title', 120);
    $subtitle = field($d, 'subtitle', 255, false);
    $link = safe_link((string) ($d['link_url'] ?? ''));
    $label = field($d, 'link_label', 40, false);
    $starts = trim((string) ($d['starts_on'] ?? '')) !== '' ? parse_jdate((string) $d['starts_on']) : null;
    $ends = trim((string) ($d['ends_on'] ?? '')) !== '' ? parse_jdate((string) $d['ends_on']) : null;
    if ($starts && $ends && $ends < $starts) {
        throw new RuntimeException('تاریخ پایان نمایش باید بعد از تاریخ شروع باشد.');
    }
    $image = $row['image_file'] ?? null;
    if (($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $image = store_cover_image($files['image'], 'banners', 1200, 400);
    } elseif (!empty($d['remove_image'])) {
        $image = null;
    }
    $values = [$title, $subtitle, $image, $link ?: null, $label, !empty($d['active']) ? 1 : 0, $starts, $ends];
    if ($id) {
        query('UPDATE site_banners SET title=?,subtitle=?,image_file=?,link_url=?,link_label=?,active=?,starts_on=?,ends_on=? WHERE id=?', [...$values, $id]);
    } else {
        $sort = (int) query('SELECT COALESCE(MAX(sort_order),0)+1 FROM site_banners')->fetchColumn();
        query('INSERT INTO site_banners(title,subtitle,image_file,link_url,link_label,active,starts_on,ends_on,sort_order) VALUES(?,?,?,?,?,?,?,?,?)', [...$values, $sort]);
        $id = (int) db()->lastInsertId();
    }
    if ($row && $row['image_file'] && $row['image_file'] !== $image) {
        @unlink(media_path('banners', $row['image_file']));
    }
    audit('content.banner', $id);
    return 'بنر ذخیره شد.';
}

function banner_delete(int $id): void
{
    $row = query('SELECT * FROM site_banners WHERE id=?', [$id])->fetch();
    if (!$row) {
        throw new RuntimeException('بنر پیدا نشد.');
    }
    query('DELETE FROM site_banners WHERE id=?', [$id]);
    if ($row['image_file']) {
        @unlink(media_path('banners', $row['image_file']));
    }
    audit('content.banner_delete', $id);
}

function banner_move(int $id, string $direction): void
{
    db_transaction(function () use ($id, $direction) {
        $rows = query('SELECT id FROM site_banners ORDER BY sort_order,id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search($id, array_map('intval', $rows), true);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($i === false || !isset($rows[$j])) {
            return;
        }
        [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
        foreach ($rows as $n => $rid) {
            query('UPDATE site_banners SET sort_order=? WHERE id=?', [$n + 1, $rid]);
        }
    });
}

/** Active banners within their display window, for the homepage. */
function home_banners(): array
{
    return query(
        'SELECT * FROM site_banners WHERE active=1 AND (starts_on IS NULL OR starts_on<=CURDATE()) AND (ends_on IS NULL OR ends_on>=CURDATE()) ORDER BY sort_order,id LIMIT 5',
    )->fetchAll();
}

/* ---------- Platform announcements ---------- */

function announcement_audiences(): array
{
    return [
        'all' => 'همه کاربران فعال',
        'patients' => 'بیماران',
        'doctors' => 'پزشکان دارای حساب',
        'secretaries' => 'منشی‌ها',
        'centers' => 'مدیران و کارکنان مراکز',
    ];
}

function announcement_status_labels(): array
{
    return ['queued' => 'در صف ارسال', 'sending' => 'در حال ارسال', 'sent' => 'ارسال شد', 'cancelled' => 'لغو شد'];
}

/** Recipient SQL for an audience: active user ids greater than ?, ascending. */
function announcement_audience_sql(string $audience): string
{
    $base = 'SELECT u.id FROM users u WHERE u.active=1 AND u.id>? AND ';
    return match ($audience) {
        'all' => $base . '1=1',
        'patients' => $base . "EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='patient')",
        'doctors' => $base . 'EXISTS(SELECT 1 FROM doctor_accounts a WHERE a.user_id=u.id)',
        'secretaries' => $base . "EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='secretary')",
        'centers' => $base . '(EXISTS(SELECT 1 FROM centers c WHERE c.owner_user_id=u.id) OR EXISTS(SELECT 1 FROM center_staff s WHERE s.user_id=u.id AND s.active=1))',
        default => throw new RuntimeException('گروه گیرندگان معتبر نیست.'),
    } . ' ORDER BY u.id';
}

function announce_create(array $d): string
{
    $audience = (string) ($d['audience'] ?? '');
    if (!isset(announcement_audiences()[$audience])) {
        throw new RuntimeException('گروه گیرندگان را انتخاب کنید.');
    }
    $title = field($d, 'title', 120);
    $body = field($d, 'body', 1000);
    $url = safe_link((string) ($d['url'] ?? ''));
    $total = (int) query('SELECT COUNT(*) FROM (' . announcement_audience_sql($audience) . ') x', [0])->fetchColumn();
    if (!$total) {
        throw new RuntimeException('در این گروه کاربر فعالی وجود ندارد.');
    }
    query("INSERT INTO admin_announcements(title,body,url,audience,status,total,created_by_admin) VALUES(?,?,?,?,'queued',?,?)", [
        $title, $body, $url ?: null, $audience, $total, $_SESSION['admin_id'] ?? null,
    ]);
    $id = (int) db()->lastInsertId();
    audit('announcement.create', $id, $audience);
    // The first batch goes out now; larger audiences continue on the next cron runs.
    announcements_process();
    $row = query('SELECT * FROM admin_announcements WHERE id=?', [$id])->fetch();
    return $row['status'] === 'sent'
        ? 'اطلاعیه برای ' . fa_number($row['sent_count']) . ' کاربر ارسال شد.'
        : 'ارسال آغاز شد؛ ' . fa_number($row['sent_count']) . ' از ' . fa_number($row['total']) . ' کاربر. بقیه با اجرای بعدی Cron ارسال می‌شود.';
}

function announce_cancel(int $id): void
{
    $n = query("UPDATE admin_announcements SET status='cancelled',finished_at=NOW() WHERE id=? AND status IN ('queued','sending')", [$id])->rowCount();
    if (!$n) {
        throw new RuntimeException('این اطلاعیه قابل لغو نیست.');
    }
    audit('announcement.cancel', $id);
}

/**
 * Delivers queued announcements in batches. The row is locked while a batch is written and the cursor
 * (last_user_id) advances in the same transaction, so concurrent cron runs never notify a user twice.
 */
function announcements_process(?int $limit = null): int
{
    $limit ??= setting_int('announcement_batch', 500, 50, 5000);
    $sent = 0;
    while ($sent < $limit) {
        $done = db_transaction(function () use (&$sent, $limit) {
            $a = query("SELECT * FROM admin_announcements WHERE status IN ('queued','sending') ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
            if (!$a) {
                return true;
            }
            $ids = query(announcement_audience_sql($a['audience']) . ' LIMIT ' . max(1, $limit - $sent), [(int) $a['last_user_id']])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $uid) {
                notify_user((int) $uid, $a['title'], $a['body'], null, 'announcement', (string) ($a['url'] ?? ''));
            }
            $sent += count($ids);
            $finished = count($ids) < $limit - ($sent - count($ids));
            query(
                'UPDATE admin_announcements SET last_user_id=?,sent_count=sent_count+?,status=?,finished_at=IF(?=1,NOW(),NULL) WHERE id=?',
                [$ids ? (int) end($ids) : (int) $a['last_user_id'], count($ids), $finished ? 'sent' : 'sending', $finished ? 1 : 0, $a['id']],
            );
            return false;
        });
        if ($done) {
            break;
        }
    }
    return $sent;
}

function admin_content_data(): array
{
    $edit = !empty($_GET['edit']) ? (query('SELECT * FROM site_banners WHERE id=?', [(int) $_GET['edit']])->fetch() ?: []) : [];
    return [
        'banners' => query('SELECT * FROM site_banners ORDER BY sort_order,id')->fetchAll(),
        'edit' => $edit,
        'home' => ['home_notice' => setting('home_notice')],
    ];
}
