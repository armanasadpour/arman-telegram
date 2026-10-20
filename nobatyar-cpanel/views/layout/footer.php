<?php /** @var string $siteName @var array $contact @var array $bottomNav @var array $footer @var ?array $shell */ ?>
</main>
<?php $adminShell = str_contains((string) ($options['body_class'] ?? ''), 'admin-page'); ?>
<?php if (!$adminShell && empty($GLOBALS['panel_navigation'])): ?>
<footer class="site-footer">
  <div class="footer-grid">
    <section class="footer-about">
      <a class="brand" href="index.php"><?= site_brand_logo() ?><?php if (!site_logo_url()): ?><span><?= esc($siteName) ?><small>سامانه نوبت‌دهی پزشکان</small></span><?php endif; ?></a>
      <?php if ($footer['about'] !== ''): ?><p><?= esc($footer['about']) ?></p><?php endif; ?>
      <?php if ($footer['social']): ?>
        <ul class="social-links" aria-label="شبکه‌های اجتماعی">
          <?php foreach ($footer['social'] as $k => $url): ?><li><a href="<?= esc($url) ?>" rel="noopener" target="_blank"><?= esc(social_networks()[$k]) ?></a></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <nav class="footer-col" aria-label="پیوندهای مهم">
      <h2>پیوندهای مهم</h2>
      <a href="index.php">صفحه اصلی</a>
      <a href="index.php?view=doctors">نوبت‌دهی پزشکان</a>
      <a href="specialties.php">تخصص‌ها</a>
      <a href="centers.php">مراکز درمانی</a>
      <a href="articles.php">مقالات سلامت</a>
      <a href="app-install.php">نصب برنامه</a>
    </nav>
    <nav class="footer-col" aria-label="راهنما و قوانین">
      <h2>راهنما</h2>
      <?php foreach (['patient-guide', 'doctor-guide', 'terms', 'privacy', 'about'] as $slug): if (isset($footer['pages'][$slug])): ?>
        <a href="<?= esc(page_url($slug)) ?>"><?= esc($footer['pages'][$slug]) ?></a>
      <?php endif; endforeach; ?>
      <a href="register.php">ثبت‌نام پزشک</a>
      <a href="center-register.php">ثبت مرکز درمانی</a>
    </nav>
    <section class="footer-col" aria-label="تماس">
      <h2>تماس با ما</h2>
      <?php if ($contact['phone']): ?><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $contact['phone'])) ?>"><?= icon('phone') ?><bdi><?= esc(fa_number($contact['phone'])) ?></bdi></a><?php endif; ?>
      <?php if ($contact['email']): ?><a href="mailto:<?= esc($contact['email']) ?>"><?= icon('mail') ?><span dir="ltr"><?= esc($contact['email']) ?></span></a><?php endif; ?>
      <?php if ($footer['address'] !== ''): ?><span><?= icon('pin') ?><?= esc($footer['address']) ?></span><?php endif; ?>
      <?php if ($contact['hours']): ?><span><?= icon('clock') ?><?= esc($contact['hours']) ?></span><?php endif; ?>
      <a href="support.php"><?= icon('chat') ?>پشتیبانی آنلاین</a>
      <?php if (isset($footer['pages']['contact'])): ?><a href="<?= esc(page_url('contact')) ?>"><?= icon('help') ?><?= esc($footer['pages']['contact']) ?></a><?php endif; ?>
    </section>
  </div>
  <?php if ($footer['badges']): ?>
    <div class="trust-badges" aria-label="نمادها و مجوزها">
      <?php foreach ($footer['badges'] as $b): ?>
        <?php $img = '<img src="' . esc(home_image_url($b['image'])) . '" alt="' . esc($b['title'] ?: 'نماد') . '" width="80" height="80" loading="lazy">'; ?>
        <?= $b['url'] !== '' ? '<a href="' . esc($b['url']) . '" rel="noopener" target="_blank">' . $img . '</a>' : $img ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="footer-bottom">
    <?php if ($contact['footer']): ?><p><?= esc($contact['footer']) ?></p><?php endif; ?>
    <p>© <?= esc(fa_number(jalali_parts(date('Y-m-d'))[0])) ?> <?= esc($siteName) ?> • نسخه <?= esc(APP_VERSION_FA) ?></p>
    <small>هزینه ویزیت در مطب پرداخت می‌شود؛ <?= esc($siteName) ?> فقط کارمزد خدمات رزرو را دریافت می‌کند.</small>
  </div>
</footer>
<?php endif; ?>
<?php if ($bottomNav): ?>
<nav class="bottom-nav" aria-label="منوی پایین">
  <?php foreach ($bottomNav as $item): ?>
    <a href="<?= esc($item['url']) ?>"<?= $item['current'] ? ' class="current" aria-current="page"' : '' ?><?= isset($item['auth']) ? ' data-auth-open="' . esc($item['auth']) . '"' : '' ?>><?= icon($item['icon']) ?><span><?= esc($item['label']) ?></span><?php if (!empty($item['count'])): ?><b class="bottom-count"><?= fa_number(min(99, $item['count'])) ?></b><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($shell): ?><?php render('layout/drawer', $shell); ?><?php if ($shell['auth']): ?><?php render('auth/sheet', $shell['auth']); ?><?php endif; ?><?php endif; ?>
<?php if (!$adminShell && $contact['phone'] && !empty($contact['fab']) && !str_contains((string) ($options['body_class'] ?? ''), 'queue-display')): ?>
<a class="support-fab" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $contact['phone'])) ?>" aria-label="تماس با پشتیبانی" title="تماس با پشتیبانی"><?= icon('headset') ?></a>
<?php endif; ?>
<?php $pwaApp = $adminShell ? '' : (['doctor-panel.php' => 'doctor', 'center.php' => 'center', 'patient.php' => 'patient', 'panel.php' => 'patient'][basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))] ?? ''); ?>
<?php if ($pwaApp): $pwaName = ['doctor' => 'پنل پزشک', 'center' => 'پنل مرکز درمانی', 'patient' => $siteName][$pwaApp]; ?>
<div class="pwa-banner" data-pwa-banner="<?= $pwaApp ?>" role="dialog" aria-labelledby="pwa-title" hidden>
  <img src="assets/icons/icon-192.png" alt="" width="48" height="48">
  <div class="pwa-text">
    <strong id="pwa-title">نصب برنامه «<?= esc($pwaName) ?>»</strong>
    <span data-hint="prompt">با یک لمس از صفحه اصلی باز می‌شود و اعلان نوبت‌ها را دریافت می‌کنید.</span>
    <span data-hint="ios">در Safari دکمه اشتراک‌گذاری <?= icon('share', 'icon pwa-inline') ?> و سپس «<bdi dir="ltr">Add to Home Screen</bdi>» را بزنید.</span>
    <span data-hint="menu">از منوی <b>⋮</b> مرورگر، «نصب برنامه» یا «افزودن به صفحه اصلی» را بزنید.</span>
  </div>
  <button type="button" class="pwa-install" data-pwa-install><?= icon('install') ?> نصب</button>
  <button type="button" class="pwa-close" data-pwa-close aria-label="بستن"><?= icon('x') ?></button>
</div>
<?php endif; ?>
<p class="net-status" data-net-status hidden aria-live="assertive">اتصال اینترنت قطع است؛ تا برقراری دوباره، اطلاعات تازه نمایش داده نمی‌شود.</p>
<script src="assets/release.js?v=<?= esc(APP_VERSION) ?>" defer></script>
<script src="assets/app.js?v=<?= esc(APP_VERSION) ?>" defer></script>
<script src="assets/jdate.js?v=<?= esc(APP_VERSION) ?>" defer></script>
<?php if (str_contains((string) ($options['body_class'] ?? ''), 'doctor-register-page')): ?><script src="assets/doctor-register.js?v=<?= esc(APP_VERSION) ?>" defer></script><?php endif; ?>
<?php if ($adminShell): ?><script src="assets/admin.js?v=<?= esc(APP_VERSION) ?>" defer></script><?php endif; ?>
<?php if ($shell): ?><script src="assets/shell.js?v=<?= esc(APP_VERSION) ?>" defer></script><?php endif; ?>
</body>
</html>
