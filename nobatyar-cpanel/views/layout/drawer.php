<?php
if (!empty($GLOBALS['panel_navigation'])) {
    render('layout/panel-drawer', ['panel'=>$GLOBALS['panel_navigation']]);
    return;
}

/**
 * Phone side menu. Opened by the header button (assets/shell.js); hidden without JavaScript, where the
 * header links, footer and bottom navigation cover the same pages.
 *
 * @var ?array $user @var bool $isAdmin @var array $menu @var array $banner @var string $siteName @var ?array $auth
 */
$here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$isCurrent = fn(string $url) => $url !== '' && strtok($url, '#') === $here;
?>
<div class="drawer-backdrop" data-drawer-backdrop hidden></div>
<aside class="drawer" id="side-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title" data-drawer hidden>
  <div class="drawer-head">
    <a class="brand drawer-brand" href="index.php"><?= site_brand_logo() ?><span id="drawer-title"<?= site_logo_url() ? ' class="sr-only"' : '' ?>><?= esc($siteName) ?></span></a>
    <button type="button" class="drawer-close" data-drawer-close aria-label="بستن منو"><?= icon('x') ?></button>
  </div>
  <div class="drawer-body" data-drawer-scroll>
    <?php if ($banner['enabled']): ?>
      <?php $tag = $banner['url'] !== '' ? 'a' : 'div'; ?>
      <<?= $tag ?> class="drawer-banner theme-<?= esc($banner['theme']) ?>"<?= $tag === 'a' ? ' href="' . esc($banner['url']) . '"' : '' ?>>
        <span class="drawer-banner-text">
          <?php if ($banner['title'] !== ''): ?><strong><?= esc($banner['title']) ?></strong><?php endif; ?>
          <?php if ($banner['text'] !== ''): ?><small><?= esc($banner['text']) ?></small><?php endif; ?>
          <?php if ($banner['button'] !== ''): ?><span class="drawer-banner-btn"><?= esc($banner['button']) ?></span><?php endif; ?>
        </span>
        <?php if ($banner['image'] !== ''): ?><img src="<?= esc(home_image_url($banner['image'])) ?>" alt="" width="180" height="75" loading="lazy" decoding="async"><?php endif; ?>
      </<?= $tag ?>>
    <?php endif; ?>

    <?php if ($user): ?>
      <div class="drawer-user">
        <span class="drawer-avatar" aria-hidden="true"><?= esc(mb_substr(trim((string) $user['name']) ?: 'ک', 0, 1)) ?></span>
        <span class="drawer-user-text"><strong><?= esc(trim((string) $user['name']) ?: 'حساب کاربری') ?></strong><small dir="ltr"><?= esc(fa_number($user['mobile'])) ?></small></span>
      </div>
      <div class="drawer-account">
        <a href="panel.php"><?= icon('user') ?>حساب من</a>
        <a href="notifications.php"><?= icon('bell') ?>اعلان‌ها</a>
        <form method="post" action="panel.php" class="drawer-logout"><input type="hidden" name="csrf" value="<?= esc(csrf()) ?>"><input type="hidden" name="action" value="logout"><button><?= icon('logout') ?>خروج</button></form>
      </div>
    <?php else: ?>
      <a class="button drawer-login" href="login.php" data-auth-open><?= icon('login') ?> ورود یا ثبت‌نام</a>
    <?php endif; ?>

    <nav class="drawer-nav" aria-label="منوی سایت">
      <ul>
        <?php if ($isAdmin): ?><li><a class="drawer-item" href="admin.php"><?= icon('layout', 'icon drawer-icon') ?><span>پنل مدیریت</span><?= icon('chevron-left', 'icon drawer-chev') ?></a></li><?php endif; ?>
        <?php foreach ($menu as $i => $item): ?>
          <li>
            <?php if (!empty($item['children'])): ?>
              <?php $open = (bool) array_filter($item['children'], fn($c) => $isCurrent($c['url'])); ?>
              <button type="button" class="drawer-item" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="drawer-sub-<?= $i ?>" data-drawer-group>
                <?= icon($item['icon'], 'icon drawer-icon') ?><span><?= esc($item['label']) ?></span><?= icon('chevron-down', 'icon drawer-chev') ?>
              </button>
              <div class="drawer-sub" id="drawer-sub-<?= $i ?>"<?= $open ? '' : ' hidden' ?>>
                <ul>
                  <?php foreach ($item['children'] as $c): ?>
                    <li><a href="<?= esc($c['url']) ?>"<?= $isCurrent($c['url']) ? ' aria-current="page"' : '' ?>><?= esc($c['label']) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php else: ?>
              <a class="drawer-item" href="<?= esc($item['url']) ?>"<?= $isCurrent($item['url']) ? ' aria-current="page"' : '' ?><?= isset($item['auth']) && $auth ? ' data-auth-open="' . esc($item['auth']) . '"' : '' ?>>
                <?= icon($item['icon'], 'icon drawer-icon') ?><span><?= esc($item['label']) ?></span><?= icon('chevron-left', 'icon drawer-chev') ?>
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
  </div>
</aside>
