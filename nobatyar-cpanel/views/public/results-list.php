<?php
/**
 * Results body: count, active filter chips, cards, empty state and "show more". Also returned alone
 * (index.php?partial=1) so filters update without reloading; partial=more returns only the next cards.
 *
 * @var array $result @var array $input @var array $base @var ?array $city @var array $cityIds @var bool $append
 * @var string $searchError @var string $scope @var array $nearby
 */
$cfg = search_config();
$link = fn(array $change = []) => 'index.php?' . search_query($input, $base, $change);
$next = $result['page'] < $result['pages'] ? $result['page'] + 1 : 0;
$more = function () use ($next, $link, $result) {
    if (!$next) {
        return;
    }
    $href = $link(['page' => $next]);
    echo '<div class="load-more-wrap" data-load-more-wrap><a class="button secondary load-more" href="' . esc($href) . '" data-load-more>' . icon('refresh') . ' نمایش پزشکان بیشتر <small>(' . fa_number($result['total'] - $result['page'] * $result['per_page']) . ' پزشک دیگر)</small></a></div>';
};
if ($append) {
    foreach ($result['items'] as $d) {
        render('public/result-card', ['d' => $d, 'cfg' => $cfg]);
    }
    $more();
    return;
}
$chips = [];
if ($city && $scope !== 'all') {
    $chips[] = [($scope === 'nearby' ? $city['name'] . ' و اطراف' : $city['name']), 'index.php?' . search_query($input, ['scope' => 'all'] + array_diff_key($base, ['scope' => 1])), 'location'];
}
if ($input['specialty']) {
    $names = query('SELECT id,name FROM specialties WHERE id IN (' . placeholders($input['specialty']) . ')', $input['specialty'])->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($input['specialty'] as $sid) {
        if (isset($names[$sid])) {
            $chips[] = [$names[$sid], $link(['specialty' => ['remove' => $sid], 'page' => null]), 'specialty'];
        }
    }
}
if ($input['q'] !== '') {
    $chips[] = ['«' . $input['q'] . '»', $link(['q' => null, 'page' => null]), 'q'];
}
if ($input['gender']) {
    $chips[] = [$input['gender'] === 'female' ? 'پزشک خانم' : 'پزشک آقا', $link(['gender' => null, 'page' => null]), 'gender'];
}
if ($input['available']) {
    $chips[] = ['دارای نوبت آزاد', $link(['available' => null, 'page' => null]), 'available'];
}
if ($input['day']) {
    $chips[] = [$input['day'] === 'today' ? 'نوبت امروز' : 'نوبت فردا', $link(['day' => null, 'page' => null]), 'day'];
}
if ($input['from']) {
    $chips[] = ['از ' . jdate($input['from']) . ' تا ' . jdate($input['to']), $link(['from' => null, 'to' => null, 'page' => null]), 'range'];
}
foreach ($input['center'] as $g) {
    $chips[] = [center_groups()[$g]['label'], $link(['center' => ['remove' => $g], 'page' => null]), 'center'];
}
foreach ($input['district'] as $dname) {
    $chips[] = [$dname, $link(['district' => ['remove' => $dname], 'page' => null]), 'district'];
}
$active = search_active_count($input);
?>
<div class="results-meta" data-active-count="<?= $active ?>" data-total="<?= (int) $result['total'] ?>">
  <?php if ($chips): ?>
    <ul class="active-chips" aria-label="فیلترهای فعال">
      <?php foreach ($chips as [$label, $href, $kind]): ?>
        <li><a href="<?= esc($href) ?>" data-search-link aria-label="حذف فیلتر <?= esc($label) ?>"><span><?= esc($label) ?></span><?= icon('x') ?></a></li>
      <?php endforeach; ?>
      <?php if ($active > 1): ?><li class="clear"><a href="<?= esc('index.php?' . http_build_query(['view' => 'doctors'] + $base)) ?>" data-search-link>پاک کردن همه</a></li><?php endif; ?>
    </ul>
  <?php endif; ?>
  <p class="results-count" role="status"><?= $result['total'] ? '<b>' . fa_number($result['total']) . '</b> پزشک یافت شد' : 'پزشکی یافت نشد' ?><?php if ($result['total'] && $input['sort'] !== 'first'): ?> <span>• مرتب‌شده بر اساس <?= esc(search_sorts()[$input['sort']] ?? '') ?></span><?php endif; ?></p>
  <?php if ($searchError): ?><p class="notice" role="alert"><?= esc($searchError) ?></p><?php endif; ?>
</div>
<?php if ($result['items']): ?>
  <div class="result-list" data-result-list>
    <?php foreach ($result['items'] as $d): ?><?php render('public/result-card', ['d' => $d, 'cfg' => $cfg]); ?><?php endforeach; ?>
    <?php $more(); ?>
  </div>
  <?php if ($result['pages'] > 1): ?>
    <nav class="pagination results-pages" aria-label="صفحه‌های نتایج" data-pages>
      <?php for ($i = max(1, $result['page'] - 2); $i <= min($result['pages'], $result['page'] + 2); $i++): ?>
        <a class="page-link<?= $i === $result['page'] ? ' current' : '' ?>" href="<?= esc($link(['page' => $i])) ?>"<?= $i === $result['page'] ? ' aria-current="page"' : '' ?>><?= fa_number($i) ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php else: ?>
  <div class="results-empty">
    <span class="results-empty-icon"><?= icon('search') ?></span>
    <h2>پزشکی با این شرایط پیدا نشد</h2>
    <p>شاید یکی از فیلترهای زیر نتایج را بیش از حد محدود کرده باشد:</p>
    <div class="chip-row">
      <?php foreach ($chips as [$label, $href, $kind]): ?>
        <a class="chip" href="<?= esc($href) ?>" data-search-link>حذف «<?= esc($label) ?>»</a>
      <?php endforeach; ?>
      <?php if ($city && $scope === 'city' && $nearby): ?><a class="chip" href="<?= esc('index.php?' . search_query($input, ['scope' => 'nearby'] + array_diff_key($base, ['scope' => 1]))) ?>" data-search-link>جستجو در شهرهای اطراف</a><?php endif; ?>
      <?php if ($scope !== 'all'): ?><a class="chip" href="<?= esc('index.php?' . search_query($input, ['scope' => 'all'] + array_diff_key($base, ['scope' => 1]))) ?>" data-search-link>جستجو در سراسر کشور</a><?php endif; ?>
    </div>
  </div>
<?php endif; ?>
