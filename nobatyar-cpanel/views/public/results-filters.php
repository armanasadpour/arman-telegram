<?php
/**
 * Filter form: right column on desktop, bottom sheet on phones (assets/search.js), plain form without JS.
 * Only filters enabled in «تنظیمات جستجو» are shown; the server validates every value again.
 *
 * @var array $input @var ?array $city @var string $scope @var array $nearby @var array $specialties
 * @var array $districts @var array $base
 */
$cfg = search_config();
$on = $cfg['filters'];
$sel = array_flip($input['specialty']);
?>
<form method="get" action="index.php" class="filter-form" data-filter-form>
  <input type="hidden" name="view" value="doctors">
  <?php if ($input['sort'] !== $cfg['default_sort']): ?><input type="hidden" name="sort" value="<?= esc($input['sort']) ?>"><?php endif; ?>
  <?php if ($input['sort'] === 'distance' && $input['lat'] !== null): ?><input type="hidden" name="lat" value="<?= esc((string) $input['lat']) ?>"><input type="hidden" name="lng" value="<?= esc((string) $input['lng']) ?>"><?php endif; ?>
  <?php if (!$city && !empty($base['province'])): ?><input type="hidden" name="province" value="<?= (int) $base['province'] ?>"><?php endif; ?>

  <?php if ($on['location']): ?>
    <fieldset class="filter-group">
      <legend>شهر و محدوده</legend>
      <p class="filter-city"><?= icon('pin') ?><b><?= $city ? esc($city['name']) : 'همه شهرها' ?></b><a href="<?= esc('city.php?back=' . rawurlencode('index.php?' . search_query($input, []))) ?>" class="link-button">تغییر شهر</a></p>
      <?php if ($city && $on['local']): ?>
        <div class="seg">
          <label><input type="radio" name="scope" value="city"<?= checked_attr($scope === 'city') ?>><span>فقط <?= esc($city['name']) ?></span></label>
          <?php if ($nearby): ?><label><input type="radio" name="scope" value="nearby"<?= checked_attr($scope === 'nearby') ?>><span>شهرهای اطراف</span></label><?php endif; ?>
          <label><input type="radio" name="scope" value="all"<?= checked_attr($scope === 'all') ?>><span>سراسر کشور</span></label>
        </div>
      <?php elseif ($city && $scope !== 'city'): ?>
        <input type="hidden" name="scope" value="<?= esc($scope) ?>">
      <?php endif; ?>
    </fieldset>
  <?php endif; ?>

  <?php if ($on['name']): ?>
    <fieldset class="filter-group">
      <legend>نام پزشک یا مطب</legend>
      <label class="sr-only" for="f-q">نام پزشک، تخصص یا مطب</label>
      <input id="f-q" name="q" type="search" value="<?= esc($input['q']) ?>" placeholder="مثلاً نام پزشک" maxlength="100" autocomplete="off">
    </fieldset>
  <?php endif; ?>

  <?php if ($on['specialty'] && $specialties): ?>
    <fieldset class="filter-group">
      <legend>تخصص و فوق‌تخصص</legend>
      <div class="filter-scroll">
        <?php foreach ($specialties as $s): ?>
          <label class="check"><input type="checkbox" name="specialty[]" value="<?= (int) $s['id'] ?>"<?= checked_attr(isset($sel[(int) $s['id']])) ?>> <span><?= esc(specialty_display_title($s['name'], $s['level'] ?? null)) ?></span> <small><?= fa_number($s['doctors']) ?></small></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>

  <?php if ($on['available'] || $on['day'] || $on['range']): ?>
    <fieldset class="filter-group">
      <legend>زمان نوبت</legend>
      <?php if ($on['available']): ?><label class="check switch"><input type="checkbox" name="available" value="1"<?= checked_attr($input['available']) ?>> <span>فقط پزشکان دارای نوبت آزاد</span></label><?php endif; ?>
      <?php if ($on['day']): ?>
        <div class="seg">
          <label><input type="radio" name="day" value=""<?= checked_attr($input['day'] === '') ?>><span>هر زمان</span></label>
          <label><input type="radio" name="day" value="today"<?= checked_attr($input['day'] === 'today') ?>><span>امروز</span></label>
          <label><input type="radio" name="day" value="tomorrow"<?= checked_attr($input['day'] === 'tomorrow') ?>><span>فردا</span></label>
        </div>
      <?php endif; ?>
      <?php if ($on['range']): ?>
        <div class="filter-range">
          <label>از تاریخ<input data-jdate name="from" value="<?= esc($input['from'] ? jdate($input['from']) : '') ?>" placeholder="<?= esc(jdate(date('Y-m-d'))) ?>" inputmode="numeric" maxlength="10" dir="ltr"></label>
          <label>تا تاریخ<input data-jdate name="to" value="<?= esc($input['to'] ? jdate($input['to']) : '') ?>" placeholder="<?= esc(jdate(date('Y-m-d', strtotime('+6 days')))) ?>" inputmode="numeric" maxlength="10" dir="ltr"></label>
        </div>
        <small class="muted">بازه حداکثر ۱۴ روز</small>
      <?php endif; ?>
    </fieldset>
  <?php endif; ?>

  <?php if ($on['gender']): ?>
    <fieldset class="filter-group">
      <legend>جنسیت پزشک</legend>
      <div class="seg">
        <label><input type="radio" name="gender" value=""<?= checked_attr($input['gender'] === '') ?>><span>فرقی ندارد</span></label>
        <label><input type="radio" name="gender" value="female"<?= checked_attr($input['gender'] === 'female') ?>><span>خانم</span></label>
        <label><input type="radio" name="gender" value="male"<?= checked_attr($input['gender'] === 'male') ?>><span>آقا</span></label>
      </div>
    </fieldset>
  <?php endif; ?>

  <?php if ($on['center']): ?>
    <fieldset class="filter-group">
      <legend>نوع مرکز درمانی</legend>
      <?php foreach (center_groups() as $k => $g): ?>
        <label class="check"><input type="checkbox" name="center[]" value="<?= esc($k) ?>"<?= checked_attr(in_array($k, $input['center'], true)) ?>> <span><?= esc($g['label']) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
  <?php endif; ?>

  <?php if ($on['district'] && $districts): ?>
    <fieldset class="filter-group">
      <legend>محله یا محدوده<?= $city ? ' در ' . esc($city['name']) : '' ?></legend>
      <div class="filter-scroll">
        <?php foreach ($districts as $name => $n): ?>
          <label class="check"><input type="checkbox" name="district[]" value="<?= esc($name) ?>"<?= checked_attr(in_array($name, $input['district'], true)) ?>> <span><?= esc($name) ?></span> <small><?= fa_number($n) ?></small></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>

  <div class="filter-actions">
    <button><?= icon('check') ?> نمایش نتایج</button>
    <a class="button ghost" href="<?= esc('index.php?' . http_build_query(['view' => 'doctors'] + $base)) ?>" data-search-link data-clear-filters>پاک کردن همه</a>
  </div>
</form>
