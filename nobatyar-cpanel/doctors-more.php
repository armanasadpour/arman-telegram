<?php
declare(strict_types=1);

/** Next batch of the homepage «همه پزشکان» cards (HTML); X-More: 1 when another batch follows. */
require __DIR__ . '/core.php';
require_schema();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
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
