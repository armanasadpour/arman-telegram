<?php
declare(strict_types=1);

/** XML sitemap of public pages: home, lists, active cities and specialties, visible doctors, articles, pages. */

require __DIR__ . '/core.php';
if (!schema_is_current()) {
    http_response_code(503);
    exit();
}
header_remove('Cache-Control');
header('Cache-Control: public, max-age=3600');
header('Content-Type: application/xml; charset=utf-8');
$base = app_base_url();
$urls = [['index.php', null], ['specialties.php', null], ['centers.php', null], ['articles.php', null], ['faq.php', null]];
foreach (query("SELECT DISTINCT d.id,d.name FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE " . visible_doctor_sql() . ' AND ' . public_clinic_sql() . ' LIMIT 20000')->fetchAll() as $d) {
    $urls[] = [doctor_url((int) $d['id'], $d['name']), null];
}
foreach (query("SELECT DISTINCT c.id,c.name FROM cities c JOIN clinics cl ON cl.city_id=c.id JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN provinces p ON p.id=c.province_id WHERE " . visible_doctor_sql() . ' AND ' . public_clinic_sql())->fetchAll() as $c) {
    $urls[] = [city_url((int) $c['id'], $c['name']), null];
}
foreach (query('SELECT id,name FROM specialties WHERE active=1 AND deleted_at IS NULL')->fetchAll() as $s) {
    $urls[] = [specialty_url((int) $s['id'], $s['name']), null];
}
foreach (query("SELECT slug,COALESCE(updated_at,published_at) m FROM articles WHERE status='published' AND published_at<=NOW()")->fetchAll() as $a) {
    $urls[] = [article_url($a['slug']), $a['m']];
}
foreach (query('SELECT slug,updated_at FROM site_pages WHERE published=1')->fetchAll() as $p) {
    $urls[] = [page_url($p['slug']), $p['updated_at']];
}
foreach (query("SELECT id FROM centers ce WHERE " . center_open_sql('ce'))->fetchAll() as $c) {
    $urls[] = ['clinic.php?id=' . (int) $c['id'], null];
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$u, $m]) {
    echo '<url><loc>' . htmlspecialchars($base . $u, ENT_XML1) . '</loc>' . ($m ? '<lastmod>' . date('Y-m-d', strtotime((string) $m)) . '</lastmod>' : '') . "</url>\n";
}
echo '</urlset>';
