<?php
/** List or single doctor. Variables come from admin_doctors_data(). */
$labels = doctor_status_labels();
if (!empty($notFound)): ?>
  <p class="error">پزشک پیدا نشد.</p>
  <?php return; ?>
<?php endif; ?>

<?php if ($doctor): ?>
  <?php
  $d = $doctor;
  $postAction = $post['action'] ?? '';
  $subActive = $d['subscription_until'] && $d['subscription_until'] >= date('Y-m-d');
  ?>
  <a class="back-link" href="admin.php?tab=doctors">&larr; بازگشت به فهرست پزشکان</a>
  <?php if (!empty($d['deleted_at'])): ?><p class="error" role="alert">این پزشک در <?= esc(jdate($d['deleted_at'], true)) ?> حذف شده است و در سامانه نمایش داده نمی‌شود.</p><?php endif; ?>
  <div class="profile-head">
    <div>
      <h2><?= esc($d['name']) ?></h2>
      <p class="muted">کد نظام پزشکی: <bdi><?= esc(fa_number($d['medical_code'] ?? '—')) ?></bdi> • موبایل: <bdi><?= esc(admin_mobile($d['mobile'] ?? '')) ?></bdi></p>
    </div>
    <div class="badges">
      <span class="badge status-<?= esc($d['review_status']) ?>"><?= esc($labels[$d['review_status']] ?? $d['review_status']) ?></span>
      <span class="badge"><?= $d['active'] ? 'منتشرشده' : 'منتشرنشده' ?></span>
      <?php if (subscriptions_on()): ?><span class="badge <?= $subActive ? 'status-confirmed' : 'status-cancelled' ?>">اشتراک تا <?= esc(jdate($d['subscription_until'])) ?></span><?php endif; ?>
    </div>
  </div>
  <?php if ($account): ?>
    <p class="notice">حساب کاربری متصل: <a href="admin.php?tab=users&amp;user=<?= (int) $account['id'] ?>"><bdi><?= esc(admin_mobile($account['mobile'])) ?></bdi></a></p>
  <?php endif; ?>
  <?php if (empty($d['specialty_id'])): ?>
    <p class="notice">تخصص اعلام‌شده توسط پزشک: <strong><?= esc((string) $d['specialty']) ?></strong> — در فهرست تخصص‌ها نیست. پیش از تأیید، در «اطلاعات پزشک» تخصص مناسب را انتخاب کنید یا آن را در بخش <a href="admin.php?tab=specialties">تخصص‌ها</a> اضافه کنید.</p>
  <?php endif; ?>

  <?php if (admin_can('finance')): ?><p><a class="button secondary" href="admin.php?tab=subscriptions&amp;view=manual&amp;doctor=<?= (int) $d['id'] ?>">مدیریت اشتراک پرو و امکانات اختصاصی</a></p><?php endif; ?>
  <div class="grid-2">
    <details class="panel" open>
      <summary>اطلاعات پزشک</summary>
      <form method="post" class="stack">
        <?php form_token('edit_doctor'); ?>
        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
        <?php doctor_fields($postAction === 'edit_doctor' ? $post : $d, false, !admin_sensitive()); ?>
        <label>نشان ویژه روی کارت جستجو (اختیاری، حداکثر ۴۰ نویسه؛ فقط ادعای قابل اثبات مثل «عضو هیئت علمی»)<input name="badge_text" value="<?= esc(($postAction === 'edit_doctor' ? $post : $d)['badge_text'] ?? '') ?>" maxlength="40"></label>
        <button>ذخیره اطلاعات</button>
      </form>
    </details>
    <div class="stack">
      <details class="panel" open>
        <summary>بررسی و وضعیت</summary>
        <form method="post" class="stack">
          <?php form_token('review'); ?>
          <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
          <label>وضعیت
            <select name="status">
              <?php foreach ($labels as $value => $label): ?>
                <option value="<?= esc($value) ?>"<?= selected_attr($d['review_status'] === $value) ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>یادداشت بررسی (برای رد یا تعلیق الزامی است)
            <textarea name="review_note" maxlength="2000"><?= esc($d['review_note']) ?></textarea>
          </label>
          <label class="check"><input type="checkbox" name="active"<?= checked_attr((bool) $d['active']) ?>> انتشار پروفایل پس از تأیید (با مطب فعال)</label>
          <p class="muted">تعلیق، نمایش عمومی و رزرو جدید را متوقف می‌کند و نوبت‌های قطعی قبلی حفظ می‌شوند. تأیید مدیر به معنی استعلام خودکار نظام پزشکی نیست.</p>
          <button>ثبت وضعیت</button>
        </form>
      </details>
      <?php $futureN = (int) query("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed' AND s.starts_at>NOW()", [$d['id']])->fetchColumn(); ?>
      <details class="panel account-actions" open>
        <summary>تعلیق یا حذف پزشک</summary>
        <?php if ($d['review_status'] === 'suspended'): ?>
          <p class="notice">این پزشک تعلیق است<?= $d['review_note'] ? ': ' . esc($d['review_note']) : '' ?>.</p>
          <form method="post" class="inline-form">
            <?php form_token('doctor_unsuspend'); ?>
            <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
            <button><?= icon('check') ?> رفع تعلیق و انتشار دوباره</button>
          </form>
        <?php else: ?>
          <form method="post" class="stack">
            <?php form_token('doctor_suspend'); ?>
            <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
            <label>علت تعلیق (به پزشک نمایش داده می‌شود)<input name="reason" maxlength="500" required></label>
            <p class="muted small">پروفایل پنهان می‌شود و نوبت جدید نمی‌گیرد؛ <?= fa_number($futureN) ?> نوبت قطعی آینده و همه اطلاعات حفظ می‌شود.</p>
            <button class="secondary"><?= icon('lock') ?> تعلیق پزشک</button>
          </form>
        <?php endif; ?>
        <?php if (admin_sensitive() && empty($d['deleted_at'])): ?>
          <form method="post" class="stack danger-zone" data-confirm="پزشک حذف شود؟ این کار برگشت‌پذیر نیست.">
            <?php form_token('doctor_delete'); ?>
            <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
            <p class="small"><strong>حذف پزشک:</strong> پروفایل از سامانه برداشته می‌شود، دسترسی حساب پزشک و منشی‌ها قطع و شماره و کد نظام پزشکی آزاد می‌شود. سوابق نوبت‌های گذشته برای گزارش‌ها می‌ماند.<?= $futureN ? ' <strong>' . fa_number($futureN) . ' نوبت آینده لغو می‌شود</strong>، به بیماران پیامک و اعلان می‌رود و کارمزد پرداخت‌شده برای استرداد ثبت می‌شود.' : '' ?></p>
            <label>برای تأیید بنویسید «حذف»<input name="confirm" required autocomplete="off"></label>
            <button class="danger"><?= icon('trash') ?> حذف پزشک</button>
          </form>
        <?php endif; ?>
        <?php if (admin_sensitive()): $walletBal = (int) (query("SELECT balance FROM wallets WHERE owner_type='doctor' AND owner_id=?", [$d['id']])->fetchColumn() ?: 0); ?>
          <form method="post" class="stack danger-zone purge-zone" data-confirm="پزشک برای همیشه حذف شود؟ هیچ راه بازگشتی وجود ندارد.">
            <?php form_token('doctor_purge'); ?>
            <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
            <p class="small"><strong>حذف دائمی پزشک:</strong> پزشک و همه اطلاعاتش برای همیشه از پایگاه داده پاک می‌شود: پروفایل و عکس، مطب‌ها، برنامه کاری، خدمات، همه نوبت‌ها و سوابق مراجعه، نظرها، اشتراک‌ها، کدهای تخفیف و کیف پول. حساب کاربری متصل به‌عنوان حساب بیمار باقی می‌ماند و تراکنش‌های بانکی برای حسابداری حفظ می‌شود.<?= empty($d['deleted_at']) && $futureN ? ' <strong>' . fa_number($futureN) . ' نوبت آینده ابتدا لغو و به بیماران اطلاع داده می‌شود.</strong>' : '' ?></p>
            <?php if ($walletBal !== 0): ?><p class="error">کیف پول این پزشک <?= esc(toman($walletBal)) ?> موجودی دارد؛ پیش از حذف دائمی آن را تسویه کنید.</p><?php endif; ?>
            <label>برای تأیید بنویسید «<?= esc(DOCTOR_PURGE_WORD) ?>»<input name="confirm" required autocomplete="off"></label>
            <button class="danger"><?= icon('trash') ?> حذف دائمی پزشک</button>
          </form>
        <?php endif; ?>
      </details>

    </div>
  </div>

  <section class="panel">
    <div class="panel-head"><h2>مطب‌های پزشک</h2><span class="muted">یک پزشک می‌تواند در چند شهر مطب داشته باشد.</span></div>
    <div class="card-grid">
      <?php foreach ($clinics as $cl): ?>
        <article class="card">
          <h3><?= esc($cl['name']) ?> <?= $cl['is_primary'] ? '<span class="tag">اصلی</span>' : '' ?></h3>
          <p><?= icon('pin') ?> <?= esc($cl['city'] . ' — ' . $cl['address']) ?></p>
          <p class="muted"><?= $cl['active'] ? 'فعال' : 'غیرفعال' ?><?= $cl['latitude'] ? ' • موقعیت نقشه ثبت شده' : '' ?></p>
          <div class="actions">
            <a href="admin.php?tab=doctors&amp;doctor=<?= (int) $d['id'] ?>&amp;clinic=<?= (int) $cl['id'] ?>#clinic-form">ویرایش مطب</a>
            <?php if (!$cl['is_primary']): ?>
              <form method="post" class="inline-form" data-confirm="مطب حذف شود؟">
                <?php form_token('delete_clinic'); ?>
                <input type="hidden" name="id" value="<?= (int) $cl['id'] ?>">
                <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
                <button class="danger small">حذف</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php $edit = $postAction === 'clinic' ? $post : $editClinic; ?>
    <h3 id="clinic-form"><?= $edit ? 'ویرایش مطب' : 'افزودن مطب جدید' ?></h3>
    <form method="post" class="stack">
      <?php form_token('clinic'); ?>
      <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
      <input type="hidden" name="id" value="<?= esc($edit['id'] ?? '') ?>">
      <?php clinic_fields($edit, true); ?>
      <label class="check"><input type="checkbox" name="active"<?= checked_attr((bool) ($edit['active'] ?? !$edit)) ?>> فعال</label>
      <label class="check"><input type="checkbox" name="is_primary"<?= checked_attr(!empty($edit['is_primary'])) ?>> مطب اصلی</label>
      <div class="actions"><button>ذخیره مطب</button> <a href="admin.php?tab=doctors&amp;doctor=<?= (int) $d['id'] ?>">فرم مطب جدید</a></div>
    </form>
  </section>

  <details class="panel">
    <summary>افزودن زمان نوبت تکی به مطب</summary>
    <form method="post" class="stack">
      <?php form_token('slot'); ?>
      <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
      <?php select_field('مطب', 'clinic_id', array_values(array_filter($clinics, fn($c) => (bool) $c['active']))); ?>
      <?php jalali_input('تاریخ و ساعت شمسی به وقت تهران', 'starts_at', null, true); ?>
      <?php input('ظرفیت (۱ تا ۱۰۰)', 'capacity', '1', 'number'); ?>
      <button>ثبت نوبت</button>
      <p class="muted">برنامه هفتگی را پزشک از پنل خود تعیین می‌کند. زمان هم‌زمان برای یک پزشک حتی در دو مطب مجاز نیست.</p>
    </form>
    <?php if ($slots): ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>مطب</th><th>زمان تهران</th><th>ظرفیت</th></tr></thead>
          <tbody>
          <?php foreach ($slots as $slot): ?>
            <tr><td><?= esc($slot['clinic']) ?></td><td><bdi><?= esc(jdate($slot['starts_at'], true)) ?></bdi></td><td><?= fa_number($slot['capacity']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </details>

  <?php if ($events): ?>
    <details class="panel">
      <summary>تاریخچه تغییرات پزشک</summary>
      <ul class="timeline">
        <?php foreach ($events as $e): ?>
          <li><strong><?= esc(event_label($e['event_type'])) ?></strong> <small><bdi><?= esc(jdate($e['created_at'], true)) ?></bdi></small></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>

<?php else: ?>
  <?php $f = $filters; ?>
  <nav class="chip-tabs" aria-label="وضعیت">
    <a href="admin.php?tab=doctors"<?= $f['status'] === '' ? ' class="current"' : '' ?>>همه</a>
    <?php foreach ($labels as $value => $label): ?>
      <a href="admin.php?tab=doctors&amp;status=<?= esc($value) ?>"<?= $f['status'] === $value ? ' class="current"' : '' ?>><?= esc($label) ?> <span><?= fa_number($statusCounts[$value] ?? 0) ?></span></a>
    <?php endforeach; ?>
    <?php if (!empty($statusCounts['deleted'])): ?><a href="admin.php?tab=doctors&amp;status=deleted"<?= $f['status'] === 'deleted' ? ' class="current"' : '' ?>>حذف‌شده <span><?= fa_number($statusCounts['deleted']) ?></span></a><?php endif; ?>
  </nav>
  <form method="get" class="filter-bar">
    <input type="hidden" name="tab" value="doctors">
    <input type="hidden" name="status" value="<?= esc($f['status']) ?>">
    <label>جستجو<input name="q" value="<?= esc($f['q']) ?>" placeholder="نام، موبایل یا کد نظام پزشکی"></label>
    <label>تخصص
      <select name="specialty"><option value="0">همه</option>
        <?php foreach (specialties_list(false) as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= selected_attr($f['specialty'] === (int) $s['id']) ?>><?= esc($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="location-fields" data-geo data-optional>
      <label>استان
        <select name="province"><option value="0">همه</option>
          <?php foreach (provinces_list(false) as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= selected_attr($f['province'] === (int) $p['id']) ?>><?= esc($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>شهر
        <select name="city" data-selected="<?= (int) $f['city'] ?>"><option value="0">همه</option>
          <?php if ($f['province']): foreach (cities_of_province($f['province'], false) as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= selected_attr($f['city'] === (int) $c['id']) ?>><?= esc($c['name']) ?></option>
          <?php endforeach; endif; ?>
        </select>
      </label>
    </div>
    <label>اشتراک
      <select name="subscription">
        <option value="">همه</option>
        <option value="active"<?= selected_attr($f['subscription'] === 'active') ?>>فعال</option>
        <option value="expired"<?= selected_attr($f['subscription'] === 'expired') ?>>منقضی</option>
        <option value="none"<?= selected_attr($f['subscription'] === 'none') ?>>بدون اشتراک</option>
      </select>
    </label>
    <button><?= icon('search') ?> اعمال فیلتر</button>
  </form>
  <p class="muted">تعداد نتایج: <?= fa_number($total) ?></p>
  <div class="doctor-review-grid">
  <?php foreach($rows as $d): ?><article class="panel doctor-review-card">
  <div class="doctor-review-heading"><?= doctor_avatar_markup($d) ?><div><h2><?= esc(doctor_display_name($d['name'])) ?></h2><p><?= esc($d['specialty_name'] ?? $d['specialty']) ?></p><span class="badge status-<?= esc($d['review_status']) ?>"><?= esc($labels[$d['review_status']] ?? '') ?></span></div></div>
  <p class="muted"><?= icon('pin') ?> <?= esc($d['city_names'] ?? '') ?></p><p class="small">موبایل: <bdi><?= esc(admin_mobile($d['mobile'] ?? '')) ?></bdi> · نظام پزشکی: <?= esc(fa_number($d['medical_code'] ?? '—')) ?></p>
  <a class="button secondary small" href="admin.php?tab=doctors&amp;doctor=<?= (int)$d['id'] ?>"><?= icon('user') ?> پرونده و مدارک</a>
  <form method="post" class="review-actions" data-confirm="وضعیت پزشک تغییر کند؟"><?php form_token('review'); ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="active" value="1"><label>علت رد یا تعلیق<input name="review_note" maxlength="2000" placeholder="برای رد یا تعلیق ضروری است"></label><div class="button-row"><button name="status" value="approved" class="review-approve"><?= icon('check') ?> تأیید</button><button name="status" value="rejected" class="danger"><?= icon('close') ?> رد درخواست</button><button name="status" value="suspended" class="secondary"><?= icon('lock') ?> تعلیق</button></div></form>
  </article><?php endforeach; ?></div>
  <?php if (!$rows): ?><p class="empty-state">پزشکی با این فیلترها پیدا نشد.</p><?php endif; ?>
  <?= pagination($page, $total, 30, ['tab' => 'doctors'] + array_filter($f, fn($v) => $v !== '' && $v !== 0)) ?>

  <details class="panel"<?= ($post['action'] ?? '') === 'create_doctor' ? ' open' : '' ?>>
    <summary>ثبت پزشک توسط مدیر</summary>
    <p class="muted">پزشک به‌صورت تأییدشده ساخته می‌شود؛ برای نمایش عمومی، اشتراک را نیز ثبت کنید.</p>
    <form method="post" class="stack">
      <?php form_token('create_doctor'); ?>
      <?php $values = ($post['action'] ?? '') === 'create_doctor' ? $post : []; ?>
      <?php doctor_fields($values); ?>
      <h3>مشخصات مطب</h3>
      <?php clinic_fields($values, true); ?>
      <button>ثبت پزشک</button>
    </form>
  </details>
<?php endif; ?>
