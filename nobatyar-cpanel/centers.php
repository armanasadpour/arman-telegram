<?php
declare(strict_types=1);

/** Public list of clinics and medical centers in the visitor's city (or a chosen city). */

require __DIR__ . '/core.php';
require_schema();

$city = current_city();
$cityId = (int) ($_GET['city'] ?? ($city['id'] ?? 0));
$q = trim(field($_GET, 'q', 100, false));
$sql = 'SELECT ce.id,ce.name,ce.kind,ce.logo_file,ce.address,ci.name city,(SELECT COUNT(DISTINCT l.doctor_id) FROM center_doctors l WHERE l.center_id=ce.id AND l.status=\'active\') doctors FROM centers ce JOIN cities ci ON ci.id=ce.city_id WHERE ' . center_open_sql('ce');
$args = [];
if ($cityId) {
    $sql .= ' AND (ce.city_id=? OR EXISTS(SELECT 1 FROM center_branches b WHERE b.center_id=ce.id AND b.active=1 AND b.city_id=?))';
    array_push($args, $cityId, $cityId);
}
if ($q !== '') {
    $sql .= ' AND ce.name LIKE ?';
    $args[] = '%' . $q . '%';
}
$groups = center_groups();
$group = isset($groups[$_GET['group'] ?? '']) ? (string) $_GET['group'] : '';
if ($group !== '') {
    $sql .= ' AND ce.kind IN (' . implode(',', array_fill(0, count($groups[$group]['kinds']), '?')) . ')';
    array_push($args, ...$groups[$group]['kinds']);
}
$keep = array_filter(['city' => $cityId ?: null, 'q' => $q !== '' ? $q : null]);
$rows = query($sql . ' ORDER BY ce.name LIMIT 60', $args)->fetchAll();
$cityName = $cityId ? (string) query('SELECT name FROM cities WHERE id=?', [$cityId])->fetchColumn() : '';
$heading = $group !== '' ? $groups[$group]['label'] : 'مراکز درمانی';
layout_start($cityName ? $heading . ' ' . $cityName : $heading, ['description' => 'کلینیک‌ها و مراکز درمانی دارای نوبت‌دهی اینترنتی']);
?>
<section class="search-hero compact">
  <span class="eyebrow">کلینیک‌ها و مراکز درمانی</span>
  <h1><?= esc($heading) ?> <?= $cityName ? esc($cityName) : 'سراسر کشور' ?></h1>
  <form method="get" class="search-form" role="search">
    <?php if ($cityId): ?><input type="hidden" name="city" value="<?= $cityId ?>"><?php endif; ?>
    <?php if ($group !== ''): ?><input type="hidden" name="group" value="<?= esc($group) ?>"><?php endif; ?>
    <div class="search-main">
      <label class="sr-only" for="center-q">نام مرکز</label>
      <input id="center-q" name="q" value="<?= esc($q) ?>" placeholder="نام کلینیک یا مرکز">
      <button><?= icon('search') ?><span>جستجو</span></button>
    </div>
  </form>
  <nav class="chip-row" aria-label="نوع مرکز">
    <a class="chip<?= $group === '' ? ' active' : '' ?>" href="centers.php?<?= esc(http_build_query($keep)) ?>"<?= $group === '' ? ' aria-current="page"' : '' ?>>همه</a>
    <?php foreach ($groups as $key => $g): ?>
      <a class="chip<?= $group === $key ? ' active' : '' ?>" href="centers.php?<?= esc(http_build_query($keep + ['group' => $key])) ?>"<?= $group === $key ? ' aria-current="page"' : '' ?>><?= esc($g['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($cityId): ?><p><a href="centers.php?<?= esc(http_build_query(['city' => 0] + ($group !== '' ? ['group' => $group] : []))) ?>">نمایش همه شهرها</a></p><?php endif; ?>
</section>
<div class="center-grid">
  <?php foreach ($rows as $r): ?>
    <a class="center-card" href="clinic.php?id=<?= (int) $r['id'] ?>">
      <?php if ($r['logo_file']): ?><img src="media.php?kind=center&amp;file=<?= esc($r['logo_file']) ?>" alt="" width="56" height="56" loading="lazy"><?php else: ?><span class="center-logo"><?= icon('building') ?></span><?php endif; ?>
      <span><strong><?= esc($r['name']) ?></strong><small><?= esc(center_kinds()[$r['kind']] ?? '') ?> • <?= esc($r['city']) ?> • <?= fa_number($r['doctors']) ?> پزشک</small></span>
    </a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?><p class="empty-state">مرکز درمانی فعالی <?= $cityName ? 'در ' . esc($cityName) . ' ' : '' ?>پیدا نشد.</p><?php endif; ?>
<p class="muted center-cta">مدیر کلینیک یا مرکز درمانی هستید؟ <a href="center-register.php">مرکز خود را ثبت کنید</a>.</p>
<?php
layout_end();
