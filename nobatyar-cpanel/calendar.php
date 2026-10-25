<?php
declare(strict_types=1);

/**
 * 1.0.30 «افزودن به تقویم»: one confirmed booking of the signed-in patient as an iCalendar (.ics) file.
 * iPhone opens it in Calendar directly; Android opens it with Google Calendar or the phone's calendar app.
 * The event carries a reminder an hour (and a day) before the visit.
 */

require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=patient.php');
}
try {
    $b = own_booking(positive_id($_GET['id'] ?? 0));
    if ($b['status'] !== 'confirmed') {
        throw new AuthError('فقط نوبت قطعی به تقویم افزوده می‌شود.', 409);
    }
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 404);
    layout_start('افزودن به تقویم');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}

$city = (string) (query('SELECT c.name FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.id=?', [$b['clinic_id']])->fetchColumn() ?: '');
$utc = fn(string $local) => (new DateTimeImmutable($local, new DateTimeZone('Asia/Tehran')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
$host = parse_url(app_base_url(), PHP_URL_HOST) ?: 'nobatyar';
$doctor = doctor_display_name((string) $b['doctor']);
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Nobatyar//Booking//FA',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:booking-' . (int) $b['id'] . '@' . $host,
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $utc((string) $b['starts_at']),
    'DTEND:' . $utc((string) ($b['ends_at'] ?: date('Y-m-d H:i:s', strtotime($b['starts_at'] . ' +15 minutes')))),
    'SUMMARY:' . ics_text('نوبت ' . $doctor),
    'LOCATION:' . ics_text(implode('، ', array_filter([(string) $b['clinic'], (string) $b['address'], $city]))),
    'DESCRIPTION:' . ics_text('کد رهگیری: ' . $b['tracking_code'] . "\n" . site_name() . ' — ' . app_base_url() . 'receipt.php?id=' . (int) $b['id']),
    'URL:' . app_base_url() . 'receipt.php?id=' . (int) $b['id'],
    'BEGIN:VALARM',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . ics_text('یادآوری نوبت ' . $doctor),
    'TRIGGER:-PT1H',
    'END:VALARM',
    'BEGIN:VALARM',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . ics_text('فردا نوبت ' . $doctor . ' دارید'),
    'TRIGGER:-P1D',
    'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];
header_remove('Cache-Control');
header('Cache-Control: private, no-store');
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="nobat-' . preg_replace('/[^0-9]/', '', (string) $b['tracking_code']) . '.ics"');
echo implode("\r\n", array_map('ics_fold', $lines)), "\r\n";

/** RFC 5545 text value: backslash, semicolon, comma and line breaks escaped. */
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $s);
}

/** RFC 5545 folding: lines longer than 75 octets continue on the next line after a space, never inside a character. */
function ics_fold(string $line): string
{
    $out = '';
    $len = 0;
    foreach (mb_str_split($line) as $ch) {
        $n = strlen($ch);
        if ($len + $n > 75) {
            $out .= "\r\n ";
            $len = 1;
        }
        $out .= $ch;
        $len += $n;
    }
    return $out;
}
