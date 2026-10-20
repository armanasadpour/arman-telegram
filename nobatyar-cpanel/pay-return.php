<?php
declare(strict_types=1);

/**
 * Return address for every gateway (pay-return.php?tx=ID). The browser redirect itself proves nothing:
 * the payment module verifies server-to-server before anything is fulfilled, and repeated visits are harmless.
 */

require __DIR__ . '/core.php';
require_schema();

$txId = (int) ($_GET['tx'] ?? 0);
$tx = null;
$error = '';
try {
    throttle('pay_return', 120, 3600);
    $tx = payment_return($txId, $_GET + $_POST);
} catch (Throwable $e) {
    $error = error_message($e);
    $tx = $txId ? query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch() ?: null : null;
}

$back = 'index.php';
$invoice = null;
if ($tx && $tx['purpose'] === 'subscription') {
    $back = 'doctor-panel.php?doctor=' . (int) $tx['doctor_id'] . '&tab=subscription';
    $invoice = 'subscription-invoice.php?id=' . (int) $tx['reference_id'];
} elseif ($tx && $tx['purpose'] === 'booking') {
    $back = 'checkout.php?id=' . (int) $tx['reference_id'];
    $invoice = 'receipt.php?id=' . (int) $tx['reference_id'] . '&new=1';
} elseif ($tx && $tx['purpose'] === 'gateway_test') {
    $back = 'admin.php?tab=gateways';
}

layout_start('نتیجه پرداخت', ['body_class' => 'auth-page']);
?>
<section class="pay-result <?= $tx && $tx['status'] === 'verified' ? 'success' : 'failure' ?>">
  <?php if ($tx && $tx['status'] === 'verified'): ?>
    <?= icon('check', 'result-icon') ?>
    <h1>پرداخت موفق بود</h1>
    <p>مبلغ <?= esc(toman($tx['amount_toman'])) ?> پرداخت و توسط سامانه تأیید شد.<?= $tx['purpose'] === 'booking' ? ' نوبت شما قطعی شد.' : '' ?><?= $tx['purpose'] === 'gateway_test' ? ' اتصال درگاه تأیید شد و اکنون می‌توانید آن را فعال کنید.' : '' ?></p>
    <dl class="facts">
      <dt>شماره مرجع بانک</dt><dd><bdi><?= esc(fa_number($tx['ref_num'])) ?></bdi></dd>
      <?php if ($tx['card_mask']): ?><dt>کارت</dt><dd><bdi dir="ltr"><?= esc(fa_number($tx['card_mask'])) ?></bdi></dd><?php endif; ?>
      <dt>کد سفارش</dt><dd><bdi><?= esc($tx['order_code']) ?></bdi></dd>
    </dl>
  <?php elseif ($tx && $tx['status'] === 'refund_due'): ?>
    <?= icon('alert', 'result-icon') ?>
    <h1>پرداخت انجام شد اما سفارش تکمیل نشد</h1>
    <p>مبلغ از حساب شما کسر شده ولی سفارش دیگر قابل انجام نبود. مورد برای بازگشت وجه ثبت شد و پشتیبانی پیگیری می‌کند.</p>
    <p>شماره مرجع: <bdi><?= esc(fa_number($tx['ref_num'] ?? '—')) ?></bdi></p>
  <?php else: ?>
    <?= icon('x', 'result-icon') ?>
    <h1>پرداخت انجام نشد</h1>
    <p><?= esc($error ?: ($tx['error'] ?? 'تراکنش ناموفق بود یا لغو شد. اگر مبلغی کسر شده باشد، طبق قوانین بانکی حداکثر ظرف ۷۲ ساعت برگشت داده می‌شود.')) ?></p>
  <?php endif; ?>
  <div class="actions">
    <a class="button" href="<?= esc($back) ?>">بازگشت</a>
    <?php if ($invoice && $tx && $tx['status'] === 'verified'): ?><a class="button secondary" href="<?= esc($invoice) ?>"><?= $tx['purpose'] === 'booking' ? 'مشاهده رسید نوبت' : 'مشاهده فاکتور' ?></a><?php endif; ?>
  </div>
</section>
<?php
layout_end();
