<?php
declare(strict_types=1);

/** All active specialties with the number of visible doctors in the visitor's city (or nationwide). */

require __DIR__ . '/core.php';
require_schema();
$city = visitor_city();
$rows = query(
    "SELECT s.id,s.name,s.icon,s.image_file,s.level,s.description,(SELECT COUNT(DISTINCT d.id) FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE (sp.id=s.id OR sp.parent_id=s.id) AND " .
        visible_doctor_sql() . ' AND ' . public_clinic_sql() . ($city ? ' AND c.id=' . (int) $city['id'] : '') . ") doctors
     FROM specialties s WHERE s.active=1 AND s.deleted_at IS NULL ORDER BY FIELD(s.level,'general','specialty','subspecialty','allied'),s.sort_order,s.name",
)->fetchAll();
$groups = [];
foreach ($rows as $r) {
    $groups[$r['level']][] = $r;
}
layout_start('تخصص‌های پزشکی', ['description' => 'فهرست تخصص‌های پزشکی و پزشکان دارای نوبت اینترنتی' . ($city ? ' در ' . $city['name'] : ''), 'canonical' => 'specialties.php']);
?>
<section class="page-head">
  <h1>تخصص‌های پزشکی</h1>
  <p class="muted"><?= $city ? 'تعداد پزشکان در ' . esc($city['name']) . ' • <a href="city.php?back=specialties.php">تغییر شهر</a>' : 'تعداد پزشکان در سراسر کشور' ?></p>
</section>
<?php foreach (specialty_levels() as $level => $label): if (empty($groups[$level])) continue; ?>
  <section class="home-sec">
    <div class="sec-head"><h2><?= esc($label) ?></h2></div>
    <div class="spec-grid">
      <?php foreach ($groups[$level] as $s): ?>
        <a class="spec-card" href="<?= esc(specialty_url((int) $s['id'], $s['name'])) ?>">
          <?= specialty_visual($s, 'spec-icon') ?><strong><?= esc(specialty_display_title($s['name'], $s['level'] ?? null)) ?></strong>
          <small><?= $s['doctors'] ? fa_number($s['doctors']) . ' پزشک' : 'هنوز پزشکی ثبت نشده' ?></small>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="empty-state">هنوز تخصصی تعریف نشده است.</p><?php endif; ?>
<?php layout_end();
