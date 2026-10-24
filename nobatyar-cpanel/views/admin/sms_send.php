<?php /** @var array $segments @var array $counts @var array $provinces @var array $post */ ?>
<div class="grid-2 align-start">
<section class="panel"><h2><?= icon('user') ?> پیامک به یک نفر</h2><p class="muted">گیرنده را جستجو و از نتایج انتخاب کنید. پیام از الگوی «پیام دستی مدیر» و خط فرستنده تنظیم‌شده ارسال می‌شود.</p><form method="post" data-confirm="پیامک برای گیرنده انتخاب‌شده در صف ارسال قرار گیرد؟"><?php form_token('manual_sms'); ?><input type="hidden" name="request_key" value="<?= esc(bin2hex(random_bytes(16))) ?>"><div data-recipient-search><label>گروه<select data-recipient-kind><option value="user">کاربر</option><option value="patient">بیمار</option><option value="doctor">پزشک</option><option value="center">مالک کلینیک</option></select></label><label>جستجوی نام یا شماره<input type="search" data-recipient-query autocomplete="off" placeholder="حداقل دو حرف"></label><label>گیرنده<select name="recipient" data-recipient-results required><option value="">گیرنده را جستجو کنید</option></select></label><p data-recipient-status role="status"></p></div><div data-sms-editor><label>متن پیام<textarea name="message" maxlength="1000" required data-sms-body></textarea></label><small data-sms-count></small></div><button>ارسال پیامک</button></form></section>

<section class="panel">
  <h2><?= icon('sms') ?> پیامک گروهی پزشکان</h2>
  <p class="muted small">برای همه پزشکان، پزشکان پرو، پزشکان عادی یا پزشکان یک شهر. پیام به موبایل حساب پزشک و با الگوی «پیام دستی مدیر» ارسال می‌شود.</p>
  <form method="post" class="stack" data-confirm="پیامک برای همه پزشکان گروه انتخاب‌شده در صف ارسال قرار گیرد؟ این کار قابل بازگشت نیست.">
    <?php form_token('bulk_sms'); ?>
    <input type="hidden" name="request_key" value="<?= esc(bin2hex(random_bytes(16))) ?>">
    <?php render('admin/audience_picker', ['name' => 'segment', 'options' => $segments, 'counts' => $counts, 'provinces' => $provinces, 'selected' => (string) ($post['segment'] ?? ''), 'post' => $post]); ?>
    <div data-sms-editor><label>متن پیام<textarea name="message" maxlength="1000" required data-sms-body><?= esc((string) ($post['message'] ?? '')) ?></textarea></label><small data-sms-count></small></div>
    <p class="bulk-sms-note"><?= icon('info') ?><span>پزشک و منشی امکان ارسال پیامک یا اعلان به بیماران را ندارند؛ اطلاع‌رسانی فقط از همین بخش مدیریت انجام می‌شود.</span></p>
    <button><?= icon('sms') ?> ارسال پیامک گروهی</button>
  </form>
</section>
</div>
