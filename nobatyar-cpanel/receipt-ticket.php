<?php
declare(strict_types=1);

/**
 * Printable A5 appointment receipt for the patient (1.0.20): drawn on a canvas in the browser and saved as PDF or
 * JPG, or printed. Only the patient's own confirmed or completed bookings; the QR code opens the receipt page.
 */
require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=patient.php');
}
try {
    $id = positive_id($_GET['id'] ?? 0);
    $b = own_booking($id);
    if (!in_array($b['status'], ['confirmed', 'completed'], true)) {
        throw new AuthError('رسید چاپی فقط برای نوبت‌های قطعی آماده می‌شود.', 409);
    }
    $doc = query('SELECT d.name,d.photo_file,d.medical_code,sp.name specialty,sp.level FROM doctors d LEFT JOIN specialties sp ON sp.id=d.specialty_id WHERE d.id=?', [$b['doctor_id']])->fetch();
    $cl = query('SELECT cl.address,cl.street_main,cl.street_side,cl.address_extra,cl.phone,c.name city,p.name province FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.id=?', [$b['clinic_id']])->fetch();
    [$main, $side, $extra] = clinic_address_parts($cl);
    $day = substr((string) $b['starts_at'], 0, 10);
    [$jy, $jm, $jd] = jalali_parts($day);
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $base = app_base_url();
    $data = [
        'site' => site_name(),
        'logo' => site_logo_url(),
        'domain' => parse_url($base, PHP_URL_HOST) ?: site_name(),
        'issued' => jdate(date('Y-m-d')),
        'issuedTime' => fa_number(date('H:i')),
        'doctor' => doctor_display_name((string) $doc['name']),
        'specialty' => $doc['specialty'] ? specialty_display_title($doc['specialty'], $doc['level']) : '',
        'code' => $doc['medical_code'] ? fa_number($doc['medical_code']) : '',
        'photo' => !empty($doc['photo_file']) ? 'photo.php?id=' . (int) $b['doctor_id'] : '',
        'patient' => trim((string) $b['patient_name']),
        'date' => jalali_weekday($day) . ' ' . fa_number($jd) . ' ' . $months[$jm - 1] . ' ' . fa_number($jy),
        'time' => fa_number(substr((string) $b['starts_at'], 11, 5)),
        'tracking' => fa_number(tracking_label((string) $b['tracking_code'])),
        'status' => $b['status'] === 'completed' ? 'انجام‌شده' : 'تأیید شده',
        'address' => implode('، ', array_filter([$cl['city'], $main, $side])),
        'extra' => $extra,
        // 076 3333 4444 / 0912 345 6789
        'phone' => $cl['phone'] ? fa_number(preg_match('/^0[0-9]{10}$/', $cl['phone']) ? (str_starts_with($cl['phone'], '09') ? substr($cl['phone'], 0, 4) . ' ' . substr($cl['phone'], 4, 3) . ' ' . substr($cl['phone'], 7) : substr($cl['phone'], 0, 3) . ' ' . substr($cl['phone'], 3, 4) . ' ' . substr($cl['phone'], 7)) : $cl['phone']) : '',
        'url' => $base . 'receipt.php?id=' . $id,
        'notes' => [
            'لطفاً ۱۵ دقیقه قبل از زمان نوبت در مطب حضور داشته باشید.',
            'در صورت عدم حضور، نوبت شما لغو خواهد شد.',
            'در صورت نیاز به تغییر یا لغو نوبت، از طریق سایت ' . site_name() . ' اقدام کنید.',
            'این رسید فقط مخصوص این نوبت است و قابل استفاده برای نوبت‌های دیگر نمی‌باشد.',
        ],
        'file' => 'nobat-' . preg_replace('/[^0-9A-Za-z]/', '', (string) $b['tracking_code']),
    ];
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    layout_start('رسید نوبت');
    echo '<p class="error">' . esc(error_message($e)) . '</p><p><a class="button secondary" href="patient.php">نوبت‌های من</a></p>';
    layout_end();
    exit();
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>رسید نوبت <?= esc($data['doctor']) ?></title><link rel="stylesheet" href="assets/style.css?v=<?= esc(APP_VERSION) ?>"><link rel="stylesheet" href="assets/receipt-ticket.css?v=<?= esc(APP_VERSION) ?>"></head>
<body class="ticket-page">
<nav class="ticket-tools">
  <a class="button ghost" href="receipt.php?id=<?= (int) $id ?>"><?= icon('arrow-left') ?> بازگشت</a>
  <button type="button" class="button" id="ticket-pdf" disabled><?= icon('download') ?> دانلود PDF</button>
  <button type="button" class="button secondary" id="ticket-jpg" disabled><?= icon('image') ?> دانلود JPG</button>
  <button type="button" class="button secondary" id="ticket-print" disabled><?= icon('print') ?> چاپ</button>
  <p id="ticket-status" role="status">در حال آماده‌سازی رسید…</p>
</nav>
<main class="ticket-sheet"><canvas id="ticket-canvas" width="1748" height="2480" aria-label="رسید نوبت"></canvas><img id="ticket-image" alt="رسید نوبت <?= esc($data['doctor']) ?>" hidden></main>
<script id="ticket-data" type="application/json"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="assets/vendor/qrcode.js"></script>
<script src="assets/receipt-ticket.js?v=<?= esc(APP_VERSION) ?>"></script>
</body></html>
