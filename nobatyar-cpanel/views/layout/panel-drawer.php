<?php /** @var array $panel */ ?>
<div class="drawer-backdrop" data-drawer-backdrop hidden></div>
<aside class="drawer" id="side-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title" data-drawer hidden>
  <div class="drawer-head"><a class="brand drawer-brand" href="<?= esc($panel['home']) ?>"><?= site_brand_logo() ?><span id="drawer-title"><?= esc($panel['title']) ?></span></a><button type="button" class="drawer-close" data-drawer-close aria-label="بستن منو"><?= icon('x') ?></button></div>
  <div class="drawer-body" data-drawer-scroll><nav class="drawer-nav" aria-label="<?= esc($panel['title']) ?>"><ul>
  <?php foreach ($panel['menu'] as $item): ?><li><a class="drawer-item" href="<?= esc($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>><?= icon($item['icon'],'icon drawer-icon') ?><span><?= esc($item['label']) ?></span><?= icon('chevron-left','icon drawer-chev') ?></a></li><?php endforeach; ?>
  <li class="panel-home-link"><a class="drawer-item" href="index.php"><?= icon('home','icon drawer-icon') ?><span>بازگشت به صفحه اصلی نوبت‌یار</span><?= icon('chevron-left','icon drawer-chev') ?></a></li>
  </ul></nav></div>
</aside>
