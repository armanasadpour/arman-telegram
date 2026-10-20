<?php
declare(strict_types=1);

/** Public page of a clinic / medical center: profile, branches with map links and its doctors. */

require __DIR__ . '/core.php';
require_schema();

try {
    $c = public_center((int) ($_GET['id'] ?? 0));
} catch (Throwable $e) {
    http_response_code(404);
    layout_start('مرکز پیدا نشد');
    echo '<article class="card narrow"><h1>مرکز پیدا نشد</h1><p>' . esc(error_message($e)) . '</p><a class="button" href="centers.php">فهرست مراکز درمانی</a></article>';
    layout_end();
    exit();
}
$branches = center_branches((int) $c['id'], null, true);
$doctors = public_center_doctors((int) $c['id']);
$byBranch = [];
foreach ($doctors as $d) {
    $byBranch[$d['branch_id']][] = $d;
}
layout_start($c['name'], ['description' => mb_substr(($c['description'] ?: center_kinds()[$c['kind']] . ' ' . $c['name'] . ' در ' . $c['city']), 0, 160), 'body_class' => 'clinic-page']);
render('public/clinic', ['c' => $c, 'branches' => $branches, 'byBranch' => $byBranch, 'doctorCount' => count(array_unique(array_column($doctors, 'id')))]);
layout_end();
