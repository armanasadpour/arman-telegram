<?php
/**
 * Patient step of the booking. @var array $u @var array $c @var array $svc @var string $start @var int $move
 * @var ?array $old @var bool $free @var string $error @var array $post @var array $missing @var array $members
 * @var ?int $selectedMember @var string $self @var int $fee
 */
$idMode = patient_id_mode();
$summary = ['doctor_id' => $c['doctor_id'], 'doctor' => $c['doctor'], 'photo_file' => $c['photo_file'], 'specialty' => $c['specialty'], 'clinic' => $c['name'], 'address' => $c['address'], 'city' => $c['city'], 'starts_at' => $start ?: date('Y-m-d H:i:s'), 'service' => $svc['name']];
$profilePost = ($post['action'] ?? '') === 'profile' ? $post : [];
$v = $profilePost ?: ['first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'gender' => $u['gender'], 'insurance' => $u['insurance'] ?? null, 'city_id' => $u['city_id'] ?: (current_city()['id'] ?? 0)];
$genders = ['male' => 'آقا', 'female' => 'خانم'];
$relations = ['همسر', 'فرزند', 'پدر', 'مادر', 'سایر'];
$req = '<span class="req" aria-hidden="true">*</span>';
/** National ID with the «اتباع خارجی هستم» tick (shared helper). */
$idField = function (string $hint, bool $foreign, string $who) use ($idMode) {
    echo national_id_field($hint, $foreign, $who, $idMode === 'required');
};
$genderField = function (?string $value) use ($genders, $req) {
    ?>
    <fieldset class="field"><legend>جنسیت <?= $req ?></legend>
      <div class="seg">
        <?php foreach ($genders as $k => $l): ?><label><input type="radio" name="gender" value="<?= $k ?>"<?= checked_attr($value === $k) ?> required><span><?= $l ?></span></label><?php endforeach; ?>
      </div>
    </fieldset>
    <?php
};
$profileForm = function (bool $editing) use ($v, $u, $req, $idField, $genderField, $profilePost) {
    ?>
    <form method="post" class="bk-form" novalidate data-validate>
      <?php form_token('profile'); ?>
      <div class="field-grid">
        <label class="field"><span>نام <?= $req ?></span><input name="first_name" value="<?= esc($v['first_name'] ?? '') ?>" placeholder="نام خود را وارد کنید" autocomplete="given-name" maxlength="50" required></label>
        <label class="field"><span>نام خانوادگی <?= $req ?></span><input name="last_name" value="<?= esc($v['last_name'] ?? '') ?>" placeholder="نام خانوادگی خود را وارد کنید" autocomplete="family-name" maxlength="50" required></label>
      </div>
      <?php $idField(identity_hint($u['id_type'], $u['national_id_enc']), !empty($v['foreign']) || $u['id_type'] === 'foreign', 'self'); ?>
      <?= birth_select($u['birth_date'] ?? null, $profilePost) ?>
      <div class="field-grid">
        <?php $genderField($v['gender'] ?? null); ?>
        <?= insurance_select($v['insurance'] ?? null) ?>
      </div>
      <div class="field-geo"><?php location_fields(['city_id' => (int) ($v['city_id'] ?? 0), 'province_id' => (int) ($v['province_id'] ?? 0)]); ?></div>
      <p class="field-help"><?= icon('lock') ?> کد ملی رمزنگاری‌شده نگهداری می‌شود؛ فقط پزشک و منشی مطبی که نوبت گرفته‌اید آن را در فهرست روزانه می‌بینند.</p>
      <div class="bk-actions"><button class="button block"><?= $editing ? 'ذخیره تغییرات' : 'ثبت و ادامه' ?> <?= icon('chevron-left') ?></button></div>
    </form>
    <?php
};
/** Add or complete a family member: every detail the clinic needs, the same as for the account holder. */
$memberForm = function (?array $m, array $p) use ($relations, $req, $idField, $genderField) {
    $editing = $m !== null;
    $val = fn(string $k) => $p ? ($p[$k] ?? '') : ($m[$k] ?? '');
    ?>
    <form method="post" class="bk-form" novalidate data-validate>
      <?php form_token('family'); ?>
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><?php endif; ?>
      <div class="field-grid">
        <label class="field"><span>نام و نام خانوادگی <?= $req ?></span><input name="name" value="<?= esc((string) $val('name')) ?>" maxlength="190" required placeholder="مثلاً سارا محمدی"></label>
        <label class="field"><span>نسبت با شما <?= $req ?></span><select name="relationship" required><option value="">انتخاب کنید</option><?php foreach ($relations as $r): ?><option<?= selected_attr($val('relationship') === $r) ?>><?= $r ?></option><?php endforeach; ?></select></label>
      </div>
      <?php $idField($editing ? identity_hint($m['id_type'] ?? null, $m['national_id_enc'] ?? null) : '', !empty($p['foreign']) || ($editing && ($m['id_type'] ?? '') === 'foreign'), 'other'); ?>
      <?= birth_select($editing ? $m['birth_date'] : null, $p) ?>
      <div class="field-grid">
        <?php $genderField((string) $val('gender')); ?>
        <?= insurance_select((string) $val('insurance')) ?>
      </div>
      <div class="bk-actions"><button class="button secondary block"><?= $editing ? icon('check') . ' ذخیره اطلاعات ' . esc($m['name']) : icon('user-plus') . ' افزودن مراجعه‌کننده' ?></button></div>
    </form>
    <?php
};
$familyPost = ($post['action'] ?? '') === 'family' ? $post : [];
$editingId = (int) ($familyPost['id'] ?? ($_GET['complete'] ?? 0));
?>
<div class="bk" data-booking-step>
  <?php render('public/booking-steps', ['current' => 2]); ?>
  <?php render('public/booking-summary', ['s' => $summary, 'collapsible' => false]); ?>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= icon('alert') ?><?= esc($error) ?></p><?php endif; ?>

  <?php if (!$free): ?>
    <section class="bk-card alert-card">
      <span class="ac-icon"><?= icon('calendar') ?></span>
      <h2>این زمان دیگر آزاد نیست</h2>
      <p>ممکن است بیمار دیگری آن را رزرو کرده یا زمانش گذشته باشد. لطفاً زمان دیگری انتخاب کنید.</p>
      <a class="button" href="<?= esc('doctor.php?id=' . (int) $c['doctor_id'] . '&clinic=' . (int) $c['id'] . '&service=' . (int) $svc['id']) ?>"><?= icon('calendar') ?> انتخاب زمان دیگر</a>
    </section>
  <?php elseif ($old): ?>
    <section class="bk-card">
      <h2>انتقال نوبت <?= esc($old['patient_name']) ?></h2>
      <p class="muted">نوبت فعلی: <?= esc(jalali_long($old['starts_at'])) ?></p>
      <form method="post" class="bk-form">
        <?php form_token('reserve'); ?>
        <input type="hidden" name="revision" value="<?= (int) $old['revision'] ?>">
        <label class="field"><span>علت تغییر <?= $req ?></span><input name="reason" maxlength="500" required placeholder="مثلاً هم‌زمانی با کار"></label>
        <div class="bk-actions"><button class="button block">ثبت زمان جدید</button></div>
      </form>
    </section>
  <?php elseif ($missing): ?>
    <section class="bk-card">
      <h2>تکمیل اطلاعات</h2>
      <p class="muted">این اطلاعات فقط یک بار پرسیده می‌شود و روی نوبت‌ها و رسیدها درج می‌شود.</p>
      <?php $profileForm(false); ?>
    </section>
  <?php else: ?>
    <form method="post" class="bk-card bk-who" data-who-form>
      <?php form_token('reserve'); ?>
      <h2>مراجعه‌کننده</h2>
      <p class="muted">برای چه کسی نوبت می‌گیرید؟</p>
      <div class="who-list" role="radiogroup" aria-label="مراجعه‌کننده">
        <div class="who-item">
          <label class="who-card">
            <input type="radio" name="family_member_id" value="0"<?= checked_attr($selectedMember === 0) ?> required>
            <span class="who-radio" aria-hidden="true"></span>
            <span class="who-body"><strong><?= esc($u['name']) ?> <small>(خودم)</small></strong><small dir="ltr"><?= esc(fa_number($u['mobile'])) ?></small></span>
          </label>
          <a class="who-edit" href="#edit-profile" data-open-details="edit-profile"><?= icon('edit') ?> ویرایش</a>
        </div>
        <?php foreach ($members as $m): $incomplete = member_missing($m); ?>
          <div class="who-item">
            <label class="who-card<?= $incomplete ? ' is-incomplete' : '' ?>">
              <input type="radio" name="family_member_id" value="<?= (int) $m['id'] ?>"<?= checked_attr($selectedMember === (int) $m['id']) ?>>
              <span class="who-radio" aria-hidden="true"></span>
              <span class="who-body"><strong><?= esc($m['name']) ?> <small>(<?= esc($m['relationship']) ?>)</small></strong><small><?= esc(implode(' • ', array_filter([$m['birth_date'] ? 'متولد ' . jdate($m['birth_date']) : '', $genders[$m['gender'] ?? ''] ?? '', insurance_label($m['insurance'] ?? null), identity_hint($m['id_type'] ?? null, $m['national_id_enc'] ?? null)]))) ?></small>
                <?php if ($incomplete): ?><small class="who-warn"><?= icon('alert') ?> اطلاعات ناقص است؛ پیش از رزرو تکمیل کنید</small><?php endif; ?></span>
            </label>
            <a class="who-edit<?= $incomplete ? ' strong' : '' ?>" href="<?= esc($self . '&complete=' . (int) $m['id']) ?>#member-<?= (int) $m['id'] ?>"><?= icon('edit') ?> <?= $incomplete ? 'تکمیل اطلاعات' : 'ویرایش' ?></a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php booking_reminder_choice($start, $post); ?>
      <div class="bk-actions sticky-cta">
        <button class="button block" data-who-continue>رزرو موقت و ادامه <?= icon('chevron-left') ?></button>
        <small class="cta-note"><?= icon('clock') ?> زمان انتخابی <?= fa_number(setting('hold_minutes', '10')) ?> دقیقه برای شما نگه داشته می‌شود<?= $fee > 0 ? '؛ کارمزد رزرو ' . esc(toman($fee)) . (vat_amount($fee) > 0 ? ' به‌علاوه ' . esc(vat_label(vat_percent())) . ' مالیات بر ارزش افزوده' : '') : '' ?>.</small>
      </div>
    </form>

    <?php foreach ($members as $m): if ($editingId !== (int) $m['id']) continue; ?>
      <section class="bk-card bk-member-edit" id="member-<?= (int) $m['id'] ?>">
        <h2><?= icon('edit') ?> اطلاعات <?= esc($m['name']) ?></h2>
        <?php $memberForm($m, (int) ($familyPost['id'] ?? 0) === (int) $m['id'] ? $familyPost : []); ?>
      </section>
    <?php endforeach; ?>

    <details class="bk-card bk-add"<?= $familyPost && empty($familyPost['id']) ? ' open' : '' ?>>
      <summary><?= icon('plus') ?> دریافت نوبت برای شخص دیگر</summary>
      <?php $memberForm(null, empty($familyPost['id']) ? $familyPost : []); ?>
    </details>

    <details class="bk-card bk-add" id="edit-profile"<?= $profilePost ? ' open' : '' ?>>
      <summary><?= icon('edit') ?> ویرایش اطلاعات من</summary>
      <?php $profileForm(true); ?>
    </details>
  <?php endif; ?>
</div>
<script src="assets/booking.js?v=<?= esc(APP_VERSION) ?>" defer></script>
