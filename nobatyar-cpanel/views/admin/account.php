<?php /** @var array $me @var ?array $role @var array $permissions */ ?>
<div class="grid-2 align-start">
  <section class="panel">
    <h2>حساب من</h2>
    <dl class="receipt-details">
      <div><dt>نام</dt><dd><?= esc($me['name'] ?: '—') ?></dd></div>
      <div><dt>ایمیل</dt><dd dir="ltr"><?= esc($me['email']) ?></dd></div>
      <div><dt>نقش</dt><dd><?= esc($role['label'] ?? '') ?> — <small><?= esc($role['note'] ?? '') ?></small></dd></div>
      <div><dt>آخرین ورود</dt><dd><bdi><?= esc(jdate($me['last_login_at'] ?? null, true)) ?></bdi></dd></div>
    </dl>
    <h3>دسترسی‌های شما</h3>
    <ul class="perm-chips"><?php foreach ($permissions as $p => $label): ?><li class="<?= in_array($p, $role['perms'] ?? [], true) ? 'on' : '' ?>"><?= esc($label) ?></li><?php endforeach; ?></ul>
  </section>
  <section class="panel">
    <h2>تغییر رمز عبور</h2>
    <form method="post" class="stack" autocomplete="off">
      <?php form_token('admin_password'); ?>
      <?php input('رمز فعلی', 'current', '', 'password', true, 255); ?>
      <?php input('رمز جدید (حداقل ۱۲ نویسه، حرف و عدد)', 'password', '', 'password', true, 255); ?>
      <button>تغییر رمز</button>
    </form>
    <p class="muted small">پس از تغییر رمز، نشست‌های شما روی دستگاه‌های دیگر بسته می‌شود.</p>
  </section>
</div>
