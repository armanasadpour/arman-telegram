<?php
declare(strict_types=1);

/**
 * Every state-changing admin form posts an "action". This file maps actions to handlers.
 * Handlers validate input, run inside transactions where several rows change, and write the audit log.
 * A handler may return a custom success message; otherwise the default "تغییرات ذخیره شد." is shown.
 */

function admin_dispatch(string $action, array $data, array $files = []): ?string
{
    if (!admin()) {
        throw new RuntimeException('ابتدا وارد شوید.');
    }
    if ($action === 'admin_password') {
        return admin_change_own_password((string) ($data['current'] ?? ''), (string) ($data['password'] ?? ''));
    }
    $perm = admin_action_permission($action);
    if (!admin_can($perm)) {
        audit('admin.denied', null, $action);
        throw new AuthError('این عملیات برای نقش شما مجاز نیست.', 403);
    }
    $access = ['user_status', 'link_doctor', 'unlink_doctor', 'grant_scope', 'revoke_scope'];
    if (in_array($action, $access, true)) {
        return admin_access_action($action, $data);
    }
    $handlers = [
        'wallet_payout_handle'=>'wallet_payout_handle',
        'wallet_refund_settings'=>'wallet_refund_settings',
        'manual_sms'=>'admin_manual_sms',
        'taxonomy' => fn($d) => admin_save_taxonomy($d, $files),
        'delete_taxonomy' => 'admin_delete_taxonomy',
        'restore_specialty' => fn($d) => specialty_admin_delete(positive_id($d['id'] ?? 0), true),
        'specialty_starter' => fn($d) => specialty_add_starter(),
        'move' => fn($d) => move_sort_order(
            (string) ($d['type'] ?? ''),
            positive_id($d['id'] ?? 0),
            (string) ($d['direction'] ?? ''),
        ),
        'create_doctor' => fn($d) => register_doctor($d, true),
        'edit_doctor' => 'admin_edit_doctor',
        'doctor_features' => 'admin_doctor_features',
        'review' => 'admin_review_doctor',
        'subscription' => 'admin_manual_subscription',
        'clinic' => 'admin_save_clinic',
        'delete_clinic' => 'admin_delete_clinic',
        'slot' => 'admin_add_slot',
        'settings' => 'admin_save_fee',
        'revenue_settings' => 'admin_revenue_settings',
        'sms_settings' => 'sms_save_settings',
        'sms_templates' => 'sms_save_templates',
        'sms_test' => 'admin_sms_test',
        'sms_account' => 'admin_sms_account',
        'booking_config' => 'admin_booking_config',
        'booking_page' => 'admin_booking_page',
        'geo_bulk' => 'admin_geo_bulk',
        'gateway_logo' => fn($d) => admin_gateway_logo($d, $files),
        'gateway_save' => fn($d) => admin_gateway_save($d),
        'gateway_enable' => fn($d) => gateway_set_enabled((string) ($d['gateway'] ?? ''), ($d['enabled'] ?? '') === '1'),
        'gateway_roles' => fn($d) => gateway_set_roles((string) ($d['default'] ?? ''), (string) ($d['fallback'] ?? '')),
        'fee_rule_save' => fn($d) => fee_rule_save(['target_id' => $d['city_id'] ?? $d['target_id'] ?? 0] + $d),
        'fee_rule_delete' => function ($d) {
            query('DELETE FROM booking_fee_rules WHERE id=?', [positive_id($d['id'] ?? 0)]);
            audit('fee.rule_delete');
        },
        'refund_handle' => fn($d) => refund_handle(
            positive_id($d['id'] ?? 0),
            (string) ($d['status'] ?? ''),
            (string) ($d['method'] ?? 'card'),
            digits((string) ($d['reference'] ?? '')),
            (string) ($d['note'] ?? ''),
        ),
        'geo_settings' => 'admin_geo_settings',
        'cron_run' => 'admin_cron_run',
        'plan_save' => fn($d) => plan_admin_save($d),
        'discount_save' => fn($d) => discount_admin_save($d),
        'sub_grant' => fn($d) => subscription_grant(
            positive_id($d['doctor_id'] ?? 0),
            (int) digits((string) ($d['days'] ?? '0')),
            (string) ($d['kind'] ?? 'gift'),
            field($d, 'note', 255),
        ),
        'sub_offline' => fn($d) => subscription_offline_paid(positive_id($d['order_id'] ?? 0), (string) ($d['reference'] ?? '')),
        'sub_admin_order' => 'admin_subscription_order',
        'sub_admin_cancel' => function ($d) {
            db_transaction(fn() => subscription_cancel_order_locked(positive_id($d['order_id'] ?? 0)));
            audit('subscription.cancel', (int) $d['order_id']);
        },
        'subscription_settings' => 'admin_subscription_settings',
        'pro_catalog' => 'admin_pro_catalog_save',
        'user_suspend' => 'admin_user_suspend',
        'user_unsuspend' => 'admin_user_unsuspend',
        'user_delete' => 'admin_user_delete',
        'doctor_suspend' => 'admin_doctor_suspend',
        'doctor_unsuspend' => 'admin_doctor_unsuspend',
        'doctor_delete' => 'admin_doctor_delete',
        'notify_settings' => 'admin_notify_settings',
        'push_regenerate' => function () {
            [$pem, $point] = p256_generate();
            $d = openssl_pkey_get_details(openssl_pkey_get_private($pem))['ec']['d'];
            setting_set('push_vapid_public', b64url_encode($point));
            secret_set('push_vapid_private', b64url_encode(str_pad($d, 32, "\0", STR_PAD_LEFT)));
            // Browsers subscribed with the old key cannot receive messages signed with the new one.
            query('UPDATE push_subscriptions SET active=0');
            audit('push.keys');
            return 'کلیدهای جدید ساخته شد؛ کاربران باید اعلان مرورگر را دوباره فعال کنند.';
        },
        'center_review' => fn($d) => center_review(positive_id($d['center_id'] ?? 0), (string) ($d['status'] ?? ''), (string) ($d['note'] ?? '')),
        'center_active' => fn($d) => center_set_active(positive_id($d['center_id'] ?? 0), ($d['active'] ?? '') === '1'),
        'center_grant' => fn($d) => center_grant(positive_id($d['center_id'] ?? 0), (int) digits((string) ($d['days'] ?? '0')), (string) ($d['kind'] ?? 'gift'), (string) ($d['note'] ?? '')),
        'center_order' => function ($d) {
            center_order_create(positive_id($d['center_id'] ?? 0), positive_id($d['plan_id'] ?? 0), (string) ($d['discount_code'] ?? ''), true);
        },
        'center_offline' => fn($d) => center_offline_paid(positive_id($d['order_id'] ?? 0), (string) ($d['reference'] ?? '')),
        'general_settings' => fn($d)=>general_settings_save($d,$files),
        'home_content' => 'home_content_save',
        'banner_save' => fn($d) => banner_save($d, $files),
        'home_draft' => fn($d) => home_save_draft($d, $files),
        'home_publish' => fn() => home_publish(),
        'home_discard' => fn() => home_discard(),
        'home_featured' => fn($d) => home_featured_set(positive_id($d['doctor_id'] ?? 0), ($d['on'] ?? '') === '1'),
        'home_featured_order' => function ($d) {
            reorder_rows('doctors', 'featured_order', (array) ($d['order'] ?? []));
            audit('home.featured_order');
            return 'ترتیب پزشکان منتخب ذخیره شد.';
        },
        'home_specialties' => 'home_specialties_save',
        'faq_save' => 'faq_save',
        'faq_delete' => function ($d) {
            query('DELETE FROM faqs WHERE id=?', [positive_id($d['id'] ?? 0)]);
            audit('home.faq_delete', (int) $d['id']);
            return 'سوال حذف شد.';
        },
        'faq_order' => function ($d) {
            reorder_rows('faqs', 'sort_order', (array) ($d['order'] ?? []));
            return 'ترتیب سوالات ذخیره شد.';
        },
        'page_save' => 'site_page_save',
        'footer_save' => fn($d) => footer_settings_save($d, $files),
        'search_settings' => 'search_config_save',
        'search_cache' => function ($d) {
            query('UPDATE clinic_free_cache SET computed_at=0');
            $n = clinic_free_cron(500);
            audit('search.cache');
            return 'اولین نوبت آزاد ' . fa_number($n) . ' مطب دوباره محاسبه شد.';
        },
        'review_moderate' => fn($d) => review_moderate(positive_id($d['id'] ?? 0), (string) ($d['status'] ?? '')),
        'drawer_banner_save' => function ($d) use ($files) {
            drawer_banner_save($d, $files);
            audit('home.drawer_banner');
            return 'بنر منوی موبایل ذخیره شد.';
        },
        'article_save' => fn($d) => article_save($d, $files),
        'article_delete' => function ($d) {
            article_delete(positive_id($d['id'] ?? 0));
            return 'مقاله حذف شد.';
        },
        'banner_delete' => fn($d) => banner_delete(positive_id($d['id'] ?? 0)),
        'banner_move' => fn($d) => banner_move(positive_id($d['id'] ?? 0), (string) ($d['direction'] ?? '')),
        'announce_create' => 'announce_create',
        'announce_cancel' => fn($d) => announce_cancel(positive_id($d['id'] ?? 0)),
        'ticket_reply' => fn($d) => ticket_admin_reply(positive_id($d['ticket_id'] ?? 0), $d),
        'ticket_update' => fn($d) => ticket_admin_update(positive_id($d['ticket_id'] ?? 0), $d),
        'admin_save' => 'admin_account_save',
        'backup_create' => fn() => 'پشتیبان ساخته شد: ' . backup_create('manual'),
        'backup_delete' => fn($d) => backup_delete((string) ($d['name'] ?? '')),
        'backup_upload' => fn() => 'فایل پشتیبان بارگذاری شد: ' . backup_upload($files['backup'] ?? []),
        'update_upload' => function () use ($files) {
            $p = update_receive($files['package'] ?? []);
            return 'بسته نسخه ' . $p['version'] . ' بررسی شد و سالم است. تغییرات را ببینید و «نصب به‌روزرسانی» را بزنید.';
        },
        'update_cancel' => function () {
            update_cancel();
            audit('update.cancel');
            return 'بسته بارگذاری‌شده حذف شد.';
        },
        'update_apply' => function ($d) {
            $token = update_apply((string) ($d['password'] ?? ''));
            redirect('upgrade.php?finish=' . $token);
        },
        'update_rollback' => function ($d) {
            $r = update_rollback((string) ($d['password'] ?? ''));
            return 'فایل‌های نسخه ' . $r['from'] . ' برگردانده شد. پشتیبان دیتابیس پیش از آن به‌روزرسانی: ' . $r['backup'] . ' (در «امنیت و پشتیبان»).';
        },
        'backup_settings' => function ($d) {
            settings_save(['backup_auto' => !empty($d['backup_auto']) ? '1' : '0', 'backup_keep' => (string) number_range($d, 'backup_keep', 2, 60)]);
            audit('backup.settings');
        },
        'backup_restore' => function ($d) {
            $safety = backup_restore((string) ($d['name'] ?? ''), (string) ($d['password'] ?? ''), (string) ($d['confirm'] ?? ''));
            session_destroy();
            session_start();
            flash('بازیابی انجام شد. نسخه پشتیبان پیش از بازیابی: ' . $safety . '. همه کاربران و مدیران باید دوباره وارد شوند.');
            redirect('admin.php');
        },
        'admin_status' => fn($d) => admin_account_status(positive_id($d['id'] ?? 0), ($d['active'] ?? '') === '1'),
        'admin_reset_password' => fn($d) => admin_reset_password(positive_id($d['id'] ?? 0), (string) ($d['password'] ?? '')),
        'center_settings' => function ($d) {
            settings_save([
                'center_registration_enabled' => !empty($d['center_registration_enabled']) ? '1' : '0',
                'center_trial_enabled' => '0',
                'center_grace_days' => (string) number_range($d, 'center_grace_days', 0, 60),
            ]);
            audit('center.settings');
            center_sync_all();
        },
    ];
    if (!isset($handlers[$action])) {
        throw new RuntimeException('عملیات ناشناخته است.');
    }
    $result = $handlers[$action]($data);
    return is_string($result) ? $result : null;
}

/* ---------- Provinces, cities, specialties ---------- */

function admin_save_taxonomy(array $data, array $files = []): ?string
{
    $type = (string) ($data['type'] ?? '');
    if ($type === 'provinces') {
        geo_admin_save_province($data);
    } elseif ($type === 'cities') {
        geo_admin_save_city($data);
    } elseif ($type === 'specialties') {
        specialty_admin_save($data, $files);
    } else {
        throw new RuntimeException('نوع اطلاعات نامعتبر است.');
    }
    return null;
}

/** Provinces and cities are removed only when nothing references them; specialties are archived. */
function admin_delete_taxonomy(array $data): ?string
{
    $type = (string) ($data['type'] ?? '');
    $id = positive_id($data['id'] ?? 0);
    if ($type === 'specialties') {
        specialty_admin_delete($id);
        return 'تخصص بایگانی شد و قابل بازگردانی است.';
    }
    if (!in_array($type, ['provinces', 'cities'], true)) {
        throw new RuntimeException('نوع اطلاعات نامعتبر است.');
    }
    db_transaction(function () use ($type, $id) {
        query("DELETE FROM `$type` WHERE id=?", [$id]);
        audit('delete_taxonomy', $id);
    });
    return null;
}

function admin_geo_settings(array $data): ?string
{
    $tiles = trim((string) ($data['map_tile_url'] ?? ''));
    if (!preg_match('#^https://[a-z0-9.{}-]+/[^\s"\'<>]*\{z\}[^\s"\'<>]*\{x\}[^\s"\'<>]*\{y\}[^\s"\'<>]*$#i', $tiles)) {
        throw new AuthError('نشانی کاشی نقشه باید HTTPS و شامل {z}، {x} و {y} باشد.');
    }
    settings_save([
        'nearby_radius_km' => number_range($data, 'nearby_radius_km', 5, 500),
        'map_tile_url' => $tiles,
        'map_attribution' => field($data, 'map_attribution', 200),
    ]);
    audit('settings.geo');
    return null;
}

/* ---------- Doctors ---------- */

function admin_edit_doctor(array $data): ?string
{
    $id = positive_id($data['id'] ?? 0);
    if (!admin_sensitive()) {
        // Roles that cannot see full numbers cannot change them either.
        $data['mobile'] = (string) query('SELECT mobile FROM doctors WHERE id=?', [$id])->fetchColumn();
    }
    [$name, $mobile, $code, $spec, $bio] = doctor_data($data);
    if (!query('SELECT id FROM doctors WHERE id=?', [$id])->fetch()) {
        throw new RuntimeException('پزشک پیدا نشد.');
    }
    if (medical_code_taken($code, $id)) {
        throw new RuntimeException('این کد نظام پزشکی قبلاً برای پزشک دیگری ثبت شده است.');
    }
    if (
        query(
            'SELECT a.user_id FROM doctor_accounts a JOIN users u ON u.id=a.user_id WHERE a.doctor_id=? AND u.mobile<>?',
            [$id, $mobile],
        )->fetch()
    ) {
        throw new RuntimeException('برای تغییر شماره پزشک، ابتدا اتصال حساب قبلی را در بخش کاربران قطع کنید.');
    }
    db_transaction(function () use ($name, $mobile, $code, $spec, $bio, $id, $data) {
        lock_doctor($id);
        query('UPDATE doctors SET name=?,mobile=?,medical_code=?,specialty=?,specialty_id=?,bio=? WHERE id=?', [
            $name,
            $mobile,
            $code,
            $spec['name'],
            $spec['id'],
            $bio,
            $id,
        ]);
        doctor_set_extra($id, $data, true);
        event_record($id, 'admin.edit_doctor', ['name' => $name, 'specialty_id' => $spec['id']]);
    });
    return null;
}

/**
 * Review states: pending, approved, rejected, suspended. Only approved doctors may be published;
 * suspension hides the profile and blocks new bookings while existing appointments stay intact.
 */
function admin_review_doctor(array $data): ?string
{
    $status = (string) ($data['status'] ?? '');
    if (!isset(doctor_status_labels()[$status])) {
        throw new RuntimeException('وضعیت نامعتبر است.');
    }
    $id = positive_id($data['id'] ?? 0);
    $active = $status === 'approved' && isset($data['active']) ? 1 : 0;
    $note = field($data, 'review_note', 2000, false);
    if (in_array($status, ['rejected', 'suspended'], true) && $note === '') {
        throw new RuntimeException('برای رد یا تعلیق، علت را در یادداشت بررسی بنویسید.');
    }
    db_transaction(function () use ($status, $note, $active, $id) {
        lock_doctor($id);
        $old = (string) query('SELECT review_status FROM doctors WHERE id=?', [$id])->fetchColumn();
        // 1.0.0-rc.16: a doctor registered with a typed-in specialty needs a listed one before approval.
        if ($status === 'approved' && !query('SELECT specialty_id FROM doctors WHERE id=? AND specialty_id IS NOT NULL', [$id])->fetch()) {
            throw new RuntimeException('پیش از تأیید، در «اطلاعات پزشک» تخصص را از فهرست انتخاب کنید (یا آن را در بخش تخصص‌ها اضافه کنید).');
        }
        query(
            'UPDATE doctors SET review_status=?,review_note=?,active=?,status_changed_at=IF(?<>?,NOW(),status_changed_at) WHERE id=?',
            [$status, $note, $active, $old, $status, $id],
        );
        if ($old !== $status) {
            $dr=query('SELECT name,mobile FROM doctors WHERE id=?',[$id])->fetch();
            $event='doctor_'.$status;
            if (isset(sms_events()[$event])) sms_enqueue($event,$dr['mobile'],['token10'=>doctor_display_name($dr['name']),'token20'=>mb_substr($note,0,250)],'doctor-review:'.$id.':'.bin2hex(random_bytes(12)));
            notify_doctor($id,'وضعیت پروفایل: '.doctor_status_labels()[$status],$note ?: 'پروفایل پزشک شما تأیید شد.');
        }
        event_record($id, 'admin.review', ['from' => $old, 'review_status' => $status, 'active' => $active]);
        // rc.20: a doctor who picked Pro at registration gets the free trial on approval (when enabled),
        // otherwise a notice to activate Pro from the panel.
        if ($status === 'approved' && $old !== 'approved' && subscriptions_on()) {
            $dr = query('SELECT * FROM doctors WHERE id=?', [$id])->fetch();
            if ($dr && $dr['plan_choice'] === 'pro' && !doctor_is_pro($id, $dr)) {
                if (subscription_status($dr)['trial_available']) {
                    subscription_start_trial($id);
                    notify_doctor($id, 'پروفایل تأیید شد؛ دوره آزمایشی پرو فعال شد', 'امکانات پرو تا پایان دوره آزمایشی فعال است. برای ادامه، از بخش «اشتراک پرو» پلن را انتخاب کنید.');
                } else {
                    notify_doctor($id, 'پروفایل تأیید شد', 'برای فعال‌سازی نسخه پرو که هنگام ثبت‌نام انتخاب کردید، به بخش «اشتراک پرو» در پنل پزشک بروید.');
                }
            }
        }
    });
    return null;
}

function admin_manual_subscription(array $data): ?string
{
    $id = positive_id($data['id'] ?? 0);
    $until = parse_jdate(field($data, 'until', 20));
    if ($until < date('Y-m-d')) {
        throw new RuntimeException('تاریخ پایان معتبر و امروز یا بعد از امروز باشد.');
    }
    db_transaction(function () use ($id, $until) {
        if (!query('SELECT id FROM doctors WHERE id=? FOR UPDATE', [$id])->fetch()) {
            throw new RuntimeException('پزشک یافت نشد.');
        }
        query('UPDATE doctors SET subscription_until=? WHERE id=?', [$until, $id]);
        entitlement_sync($id);
        query(
            "INSERT INTO subscriptions(doctor_id,starts_on,ends_on,amount,payment_status) VALUES(?,?,?,0,'manual')",
            [$id, date('Y-m-d'), $until],
        );
        event_record($id, 'admin.subscription', ['until' => $until]);
    });
    return null;
}

function admin_save_clinic(array $data): ?string
{
    $doctor = positive_id($data['doctor_id'] ?? 0);
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    [$name, $city, $address, $phone, $lat, $lng] = clinic_data($data);
    $active = isset($data['active']) ? 1 : 0;
    db_transaction(function () use ($doctor, $id, $name, $city, $address, $phone, $lat, $lng, $active, $data) {
        if (!query('SELECT id FROM doctors WHERE id=? FOR UPDATE', [$doctor])->fetch()) {
            throw new RuntimeException('پزشک پیدا نشد.');
        }
        $old = $id
            ? query('SELECT * FROM clinics WHERE id=? AND doctor_id=? FOR UPDATE', [$id, $doctor])->fetch()
            : null;
        if ($id && !$old) {
            throw new RuntimeException('مطب متعلق به این پزشک نیست.');
        }
        // Existing appointments retain the booked address; a move needs a new clinic.
        if (
            $old &&
            ((int) $old['city_id'] !== (int) $city['id'] || $old['address'] !== $address) &&
            query('SELECT b.id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? LIMIT 1', [
                $id,
            ])->fetch()
        ) {
            throw new RuntimeException(
                'این مطب رزرو ثبت‌شده دارد. برای تغییر محل، مطب جدید بسازید تا نشانی رزروهای قبلی حفظ شود.',
            );
        }
        $primary =
            isset($data['is_primary']) ||
            ($old && (int) $old['is_primary'] === 1) ||
            !query('SELECT id FROM clinics WHERE doctor_id=? AND is_primary=1', [$doctor])->fetch();
        if ($primary && !$active) {
            throw new RuntimeException('برای غیرفعال‌کردن مطب اصلی، ابتدا مطب فعال دیگری را اصلی کنید.');
        }
        if ($primary) {
            query('UPDATE clinics SET is_primary=0 WHERE doctor_id=?', [$doctor]);
        }
        if ($id) {
            query(
                'UPDATE clinics SET name=?,city_id=?,address=?,phone=?,active=?,is_primary=?,latitude=?,longitude=? WHERE id=? AND doctor_id=?',
                [$name, $city['id'], $address, $phone, $active, $primary ? 1 : 0, $lat, $lng, $id, $doctor],
            );
        } else {
            query(
                'INSERT INTO clinics(doctor_id,name,city_id,address,phone,active,is_primary,latitude,longitude) VALUES(?,?,?,?,?,?,?,?,?)',
                [$doctor, $name, $city['id'], $address, $phone, $active, $primary ? 1 : 0, $lat, $lng],
            );
            $id = (int) db()->lastInsertId();
        }
        clinic_set_district($id, $data);
        sync_primary($doctor);
        event_record($doctor, 'admin.clinic', ['clinic_id' => $id, 'name' => $name, 'active' => $active]);
    });
    return null;
}

function admin_delete_clinic(array $data): ?string
{
    $doctor = positive_id($data['doctor_id'] ?? 0);
    $id = positive_id($data['id'] ?? 0);
    db_transaction(function () use ($doctor, $id) {
        query('SELECT id FROM doctors WHERE id=? FOR UPDATE', [$doctor]);
        $c = query('SELECT * FROM clinics WHERE id=? AND doctor_id=? FOR UPDATE', [$id, $doctor])->fetch();
        if (!$c || $c['is_primary']) {
            throw new RuntimeException('مطب اصلی قابل حذف نیست. ابتدا مطب اصلی دیگری انتخاب کنید.');
        }
        query('DELETE FROM clinics WHERE id=? AND doctor_id=?', [$id, $doctor]);
        event_record($doctor, 'admin.delete_clinic', ['clinic_id' => $id]);
    });
    return null;
}

/** One-off appointment time (kept from 0.2; weekly schedules are managed in the doctor panel). */
function admin_add_slot(array $data): ?string
{
    $doctor = positive_id($data['doctor_id'] ?? 0);
    $clinic = positive_id($data['clinic_id'] ?? 0);
    if (
        !query(
            'SELECT cl.id FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.id=? AND cl.doctor_id=? AND ' .
                public_clinic_sql(),
            [$clinic, $doctor],
        )->fetch()
    ) {
        throw new RuntimeException('مطب فعال متعلق به پزشک انتخاب کنید.');
    }
    $date = new DateTimeImmutable(parse_jdate(field($data, 'starts_at', 30), true));
    if ($date->getTimestamp() <= time()) {
        throw new RuntimeException('زمان نوبت باید معتبر و در آینده باشد.');
    }
    $capacity = positive_id($data['capacity'] ?? 0);
    if ($capacity > 100) {
        throw new RuntimeException('ظرفیت حداکثر ۱۰۰ نفر است.');
    }
    db_transaction(function () use ($doctor, $clinic, $date, $capacity) {
        lock_doctor($doctor);
        $at = $date->format('Y-m-d H:i:s');
        $end = $date->modify('+15 minutes')->format('Y-m-d H:i:s');
        if (
            query(
                "SELECT id FROM slots WHERE doctor_id=? AND source='legacy' AND starts_at<? AND COALESCE(ends_at,DATE_ADD(starts_at,INTERVAL 15 MINUTE))>?",
                [$doctor, $end, $at],
            )->fetch() ||
            occupied($doctor, $at, $end)
        ) {
            throw new RuntimeException('زمان تکراری است یا تداخل دارد.');
        }
        query('INSERT INTO slots(doctor_id,clinic_id,starts_at,ends_at,capacity) VALUES(?,?,?,?,?)', [
            $doctor,
            $clinic,
            $at,
            $end,
            $capacity,
        ]);
        event_record($doctor, 'admin.slot', ['clinic_id' => $clinic, 'starts_at' => $at]);
    });
    return null;
}

/* ---------- Fees, booking rules, gateway ---------- */

function admin_save_fee(array $data): ?string
{
    $fee = parse_toman(field($data, 'fee', 20));
    $enabled = isset($data['enabled']) ? '1' : '0';
    if ($fee > 0 && $fee < 1000) {
        throw new AuthError('کارمزد یا صفر است یا حداقل ۱٬۰۰۰ تومان (حداقل مبلغ درگاه‌ها).');
    }
    if ($fee > 0 && $enabled === '1' && !payment_available()) {
        throw new RuntimeException('کارمزد مثبت تا فعال‌شدن دست‌کم یک درگاه تأییدشده قابل فعال‌سازی نیست.');
    }
    settings_save([
        'booking_fee' => (string) $fee,
        'booking_fee_enabled' => $enabled,
        'booking_fee_refund_on_cancel' => !empty($data['refund_on_cancel']) ? '1' : '0',
    ]);
    audit('settings');
    return null;
}

function admin_booking_config(array $data): ?string
{
    $policy = field($data, 'secretary_fee_policy', 20, false) ?: setting('secretary_fee_policy', 'exempt');
    if (!in_array($policy, ['exempt', 'online'], true)) {
        throw new AuthError('سیاست کارمزد نامعتبر است.');
    }
    $values = [
        'secretary_fee_policy' => $policy,
        'hold_minutes' => number_range($data, 'hold_minutes', 1, 30),
        'max_active_bookings' => number_range($data, 'max_active_bookings', 1, 50),
        'patient_cancel_hours' => number_range($data, 'patient_cancel_hours', 0, 720),
        'patient_move_hours' => number_range($data, 'patient_move_hours', 0, 720),
        'patient_cancel_enabled' => (int) !empty($data['patient_cancel_enabled']),
        'patient_move_enabled' => (int) !empty($data['patient_move_enabled']),
    ];
    db_transaction(function () use ($values, $data) {
        settings_save($values);
        // Template content and its enabled state are owned by the SMS templates page.
        audit('booking.settings');
    });
    return 'قوانین ذخیره شد.';
}

/**
 * Turn many provinces or cities on or off at once. type=provinces|cities, ids[]=…, state=1|0;
 * cascade=1 (provinces only) applies the same state to every city of the chosen provinces.
 * Nothing is deleted: inactive places, their doctors and clinics just leave the public pages.
 */
function admin_geo_bulk(array $data): string
{
    $type = (string) ($data['type'] ?? '');
    if (!in_array($type, ['provinces', 'cities'], true)) {
        throw new AuthError('نوع انتخاب نامعتبر است.');
    }
    $state = ($data['state'] ?? '') === '1' ? 1 : (($data['state'] ?? '') === '0' ? 0 : null);
    if ($state === null) {
        throw new AuthError('فعال‌سازی یا غیرفعال‌سازی را انتخاب کنید.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['ids'] ?? [])), fn($i) => $i > 0)));
    if (!$ids) {
        throw new AuthError('دست‌کم یک ' . ($type === 'provinces' ? 'استان' : 'شهر') . ' را تیک بزنید.');
    }
    if (count($ids) > 5000) {
        throw new AuthError('تعداد انتخاب بیش از حد است.');
    }
    $in = placeholders($ids);
    $cities = 0;
    db_transaction(function () use ($type, $state, $ids, $in, $data, &$cities) {
        query("UPDATE $type SET active=? WHERE id IN ($in)", array_merge([$state], $ids));
        if ($type === 'provinces' && !empty($data['cascade'])) {
            $cities = query("UPDATE cities SET active=? WHERE province_id IN ($in)", array_merge([$state], $ids))->rowCount();
        }
        audit('geo.bulk', null, $type . ':' . ($state ? 'on' : 'off') . ':' . count($ids) . ($cities ? '+cities' : ''));
    });
    $what = $type === 'provinces' ? 'استان' : 'شهر';
    return fa_number(count($ids)) . ' ' . $what . ' ' . ($state ? 'فعال' : 'غیرفعال') . ' شد' . ($cities ? ' (همراه ' . fa_number($cities) . ' شهر)' : '') . '.';
}

/** Booking page content: days in the strip, national-ID mode, profile fields and the payment note. */
function admin_booking_page(array $data): string
{
    $days = (int) ($data['booking_days'] ?? 14);
    if (!in_array($days, [7, 14, 21, 30], true)) {
        throw new AuthError('تعداد روزهای نوار تاریخ یکی از ۷، ۱۴، ۲۱ یا ۳۰ است.');
    }
    $mode = (string) ($data['patient_id_mode'] ?? 'required');
    if (!in_array($mode, ['optional', 'required'], true)) {
        throw new AuthError('حالت کد ملی نامعتبر است.');
    }
    $note = trim(preg_replace('/\s+/u', ' ', (string) ($data['booking_payment_note'] ?? '')));
    if (mb_strlen($note) > 400) {
        throw new AuthError('متن توضیح پرداخت حداکثر ۴۰۰ نویسه است.');
    }
    settings_save([
        'booking_days' => (string) $days,
        'patient_id_mode' => $mode,
        'booking_profile_fields' => !empty($data['booking_profile_fields']) ? '1' : '0',
        'booking_payment_note' => $note,
    ]);
    audit('booking.page', null, $mode);
    return 'تنظیمات صفحه رزرو ذخیره شد.';
}

/** Official bank logo for the payment step (PNG/JPEG/WebP, re-encoded); remove=1 deletes it. */
function admin_gateway_logo(array $data, array $files): string
{
    $name = (string) ($data['gateway'] ?? '');
    if (!isset(payment_gateways()[$name])) {
        throw new AuthError('درگاه نامعتبر است.');
    }
    if (!empty($data['remove'])) {
        setting_set('gateway_logo_' . $name, '');
        audit('gateway.logo', null, $name . ':removed');
        return 'نشان درگاه حذف شد.';
    }
    if (empty($files['logo'])) {
        throw new AuthError('فایل نشان را انتخاب کنید.');
    }
    setting_set('gateway_logo_' . $name, store_cover_image($files['logo'], 'home', 160, 160, true));
    audit('gateway.logo', null, $name);
    return 'نشان درگاه ذخیره شد.';
}

function admin_gateway_save(array $data): string
{
    $name = (string) ($data['gateway'] ?? '');
    $before = gateway_usable($name);
    gateway_save($name, $data);
    return $before && !gateway_usable($name)
        ? 'اطلاعات تغییر کرد؛ درگاه تا انجام پرداخت آزمایشی موفق با اطلاعات جدید غیرفعال شد.'
        : 'اطلاعات درگاه ذخیره شد.';
}

function admin_subscription_order(array $data): ?string
{
    $id = subscription_create_order(
        positive_id($data['doctor_id'] ?? 0),
        positive_id($data['plan_id'] ?? 0),
        (string) ($data['kind'] ?? 'renew'),
        (string) ($data['discount_code'] ?? ''),
        true,
    );
    audit('subscription.admin_order', $id);
    return 'پیش‌فاکتور شماره داخلی ' . fa_number($id) . ' برای پزشک صادر شد.';
}

/** VAT percent: 0–30 with up to two decimals (Persian digits accepted). */
function admin_vat_input(array $data): string
{
    $vat = digits(trim((string) ($data['invoice_vat_percent'] ?? '0')));
    $vat = $vat === '' ? '0' : str_replace(['٫', '/'], '.', $vat);
    if (!preg_match('/^[0-9]{1,2}(\.[0-9]{1,2})?$/', $vat) || (float) $vat > 30) {
        throw new AuthError('درصد مالیات بر ارزش افزوده بین ۰ تا ۳۰ باشد.');
    }
    return $vat;
}

/** Seller details printed on invoices (patient receipts and subscription invoices). */
function admin_invoice_seller(array $data): array
{
    return [
        'invoice_seller_name' => field($data, 'invoice_seller_name', 190, false),
        'invoice_seller_national_id' => digits(field($data, 'invoice_seller_national_id', 20, false)),
        'invoice_seller_economic_code' => digits(field($data, 'invoice_seller_economic_code', 20, false)),
        'invoice_seller_address' => field($data, 'invoice_seller_address', 500, false),
        'invoice_seller_phone' => digits(field($data, 'invoice_seller_phone', 20, false)),
    ];
}

/**
 * 1.0.0-rc.17: Settings → «درآمد و مالیات»: subscription sales on/off, VAT percent added to every invoice
 * (patient booking fee and subscriptions) and the seller details.
 */
function admin_revenue_settings(array $data): ?string
{
    settings_save(['invoice_vat_percent' => admin_vat_input($data)] + admin_invoice_seller($data));
    audit('settings.revenue');
    return null;
}

/**
 * 1.0.0-rc.20: Subscriptions → «امکانات و قیمت پرو». Ticked features are Pro-only; limits apply to the free
 * plan (empty = unlimited). Every doctor's clinics and services are re-checked against the new limits.
 */
function admin_pro_catalog_save(array $data): ?string
{
    $pro = [];
    $limits = [];
    foreach (pro_catalog() as $key => $f) {
        if ($f['kind'] === 'base') {
            continue;
        }
        $pro[$key] = !empty($data['pro'][$key]);
        if ($f['kind'] === 'limit') {
            $v = trim(digits((string) ($data['limit'][$key] ?? '')));
            if ($v !== '' && (!preg_match('/^[0-9]{1,3}$/', $v))) {
                throw new AuthError('حد نسخه رایگان باید عدد (۰ تا ۹۹۹) یا خالی باشد.');
            }
            $limits[$key] = $v === '' ? null : (int) $v;
            if (in_array($key, ['clinics', 'services'], true) && $limits[$key] === 0) {
                throw new AuthError('نسخه رایگان دست‌کم یک مطب و یک خدمت لازم دارد.');
            }
        }
    }
    settings_save([
        'pro_features' => json_encode(['pro' => $pro, 'limits' => $limits], JSON_UNESCAPED_UNICODE),
        'daily_summary_hour' => (string) number_range($data, 'daily_summary_hour', 0, 23),
    ]);
    pro_settings_reset_cache();
    $n = entitlement_sync_all();
    audit('pro.catalog', null, implode(',', array_keys(array_filter($pro))));
    return 'امکانات پرو ذخیره شد؛ محدودیت‌های ' . fa_number($n) . ' پزشک دوباره بررسی شد.';
}

function admin_subscription_settings(array $data): ?string
{
    // VAT and seller details moved to Settings → «درآمد و مالیات» (rc.17); still accepted here when posted.
    $invoice = array_key_exists('invoice_vat_percent', $data) ? ['invoice_vat_percent' => admin_vat_input($data)] + admin_invoice_seller($data) : [];
    $thresholds = array_values(array_unique(array_filter(array_map('intval', explode(',', digits((string) ($data['subscription_warning_thresholds'] ?? '7,3,1')))), fn($n) => $n >= 1 && $n <= 60)));
    if (!$thresholds) {
        throw new AuthError('روزهای هشدار را با ویرگول جدا کنید، مانند ۷,۳,۱');
    }
    rsort($thresholds);
    settings_save([
        'subscription_grace_days' => number_range($data, 'subscription_grace_days', 0, 30),
        'subscription_trial_enabled' => !empty($data['subscription_trial_enabled']) ? '1' : '0',
        'subscription_trial_days' => number_range($data, 'subscription_trial_days', 1, 90),
        'subscription_warning_thresholds' => implode(',', $thresholds),
        'proforma_valid_hours' => number_range($data, 'proforma_valid_hours', 1, 720),
    ] + $invoice);
    if (!empty($data['subscription_sales_form'])) {
        $on = !empty($data['subscriptions_enabled']) ? '1' : '0';
        $was = setting('subscriptions_enabled', '1');
        settings_save(['subscriptions_enabled' => $on]);
        if ($on !== $was) entitlement_sync_all();
    }
    audit('subscription.settings');
    return null;
}

function admin_cron_run(array $data): ?string
{
    throttle('cron_manual', 20, 3600);
    $report = run_cron();
    audit('cron.manual');
    return isset($report['skipped']) ? $report['skipped'] : 'وظایف زمان‌بندی اجرا شد.';
}

/* ---------- SMS ---------- */

function admin_sms_test(array $data): ?string
{
    $result = sms_send_test((string) ($data['event'] ?? ''), (string) ($data['mobile'] ?? ''));
    return $result['status'] === 'test'
        ? 'پیامک در حالت آزمایشی ثبت شد و به سرویس ارسال نشد.'
        : 'سرویس کاوه‌نگار پیامک آزمایشی را پذیرفت (شناسه ' .
            $result['messageid'] .
            '). رسیدن پیامک را روی گوشی بررسی کنید.';
}

function admin_sms_account(array $data): ?string
{
    throttle('sms_account', 30, 3600);
    $info = kavenegar_account();
    audit('sms.account');
    return 'اعتبار باقی‌مانده حساب کاوه‌نگار: ' . fa_number(number_format($info['remaincredit'], 0, '.', '٬')) . ' ریال';
}

/* ---------- Users and access scopes ---------- */

function admin_access_action(string $action, array $data): ?string
{
    $uid = positive_id($data['user_id'] ?? 0);
    db_transaction(function () use ($action, $data, $uid) {
        $u = query('SELECT * FROM users WHERE id=? FOR UPDATE', [$uid])->fetch();
        if (!$u) {
            throw new AuthError('کاربر پیدا نشد.');
        }
        if ($action === 'user_status') {
            // Any status save invalidates the user's live sessions.
            query('UPDATE users SET active=?,session_version=session_version+1 WHERE id=?', [
                isset($data['active']) ? 1 : 0,
                $uid,
            ]);
        } elseif ($action === 'link_doctor') {
            $did = positive_id($data['doctor_id'] ?? 0);
            $d = query('SELECT * FROM doctors WHERE id=? FOR UPDATE', [$did])->fetch();
            if (!$d || $d['mobile'] !== $u['mobile'] || !$u['verified_at']) {
                throw new AuthError('شماره تأییدشده کاربر باید با شماره پزشک یکسان باشد.');
            }
            query('INSERT INTO doctor_accounts(doctor_id,user_id) VALUES(?,?)', [$did, $uid]);
            query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'doctor')", [$uid]);
        } elseif ($action === 'unlink_doctor') {
            query('DELETE FROM doctor_accounts WHERE user_id=?', [$uid]);
            query("DELETE FROM user_roles WHERE user_id=? AND role='doctor'", [$uid]);
        } else {
            $role = $data['role'] ?? '';
            if (!in_array($role, ['secretary', 'clinic'], true)) {
                throw new AuthError('نقش انتخابی مجاز نیست.');
            }
            $cid = positive_id($data['clinic_id'] ?? 0);
            if (!query('SELECT id FROM clinics WHERE id=?', [$cid])->fetch()) {
                throw new AuthError('مطب پیدا نشد.');
            }
            if ($action === 'grant_scope') {
                query('INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,?)', [$uid, $role]);
                query('INSERT IGNORE INTO staff_scopes(user_id,clinic_id,role) VALUES(?,?,?)', [$uid, $cid, $role]);
            } else {
                query('DELETE FROM staff_scopes WHERE user_id=? AND clinic_id=? AND role=?', [$uid, $cid, $role]);
                if (
                    !query('SELECT clinic_id FROM staff_scopes WHERE user_id=? AND role=?', [$uid, $role])->fetch()
                ) {
                    query('DELETE FROM user_roles WHERE user_id=? AND role=?', [$uid, $role]);
                }
            }
        }
        audit($action, $uid);
    });
    return null;
}

function admin_notify_settings(array $data): string
{
    $hours = array_values(array_unique(array_filter(array_map('intval', explode(',', digits((string) ($data['reminder_hours'] ?? '')))), fn($h) => $h >= 1 && $h <= 168)));
    if (count($hours) > 4) {
        throw new AuthError('حداکثر چهار زمان یادآوری مجاز است.');
    }
    rsort($hours);
    $contact = trim((string) ($data['push_contact'] ?? ''));
    if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        throw new AuthError('ایمیل تماس اعلان مرورگر معتبر نیست.');
    }
    $smsFee = parse_toman((string)($data['patient_reminder_fee'] ?? '0'));
    if ($smsFee > 10000000) throw new AuthError('مبلغ یادآور بیش از سقف مجاز است.');
    if ($smsFee > 0 && $smsFee < 1000) throw new AuthError('هزینه یادآور باید صفر یا حداقل ۱٬۰۰۰ تومان باشد.');
    $smsHours = number_range($data, 'patient_reminder_hours', 1, 168);
    settings_save([
        'patient_reminder_hours' => (string)$smsHours,
        'patient_reminder_fee' => (string)$smsFee,
        'reminder_hours' => implode(',', $hours),
        'reminder_sms_enabled' => !empty($data['reminder_sms_enabled']) ? '1' : '0',
        'push_enabled' => !empty($data['push_enabled']) ? '1' : '0',
        'push_contact' => $contact,
        'waitlist_enabled' => !empty($data['waitlist_enabled']) ? '1' : '0',
        'waitlist_max_days' => (string) number_range($data, 'waitlist_max_days', 1, 60),
        'waitlist_cooldown_minutes' => (string) number_range($data, 'waitlist_cooldown_minutes', 5, 1440),
        'waitlist_batch' => (string) number_range($data, 'waitlist_batch', 1, 50),
        'waitlist_max_entries' => (string) number_range($data, 'waitlist_max_entries', 1, 50),
    ]);
    audit('notify.settings');
    return 'تنظیمات اعلان و صف ذخیره شد.';
}

function admin_doctor_features(array $data): string {
    $id = positive_id($data['id'] ?? 0);
    $out = [];
    $catalog = ['pro' => ['kind' => 'feature']] + pro_catalog();
    foreach ($catalog as $key => $f) {
        $v = (string) ($data['override'][$key] ?? '');
        if ($v === '') continue;
        if ($f['kind'] === 'limit') {
            if ($v === 'unlimited') $out[$key] = null;
            elseif (ctype_digit(digits($v))) $out[$key] = min(100000, (int) digits($v));
            else throw new AuthError('محدودیت باید عدد صفر یا بیشتر، یا unlimited باشد.');
        } else {
            if (!in_array($v, ['0','1'], true)) throw new AuthError('وضعیت امکان نامعتبر است.');
            $out[$key] = (int) $v;
        }
    }
    db_transaction(function () use ($id, $out) {
        lock_doctor($id);
        query('UPDATE doctors SET feature_overrides=? WHERE id=?', [json_encode($out, JSON_UNESCAPED_UNICODE), $id]);
        unset($GLOBALS['doctor_overrides_cache'][$id]);
        entitlement_sync($id);
        event_record($id, 'admin.features', $out);
    });
    return 'امکانات اختصاصی پزشک ذخیره شد.';
}
