<?php
/**
 * @var ?array $city @var int $province @var string $scope @var array $nearby @var array $specialties
 * @var array $filters @var array $result @var string $searchError @var array $capitals @var array $centers
 * @var string $notice @var array $provinces
 */
$total = $result['total'];
$rows = $result['items'];
$page = $result['page'];
$base = ['scope' => $scope] + array_filter(
    ['q' => $filters['q'], 'specialty_id' => $filters['specialty_id'], 'sort' => $filters['sort'] !== 'newest' ? $filters['sort'] : '', 'days' => $filters['days'] !== 7 ? $filters['days'] : '', 'available' => $filters['available'] ? 1 : ''],
    fn($v) => $v !== '' && $v !== 0,
);
if (!$city && $province) {
    $base['province'] = $province;
}
?>
<?php if ($notice !== ''): ?>
  <p class="home-notice" role="note"><?= icon('megaphone') ?><span><?= esc($notice) ?></span></p>
<?php endif; ?>
<?php if (!$city): ?>
  <section class="city-prompt" data-city-prompt aria-labelledby="city-prompt-title">
    <div class="city-prompt-text">
      <span class="eyebrow">شروع رزرو</span>
      <h2 id="city-prompt-title">شهر خود را انتخاب کنید</h2>
      <p>پزشکان، تخصص‌ها و مطب‌های همان شهر را می‌بینید. انتخاب شما ذخیره می‌شود و هر زمان از بالای صفحه قابل تغییر است.</p>
    </div>
    <form class="city-search" action="city.php" method="get" role="search">
      <label class="sr-only" for="city-q">جستجوی شهر</label>
      <input id="city-q" name="q" placeholder="نام شهر، مثلاً بستک" autocomplete="off" data-city-search>
      <button><?= icon('search') ?><span>جستجو</span></button>
      <ul class="city-suggestions" data-city-results hidden></ul>
    </form>
    <div class="chip-row" aria-label="مراکز استان‌ها">
      <?php foreach (array_slice($capitals, 0, 12) as $c): ?>
        <a class="chip" href="<?= esc(city_url((int) $c['id'], $c['name'])) ?>"><?= esc($c['name']) ?></a>
      <?php endforeach; ?>
      <a class="chip ghost" href="city.php">همه شهرها</a>
    </div>
  </section>
<?php endif; ?>

<section class="search-hero">
  <span class="eyebrow">نوبت‌دهی اینترنتی پزشکان</span>
  <h1 id="search"><?= $city ? 'پزشکان ' . esc($city['name']) : 'پزشک و مطب نزدیک خود را پیدا کنید' ?></h1>
  <?php if ($city): ?>
    <nav class="scope-tabs" aria-label="محدوده جستجو">
      <a href="index.php?<?= esc(http_build_query(['scope' => 'city'] + array_diff_key($base, ['scope' => 1]))) ?>"<?= $scope === 'city' ? ' class="current" aria-current="true"' : '' ?>>فقط <?= esc($city['name']) ?></a>
      <?php if ($nearby): ?>
        <a href="index.php?<?= esc(http_build_query(['scope' => 'nearby'] + array_diff_key($base, ['scope' => 1]))) ?>"<?= $scope === 'nearby' ? ' class="current" aria-current="true"' : '' ?>><?= esc($city['name']) ?> و شهرهای اطراف</a>
      <?php endif; ?>
      <a href="index.php?<?= esc(http_build_query(['scope' => 'all'] + array_diff_key($base, ['scope' => 1]))) ?>"<?= $scope === 'all' ? ' class="current" aria-current="true"' : '' ?>>سراسر کشور</a>
    </nav>
  <?php endif; ?>
  <form method="get" action="index.php" class="search-form" role="search" data-suggest>
    <input type="hidden" name="scope" value="<?= esc($scope) ?>">
    <?php if (!$city && $province): ?><input type="hidden" name="province" value="<?= $province ?>"><?php endif; ?>
    <div class="search-main">
      <label class="sr-only" for="q">جستجو</label>
      <input id="q" name="q" type="search" value="<?= esc($filters['q']) ?>" placeholder="نام پزشک، تخصص، مطب یا محله" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="q-suggest" aria-autocomplete="list" data-suggest-input>
      <button><?= icon('search') ?><span>جستجو</span></button>
      <div class="suggest-box" id="q-suggest" role="listbox" hidden data-suggest-list></div>
    </div>
    <details class="search-more"<?= $filters['sort'] !== 'newest' || $filters['available'] || $filters['specialty_id'] ? ' open' : '' ?>>
      <summary>فیلتر و مرتب‌سازی</summary>
      <div class="form-grid three">
        <label>تخصص
          <select name="specialty_id">
            <option value="0">همه تخصص‌ها</option>
            <?php foreach ($specialties as $s): ?>
              <option value="<?= (int) $s['id'] ?>"<?= selected_attr($filters['specialty_id'] === (int) $s['id']) ?>><?= esc($s['name']) ?> (<?= fa_number($s['doctors']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>مرتب‌سازی
          <select name="sort">
            <?php foreach (['newest' => 'جدیدترین', 'name' => 'نام پزشک', 'first' => 'اولین نوبت آزاد'] as $v => $label): ?>
              <option value="<?= $v ?>"<?= selected_attr($filters['sort'] === $v) ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>بازه بررسی نوبت آزاد
          <select name="days">
            <?php foreach ([7, 14, 30, 90] as $n): ?>
              <option value="<?= $n ?>"<?= selected_attr($filters['days'] === $n) ?>><?= fa_number($n) ?> روز آینده</option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label class="check"><input type="checkbox" name="available" value="1"<?= checked_attr($filters['available']) ?>> فقط مطب‌های دارای نوبت آزاد در بازه انتخابی</label>
    </details>
  </form>
</section>



<?php if ($specialties): ?>
  <section class="specialty-strip" aria-label="تخصص‌های دارای پزشک فعال">
    <?php foreach ($specialties as $s): ?>
      <a class="specialty-chip<?= $filters['specialty_id'] === (int) $s['id'] ? ' current' : '' ?>" href="index.php?<?= esc(http_build_query(['specialty_id' => $s['id']] + array_diff_key($base, ['specialty_id' => 1]))) ?>">
        <?= specialty_visual($s) ?><span><?= esc(specialty_display_title($s['name'], $s['level'] ?? null)) ?></span><small><?= fa_number($s['doctors']) ?> پزشک</small>
      </a>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($searchError): ?><p class="error" role="alert"><?= esc($searchError) ?></p><?php endif; ?>

<div class="results-head">
  <h2><?= fa_number($total) ?> مطب با شرایط انتخابی</h2>
  <?php if ($city && $scope === 'all'): ?><span class="muted">نمایش سراسری؛ شهر انتخابی شما: <?= esc($city['name']) ?></span><?php endif; ?>
</div>
<div class="doctor-grid">
  <?php foreach ($rows as $r): ?>
    <article class="doctor-card">
      <div class="doctor-card-head">
        <?php if ($r['photo_file']): ?>
          <img class="avatar" src="photo.php?id=<?= (int) $r['id'] ?>" alt="" loading="lazy" width="64" height="64">
        <?php else: ?>
          <span class="avatar placeholder" aria-hidden="true"><?= esc(mb_substr($r['name'], 0, 1)) ?></span>
        <?php endif; ?>
        <div>
          <h3><a href="<?= esc(doctor_url((int) $r['id'], $r['name'], (int) $r['clinic_id'])) ?>"><?= esc($r['name']) ?></a></h3>
          <span class="tag"><?= esc(specialty_display_title($r['specialty'])) ?></span>
        </div>
      </div>
      <p class="doctor-clinic"><?= icon('building') ?> <?= esc($r['clinic_name']) ?></p>
      <p class="doctor-meta"><?= icon('pin') ?> <?= esc($r['province'] . '، ' . $r['city'] . '، ' . $r['address']) ?></p>
      <p class="first-free<?= $r['first_available'] ? '' : ' none' ?>"><?= icon('clock') ?>
        <?= $r['first_available'] ? 'نخستین زمان آزاد: <bdi>' . esc($r['first_available']['label']) . '</bdi>' : 'در بازه انتخابی زمان آزادی نداریم' ?>
      </p>
      <a class="button block" href="<?= esc(doctor_url((int) $r['id'], $r['name'], (int) $r['clinic_id'])) ?>">پروفایل و نوبت‌های این مطب</a>
    </article>
  <?php endforeach; ?>
</div>
<?php if (!$rows && !$searchError): ?>
  <div class="empty-state">
    <p>پزشک دارای اشتراک و مطب فعال برای این جستجو یافت نشد.</p>
    <?php if ($city && $scope === 'city' && $nearby): ?>
      <a class="button secondary" href="index.php?<?= esc(http_build_query(['scope' => 'nearby'] + array_diff_key($base, ['scope' => 1]))) ?>">جستجو در شهرهای اطراف</a>
    <?php elseif ($scope !== 'all'): ?>
      <a class="button secondary" href="index.php?<?= esc(http_build_query(['scope' => 'all'] + array_diff_key($base, ['scope' => 1]))) ?>">جستجو در سراسر کشور</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?= pagination($page, $total, 12, $base) ?>

<?php if ($centers): ?>
  <section class="centers-strip">
    <div class="results-head"><h2>مراکز درمانی <?= esc($city['name']) ?></h2><a href="centers.php?city=<?= (int) $city['id'] ?>">همه مراکز ←</a></div>
    <div class="center-grid">
      <?php foreach ($centers as $r): ?>
        <a class="center-card" href="clinic.php?id=<?= (int) $r['id'] ?>">
          <?php if ($r['logo_file']): ?><img src="media.php?kind=center&amp;file=<?= esc($r['logo_file']) ?>" alt="" width="56" height="56" loading="lazy"><?php else: ?><span class="center-logo"><?= icon('building') ?></span><?php endif; ?>
          <span><strong><?= esc($r['name']) ?></strong><small><?= esc(center_kinds()[$r['kind']] ?? '') ?> • <?= fa_number($r['doctors']) ?> پزشک</small></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($city && $nearby): ?>
  <section class="nearby">
    <h2>شهرهای اطراف <?= esc($city['name']) ?></h2>
    <div class="chip-row">
      <?php foreach ($nearby as $c): ?>
        <a class="chip" href="<?= esc(city_url((int) $c['id'], $c['name'])) ?>"><?= esc($c['name']) ?><?= $c['distance_km'] !== null ? ' <small>' . fa_number((int) round($c['distance_km'])) . ' کیلومتر</small>' : '' ?></a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
