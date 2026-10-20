<?php
declare(strict_types=1);

/** Printable proforma or invoice of a center subscription order (billing staff or administrator). */

require __DIR__ . '/core.php';
require_schema();

$id = (int) ($_GET['id'] ?? 0);
try {
    $o = center_order($id);
    if (!admin()) {
        $staff = query('SELECT * FROM center_staff WHERE center_id=? AND user_id=? AND active=1 AND perm_billing=1', [$o['center_id'], require_user()['id']])->fetch();
        if (!$staff) {
            throw new AuthError('به این فاکتور دسترسی ندارید.', 403);
        }
    }
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 404);
    layout_start('فاکتور');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}
$tx = $o['payment_tx_id'] ? query('SELECT * FROM payment_transactions WHERE id=?', [$o['payment_tx_id']])->fetch() : null;
$paid = $o['status'] === 'paid';
$code = $o['discount_code_id'] ? query('SELECT code FROM discount_codes WHERE id=?', [$o['discount_code_id']])->fetchColumn() : '';
$methods = ['wallet'=>'کیف پول','paid' => 'پرداخت آنلاین', 'offline' => 'واریز ثبت‌شده توسط مدیر', 'free' => 'رایگان'];
layout_start($paid ? 'فاکتور ' . $o['number'] : 'پیش‌فاکتور', ['body_class' => 'print-page']);
?>
<article class="invoice">
  <header class="invoice-head">
    <div>
      <h1><?= $paid ? 'فاکتور فروش خدمات' : 'پیش‌فاکتور' ?></h1>
      <p><?= $paid ? 'شماره: <bdi>' . esc($o['number']) . '</bdi>' : 'معتبر تا ' . esc(jdate($o['expires_at'], true)) ?></p>
    </div>
    <div class="invoice-meta">
      <p>تاریخ صدور: <bdi><?= esc(jdate($paid ? $o['paid_at'] : $o['created_at'], true)) ?></bdi></p>
      <p>وضعیت: <strong><?= esc(order_status_label($o['status'])) ?></strong></p>
    </div>
  </header>
  <div class="invoice-parties">
    <section>
      <h2>فروشنده</h2>
      <p><strong><?= esc(setting('invoice_seller_name', '') ?: 'نوبت‌یار') ?></strong></p>
      <?php foreach (['invoice_seller_national_id' => 'شناسه ملی', 'invoice_seller_economic_code' => 'کد اقتصادی', 'invoice_seller_phone' => 'تلفن'] as $k => $l): if (setting($k, '') !== ''): ?>
        <p><?= $l ?>: <bdi><?= esc(fa_number(setting($k))) ?></bdi></p>
      <?php endif; endforeach; ?>
      <?php if (setting('invoice_seller_address', '') !== ''): ?><p><?= esc(setting('invoice_seller_address')) ?></p><?php endif; ?>
    </section>
    <section>
      <h2>خریدار</h2>
      <p><strong><?= esc($o['center_name']) ?></strong></p>
      <p>شماره پروانه: <bdi><?= esc(fa_number($o['license_number'])) ?></bdi></p>
      <?php if ($o['national_id']): ?><p>شناسه ملی: <bdi><?= esc(fa_number($o['national_id'])) ?></bdi></p><?php endif; ?>
      <p><?= esc($o['address']) ?></p>
    </section>
  </div>
  <table class="invoice-table">
    <thead><tr><th>شرح</th><th>دوره</th><th>مبلغ</th></tr></thead>
    <tbody>
      <tr>
        <td>اشتراک <?= esc($o['plan_name']) ?> نوبت‌یار برای مرکز درمانی</td>
        <td><?= fa_number($o['duration_days']) ?> روز<br><small><bdi><?= esc(jdate($o['period_start'])) ?></bdi> تا <bdi><?= esc(jdate($o['period_end'])) ?></bdi></small></td>
        <td><?= esc(toman($o['base_toman'])) ?></td>
      </tr>
      <?php if ($o['discount_toman'] > 0): ?><tr><td colspan="2">تخفیف (<?= esc($code) ?>)</td><td>− <?= esc(toman($o['discount_toman'])) ?></td></tr><?php endif; ?>
      <?php if ($o['vat_toman'] > 0): ?><tr><td colspan="2">مالیات بر ارزش افزوده (<?= esc(fa_number(rtrim(rtrim($o['vat_percent'], '0'), '.'))) ?>٪)</td><td><?= esc(toman($o['vat_toman'])) ?></td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th colspan="2">مبلغ قابل پرداخت</th><th><?= esc(toman($o['payable_toman'])) ?></th></tr></tfoot>
  </table>
  <?php if ($paid): ?>
    <p class="muted">روش پرداخت: <?= esc($methods[$o['payment_method']] ?? $o['payment_method']) ?>
      <?= $tx ? ' • مرجع بانک: <bdi>' . esc(fa_number($tx['ref_num'])) . '</bdi>' : '' ?>
      <?= $o['offline_reference'] ? ' • مرجع واریز: <bdi>' . esc(fa_number($o['offline_reference'])) . '</bdi>' : '' ?></p>
  <?php else: ?>
    <p class="muted">این پیش‌فاکتور پس از پرداخت موفق به فاکتور با شماره ترتیبی تبدیل می‌شود.</p>
  <?php endif; ?>
  <div class="actions no-print">
    <button type="button" data-print hidden><?= icon('print') ?> چاپ</button>
    <a href="<?= admin() ? 'admin.php?tab=centers&center=' . (int) $o['center_id'] : 'center.php?center=' . (int) $o['center_id'] . '&tab=subscription' ?>">بازگشت</a>
  </div>
</article>
<?php
layout_end();
