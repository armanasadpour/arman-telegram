<?php /** @var array $c @var array $s @var array $sub @var array $plans @var array $orders @var array $periods @var bool $payable */
$open = array_values(array_filter($orders, fn($o) => $o['status'] === 'proforma' && $o['expires_at'] > date('Y-m-d H:i:s')));
$kinds = ['wallet'=>'کیف پول','paid' => 'پرداخت آنلاین', 'offline' => 'واریز ثبت‌شده', 'free' => 'رایگان', 'trial' => 'دوره آزمایشی', 'gift' => 'هدیه', 'manual' => 'تمدید دستی'];
$walletOwner=(int)$c['owner_user_id']===(int)require_user()['id'];
$statuses = ['proforma' => 'پیش‌فاکتور', 'paid' => 'پرداخت‌شده', 'cancelled' => 'لغوشده', 'expired' => 'منقضی'];
?>
<article class="subscription-card">
  <div><span class="eyebrow">وضعیت اشتراک</span><h2><?= esc($sub['label']) ?></h2>
    <p><?= $sub['until'] ? 'اعتبار تا ' . esc(jdate($sub['until'])) . ($sub['state'] === 'grace' ? ' • فرجه تا ' . esc(jdate($sub['grace_end'])) : '') : 'اشتراک فعالی ندارید.' ?></p></div>
  <div class="days-left"><strong><?= fa_number(max(0, $sub['days_left'])) ?></strong><span>روز باقی‌مانده</span></div>
</article>
<p class="muted">اشتراک مرکز امکانات خود مرکز را فعال می‌کند: دسترسی پذیرش کارکنان، تقویم تجمیعی، گزارش‌ها، اطلاعیه و صفحه عمومی. پزشکان همکار اشتراک خود را جداگانه دارند و نوبت‌دهی آن‌ها به اشتراک مرکز وابسته نیست.</p>
<?php if ($c['review_status'] !== 'approved'): ?>
  <p class="notice">خرید اشتراک پس از تأیید مرکز ممکن است.</p>
<?php else: ?>
  <?php if ($sub['trial_available']): ?>
    <form method="post" class="card"><?php form_token('sub_trial'); ?><h2>دوره آزمایشی رایگان</h2><p><?= fa_number(setting('center_trial_days', '14')) ?> روز استفاده رایگان، فقط یک بار.</p><button>فعال‌سازی دوره آزمایشی</button></form>
  <?php endif; ?>
  <?php foreach ($open as $o): ?>
    <article class="card proforma">
      <h2>پیش‌فاکتور باز — <?= esc($o['plan_name']) ?></h2>
      <dl class="bill">
        <dt>مبلغ پلن</dt><dd><?= esc(toman($o['base_toman'])) ?></dd>
        <?php if ($o['discount_toman']): ?><dt>تخفیف</dt><dd class="discount">− <?= esc(toman($o['discount_toman'])) ?></dd><?php endif; ?>
        <?php if ($o['vat_toman']): ?><dt>مالیات بر ارزش افزوده</dt><dd><?= esc(toman($o['vat_toman'])) ?></dd><?php endif; ?>
        <dt class="total">قابل پرداخت</dt><dd class="total"><?= esc(toman($o['payable_toman'])) ?></dd>
      </dl>
      <p class="muted">دوره <?= esc(jdate($o['period_start'])) ?> تا <?= esc(jdate($o['period_end'])) ?> • مهلت پرداخت تا <?= esc(jdate($o['expires_at'], true)) ?></p>
      <div class="actions">
        <?php if ($payable || (int) $o['payable_toman'] === 0 || ($walletOwner && (int)wallet_account('center',(int)$c['id'])['balance'] >= (int)$o['payable_toman'])): ?>
          <form method="post"><?php form_token('sub_checkout'); if($walletOwner && (int)$o['payable_toman']>0) wallet_toggle('center',(int)$c['id']); ?><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button data-pay-button><?= icon('card') ?> پرداخت آنلاین</button></form>
        <?php else: ?>
          <p class="muted">درگاه پرداخت فعالی وجود ندارد؛ برای پرداخت با پشتیبانی تماس بگیرید تا واریز شما ثبت شود.</p>
        <?php endif; ?>
        <a class="button secondary" href="center-invoice.php?id=<?= (int) $o['id'] ?>" target="_blank">پیش‌فاکتور قابل چاپ</a>
        <form method="post" data-confirm="پیش‌فاکتور لغو شود؟"><?php form_token('sub_cancel'); ?><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="secondary">لغو</button></form>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if ($plans): ?>
    <div class="center-plans">
      <?php foreach ($plans as $p): ?>
        <article class="center-plan<?= $p['highlight'] ? ' featured' : '' ?>">
          <h3><?= esc($p['name']) ?></h3>
          <strong class="price"><?= esc(toman($p['price_toman'])) ?></strong>
          <small><?= fa_number($p['duration_days']) ?> روز</small>
          <ul><?php foreach (plan_features($p) as $line): ?><li><?= esc($line) ?></li><?php endforeach; ?></ul>
          <form method="post" class="stack">
            <?php form_token('sub_order'); ?><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <label>کد تخفیف (اختیاری)<input name="discount_code" maxlength="40" dir="ltr"></label>
            <button>صدور پیش‌فاکتور</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="empty-state">هنوز پلن فعالی برای مراکز تعریف نشده است.</p>
  <?php endif; ?>
<?php endif; ?>
<section class="panel">
  <h2>سفارش‌ها و فاکتورها</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>شماره</th><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr><td><bdi><?= esc($o['number'] ?: '—') ?></bdi></td><td><?= esc($o['plan_name']) ?></td><td><?= esc(toman($o['payable_toman'])) ?></td><td><?= esc($statuses[$o['status']] ?? $o['status']) ?><?= $o['payment_method'] ? '<br><small>' . esc($kinds[$o['payment_method']] ?? $o['payment_method']) . '</small>' : '' ?></td><td><bdi><?= esc(jdate($o['paid_at'] ?: $o['created_at'], true)) ?></bdi></td>
          <td><a href="center-invoice.php?id=<?= (int) $o['id'] ?>" target="_blank"><?= $o['status'] === 'paid' ? 'فاکتور' : 'پیش‌فاکتور' ?></a></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!$orders): ?><p class="muted">سفارشی ثبت نشده است.</p><?php endif; ?>
  <h3>دوره‌های اشتراک</h3>
  <ul class="tx-list"><?php foreach ($periods as $p): ?><li><?= esc($kinds[$p['kind']] ?? $p['kind']) ?> • <?= esc(jdate($p['starts_on'])) ?> تا <?= esc(jdate($p['ends_on'])) ?><?= $p['note'] ? ' • ' . esc($p['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
