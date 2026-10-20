<?php
require __DIR__ . '/core.php';
require_phase2();
$id = (int) ($_GET['id'] ?? 0);
$d = query(
    'SELECT d.*,sp.active specialty_active FROM doctors d LEFT JOIN specialties sp ON sp.id=d.specialty_id WHERE d.id=?',
    [$id],
)->fetch();
$public =
    $d &&
    $d['active'] &&
    $d['review_status'] === 'approved' &&
    $d['specialty_active'] &&
    (string) bookable_until($d['subscription_until']) >= date('Y-m-d');
$own = false;
if (!$public && $d) {
    if (admin()) {
        $own = true;
    } elseif (user_now()) {
        try {
            $own = doctor_actor($id, 'owner', 0, true)['is_owner'];
        } catch (Throwable $e) {
        }
    }
}
if (
    !$d ||
    (!$public && !$own) ||
    !preg_match('/^[a-f0-9]{48}\.jpg$/', $d['photo_file'] ?? '') ||
    !is_file(__DIR__ . '/private/photos/' . $d['photo_file'])
) {
    http_response_code(404);
    exit();
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize(__DIR__ . '/private/photos/' . $d['photo_file']));
readfile(__DIR__ . '/private/photos/' . $d['photo_file']);
