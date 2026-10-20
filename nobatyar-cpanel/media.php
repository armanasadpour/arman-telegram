<?php
declare(strict_types=1);

/** Serves public uploaded media (specialty icons, banners) from private/media with long-lived caching. */

require __DIR__ . '/core.php';

$kinds = ['specialty' => 'specialties', 'center' => 'centers', 'banner' => 'banners', 'home' => 'home', 'article' => 'articles'];
$kind = (string) ($_GET['kind'] ?? '');
$file = (string) ($_GET['file'] ?? '');
try {
    if (!isset($kinds[$kind])) {
        throw new AuthError('نوع فایل نامعتبر است.', 404);
    }
    $path = media_path($kinds[$kind], $file);
    if (!is_file($path)) {
        throw new AuthError('فایل پیدا نشد.', 404);
    }
} catch (Throwable $e) {
    http_response_code(404);
    exit();
}
$etag = '"' . sha1($file . filemtime($path)) . '"';
header_remove('Cache-Control');
header('Cache-Control: public, max-age=2592000, immutable');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit();
}
header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
readfile($path);
