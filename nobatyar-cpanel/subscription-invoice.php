<?php
declare(strict_types=1);

/** Printable proforma (before payment) or invoice (after payment) of a subscription order. */

require __DIR__ . '/core.php';
require_schema();

$id = (int) ($_GET['id'] ?? 0);
try {
    $o = subscription_order($id);
    if (!admin()) {
        doctor_actor((int) $o['doctor_id'], 'owner', 0, true);
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
$seller = [
    'name' => setting('invoice_seller_name', '') ?: 'نوبت‌یار',
    'national_id' => setting('invoice_seller_national_id', ''),
    'economic_code' => setting('invoice_seller_economic_code', ''),
    'address' => setting('invoice_seller_address', ''),
    'phone' => setting('invoice_seller_phone', ''),
];
$clinic = query('SELECT cl.address,c.name city FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.doctor_id=? AND cl.is_primary=1', [
    $o['doctor_id'],
])->fetch();

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
      <p><strong><?= esc($seller['name']) ?></strong></p>
      <?php if ($seller['national_id']): ?><p>شناسه ملی: <bdi><?= esc(fa_number($seller['national_id'])) ?></bdi></p><?php endif; ?>
      <?php if ($seller['economic_code']): ?><p>کد اقتصادی: <bdi><?= esc(fa_number($seller['economic_code'])) ?></bdi></p><?php endif; ?>
      <?php if ($seller['address']): ?><p><?= esc($seller['address']) ?></p><?php endif; ?>
      <?php if ($seller['phone']): ?><p>تلفن: <bdi><?= esc(fa_number($seller['phone'])) ?></bdi></p><?php endif; ?>
    </section>
    <section>
      <h2>خریدار</h2>
      <p><strong><?= esc($o['doctor_name']) ?></strong></p>
      <p>کد نظام پزشکی: <bdi><?= esc(fa_number($o['medical_code'] ?? '—')) ?></bdi></p>
      <?php if ($clinic): ?><p><?= esc($clinic['city'] . '، ' . $clinic['address']) ?></p><?php endif; ?>
    </section>
  </div>
  <table class="invoice-table">
    <thead><tr><th>شرح</th><th>دوره</th><th>مبلغ</th></tr></thead>
    <tbody>
      <tr>
        <td>اشتراک <?= esc($o['plan_name']) ?> نوبت‌یار — <?= esc(subscription_order_kinds()[$o['kind']] ?? '') ?></td>
        <td><?= fa_number($o['duration_days']) ?> روز<br><small><bdi><?= esc(jdate($o['period_start'])) ?></bdi> تا <bdi><?= esc(jdate($o['period_end'])) ?></bdi></small></td>
        <td><?= esc(toman($o['base_toman'])) ?></td>
      </tr>
      <?php if ($o['credit_toman'] > 0): ?>
        <tr><td colspan="2">اعتبار روزهای استفاده‌نشده پلن قبلی</td><td>− <?= esc(toman($o['credit_toman'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($o['discount_toman'] > 0): ?>
        <tr><td colspan="2">تخفیف (<?= esc($o['discount_code']) ?>)</td><td>− <?= esc(toman($o['discount_toman'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($o['vat_toman'] > 0): ?>
        <tr><td colspan="2">مالیات بر ارزش افزوده (<?= esc(fa_number(rtrim(rtrim($o['vat_percent'], '0'), '.'))) ?>٪)</td><td><?= esc(toman($o['vat_toman'])) ?></td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot><tr><th colspan="2">مبلغ قابل پرداخت</th><th><?= esc(toman($o['payable_toman'])) ?></th></tr></tfoot>
  </table>
  <?php if ($paid): ?>
    <p class="muted">روش پرداخت: <?= esc(subscription_kind_label($o['payment_method'])) ?>
      <?= $tx ? ' • مرجع بانک: <bdi>' . esc(fa_number($tx['ref_num'])) . '</bdi>' : '' ?>
      <?= $o['offline_reference'] ? ' • مرجع واریز: <bdi>' . esc($o['offline_reference']) . '</bdi>' : '' ?></p>
  <?php else: ?>
    <p class="muted">این پیش‌فاکتور پس از پرداخت موفق به فاکتور با شماره ترتیبی تبدیل می‌شود.</p>
  <?php endif; ?>
  <div class="actions no-print">
    <button type="button" data-print hidden><?= icon('print') ?> چاپ</button>
    <a href="<?= admin() ? 'admin.php?tab=subscriptions&view=orders' : 'doctor-panel.php?doctor=' . (int) $o['doctor_id'] . '&tab=subscription' ?>">بازگشت</a>
  </div>
</article>
<?php
layout_end();
