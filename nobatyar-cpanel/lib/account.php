<?php
declare(strict_types=1);

/** Shared pieces of the account area: booking cards, page tabs, pagination and the work panels of a user. */

/**
 * One appointment as a card: date block, doctor, clinic and service, time and patient, tracking number,
 * status and the main action. $staff adds the patient's mobile (doctor, secretary and center views only).
 */
function booking_card(array $b, bool $staff = false): string
{
    $day = substr((string) $b['starts_at'], 0, 10);
    [, $m, $d] = jalali_parts($day);
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $held = ($b['status'] ?? '') === 'held';
    $who = trim((string) ($b['patient_name'] ?? ''));
    $meta = [ 'ساعت ' . fa_number(substr((string) $b['starts_at'], 11, 5))];
    if ($who !== '') {
        $meta[] = ($staff ? 'بیمار: ' : 'برای ') . $who;
    }
    if ($staff && !empty($b['phone'])) {
        $meta[] = '<bdi>' . esc(fa_number($b['phone'])) . '</bdi>';
    }
    $place = array_filter([$b['clinic'] ?? '', $b['service_name'] ?? '']);
    $link = $staff ? '' : '<a class="button ' . ($held ? '' : 'secondary ') . 'small" href="' . ($held ? 'checkout.php' : 'receipt.php') . '?id=' . (int) $b['id'] . '">' . ($held ? 'تکمیل رزرو' : 'رسید و جزئیات') . '</a>';
    if (!$staff && in_array($b['status'] ?? '', ['confirmed', 'completed'], true)) {
        $link .= ' <a class="button ghost small" href="receipt-ticket.php?id=' . (int) $b['id'] . '">' . icon('download') . ' دانلود رسید</a>';
    }
    return '<article class="appt">' .
        '<div class="appt-date" aria-hidden="true"><span>' . esc(jalali_weekday($day)) . '</span><b>' . fa_number($d) . '</b><small>' . $months[$m - 1] . '</small></div>' .
        '<div class="appt-body">' .
        '<div class="appt-top"><h3>' . esc(doctor_display_name((string) ($b['doctor'] ?? ''))) . pro_badge(['id'=>(int)($b['doctor_id'] ?? 0)]) . '</h3><span class="badge status-' . esc((string) $b['status']) . '">' . esc(booking_status_label((string) $b['status'])) . '</span></div>' .
        '<p class="appt-place">' . esc(implode(' • ', $place)) . '</p>' .
        '<p class="appt-meta"><span class="sr-only">' . esc(jdate($day)) . ' </span>' . implode(' • ', array_map(fn($x) => str_starts_with($x, '<bdi>') ? $x : esc($x), $meta)) . '</p>' .
        '<p class="appt-track">کد رهگیری <bdi dir="ltr">' . esc(tracking_label((string) $b['tracking_code'])) . '</bdi></p>' .
        (function_exists('booking_live_line') && !$staff ? booking_live_line($b) : '') .
        ($link !== '' ? '<div class="appt-actions">' . $link . '</div>' : '') .
        '</div></article>';
}

/** Horizontal tab bar (scrolls on phones). $tabs: key => label; $url builds the link of a key. */
function page_tabs(array $tabs, string $current, callable $url, string $label = 'بخش‌ها'): string
{
    $out = '<nav class="page-tabs" aria-label="' . esc($label) . '">';
    foreach ($tabs as $key => $text) {
        $on = (string) $key === $current;
        $out .= '<a href="' . esc($url($key)) . '"' . ($on ? ' class="current" aria-current="page"' : '') . '>' . esc($text) . '</a>';
    }
    return $out . '</nav>';
}

/** Page numbers, only when there is more than one page. */
function pager(int $total, int $per, int $page, callable $url): string
{
    $pages = (int) ceil($total / max(1, $per));
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="pager" aria-label="صفحه‌ها">';
    if ($page > 1) {
        $out .= '<a href="' . esc($url($page - 1)) . '" aria-label="صفحه قبل">' . icon('chevron-right') . '</a>';
    }
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $out .= $i === $page ? '<span aria-current="page">' . fa_number($i) . '</span>' : '<a href="' . esc($url($i)) . '">' . fa_number($i) . '</a>';
    }
    if ($page < $pages) {
        $out .= '<a href="' . esc($url($page + 1)) . '" aria-label="صفحه بعد">' . icon('chevron-left') . '</a>';
    }
    return $out . '</nav>';
}

/**
 * Work panels this account really has (doctor, secretary, center). A patient-only account gets none, so
 * patients never see doctor or staff entries.
 */
function account_workspaces(array $u): array
{
    $roles = roles((int) $u['id']);
    $out = [];
    if (in_array('doctor', $roles, true) && ($d = my_doctor((int) $u['id']))) {
        $out[] = ['icon' => 'doctor', 'title' => 'پنل پزشک', 'text' => $d['name'], 'url' => 'doctor-panel.php', 'cta' => 'ورود به پنل پزشک', 'kind' => 'doctor'];
    } elseif (($claim = doctor_claimable($u)) && empty($claim['deleted_at'])) {
        // rc.21: this number belongs to a doctor profile not linked yet — the panel button leads to the link request.
        $out[] = ['icon' => 'doctor', 'title' => 'پنل پزشک', 'text' => $claim['name'], 'url' => 'doctor-panel.php', 'cta' => 'ورود به پنل پزشک', 'kind' => 'doctor'];
    }
    if (in_array('secretary', $roles, true)) {
        $out[] = ['icon' => 'users', 'title' => 'پنل منشی', 'text' => 'پذیرش، صف و نوبت‌های مطب', 'url' => 'secretary.php', 'cta' => 'ورود به پنل منشی', 'kind' => 'secretary'];
    }
    if (in_array('clinic', $roles, true)) {
        foreach (my_centers() as $mc) {
            $out[] = ['icon' => 'building', 'title' => $mc['name'], 'text' => (center_status_labels()[$mc['review_status']] ?? '') . ' • ' . (center_roles()[$mc['staff_role']]['label'] ?? ''), 'url' => 'center.php?center=' . (int) $mc['id'], 'cta' => 'ورود به پنل مرکز', 'kind' => 'center'];
        }
    }
    return $out;
}
