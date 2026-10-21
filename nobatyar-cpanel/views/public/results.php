<?php
/**
 * Doctor search results. Desktop: filters in the right column, results, optional map beside them.
 * Phone: filter / sort / map bar under the header; filters and sorting open as bottom sheets (search.js).
 * Without JavaScript the filter form shows above the results and every chip and link is a normal link.
 *
 * @var ?array $city @var int $province @var array $provinces @var string $scope @var array $nearby
 * @var array $cityIds @var array $specialties @var array $districts @var array $input @var array $result
 * @var array $base @var string $searchError @var string $notice @var string $specialtyName @var string $title
 */
$cfg = search_config();
$active = search_active_count($input);
$sortLink = fn(string $s) => 'index.php?' . search_query($input, $base, ['sort' => $s === $cfg['default_sort'] ? null : $s, 'lat' => null, 'lng' => null, 'page' => null]);
$mapQuery = search_query($input, $base);
?>
<?php if ($notice !== ''): ?>
  <p class="home-notice" role="note"><?= icon('megaphone') ?><span><?= esc($notice) ?></span></p>
<?php endif; ?>
<div class="search-page" data-search-page data-map-enabled="<?= $cfg['map'] ? '1' : '0' ?>" data-map-query="<?= esc($mapQuery) ?>"
     data-tiles="<?= esc(setting('map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png')) ?>" data-attribution="<?= esc(setting('map_attribution', '© OpenStreetMap')) ?>">
  <div class="search-toolbar" data-search-toolbar hidden>
    <button type="button" class="tool" data-sheet-open="filters" aria-controls="filter-panel">
      <?= icon('filter') ?><span>فیلترها</span><b class="tool-count" data-filter-count<?= $active ? '' : ' hidden' ?>><?= fa_number($active) ?></b>
    </button>
    <button type="button" class="tool" data-sheet-open="sort" aria-controls="sort-sheet"><?= icon('arrow-down') ?><span>مرتب‌سازی</span></button>
    <?php if ($cfg['map']): ?><button type="button" class="tool" data-map-toggle aria-pressed="false"><?= icon('map') ?><span data-map-label>نمایش روی نقشه</span></button><?php endif; ?>
  </div>

  <?php if (count($specialties) > 1): $current = array_map('intval', (array) $input['specialty']); ?>
    <nav class="spec-scroll" aria-label="دسته‌بندی تخصص‌ها">
      <a class="spec-card spec-all<?= $current ? '' : ' current' ?>" href="index.php?<?= esc(search_query($input, $base, ['specialty' => null, 'page' => null])) ?>"<?= $current ? '' : ' aria-current="page"' ?>><span class="spec-icon"><?= icon('grip') ?></span><strong>همه تخصص‌ها</strong></a>
      <?php foreach ($specialties as $s): $on = $current === [(int) $s['id']]; ?>
        <a class="spec-card<?= $on ? ' current' : '' ?>" href="index.php?<?= esc(search_query($input, $base, ['specialty' => $on ? null : [(int) $s['id']], 'page' => null])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
          <?= specialty_visual($s, 'spec-icon') ?><strong><?= esc(specialty_display_title($s['name'], $s['level'] ?? null)) ?></strong><small><?= fa_number($s['doctors']) ?> پزشک</small>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <div class="search-layout">
    <aside class="filter-panel" id="filter-panel" data-sheet="filters" aria-label="فیلترها">
      <div class="sheet-head"><span class="sheet-grip" aria-hidden="true"></span><h2>فیلترها</h2><button type="button" class="sheet-x" data-sheet-close aria-label="بستن"><?= icon('x') ?></button></div>
      <div data-filter-slot><?php render('public/results-filters', compact('input', 'city', 'scope', 'nearby', 'specialties', 'districts', 'base')); ?></div>
    </aside>

    <section class="results-main" aria-labelledby="search">
      <header class="results-top">
        <h1 id="search"><?= esc($title) ?></h1>
        <form method="get" action="index.php" class="results-search" role="search" data-suggest>
          <input type="hidden" name="view" value="doctors">
          <?php if ($scope !== ($city ? 'city' : 'all')): ?><input type="hidden" name="scope" value="<?= esc($scope) ?>"><?php endif; ?>
          <div class="search-main">
            <label class="sr-only" for="q">جستجو</label>
            <input id="q" name="q" type="search" value="<?= esc($input['q']) ?>" placeholder="نام پزشک، تخصص، مطب یا محله" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="q-suggest" aria-autocomplete="list" data-suggest-input>
            <button><?= icon('search') ?><span>جستجو</span></button>
            <div class="suggest-box" id="q-suggest" role="listbox" hidden data-suggest-list></div>
          </div>
        </form>
        <nav class="sort-inline" aria-label="مرتب‌سازی">
          <span>مرتب‌سازی:</span>
          <?php foreach (search_sorts() as $k => $label): ?>
            <?php if ($k === 'distance'): ?>
              <button type="button" class="sort-link<?= $input['sort'] === 'distance' ? ' current' : '' ?>" data-geo-sort hidden><?= esc($label) ?></button>
            <?php else: ?>
              <a class="sort-link<?= $input['sort'] === $k ? ' current' : '' ?>" href="<?= esc($sortLink($k)) ?>" data-search-link<?= $input['sort'] === $k ? ' aria-current="true"' : '' ?>><?= esc($label) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </header>
      <div class="results-body" data-results-body aria-live="polite">
        <?php render('public/results-list', ['result' => $result, 'input' => $input, 'base' => $base, 'city' => $city, 'cityIds' => $cityIds, 'append' => false, 'searchError' => $searchError, 'scope' => $scope, 'nearby' => $nearby]); ?>
      </div>
    </section>

    <?php if ($cfg['map']): ?>
      <section class="results-map" data-results-map hidden aria-label="نقشه مطب‌ها">
        <div class="results-map-canvas" data-map-canvas></div>
        <p class="map-note" data-map-note>فقط مطب‌هایی که نشانی آن‌ها روی نقشه ثبت شده نمایش داده می‌شوند.</p>
      </section>
    <?php endif; ?>
  </div>

  <div class="sort-sheet" id="sort-sheet" data-sheet="sort" hidden role="dialog" aria-modal="true" aria-labelledby="sort-title">
    <div class="sheet-head"><span class="sheet-grip" aria-hidden="true"></span><h2 id="sort-title">مرتب‌سازی</h2><button type="button" class="sheet-x" data-sheet-close aria-label="بستن"><?= icon('x') ?></button></div>
    <ul class="sort-options">
      <?php foreach (search_sorts() as $k => $label): ?>
        <li>
          <?php if ($k === 'distance'): ?>
            <button type="button" class="sort-option<?= $input['sort'] === 'distance' ? ' current' : '' ?>" data-geo-sort><span><?= esc($label) ?><small>با اجازه شما، موقعیت فقط برای همین مرتب‌سازی استفاده و ذخیره نمی‌شود.</small></span><?= icon('check', 'icon tick') ?></button>
          <?php else: ?>
            <a class="sort-option<?= $input['sort'] === $k ? ' current' : '' ?>" href="<?= esc($sortLink($k)) ?>" data-search-link><span><?= esc($label) ?></span><?= icon('check', 'icon tick') ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="sheet-backdrop search-backdrop" data-search-backdrop hidden></div>
</div>
<script src="assets/search.js?v=<?= esc(APP_VERSION) ?>" defer></script>
