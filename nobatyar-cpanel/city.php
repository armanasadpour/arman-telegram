<?php
declare(strict_types=1);

/**
 * City picker: quick search across all active cities, browse by province, or pick a provincial capital.
 * Works without JavaScript; app.js adds instant suggestions on the same markup.
 */

require __DIR__ . '/core.php';
require_schema();

$back = (string) ($_GET['back'] ?? 'index.php');
if (!preg_match('/^[a-z0-9_-]+\.php(\?[^\s<>"]*)?$/i', $back)) {
    $back = 'index.php';
}
// The province/city form sends «city»; links and older pages send «select».
if (empty($_GET['select']) && !empty($_GET['city'])) {
    $_GET['select'] = $_GET['city'];
}
if (!empty($_GET['select'])) {
    try {
        $city = set_visitor_city((int) $_GET['select']);
        flash('شهر ' . $city['name'] . ' انتخاب شد.');
        $target = $back === 'index.php' ? 'index.php?city=' . (int) $city['id'] : $back;
        redirect($target);
    } catch (AuthError $e) {
        flash($e->getMessage());
        redirect('city.php');
    }
}

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
$province = (int) ($_GET['province'] ?? 0);
$current = visitor_city();
$results = $q !== '' ? city_search($q, 40) : [];
$provinceCities = $province ? cities_of_province($province) : [];

$selProvince = $province ?: (int) ($current['province_id'] ?? 0);
$cities = $selProvince ? cities_of_province($selProvince) : [];
layout_start('انتخاب شهر', ['body_class' => 'city-pick-page']);
?>
<section class="city-pick">
  <div class="cp-head">
    <span class="cp-icon"><?= icon('pin') ?></span>
    <div>
      <h1>انتخاب شهر</h1>
      <p class="muted"><?= $current ? 'شهر فعلی شما: <strong>' . esc($current['name']) . '</strong> (' . esc($current['province_name']) . ')' : 'استان و شهر خود را انتخاب کنید تا پزشکان نزدیک شما نمایش داده شوند.' ?></p>
    </div>
  </div>

  <form method="get" action="city.php" class="cp-form" data-geo data-city-form>
    <input type="hidden" name="back" value="<?= esc($back) ?>">
    <label class="field"><span>استان</span>
      <select name="province" required>
        <option value="">انتخاب استان</option>
        <?php foreach (provinces_list() as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected_attr($selProvince === (int) $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>شهر</span>
      <select name="city" data-autosubmit data-selected="<?= (int) ($current['id'] ?? 0) ?>">
        <option value=""><?= $selProvince ? 'انتخاب شهر' : 'ابتدا استان را انتخاب کنید' ?></option>
        <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected_attr((int) ($current['id'] ?? 0) === (int) $c['id']) ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button class="button block"><?= icon('check') ?> تأیید شهر</button>
    <noscript><p class="cp-hint muted">پس از انتخاب استان، دکمه را بزنید تا شهرهای آن استان نمایش داده شود.</p></noscript>
  </form>

  <details class="cp-search"<?= $q !== '' ? ' open' : '' ?>>
    <summary><?= icon('search') ?> جستجوی نام شهر</summary>
    <form method="get" class="city-search" role="search">
      <input type="hidden" name="back" value="<?= esc($back) ?>">
      <label class="sr-only" for="city-q">جستجوی شهر</label>
      <input id="city-q" name="q" value="<?= esc($q) ?>" placeholder="مثلاً بستک" autocomplete="off" data-city-search data-back="<?= esc($back) ?>">
      <button class="button secondary"><?= icon('search') ?><span>جستجو</span></button>
      <ul class="city-suggestions" data-city-results hidden></ul>
    </form>
    <?php if ($q !== ''): ?>
      <?php if (!$results): ?><p class="muted">شهری با این نام پیدا نشد.</p><?php endif; ?>
      <div class="chip-row">
        <?php foreach ($results as $c): ?>
          <a class="chip" href="city.php?select=<?= (int) $c['id'] ?>&amp;back=<?= esc(rawurlencode($back)) ?>"><?= esc($c['name']) ?> <small><?= esc($c['province_name']) ?></small></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </details>
</section>
<?php
layout_end();
