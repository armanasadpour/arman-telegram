<?php /** @var array $c @var array $s @var array $post */ ?>
<?php if (!empty($s['perm_center']) && $c['review_status'] !== 'suspended'): ?>
  <form method="post" enctype="multipart/form-data" class="card stack">
    <?php form_token('profile'); ?>
    <p class="muted">تغییر نام، نوع، شماره پروانه یا تصویر مدرک، مرکز را برای بررسی مجدد ارسال می‌کند.</p>
    <?php render('center/form', ['v' => $post ?: $c, 'registering' => false]); ?>
    <button>ذخیره مشخصات</button>
  </form>
<?php else: ?>
  <article class="card">
    <dl class="facts">
      <dt>نام</dt><dd><?= esc($c['name']) ?></dd>
      <dt>نوع</dt><dd><?= esc(center_kinds()[$c['kind']] ?? '') ?></dd>
      <dt>شماره پروانه</dt><dd><bdi><?= esc(fa_number($c['license_number'])) ?></bdi></dd>
      <dt>نشانی</dt><dd><?= esc($c['province'] . '، ' . $c['city'] . '، ' . $c['address']) ?></dd>
      <dt>تلفن</dt><dd><bdi><?= esc(fa_number($c['phone'])) ?></bdi></dd>
    </dl>
  </article>
<?php endif; ?>
<?php if ($c['document_file'] && (int) $c['owner_user_id'] === (int) ($_SESSION['user_id'] ?? 0)): ?>
  <p><a href="center-document.php?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener">مشاهده تصویر پروانه ثبت‌شده</a></p>
<?php endif; ?>
