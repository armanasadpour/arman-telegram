<?php
require __DIR__ . '/core.php';
require_schema();
header('Content-Type: application/json; charset=utf-8');
try {
    admin_require('communications');
    $kind = (string) ($_GET['kind'] ?? 'user');
    $q = mb_substr(trim(digits((string) ($_GET['q'] ?? ''))), 0, 100);
    if (mb_strlen($q) < 2) {
        echo '[]';
        exit();
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $sql = match ($kind) {
        'doctor' => 'SELECT id,name FROM doctors WHERE name LIKE ? OR mobile LIKE ? ORDER BY name LIMIT 30',
        'center'
            => 'SELECT c.id,c.name FROM centers c JOIN users u ON u.id=c.owner_user_id WHERE u.active=1 AND (c.name LIKE ? OR u.mobile LIKE ?) ORDER BY c.name LIMIT 30',
        'patient'
            => "SELECT u.id,u.name FROM users u WHERE u.active=1 AND (u.name LIKE ? OR u.mobile LIKE ?) AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='patient') ORDER BY u.name LIMIT 30",
        default
            => 'SELECT id,name FROM users WHERE active=1 AND (name LIKE ? OR mobile LIKE ?) ORDER BY name LIMIT 30',
    };
    if (!in_array($kind, ['user', 'patient', 'doctor', 'center'], true)) {
        throw new AuthError('گروه نامعتبر است.');
    }
    $rows = query($sql, [$like, $like])->fetchAll();
    echo json_encode(
        array_map(fn($r) => ['value' => $kind . ':' . $r['id'], 'label' => $r['name'] . ' (#' . $r['id'] . ')'], $rows),
        JSON_UNESCAPED_UNICODE,
    );
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'جستجو در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
}
