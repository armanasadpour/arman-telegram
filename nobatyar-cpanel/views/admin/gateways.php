<?php
/** @var array $gateways @var string $default @var string $fallback @var bool $development @var string $callback @var array $tests */
$usable = array_keys(array_filter($gateways, fn($g) => $g['usable']));
?>
<section class="panel">
  <h2>درگاه‌های پرداخت</h2>
  <p>هر درگاه فقط پس از یک <strong>پرداخت آزمایشی واقعی و موفق</strong> با همان اطلاعات ذخیره‌شده قابل فعال‌سازی است. با تغییر هر اطلاعات پذیرندگی، تأیید قبلی باطل و درگاه غیرفعال می‌شود. مبلغ آزمون <?= esc(toman(setting('payment_test_amount', '1000'))) ?> است و به حساب پذیرنده خودتان واریز می‌شود.</p>
  <p class="muted">نشانی بازگشت همه درگاه‌ها: <bdi dir="ltr"><?= esc($callback) ?></bdi> — این دامنه باید در پنل بانک ثبت شده باشد و IP سرور هاست نزد بانک (سامان، سداد، ملت، آپ) معرفی شود.</p>
  <form method="post" class="filter-bar">
    <?php form_token('gateway_roles'); ?>
    <label>درگاه پیش‌فرض
      <select name="default"><option value="">— خودکار —</option>
        <?php foreach ($usable as $n): ?><option value="<?= esc($n) ?>"<?= selected_attr($default === $n) ?>><?= esc($gateways[$n]['meta']['label']) ?></option><?php endforeach; ?>
      </select></label>
    <label>درگاه جایگزین (در صورت خطای پیش‌فرض)
      <select name="fallback"><option value="">— ندارد —</option>
        <?php foreach ($usable as $n): ?><option value="<?= esc($n) ?>"<?= selected_attr($fallback === $n) ?>><?= esc($gateways[$n]['meta']['label']) ?></option><?php endforeach; ?>
      </select></label>
    <button>ذخیره ترتیب</button>
  </form>
  <?php if (!$usable): ?><p class="notice">هیچ درگاهی فعال نیست؛ پرداخت آنلاین (کارمزد رزرو و اشتراک) در دسترس نیست و فقط رزرو رایگان و پرداخت اشتراک خارج از سامانه ممکن است.</p><?php endif; ?>
</section>

<div class="gateway-grid">
<?php foreach ($gateways as $name => $g): $row = $g['row']; $cfg = $g['config']; ?>
  <section class="panel gateway-card" id="gw-<?= esc($name) ?>">
    <header class="gateway-head">
      <h2><?= icon('card') ?> <?= esc($g['meta']['label']) ?></h2>
      <?php if ($g['usable']): ?><span class="badge status-confirmed">فعال</span>
      <?php elseif ($g['verified']): ?><span class="badge">تأییدشده — غیرفعال</span>
      <?php elseif ($g['configured']): ?><span class="badge status-held">در انتظار پرداخت آزمایشی</span>
      <?php else: ?><span class="badge">تنظیم نشده</span><?php endif; ?>
      <?php if ($cfg['sandbox'] === '1'): ?><span class="badge status-held">Sandbox</span><?php endif; ?>
    </header>
    <?php if ($row['verified_at']): ?>
      <p class="muted">آخرین تأیید: <bdi><?= esc(jdate($row['verified_at'], true)) ?></bdi> • مرجع <bdi><?= esc(fa_number((string) $row['verified_ref'])) ?></bdi><?= $g['verified'] ? '' : ' — با اطلاعات فعلی معتبر نیست' ?></p>
    <?php endif; ?>
    <?php if ($row['last_error']): ?><p class="error">آخرین خطا: <?= esc($row['last_error']) ?></p><?php endif; ?>
    <form method="post" class="stack" autocomplete="off">
      <?php form_token('gateway_save'); ?>
      <input type="hidden" name="gateway" value="<?= esc($name) ?>">
      <?php foreach ($g['meta']['fields'] as $field => $f): ?>
        <?php if ($f['secret']): ?>
          <label><?= esc($f['label']) ?>
            <input type="password" name="<?= esc($field) ?>" value="" dir="ltr" autocomplete="new-password" placeholder="<?= $cfg[$field] !== '' ? 'ذخیره شده — برای تغییر وارد کنید' : '' ?>"></label>
        <?php else: ?>
          <label><?= esc($f['label']) ?><input name="<?= esc($field) ?>" value="<?= esc($cfg[$field]) ?>" dir="ltr"></label>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($g['meta']['sandbox'] && $development): ?>
        <label class="check"><input type="checkbox" name="sandbox" value="1"<?= checked_attr($cfg['sandbox'] === '1') ?>> حالت آزمایشی (Sandbox) — فقط نصب توسعه</label>
      <?php endif; ?>
      <p class="muted">مستندات: <?= str_starts_with($g['meta']['docs'], 'https://') ? '<a href="' . esc($g['meta']['docs']) . '" target="_blank" rel="noopener">' . esc($g['meta']['docs']) . '</a>' : esc($g['meta']['docs']) ?></p>
      <button class="secondary">ذخیره اطلاعات</button>
    </form>
    <form method="post" enctype="multipart/form-data" class="gateway-logo">
      <?php form_token('gateway_logo'); ?><input type="hidden" name="gateway" value="<?= esc($name) ?>">
      <?php if ($logo = gateway_logo_url($name)): ?><img src="<?= esc($logo) ?>" alt="" width="48" height="48"><?php endif; ?>
      <label>نشان رسمی بانک در صفحه پرداخت (PNG، JPEG یا WebP؛ از منبع رسمی بانک)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required></label>
      <button class="secondary">بارگذاری نشان</button>
    </form>
    <?php if (gateway_logo_url($name)): ?><form method="post"><?php form_token('gateway_logo'); ?><input type="hidden" name="gateway" value="<?= esc($name) ?>"><input type="hidden" name="remove" value="1"><button class="link-button danger-link">حذف نشان</button></form><?php endif; ?>
    <div class="actions">
      <?php if ($g['configured']): ?>
        <form method="post" data-confirm="یک پرداخت واقعی <?= esc(toman(setting('payment_test_amount', '1000'))) ?> با کارت خودتان انجام می‌شود. ادامه می‌دهید؟">
          <?php form_token('gateway_test'); ?><input type="hidden" name="gateway" value="<?= esc($name) ?>">
          <button><?= icon('check') ?> پرداخت آزمایشی</button>
        </form>
      <?php endif; ?>
      <?php if ($row['enabled']): ?>
        <form method="post"><?php form_token('gateway_enable'); ?><input type="hidden" name="gateway" value="<?= esc($name) ?>"><input type="hidden" name="enabled" value="0"><button class="danger">غیرفعال‌سازی</button></form>
      <?php elseif ($g['verified'] || ($cfg['sandbox'] === '1' && $development && $g['configured'])): ?>
        <form method="post"><?php form_token('gateway_enable'); ?><input type="hidden" name="gateway" value="<?= esc($name) ?>"><input type="hidden" name="enabled" value="1"><button>فعال‌سازی</button></form>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<section class="panel">
  <h2>آخرین پرداخت‌های آزمایشی</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>زمان</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>مرجع / خطا</th></tr></thead>
    <tbody>
    <?php foreach ($tests as $t): ?>
      <tr><td><bdi><?= esc(jdate($t['created_at'], true)) ?></bdi></td><td><?= esc($gateways[$t['gateway']]['meta']['label'] ?? $t['gateway']) ?></td><td><?= esc(toman($t['amount_toman'])) ?></td>
        <td><span class="badge"><?= esc(payment_status_label($t['status'])) ?></span><?= $t['test_mode'] ? ' <span class="badge status-held">Sandbox</span>' : '' ?></td>
        <td><?= $t['ref_num'] ? '<bdi>' . esc(fa_number($t['ref_num'])) . '</bdi>' : esc((string) $t['error']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!$tests): ?><p class="empty-state">هنوز پرداخت آزمایشی انجام نشده است.</p><?php endif; ?>
</section>
