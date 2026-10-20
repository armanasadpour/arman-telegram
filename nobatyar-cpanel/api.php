<?php
require __DIR__ . '/core.php';
header('Content-Type: application/json; charset=utf-8');
function api_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
try {
    if (!schema_is_current()) {
        throw new AuthError('ابتدا ارتقای سامانه را اجرا کنید.', 503);
    }
    $route = (string) ($_GET['route'] ?? 'session');
    $method = $_SERVER['REQUEST_METHOD'];
    $allowed = [
        'reception/patients' => 'GET',
        'reception/patient' => 'POST',
        'reception/calendar' => 'GET',
        'reception/reserve' => 'POST',
        'reception/change' => 'POST',
        'reception/delay' => 'POST',
        'reception/contact' => 'POST',
        'reception/sms' => 'POST',
        'session' => 'GET',
        'auth/request' => 'POST',
        'auth/verify' => 'POST',
        'auth/complete' => 'POST',
        'auth/logout' => 'POST',
        'auth/logout-all' => 'POST',
        'geo/provinces' => 'GET',
        'geo/cities' => 'GET',
        'geo/search' => 'GET',
        'search/suggest' => 'GET',
        'search/map' => 'GET',
        'geo/nearby' => 'GET',
        'geo/select' => 'POST',
        'specialties' => 'GET',
        'profile' => 'POST',
        'bookings' => 'GET',
        'availability' => 'GET',
        'doctor/calendar' => 'GET',
        'doctor/action' => 'POST',
        'booking/create' => 'POST',
        'notifications' => 'GET',
        'notifications/read' => 'POST',
        'notifications/count' => 'GET',
        'push/key' => 'GET',
        'push/subscribe' => 'POST',
        'push/unsubscribe' => 'POST',
        'patient/family' => 'GET',
        'patient/family/save' => 'POST',
        'patient/bookings' => 'GET',
        'patient/change' => 'POST',
        'booking/hold' => 'POST',
        'booking/confirm' => 'POST',
        'booking/payment' => 'POST',
        'doctors/search' => 'GET',
    ];
    if (!isset($allowed[$route])) {
        throw new AuthError('مسیر پیدا نشد.', 404);
    }
    if ($method !== $allowed[$route]) {
        header('Allow: ' . $allowed[$route]);
        throw new AuthError('روش درخواست مجاز نیست.', 405);
    }
    $data = [];
    if ($method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
            throw new AuthError('حجم درخواست زیاد است.', 413);
        }
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
            throw new AuthError('بدنه باید JSON باشد.', 415);
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) {
            throw new AuthError('حجم درخواست زیاد است.', 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new AuthError('JSON نامعتبر است.', 400);
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            throw new AuthError('درخواست نامعتبر است.', 419);
        }
    }
    switch ($route) {
        case 'reception/patients':
            $out = reception_search(positive_id($_GET['clinic'] ?? 0), field($_GET, 'q', 190));
            break;
        case 'reception/patient':
            $out = ['id' => reception_patient($data)];
            break;
        case 'reception/calendar':
            $out = reception_list(
                positive_id($_GET['clinic'] ?? 0),
                field($_GET, 'date', 10),
                (string) ($_GET['view'] ?? 'day'),
                (string) ($_GET['status'] ?? ''),
            );
            break;
        case 'reception/reserve':
            throttle('reception', 120, 3600);
            $id = reception_reserve($data);
            $out = reception_booking($id);
            break;
        case 'reception/change':
            reception_change($data);
            $out = ['message' => 'تغییر ثبت شد.'];
            break;
        case 'reception/delay':
            $out = ['id' => reception_delay($data)];
            break;
        case 'reception/contact':
            reception_contact($data);
            $out = ['message' => 'تماس ثبت شد.'];
            break;
        case 'reception/sms':
            throttle('reception_sms', 100, 3600);
            reception_sms(positive_id($data['id'] ?? 0));
            $out = ['message' => 'سرویس پیامک درخواست را پذیرفت؛ تحویل به گوشی تأیید نشده است.'];
            break;
        case 'patient/family':
            $out = query(
                'SELECT id,name,relationship,birth_date FROM family_members WHERE user_id=? AND active=1',
                [require_user()['id']],
            )->fetchAll();
            break;
        case 'patient/family/save':
            family_action($data);
            $out = ['message' => 'خانواده به‌روزرسانی شد.'];
            break;
        case 'patient/bookings':
            $out = patient_bookings(
                (string) ($_GET['filter'] ?? 'upcoming'),
                max(1, (int) ($_GET['page'] ?? 1)),
            );
            break;
        case 'patient/change':
            patient_change($data);
            $out = ['message' => 'تغییر ثبت شد.'];
            break;
        case 'booking/hold':
            throttle('booking', 20, 3600);
            $bid = hold_booking($data);
            $out = own_booking($bid);
            break;
        case 'booking/confirm':
            $out = ['id' => confirm_free(positive_id($data['id'] ?? 0))];
            break;
        case 'booking/payment':
            $gw = (string) ($data['gateway'] ?? '');
            $out = ['redirect' => start_payment(positive_id($data['id'] ?? 0), null, $gw !== '' ? $gw : null)];
            break;
        case 'doctors/search':
            $out = search_doctors($_GET);
            break;
        case 'availability':
            $out = availability(
                (int) ($_GET['clinic'] ?? 0),
                (int) ($_GET['service'] ?? 0),
                field($_GET, 'date', 10),
            );
            break;
        case 'doctor/calendar':
            $out = doctor_bookings(
                (int) ($_GET['doctor'] ?? 0),
                field($_GET, 'from', 10),
                field($_GET, 'to', 10),
                (int) ($_GET['clinic'] ?? 0),
                !empty($_GET['history']),
            );
            break;
        case 'doctor/action':
            $out = doctor_action(positive_id($data['doctor_id'] ?? 0), field($data, 'action', 30), $data);
            break;
        case 'booking/create':
            throttle('booking', 20, 3600);
            $out = [
                'id' => reserve_service(
                    positive_id($data['clinic_id'] ?? 0),
                    positive_id($data['service_id'] ?? 0),
                    field($data, 'starts_at', 19),
                    field($data, 'name', 190),
                ),
            ];
            break;
        case 'notifications':
            $out = my_notifications();
            break;
        case 'notifications/count':
            $out = ['unread' => unread_notifications((int) require_user()['id'])];
            break;
        case 'push/key':
            $out = ['available' => push_available(), 'key' => push_available() ? setting('push_vapid_public') : null];
            break;
        case 'push/subscribe':
            push_subscribe((int) require_user()['id'], $data);
            $out = ['message' => 'اعلان مرورگر برای این دستگاه فعال شد.'];
            break;
        case 'push/unsubscribe':
            push_unsubscribe((int) require_user()['id'], (string) ($data['endpoint'] ?? ''));
            $out = ['message' => 'اعلان مرورگر این دستگاه غیرفعال شد.'];
            break;
        case 'notifications/read':
            read_notification(positive_id($data['id'] ?? 0));
            $out = ['message' => 'اعلان خوانده شد.'];
            break;
        case 'session':
            $u = user_now();
            $out = [
                'csrf' => csrf(),
                'user' => $u
                    ? [
                        'id' => (int) $u['id'],
                        'name' => $u['name'],
                        'mobile' => $u['mobile'],
                        'roles' => roles((int) $u['id']),
                    ]
                    : null,
                'signup' => !$u && pending_signup() ? ['mobile' => pending_signup()['mobile']] : null,
            ];
            break;
        case 'auth/request':
            $out = otp_request(field($data, 'mobile', 20)) + ['mobile' => normalize_mobile(field($data, 'mobile', 20))];
            break;
        case 'auth/verify':
            $r = otp_verify(field($data, 'challenge_id', 64), field($data, 'code', 20));
            $out = $r['status'] === 'profile_required'
                ? ['status' => 'profile_required', 'mobile' => $r['mobile'], 'csrf' => csrf()]
                : ['status' => $r['status'], 'id' => (int) $r['id'], 'name' => $r['name'], 'csrf' => csrf(), 'roles' => roles((int) $r['id'])];
            break;
        case 'auth/complete':
            $u = auth_complete_signup($data);
            $out = ['status' => 'logged_in', 'id' => (int) $u['id'], 'name' => $u['name'], 'csrf' => csrf(), 'roles' => roles((int) $u['id'])];
            break;
        case 'auth/logout':
            logout_user();
            $out = ['csrf' => csrf()];
            break;
        case 'auth/logout-all':
            logout_everywhere();
            $out = ['csrf' => csrf()];
            break;
        case 'geo/provinces':
            $out = array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], provinces_list());
            break;
        case 'geo/cities':
            $all = !empty($_GET['all']) && admin();
            $out = array_map(
                fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'capital' => (bool) $c['is_capital']],
                cities_of_province(positive_id($_GET['province'] ?? 0), !$all),
            );
            break;
        case 'search/map':
            throttle('search_map', 300, 3600);
            if (!search_config()['map']) {
                throw new AuthError('نمایش نقشه غیرفعال است.', 404);
            }
            $sc = search_scope($_GET);
            $out = search_markers(search_input($_GET), $sc['cityIds'], $sc['province']);
            break;
        case 'search/suggest':
            throttle('search_suggest', 900, 3600);
            $out = search_suggest(field($_GET, 'q', 60, false), visitor_city());
            break;
        case 'geo/search':
            throttle('geo_search', 600, 3600);
            $out = array_map(
                fn($c) => [
                    'id' => (int) $c['id'],
                    'name' => $c['name'],
                    'province_id' => (int) $c['province_id'],
                    'province' => $c['province_name'],
                ],
                city_search(field($_GET, 'q', 60)),
            );
            break;
        case 'geo/nearby':
            $out = array_map(
                fn($c) => [
                    'id' => (int) $c['id'],
                    'name' => $c['name'],
                    'province' => $c['province_name'],
                    'distance_km' => $c['distance_km'],
                ],
                cities_nearby(positive_id($_GET['city'] ?? 0)),
            );
            break;
        case 'geo/select':
            $c = set_visitor_city(positive_id($data['city_id'] ?? 0));
            $out = ['id' => (int) $c['id'], 'name' => $c['name'], 'province_id' => (int) $c['province_id']];
            break;
        case 'specialties':
            $city = (int) ($_GET['city'] ?? 0);
            $out = array_map(
                fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'level' => $s['level'], 'doctors' => (int) $s['doctors']],
                specialties_with_doctors($city ? [$city] : []),
            );
            break;
        case 'profile':
            update_profile($data);
            $out = ['message' => 'اطلاعات ذخیره شد.'];
            break;
        case 'bookings':
            $role = (string) ($_GET['role'] ?? 'patient');
            if (!in_array($role, ['patient', 'doctor', 'secretary', 'clinic'], true)) {
                throw new AuthError('نقش نامعتبر است.', 403);
            }
            $out = scoped_bookings(
                $role,
                max(1, (int) ($_GET['page'] ?? 1)),
                max(0, (int) ($_GET['clinic'] ?? 0)),
            );
            break;
    }
    api_out(['ok' => true, 'data' => $out]);
} catch (Throwable $e) {
    api_out(
        ['ok' => false, 'error' => ['message' => error_message($e)]],
        $e instanceof AuthError
            ? $e->status
            : ($e instanceof PDOException
                ? 500
                : ($e instanceof RuntimeException
                    ? 422
                    : 500)),
    );
}
