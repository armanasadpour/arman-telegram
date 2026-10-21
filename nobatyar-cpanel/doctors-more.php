<?php
declare(strict_types=1);

/**
 * Homepage card fragments (HTML). Default: next batch of «همه پزشکان» (X-More: 1 when another follows).
 * section=available&specialty=ID: «پزشکان دارای نوبت در شهر شما» for one specialty (0 = all specialties).
 */
require __DIR__ . '/core.php';
require_schema();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (($_GET['section'] ?? '') === 'available') {
    $section = home_layout()['sections']['available'];
    $specialty = max(0, (int) ($_GET['specialty'] ?? 0));
    $docs = empty($section['enabled']) ? [] : home_doctor_cards('available', current_city(), max(1, min(24, (int) $section['desktop'])), $specialty);
    $showCode = setting('home_show_medical_code', '0') === '1';
    foreach ($docs as $d) {
        render('public/doctor-card', ['d' => $d, 'showCode' => $showCode]);
    }
    if (!$docs) {
        echo '<div class="empty-state wide doc-grid-empty">' . icon('calendar-clock') . '<p>برای این تخصص فعلاً نوبت آزادی در دو هفته آینده نیست.</p>' .
            ($specialty ? '<a class="button secondary" href="index.php?' . esc(http_build_query(['view' => 'doctors', 'specialty' => [$specialty], 'scope' => current_city() ? 'city' : 'all'])) . '">همه پزشکان این تخصص</a>' : '') . '</div>';
    }
    exit();
}
$section = home_layout()['sections']['all_doctors'];
if (empty($section['enabled'])) {
    http_response_code(404);
    exit();
}
$offset = max(0, min(5000, (int) ($_GET['offset'] ?? 0)));
[$docs, $more] = home_all_doctors(current_city(), $offset, max(1, min(24, (int) $section['desktop'])));
header('X-More: ' . ($more ? '1' : '0'));
$showCode = setting('home_show_medical_code', '0') === '1';
foreach ($docs as $d) {
    render('public/doctor-card', ['d' => $d, 'showCode' => $showCode]);
}
