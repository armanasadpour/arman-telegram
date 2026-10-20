<?php
declare(strict_types=1);

/** License image of a center: only the administrator and the center owner may open it; never cached. */

require __DIR__ . '/core.php';
require_schema();

try {
    $c = center_row((int) ($_GET['id'] ?? 0));
    if (!admin() && (int) $c['owner_user_id'] !== (int) (user_now()['id'] ?? 0)) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    if (!$c['document_file'] || !preg_match('/^[a-f0-9]{40}\.jpg$/', $c['document_file'])) {
        throw new AuthError('مدرکی ثبت نشده است.', 404);
    }
    $path = __DIR__ . '/private/media/centerdocs/' . $c['document_file'];
    if (!is_file($path)) {
        throw new AuthError('فایل پیدا نشد.', 404);
    }
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 404);
    exit();
}
header('Cache-Control: private, no-store');
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
