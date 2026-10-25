<?php
declare(strict_types=1);

/** Wallet of a patient, doctor or clinic: balance, bank details, payout requests and the ledger. */

require __DIR__ . '/core.php';
require_schema();
if (!user_now()) {
    redirect('login.php?next=wallet.php');
}
$u = require_user();
$type = (string) ($_GET['type'] ?? 'user');
$owner = (int) ($_GET['owner'] ?? $u['id']);
$error = '';
try {
    $w = wallet_authorize($type, $owner);
} catch (Throwable $e) {
    http_response_code(403);
    layout_start('کیف پول');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}
$back = 'wallet.php?' . http_build_query(['type' => $type, 'owner' => $owner]);
if (is_post()) {
    verify_csrf();
    try {
        match ($_POST['action'] ?? '') {
            'bank' => wallet_save_bank($w, $_POST),
            'payout' => wallet_request_payout($w, $_POST),
            default => throw new AuthError('عملیات نامعتبر است.'),
        };
        flash('اطلاعات ثبت شد.');
        redirect($back);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$w = wallet_account($type, $owner);
$bank = wallet_bank((int) $w['id']) ?? [];
$payouts = query('SELECT * FROM wallet_payouts WHERE wallet_id=? ORDER BY id DESC LIMIT 100', [$w['id']])->fetchAll();
$entries = query('SELECT * FROM wallet_entries WHERE wallet_id=? ORDER BY id DESC LIMIT 100', [$w['id']])->fetchAll();
$owners = ['user' => 'بیمار / کاربر', 'doctor' => 'پزشک', 'center' => 'کلینیک'];
$payoutStatus = ['pending' => 'در انتظار بررسی', 'paid' => 'واریز شد', 'rejected' => 'رد شد'];
$panel = match ($type) {
    'doctor' => 'doctor-panel.php',
    'center' => 'center.php?center=' . $owner,
    default => 'panel.php',
};
layout_start('کیف پول و اطلاعات بانکی');
?>
<p><a class="button secondary" href="<?= esc($panel) ?>">بازگشت به پنل</a></p>
<h1>کیف پول <?= esc($owners[$type] ?? '') ?></h1>
<?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
<section class="card">
  <span>موجودی قابل استفاده</span>
  <h2><?= esc(toman($w['balance'])) ?></h2>
  <p class="muted">مبالغ درخواست‌های واریز در انتظار بررسی از موجودی قابل استفاده کنار گذاشته شده‌اند.</p>
</section>
<div class="grid-2">
  <section class="card">
    <h2>اطلاعات بانکی</h2>
    <p class="muted">اطلاعات باید متعلق به صاحب کیف پول باشد. پیش از واریز، مدیر مشخصات گیرنده را بررسی می‌کند.</p>
    <form method="post">
      <?php form_token('bank'); ?>
      <?php input('نام صاحب حساب', 'holder', $bank['holder'] ?? ''); ?>
      <?php input('شماره حساب', 'account', $bank['account'] ?? '', 'text', true, 30); ?>
      <?php input('شماره کارت ۱۶ رقمی', 'card', $bank['card'] ?? '', 'text', true, 16); ?>
      <?php input('شماره شبا با IR', 'iban', $bank['iban'] ?? '', 'text', true, 34); ?>
      <button>ذخیره اطلاعات بانکی</button>
    </form>
  </section>
  <section class="card">
    <h2>درخواست واریز</h2>
    <?php if (!$bank): ?>
      <p class="notice">برای درخواست واریز، ابتدا همه اطلاعات بانکی را ثبت کنید.</p>
    <?php else: ?>
      <form method="post">
        <?php form_token('payout'); ?>
        <input type="hidden" name="request_key" value="<?= esc(bin2hex(random_bytes(16))) ?>">
        <?php input('مبلغ درخواست (تومان)', 'amount', '', 'number', true, 10); ?>
        <p class="muted">مبلغ تا بررسی مدیر رزرو می‌شود. پس از پرداخت، شماره پیگیری اینجا ثبت خواهد شد.</p>
        <button>ثبت درخواست واریز</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<section class="card">
  <h2>درخواست‌های واریز</h2>
  <?php foreach ($payouts as $p): ?>
    <p>
      <?= esc(toman($p['amount'])) ?> · <?= esc($payoutStatus[$p['status']] ?? $p['status']) ?> · <?= esc(jdate($p['created_at'], true)) ?>
      <?php if ($p['reference']): ?> · مرجع <?= esc($p['reference']) ?><?php endif; ?>
      <?php if ($p['note']): ?><br><?= esc($p['note']) ?><?php endif; ?>
    </p>
  <?php endforeach; ?>
  <?php if (!$payouts): ?><p class="muted">درخواستی ثبت نشده است.</p><?php endif; ?>
</section>
<section class="card">
  <h2>گردش کیف پول</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>تاریخ</th><th>شرح</th><th>مبلغ</th><th>مانده</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $e): ?>
          <tr><td><?= esc(jdate($e['created_at'], true)) ?></td><td><?= esc($e['description']) ?></td><td><?= esc(toman($e['amount'])) ?></td><td><?= esc(toman($e['balance_after'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$entries): ?><p class="muted">گردش مالی ندارید؛ موجودی اولیه کیف پول صفر است.</p><?php endif; ?>
</section>
<?php layout_end(); ?>
