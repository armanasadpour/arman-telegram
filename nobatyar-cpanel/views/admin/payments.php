<?php
/** @var array $filters @var int $page @var int $total @var array $rows @var array $revenue @var array $refunds @var array $handled */
$registry = payment_gateways()+['wallet'=>['label'=>'کیف پول']];
$gross = array_sum(array_map(fn($r) => (int) $r['gross'], $revenue['by_purpose']));
$refunded = array_sum(array_map(fn($r) => (int) $r['refunded'], $revenue['by_purpose']));
$query = ['tab' => 'payments', 'from' => jdate($filters['from']), 'to' => jdate($filters['to'])] + array_filter(['status' => $filters['status'], 'gateway' => $filters['gateway'], 'purpose' => $filters['purpose'], 'q' => $filters['q']]);
?>
<?php if ($refunds): ?>
<section class="panel attention">
  <h2><?= icon('alert') ?> موارد در انتظار استرداد (<?= fa_number(count($refunds)) ?>)</h2>
  <p class="muted">برای استرداد به کیف پول، اعتبار با ثبت این فرم اضافه می‌شود. برای روش بانکی، ابتدا واریز را انجام دهید و مرجع آن را ثبت کنید؛ این فرم انتقال بانکی انجام نمی‌دهد. پرداخت آزمایشی یا قدیمی به کیف پول تبدیل نمی‌شود.</p>
  <?php foreach ($refunds as $r): ?>
    <details class="refund-item">
      <summary>
        <strong><?= esc(toman($r['amount_toman'])) ?></strong> • <?= esc(payment_purpose_label($r['purpose'])) ?> #<?= fa_number($r['reference_id']) ?>
        • <?= esc($registry[$r['gateway'] ?? '']['label'] ?? ($r['legacy_intent_id'] ? 'زرین‌پال (قدیمی)' : '—')) ?>
        • مرجع <bdi><?= esc(fa_number((string) ($r['ref_num'] ?? $r['legacy_ref'] ?? '—'))) ?></bdi>
        • <bdi><?= esc(jdate($r['created_at'], true)) ?></bdi>
      </summary>
      <p>علت: <?= esc($r['reason']) ?><?= $r['card_mask'] ? ' • کارت پرداخت‌کننده: <bdi dir="ltr">' . esc($r['card_mask']) . '</bdi>' : '' ?></p>
      <div class="grid-2">
        <form method="post" class="stack">
          <?php form_token('refund_handle'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="done">
          <label>روش<select name="method"><option value="wallet">واریز به کیف پول صاحب فاکتور</option><option value="card">کارت به کارت</option><option value="iban">واریز شبا</option><option value="gateway">استرداد از پنل درگاه</option></select></label>
          <?php input('شماره پیگیری واریز (برای روش‌های بانکی ضروری)', 'reference', '', 'text', false, 100); ?>
          <?php input('یادداشت (اختیاری)', 'note', '', 'text', false, 500); ?>
          <button>ثبت استرداد انجام‌شده</button>
        </form>
        <form method="post" class="stack" data-confirm="درخواست استرداد رد شود؟">
          <?php form_token('refund_handle'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="rejected">
          <?php input('علت رد', 'note', '', 'text', true, 500); ?>
          <button class="danger">رد درخواست</button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<form method="get" class="filter-bar panel">
  <input type="hidden" name="tab" value="payments">
  <label>از تاریخ<input data-jdate name="from" value="<?= esc(jdate($filters['from'])) ?>" dir="ltr"></label>
  <label>تا تاریخ<input data-jdate name="to" value="<?= esc(jdate($filters['to'])) ?>" dir="ltr"></label>
  <label>وضعیت<select name="status"><option value="">همه</option><?php foreach (payment_status_labels() as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($filters['status'] === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
  <label>درگاه<select name="gateway"><option value="">همه</option><?php foreach ($registry as $k => $g): ?><option value="<?= $k ?>"<?= selected_attr($filters['gateway'] === $k) ?>><?= esc($g['label']) ?></option><?php endforeach; ?></select></label>
  <label>نوع<select name="purpose"><option value="">همه</option><?php foreach (['booking', 'subscription', 'gateway_test'] as $k): ?><option value="<?= $k ?>"<?= selected_attr($filters['purpose'] === $k) ?>><?= esc(payment_purpose_label($k)) ?></option><?php endforeach; ?></select></label>
  <label>مرجع یا شماره سفارش<input name="q" value="<?= esc($filters['q']) ?>" dir="ltr"></label>
  <button><?= icon('filter') ?> نمایش</button>
  <a class="button secondary" href="admin.php?<?= esc(http_build_query($query + ['export' => 'xlsx'])) ?>"><?= icon('download') ?> خروجی اکسل</a>
</form>

<div class="stat-grid">
  <article class="stat-card"><span>دریافتی موفق در بازه</span><strong class="small"><?= esc(toman($gross)) ?></strong></article>
  <article class="stat-card"><span>استرداد شده</span><strong class="small"><?= esc(toman($refunded)) ?></strong></article>
  <article class="stat-card"><span>درآمد خالص</span><strong class="small"><?= esc(toman($gross - $refunded)) ?></strong></article>
  <article class="stat-card"><span>تراکنش ناموفق / لغو</span><strong><?= fa_number($revenue['failed']) ?></strong></article>
</div>

<div class="grid-2 align-start">
  <section class="panel">
    <h2>به تفکیک نوع</h2>
    <div class="table-wrap"><table><thead><tr><th>نوع</th><th>تعداد</th><th>دریافتی (بدون مالیات)</th><th>استرداد</th><th>مالیات بر ارزش افزوده</th></tr></thead><tbody>
      <?php foreach ($revenue['by_purpose'] as $r): ?><tr><td><?= esc(payment_purpose_label($r['purpose'])) ?></td><td><?= fa_number($r['n']) ?></td><td><?= esc(toman($r['gross'])) ?></td><td><?= esc(toman($r['refunded'])) ?></td><td><?= (int) ($r['vat'] ?? 0) ? esc(toman($r['vat'])) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="panel">
    <h2>به تفکیک درگاه</h2>
    <div class="table-wrap"><table><thead><tr><th>درگاه</th><th>تعداد موفق</th><th>مبلغ</th></tr></thead><tbody>
      <?php foreach ($revenue['by_gateway'] as $r): ?><tr><td><?= esc($registry[$r['gateway']]['label'] ?? $r['gateway']) ?></td><td><?= fa_number($r['n']) ?></td><td><?= esc(toman($r['amount'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
</div>

<section class="panel">
  <h2>تراکنش‌ها (<?= fa_number($total) ?>)</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>زمان</th><th>نوع</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>مرجع بانک</th><th>پرداخت‌کننده</th><th>توضیح</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
      <tr>
        <td><?= fa_number($t['id']) ?></td>
        <td><bdi><?= esc(jdate($t['created_at'], true)) ?></bdi></td>
        <td><?= esc(payment_purpose_label($t['purpose'])) ?> #<?= fa_number($t['reference_id']) ?></td>
        <td><?= esc($registry[$t['gateway']]['label'] ?? $t['gateway']) ?><?= $t['test_mode'] ? ' <span class="badge status-held">Sandbox</span>' : '' ?></td>
        <td><?= esc(toman($t['amount_toman'])) ?></td>
        <td><span class="badge tx-<?= esc($t['status']) ?>"><?= esc(payment_status_label($t['status'])) ?></span></td>
        <td><bdi><?= esc(fa_number((string) ($t['ref_num'] ?? '—'))) ?></bdi></td>
        <td><?= esc($t['purpose'] === 'booking' && $t['mobile'] ? admin_mobile($t['mobile']) : ($t['doctor'] ?: '—')) ?></td>
        <td class="muted"><?= esc((string) $t['error']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!$rows): ?><p class="empty-state">تراکنشی با این فیلترها پیدا نشد.</p><?php endif; ?>
  <?php if ($total > 50): ?>
    <nav class="pager">
      <?php if ($page > 1): ?><a class="button secondary" href="admin.php?<?= esc(http_build_query($query + ['page' => $page - 1])) ?>">قبلی</a><?php endif; ?>
      <span>صفحه <?= fa_number($page) ?> از <?= fa_number((int) ceil($total / 50)) ?></span>
      <?php if ($page * 50 < $total): ?><a class="button secondary" href="admin.php?<?= esc(http_build_query($query + ['page' => $page + 1])) ?>">بعدی</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<?php if ($handled): ?>
<section class="panel">
  <h2>آخرین استردادهای رسیدگی‌شده</h2>
  <div class="table-wrap"><table><thead><tr><th>زمان</th><th>مبلغ</th><th>نتیجه</th><th>پیگیری / یادداشت</th></tr></thead><tbody>
    <?php foreach ($handled as $r): ?><tr><td><bdi><?= esc(jdate($r['handled_at'], true)) ?></bdi></td><td><?= esc(toman($r['amount_toman'])) ?></td><td><?= $r['status'] === 'done' ? 'مسترد شد' : 'رد شد' ?></td><td><bdi><?= esc(fa_number((string) $r['bank_reference'])) ?></bdi> <?= esc((string) $r['note']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
