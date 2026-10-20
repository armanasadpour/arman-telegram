<?php
declare(strict_types=1);

/**
 * Kavenegar SMS service.
 *
 * Every message goes through sms_messages, which is both the delivery log and the retry queue:
 *  - OTP codes are sent synchronously (the user is waiting) and logged without the code.
 *  - Transactional messages are queued with a dedupe key, so the same event never sends twice,
 *    then dispatched immediately after the response and retried by cron.php with back-off.
 * Official contract: https://github.com/kavenegar/kavenegar-php (verify/lookup, account/info, sms/status).
 */

const KAVENEGAR_BASE = 'https://api.kavenegar.com/v1/';

/** Events that can send SMS, with the Kavenegar tokens each one fills. */
function sms_events(): array
{
    return [
        'doctor_approved' => ['label'=>'تأیید پزشک','tokens'=>['token10'=>'نام پزشک'],'sample'=>['token10'=>'دکتر نمونه'],'suggested'=>'%token10، پروفایل شما در نوبت‌یار تأیید شد. برای تکمیل برنامه کاری وارد پنل پزشک شوید.'],
        'doctor_rejected' => ['label'=>'رد درخواست پزشک','tokens'=>['token10'=>'نام پزشک','token20'=>'علت بررسی'],'sample'=>['token10'=>'دکتر نمونه','token20'=>'نیاز به اصلاح اطلاعات'],'suggested'=>'%token10، درخواست عضویت تأیید نشد. علت: %token20. جزئیات در پنل شما.'],
        'doctor_suspended' => ['label'=>'تعلیق پزشک','tokens'=>['token10'=>'نام پزشک','token20'=>'علت بررسی'],'sample'=>['token10'=>'دکتر نمونه','token20'=>'بررسی مجدد'],'suggested'=>'%token10، پروفایل شما موقتاً تعلیق شد. علت: %token20. برای پیگیری با پشتیبانی تماس بگیرید.'],
        'manual_message' => ['label'=>'پیام دستی مدیر','tokens'=>['token10'=>'نام گیرنده','token20'=>'متن پیام'],'sample'=>['token10'=>'کاربر گرامی','token20'=>'پیام نمونه'],'suggested'=>"%token10
%token20
نوبت‌یار"],
        'login' => [
            'label' => 'کد ورود',
            'tokens' => ['token' => 'کد یک‌بارمصرف'],
            'sample' => ['token' => '123456'],
            'suggested' => "کد ورود شما به نوبت‌یار: %token\nاین کد را در اختیار دیگران قرار ندهید.",
        ],
        'booking_confirmed' => [
            'label' => 'تأیید نوبت',
            'tokens' => ['token' => 'کد پیگیری کوتاه', 'token2' => 'تاریخ شمسی', 'token3' => 'ساعت', 'token10' => 'نام پزشک'],
            'sample' => ['token' => 'A1B2C3', 'token2' => '۱۴۰۵/۰۷/۲۳', 'token3' => '۱۰:۳۰', 'token10' => 'دکتر نمونه'],
            'suggested' => "نوبت شما نزد %token10 در تاریخ %token2 ساعت %token3 قطعی شد.\nکد پیگیری: %token",
        ],
        'booking_cancelled' => [
            'label' => 'لغو نوبت',
            'tokens' => ['token' => 'تاریخ شمسی', 'token2' => 'ساعت', 'token10' => 'نام پزشک'],
            'sample' => ['token' => '۱۴۰۵/۰۷/۲۳', 'token2' => '۱۰:۳۰', 'token10' => 'دکتر نمونه'],
            'suggested' => 'نوبت شما نزد %token10 در تاریخ %token ساعت %token2 لغو شد.',
        ],
        'booking_rescheduled' => [
            'label' => 'جابه‌جایی نوبت',
            'tokens' => ['token' => 'تاریخ جدید', 'token2' => 'ساعت جدید', 'token10' => 'نام پزشک'],
            'sample' => ['token' => '۱۴۰۵/۰۷/۲۴', 'token2' => '۱۱:۰۰', 'token10' => 'دکتر نمونه'],
            'suggested' => 'زمان نوبت شما نزد %token10 به %token ساعت %token2 تغییر کرد.',
        ],
        'delay' => [
            'label' => 'تأخیر پزشک',
            'tokens' => ['token' => 'دقیقه تأخیر', 'token2' => 'تاریخ نوبت', 'token3' => 'ساعت نوبت'],
            'sample' => ['token' => '۲۰', 'token2' => '۱۴۰۵/۰۷/۲۳', 'token3' => '۱۰:۳۰'],
            'suggested' => 'نوبت %token2 ساعت %token3 حدود %token دقیقه با تأخیر انجام می‌شود.',
        ],
        'booking_reminder' => [
            'label' => 'یادآوری پیش از نوبت',
            'tokens' => ['token' => 'تاریخ شمسی', 'token2' => 'ساعت (یا زمان تقریبی)', 'token10' => 'نام پزشک', 'token20' => 'نام مطب'],
            'sample' => ['token' => '۱۴۰۵/۰۷/۲۳', 'token2' => '۱۰:۳۰', 'token10' => 'دکتر نمونه', 'token20' => 'مطب مرکزی'],
            'suggested' => "یادآوری: نوبت شما نزد %token10 در %token20، تاریخ %token ساعت %token2.\nلغو یا تغییر از پنل نوبت‌یار.",
        ],
        'doctor_daily_summary' => [
            'label' => 'خلاصه پایان روز پزشک (پرو)',
            'tokens' => ['token' => 'تعداد نوبت‌های فردا', 'token2' => 'ساعت اولین نوبت فردا', 'token3' => 'نوبت‌های امروز', 'token10' => 'نام پزشک', 'token20' => 'لغوهای امروز'],
            'sample' => ['token' => '۱۲', 'token2' => '۰۹:۳۰', 'token3' => '۱۰', 'token10' => 'دکتر نمونه', 'token20' => '۱'],
            'suggested' => "%token10، فردا %token نوبت قطعی دارید؛ اولین نوبت ساعت %token2.\nامروز: %token3 نوبت، %token20 لغو.\nنوبت‌یار",
        ],
        'waitlist_available' => [
            'label' => 'زمان آزاد برای صف انتظار',
            'tokens' => ['token' => 'تاریخ شمسی', 'token2' => 'ساعت', 'token10' => 'نام پزشک'],
            'sample' => ['token' => '۱۴۰۵/۰۷/۲۴', 'token2' => '۱۱:۰۰', 'token10' => 'دکتر نمونه'],
            'suggested' => 'زمان آزاد نزد %token10: %token ساعت %token2. برای رزرو زودتر وارد نوبت‌یار شوید.',
        ],
        'visit_time_changed' => [
            'label' => 'تغییر زمان تقریبی مراجعه',
            'tokens' => ['token' => 'زمان تقریبی جدید', 'token2' => 'تاریخ', 'token10' => 'نام پزشک'],
            'sample' => ['token' => '۱۱:۱۵', 'token2' => '۱۴۰۵/۰۷/۲۳', 'token10' => 'دکتر نمونه'],
            'suggested' => 'زمان تقریبی مراجعه شما نزد %token10 در %token2 به ساعت %token تغییر کرد.',
        ],
        'center_announcement' => [
            'label' => 'اطلاعیه مرکز درمانی',
            'tokens' => ['token' => 'تاریخ نوبت', 'token2' => 'ساعت نوبت', 'token10' => 'نام مرکز', 'token20' => 'عنوان اطلاعیه'],
            'sample' => ['token' => '۱۴۰۵/۰۷/۲۳', 'token2' => '۱۰:۳۰', 'token10' => 'کلینیک نمونه', 'token20' => 'تغییر ساعت پذیرش'],
            'suggested' => "اطلاعیه %token10 درباره نوبت %token ساعت %token2:\n%token20\nجزئیات در پنل نوبت‌یار.",
        ],
        'subscription_expiring' => [
            'label' => 'هشدار پایان اشتراک پزشک',
            'tokens' => ['token' => 'روزهای باقی‌مانده', 'token2' => 'تاریخ پایان'],
            'sample' => ['token' => '۷', 'token2' => '۱۴۰۵/۰۷/۳۰'],
            'suggested' => 'اشتراک نوبت‌یار شما %token روز دیگر (%token2) به پایان می‌رسد. برای تمدید وارد پنل شوید.',
        ],
    ];
}

function sms_enabled(): bool
{
    return setting('sms_enabled', '0') === '1';
}

/** Test mode never contacts Kavenegar and is only possible when config.php says environment=development. */
function sms_dev_mode(): bool
{
    return app_environment() === 'development' && setting('sms_dev_mode', '0') === '1';
}

/** Kept for code written before 0.7.0: summary of the SMS configuration without secrets. */
function sms_config(): array
{
    $login = sms_template('login');
    return [
        'enabled' => sms_enabled(),
        'key' => secret_exists('kavenegar_api_key') ? '•' : '',
        'template' => $login['template'] ?? '',
        'daily_limit' => setting_int('sms_daily_limit', 500, 1, 100000),
    ];
}

function sms_template(string $event): ?array
{
    $row = query('SELECT * FROM sms_templates WHERE event=?', [$event])->fetch();
    return $row ?: null;
}

function valid_template_name(string $name): bool
{
    return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,99}$/', $name);
}

function sms_save_settings(array $data): void
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    $key = trim((string) ($data['api_key'] ?? ''));
    if ($key !== '' && !preg_match('/^[a-zA-Z0-9=+\/-]{16,200}$/', $key)) {
        throw new AuthError('ساختار کلید API کاوه‌نگار نامعتبر است.');
    }
    $enabled = !empty($data['enabled']);
    $hasKey = $key !== '' || secret_exists('kavenegar_api_key');
    $login = sms_template('login');
    if ($enabled && !sms_dev_mode() && (!$hasKey || !sms_template_ready($login))) {
        throw new AuthError('برای فعال‌سازی، کلید API و الگوی فعال کد ورود در «الگوهای پیامک» ضروری است.');
    }
    $values = [
        'sms_enabled' => $enabled ? '1' : '0',
        'sms_sender' => digits(field($data,'sms_sender',30,false)),
        'sms_daily_limit' => (string) number_range($data + ['daily_limit' => '500'], 'daily_limit', 1, 100000),
        'otp_ttl_seconds' => (string) number_range($data + ['otp_ttl_seconds' => setting('otp_ttl_seconds', '180')], 'otp_ttl_seconds', 60, 900),
        'otp_resend_seconds' => (string) number_range($data + ['otp_resend_seconds' => setting('otp_resend_seconds', '60')], 'otp_resend_seconds', 30, 600),
        'otp_max_attempts' => (string) number_range($data + ['otp_max_attempts' => setting('otp_max_attempts', '5')], 'otp_max_attempts', 3, 10),
        'otp_phone_hourly' => (string) number_range($data + ['otp_phone_hourly' => setting('otp_phone_hourly', '5')], 'otp_phone_hourly', 1, 20),
        'otp_ip_15min' => (string) number_range($data + ['otp_ip_15min' => setting('otp_ip_15min', '10')], 'otp_ip_15min', 2, 200),
        'otp_ip_distinct_hourly' => (string) number_range($data + ['otp_ip_distinct_hourly' => setting('otp_ip_distinct_hourly', '5')], 'otp_ip_distinct_hourly', 1, 100),
        'sms_max_attempts' => (string) number_range($data + ['sms_max_attempts' => setting('sms_max_attempts', '3')], 'sms_max_attempts', 1, 10),
    ];
    if (app_environment() === 'development') {
        $values['sms_dev_mode'] = !empty($data['dev_mode']) ? '1' : '0';
    }
    db_transaction(function () use ($values, $key) {
        settings_save($values);
        if ($key !== '') {
            secret_set('kavenegar_api_key', $key);
        }
        audit('sms.settings');
    });
}

function sms_save_templates(array $data): void
{
    admin_require('communications');
    $rows=[];
    foreach (sms_events() as $event=>$meta) {
        $mode=($data['mode'][$event] ?? 'lookup') === 'text' ? 'text' : 'lookup';
        $template=trim((string)($data['template'][$event] ?? ''));
        $body=trim((string)($data['body'][$event] ?? ''));
        $enabled=!empty($data['enabled'][$event]);
        if ($mode==='lookup' && $template!=='' && !valid_template_name($template)) throw new AuthError('نام الگوی کاوه‌نگار نامعتبر است: '.$meta['label']);
        if ($enabled && ($mode==='text' ? $body==='' : $template==='')) throw new AuthError('الگوی فعال نباید خالی باشد: '.$meta['label']);
        if ($enabled && $mode==='lookup' && !isset($meta['tokens']['token'])) throw new AuthError('برای این رویداد روش «متن دلخواه سامانه» را انتخاب کنید: '.$meta['label']);
        if (mb_strlen($body)>2000) throw new AuthError('متن الگو حداکثر ۲۰۰۰ نویسه باشد.');
        preg_match_all('/%[a-zA-Z][a-zA-Z0-9_]*/',$body,$tags);
        foreach($tags[0] as $tag) if(!isset($meta['tokens'][substr($tag,1)]) && !in_array($tag,['%site_name','%site_url'],true)) throw new AuthError('تگ نامعتبر در '.$meta['label'].': '.$tag);
        if ($event==='login' && $mode==='text' && $enabled && !str_contains($body,'%token')) throw new AuthError('الگوی ورود باید تگ %token داشته باشد.');
        $rows[$event]=[$template,(int)$enabled,$mode,$body];
    }
    db_transaction(function() use($rows) {
        foreach($rows as $event=>$row) query('INSERT INTO sms_templates(event,template,enabled,delivery_mode,body) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE template=VALUES(template),enabled=VALUES(enabled),delivery_mode=VALUES(delivery_mode),body=VALUES(body)',[$event,...$row]);
        audit('sms.templates');
    });
}

function sms_text_render(array $tpl, array $tokens): string {
    $tags=['%site_name'=>site_name(),'%site_url'=>rtrim(app_base_url(),'/')];
    foreach($tokens as $key=>$value) $tags['%'.$key]=(string)$value;
    return strtr((string)($tpl['body'] ?? ''),$tags);
}

function sms_template_ready(?array $tpl): bool {
    return !empty($tpl['enabled']) && (($tpl['delivery_mode']??'lookup')==='text'
        ? trim((string)($tpl['body']??''))!==''
        : valid_template_name((string)($tpl['template']??'')));
}

/** Kavenegar forbids spaces in token/token2/token3; token10 and token20 may contain them. */
function sms_clean_tokens(array $tokens): array
{
    $clean = [];
    foreach (['token', 'token2', 'token3', 'token10', 'token20'] as $name) {
        if (!isset($tokens[$name]) || $tokens[$name] === '') {
            continue;
        }
        $value = trim(preg_replace('/[\r\n\t]+/u', ' ', (string) $tokens[$name]));
        if (in_array($name, ['token', 'token2', 'token3'], true)) {
            $value = preg_replace('/\s+/u', "\u{200C}", $value);
        }
        $clean[$name] = mb_substr($value, 0, 100);
    }
    if (!isset($clean['token'])) {
        throw new AuthError('متغیر اصلی پیامک خالی است.');
    }
    return $clean;
}

function sms_daily_guard(): void
{
    $sent = (int) query(
        "SELECT COUNT(*) FROM sms_messages WHERE created_at>=CURDATE() AND status IN ('sent','sending','unknown')",
    )->fetchColumn();
    if ($sent >= setting_int('sms_daily_limit', 500, 1, 100000)) {
        throw new AuthError('سقف روزانه ارسال پیامک سامانه تکمیل شده است.', 429);
    }
}

/**
 * Low-level Kavenegar request. Returns ['http'=>int,'body'=>string,'sent'=>bool].
 * "sent" is false only when the connection was never established, so a retry cannot duplicate a message.
 */
function kavenegar_http(string $method, array $params): array
{
    if (!extension_loaded('curl')) {
        throw new AuthError('افزونه cURL روی هاست فعال نیست.', 503);
    }
    $key = secret_get('kavenegar_api_key');
    if ($key === '') {
        throw new AuthError('کلید API کاوه‌نگار ثبت نشده است.', 503);
    }
    $curl = curl_init(KAVENEGAR_BASE . rawurlencode($key) . '/' . $method . '.json');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($curl);
    $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $errno = curl_errno($curl);
    curl_close($curl);
    // Never log the URL (contains the key) or the body (may contain an OTP).
    $notConnected = in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR], true);
    return ['http' => $http, 'body' => $body === false ? '' : (string) $body, 'sent' => !$notConnected, 'failed' => $body === false];
}

/** Human-readable reason for well-known Kavenegar return codes. */
function kavenegar_error(int $code): string
{
    return [
        403 => 'کلید API کاوه‌نگار معتبر نیست.',
        411 => 'شماره گیرنده معتبر نیست.',
        418 => 'اعتبار حساب کاوه‌نگار کافی نیست.',
        424 => 'الگوی پیامک در پنل کاوه‌نگار پیدا نشد یا تأیید نشده است.',
    ][$code] ?? 'سرویس پیامک درخواست را نپذیرفت (کد ' . fa_number($code) . ').';
}

/** Validates a verify/lookup response; accepted states: 1 queued, 2 scheduled, 4/5 sent to operator, 10 delivered. */
function kavenegar_parse(int $http, string $body): array
{
    $result = json_decode($body, true);
    $entry = $result['entries'][0] ?? [];
    $returnStatus = (int) ($result['return']['status'] ?? 0);
    if ($http !== 200 || !is_array($result) || $returnStatus !== 200) {
        throw new AuthError(
            $returnStatus ? kavenegar_error($returnStatus) : 'سرویس پیامک درخواست را نپذیرفت. تنظیمات و اعتبار حساب را بررسی کنید.',
            503,
        );
    }
    if (empty($entry['messageid']) || !in_array((int) ($entry['status'] ?? 0), [1, 2, 4, 5, 10], true)) {
        throw new AuthError('سرویس پیامک درخواست را نپذیرفت. تنظیمات و اعتبار حساب را بررسی کنید.', 503);
    }
    return [
        'messageid' => (string) $entry['messageid'],
        'status' => (int) $entry['status'],
        'cost' => isset($entry['cost']) ? (int) $entry['cost'] : null,
    ];
}

/**
 * Sends one verify/lookup message right now and records it.
 * $transport (CLI tests only) receives the Kavenegar payload and returns ['messageid'=>...].
 */
function sms_send_now(string $event, string $mobile, array $tokens, ?callable $transport = null, array $meta = []): array
{
    if ($transport !== null && PHP_SAPI !== 'cli') {
        throw new AuthError('انتقال آزمایشی فقط در CLI مجاز است.', 403);
    }
    $mobile = normalize_mobile($mobile);
    $tpl = sms_template($event);
    $dev = sms_dev_mode();
    if ($transport === null && !$dev) {
        if (!sms_enabled()) {
            throw new AuthError('ارسال پیامک هنوز توسط مدیر فعال نشده است.', 503);
        }
        if (!sms_template_ready($tpl)) {
            throw new AuthError('الگوی پیامک «' . (sms_events()[$event]['label'] ?? $event) . '» تنظیم نشده است.', 503);
        }
    }
    sms_daily_guard();
    $textMode = ($tpl['delivery_mode'] ?? 'lookup') === 'text';
    $body = $textMode ? sms_text_render($tpl,$tokens) : null;
    $tokens = $textMode ? $tokens : sms_clean_tokens($tokens);
    $template = $tpl['template'] ?? '';
    $storeTokens = $dev || ($meta['store_tokens'] ?? $event !== 'login');
    query(
        "INSERT INTO sms_messages(event,mobile,template,tokens,status,attempts,booking_id,user_id,created_by_admin) VALUES(?,?,?,?,'sending',1,?,?,?)",
        [
            $event,
            $mobile,
            $template,
            $storeTokens ? json_encode($tokens, JSON_UNESCAPED_UNICODE) : null,
            $meta['booking_id'] ?? null,
            $meta['user_id'] ?? null,
            $meta['admin_id'] ?? null,
        ],
    );
    $id = (int) db()->lastInsertId();
    query('UPDATE sms_messages SET delivery_mode=?,body=? WHERE id=?',[$textMode ? 'text':'lookup', $event==='login' ? null : $body,$id]);
    if ($dev && $transport === null) {
        query("UPDATE sms_messages SET status='test',provider_id=?,sent_at=NOW() WHERE id=?", ['TEST-' . $id, $id]);
        return ['messageid' => 'TEST-' . $id, 'status' => 'test', 'log_id' => $id];
    }
    try {
        $payload = $tokens + ['receptor' => $mobile, 'template' => $template, 'type' => 'sms'];
        if ($transport) {
            $result = $transport($payload);
            if (!is_array($result) || empty($result['messageid'])) {
                throw new AuthError('پاسخ سرویس پیامک نامعتبر است.', 503);
            }
        } else {
            $raw = kavenegar_http($textMode ? 'sms/send' : 'verify/lookup', $textMode ? ['receptor'=>$mobile,'sender'=>setting('sms_sender'),'message'=>$body] : $payload);
            if ($raw['failed'] && $raw['sent']) {
                query("UPDATE sms_messages SET status='unknown',error=? WHERE id=?", ['مهلت پاسخ سرویس تمام شد.', $id]);
                throw new AuthError('نتیجه ارسال پیامک نامشخص است؛ از پنل کاوه‌نگار پیگیری کنید.', 503);
            }
            if ($raw['failed']) {
                throw new AuthError('ارتباط با سرویس پیامک برقرار نشد. کمی بعد دوباره تلاش کنید.', 503);
            }
            $result = kavenegar_parse($raw['http'], $raw['body']);
        }
        query(
            "UPDATE sms_messages SET status='sent',provider_id=?,provider_status=?,cost=?,sent_at=NOW() WHERE id=?",
            [(string) $result['messageid'], $result['status'] ?? null, $result['cost'] ?? null, $id],
        );
        return ['messageid' => (string) $result['messageid'], 'status' => 'sent', 'log_id' => $id];
    } catch (Throwable $e) {
        query("UPDATE sms_messages SET status='failed',error=? WHERE id=? AND status='sending'", [
            mb_substr(error_message($e), 0, 250),
            $id,
        ]);
        throw $e instanceof AuthError ? $e : new AuthError(error_message($e), 503);
    }
}

/**
 * Queues a transactional SMS. Returns false when the same dedupe key was already queued or sent,
 * or when the event template is disabled (nothing is logged in that case).
 */
function sms_enqueue(string $event, string $mobile, array $tokens, string $dedupeKey, array $meta = []): bool
{
    $tpl = sms_template($event);
    if (!sms_dev_mode() && (!sms_enabled() || !$tpl || !$tpl['enabled'])) {
        return false;
    }
    $textMode = ($tpl['delivery_mode'] ?? 'lookup') === 'text';
    $body = $textMode ? sms_text_render($tpl,$tokens) : null;
    try {
        $mobile = normalize_mobile($mobile);
        $tokens = $textMode ? $tokens : sms_clean_tokens($tokens);
    } catch (AuthError $e) {
        return false;
    }
    $q = query(
        "INSERT IGNORE INTO sms_messages(event,mobile,template,tokens,status,next_attempt_at,dedupe_key,booking_id,user_id,delivery_mode,body) VALUES(?,?,?,?,'queued',NOW(),?,?,?,?,?)",
        [
            $event,
            $mobile,
            $tpl['template'] ?? '',
            json_encode($tokens, JSON_UNESCAPED_UNICODE),
            mb_substr($dedupeKey, 0, 120),
            $meta['booking_id'] ?? null,
            $meta['user_id'] ?? null,
            $textMode ? 'text' : 'lookup', $body,
        ],
    );
    if ($q->rowCount() && PHP_SAPI !== 'cli') {
        sms_dispatch_after_response();
    }
    return $q->rowCount() > 0;
}

/** Sends queued messages after the HTTP response is flushed, so the user never waits for the SMS provider. */
function sms_dispatch_after_response(): void
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
            sms_dispatch(10);
        } catch (Throwable $e) {
            error_log('nobatyar sms dispatch: ' . $e->getMessage());
        }
    });
}

/**
 * Sends due queued messages. A failure before the provider received the request is retried
 * with back-off up to sms_max_attempts; an unknown outcome is never retried automatically.
 */
function sms_dispatch(int $limit = 50, ?callable $transport = null): array
{
    if ($transport !== null && PHP_SAPI !== 'cli') {
        throw new AuthError('انتقال آزمایشی فقط در CLI مجاز است.', 403);
    }
    $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'unknown' => 0];
    $max = setting_int('sms_max_attempts', 3, 1, 10);
    $due = query(
        "SELECT id FROM sms_messages WHERE status='queued' AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id LIMIT " .
            max(1, min(500, $limit)),
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($due as $id) {
        $claimed = query(
            "UPDATE sms_messages SET status='sending',attempts=attempts+1 WHERE id=? AND status='queued'",
            [$id],
        )->rowCount();
        if (!$claimed) {
            continue;
        }
        $m = query('SELECT * FROM sms_messages WHERE id=?', [$id])->fetch();
        // Never deliver a purchased reminder for a cancelled/past appointment.
        // If the appointment moved while queued, use its current time and defer if necessary.
        if (str_starts_with((string)$m['dedupe_key'], 'paid-reminder:')) {
            $b = query('SELECT b.status,b.reminder_opt_in,b.reminder_hours_snapshot,b.estimated_at,s.starts_at,d.name doctor,cl.name clinic FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id JOIN clinics cl ON cl.id=s.clinic_id WHERE b.id=?', [$m['booking_id']])->fetch();
            if (!$b || $b['status'] !== 'confirmed' || !$b['reminder_opt_in'] || strtotime($b['starts_at']) <= time()) {
                query("UPDATE sms_messages SET status='cancelled',error=? WHERE id=?", ['نوبت دیگر قابل یادآوری نیست.', $id]);
                continue;
            }
            $dueAt = strtotime($b['starts_at']) - (int)$b['reminder_hours_snapshot'] * 3600;
            if ($dueAt > time()) {
                query("UPDATE sms_messages SET status='queued',attempts=GREATEST(0,attempts-1),next_attempt_at=? WHERE id=?", [date('Y-m-d H:i:s',$dueAt), $id]);
                continue;
            }
            $m['tokens'] = json_encode(['token'=>digits(jdate($b['starts_at'])), 'token2'=>substr($b['estimated_at'] ?: $b['starts_at'],11,5), 'token10'=>doctor_display_name($b['doctor']), 'token20'=>mb_substr($b['clinic'],0,90)], JSON_UNESCAPED_UNICODE);
        }
        $tokens = json_decode((string) $m['tokens'], true) ?: [];
        if (str_starts_with((string)$m['dedupe_key'], 'paid-reminder:') && $m['delivery_mode']==='text') $m['body']=sms_text_render(sms_template($m['event']) ?: [],$tokens);
        $payload = $tokens + ['receptor' => $m['mobile'], 'template' => $m['template'], 'type' => 'sms'];
        try {
            sms_daily_guard();
            if (sms_dev_mode() && $transport === null) {
                $result = ['messageid' => 'TEST-' . $id, 'status' => null];
                $status = 'test';
            } else {
                $tpl = sms_template($m['event']);
                if ($transport === null && (!sms_enabled() || !$tpl || !$tpl['enabled'])) {
                    query("UPDATE sms_messages SET status='cancelled',error=? WHERE id=?", ['الگو یا سرویس غیرفعال شد.', $id]);
                    continue;
                }
                if ($transport) {
                    $result = $transport($payload);
                } else {
                    $raw = kavenegar_http(($m['delivery_mode'] ?? 'lookup') === 'text' ? 'sms/send' : 'verify/lookup', ($m['delivery_mode'] ?? 'lookup') === 'text' ? ['receptor'=>$m['mobile'],'sender'=>setting('sms_sender'),'message'=>$m['body']] : $payload);
                    if ($raw['failed'] && $raw['sent']) {
                        query(
                            "UPDATE sms_messages SET status='unknown',error=? WHERE id=?",
                            ['مهلت پاسخ سرویس تمام شد؛ ارسال مجدد خودکار انجام نمی‌شود.', $id],
                        );
                        $stats['unknown']++;
                        continue;
                    }
                    if ($raw['failed']) {
                        throw new AuthError('ارتباط با سرویس پیامک برقرار نشد.', 503);
                    }
                    $result = kavenegar_parse($raw['http'], $raw['body']);
                }
                $status = 'sent';
            }
            query(
                'UPDATE sms_messages SET status=?,provider_id=?,provider_status=?,cost=?,sent_at=NOW(),error=NULL WHERE id=?',
                [$status, (string) $result['messageid'], $result['status'] ?? null, $result['cost'] ?? null, $id],
            );
            $stats['sent']++;
        } catch (Throwable $e) {
            $attempts = (int) $m['attempts'];
            if ($attempts < $max) {
                query(
                    "UPDATE sms_messages SET status='queued',next_attempt_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),error=? WHERE id=?",
                    [5 * $attempts * $attempts, mb_substr(error_message($e), 0, 250), $id],
                );
                $stats['retry']++;
            } else {
                query("UPDATE sms_messages SET status='failed',error=? WHERE id=?", [mb_substr(error_message($e), 0, 250), $id]);
                $stats['failed']++;
            }
        }
    }
    return $stats;
}

/** Remaining credit and expiry of the Kavenegar account (account/info). */
function kavenegar_account(?callable $transport = null): array
{
    if ($transport !== null && PHP_SAPI !== 'cli') {
        throw new AuthError('انتقال آزمایشی فقط در CLI مجاز است.', 403);
    }
    if ($transport) {
        $raw = $transport();
    } else {
        $raw = kavenegar_http('account/info', []);
        if ($raw['failed']) {
            throw new AuthError('ارتباط با سرویس پیامک برقرار نشد.', 503);
        }
    }
    $result = json_decode($raw['body'], true);
    $status = (int) ($result['return']['status'] ?? 0);
    if ($raw['http'] !== 200 || $status !== 200 || !is_array($result['entries'] ?? null)) {
        throw new AuthError($status ? kavenegar_error($status) : 'پاسخ معتبری از سرویس پیامک دریافت نشد.', 503);
    }
    $entry = $result['entries'];
    $info = [
        'remaincredit' => (int) ($entry['remaincredit'] ?? 0),
        'expiredate' => isset($entry['expiredate']) ? (int) $entry['expiredate'] : null,
        'type' => (string) ($entry['type'] ?? ''),
        'checked_at' => date('Y-m-d H:i:s'),
    ];
    setting_set('sms_account_cache', json_encode($info));
    return $info;
}

function sms_account_cached(): ?array
{
    $raw = setting('sms_account_cache', '');
    $info = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($info) ? $info : null;
}

/** Admin "send test SMS" with the event's sample tokens. */
function sms_send_test(string $event, string $mobile): array
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    $events = sms_events();
    if (!isset($events[$event])) {
        throw new AuthError('رویداد پیامک نامعتبر است.');
    }
    throttle('sms_test', 20, 3600);
    $result = sms_send_now($event, $mobile, $events[$event]['sample'], null, [
        'admin_id' => (int) $_SESSION['admin_id'],
        'store_tokens' => true,
    ]);
    audit('sms.test');
    return $result;
}

function sms_status_label(string $status): string
{
    return [
        'queued' => 'در صف',
        'sending' => 'در حال ارسال',
        'sent' => 'پذیرفته‌شده توسط سرویس',
        'failed' => 'ناموفق',
        'unknown' => 'نامشخص',
        'test' => 'آزمایشی (ارسال نشد)',
        'cancelled' => 'لغوشده',
    ][$status] ?? 'نامشخص';
}

function mask_mobile(string $mobile): string
{
    return fa_number(substr($mobile, 0, 4) . '•••' . substr($mobile, -4));
}
