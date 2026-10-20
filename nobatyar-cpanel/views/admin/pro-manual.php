<section class="panel"><h2>فعال‌سازی دستی اشتراک پرو</h2><form method="get" class="stack"><input type="hidden" name="tab" value="subscriptions"><input type="hidden" name="view" value="manual"><label>انتخاب پزشک<select name="doctor" required data-live-select><option value="">انتخاب کنید</option><?php foreach ($doctors as $row): ?><option value="<?= (int) $row['id'] ?>"<?= selected_attr($d && (int)$d['id'] === (int)$row['id']) ?>><?= esc(doctor_display_name($row['name'])) ?></option><?php endforeach; ?></select></label><button>نمایش وضعیت و امکانات</button></form></section>
<?php if ($d): ?>
<section class="panel"><h2><?= esc(doctor_display_name($d['name'])) ?></h2><p>وضعیت فعلی: <strong><?= doctor_is_pro((int)$d['id'], $d) ? 'پرو' : 'رایگان' ?></strong> · پایان اشتراک زمان‌دار: <?= $d['subscription_until'] ? esc(jdate($d['subscription_until'])) : 'ثبت نشده' ?></p><p class="muted">فعال‌سازی پرو جایگزین تأیید هویت و تأیید پروفایل پزشک نیست. تنظیم اختصاصی هر امکان بر تنظیم پلن اولویت دارد.</p></section>
  <?php if (admin_can('finance')): $overrides = doctor_overrides((int) $d['id']); ?>
  <details class="panel" open><summary>پرو دائمی و امکانات اختصاصی پزشک</summary>
  <p>برای پرو بدون تاریخ پایان، «وضعیت پرو» را فعال و ذخیره کنید. برای اشتراک زمان‌دار، وضعیت پرو را روی «طبق پلن» بگذارید و از فرم روز هدیه استفاده کنید.</p><p>هر مورد مستقل از پلن قابل تنظیم است. گزینه «طبق پلن» تنظیم اختصاصی را حذف می‌کند.</p>
  <form method="post" class="stack"><?php form_token('doctor_features'); ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
  <div class="form-grid">
  <?php foreach (['pro'=>['kind'=>'feature','label'=>'وضعیت پرو']] + pro_catalog() as $key=>$feature): if ($feature['kind']==='base') continue; $v=array_key_exists($key,$overrides) ? ($overrides[$key] === null ? 'unlimited' : (string)$overrides[$key]) : ''; ?>
  <label><?= esc($feature['label']) ?>
  <?php if ($feature['kind']==='limit'): ?><input name="override[<?= esc($key) ?>]" value="<?= esc($v) ?>" placeholder="خالی: طبق پلن؛ unlimited: نامحدود؛ یا عدد">
  <?php else: ?><select name="override[<?= esc($key) ?>]"><?php foreach ([''=>'طبق پلن','1'=>'فعال','0'=>'غیرفعال'] as $value=>$label): ?><option value="<?= esc((string)$value) ?>"<?= selected_attr($v === (string)$value) ?>><?= esc($label) ?></option><?php endforeach; ?></select><?php endif; ?></label>
  <?php endforeach; ?></div><button>ذخیره امکانات اختصاصی</button></form></details>
  <?php endif; ?>
    <section class="panel">
      <h2>روز هدیه یا تمدید دستی</h2>
      <form method="post" class="stack">
        <?php form_token('sub_grant'); ?>
        <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
        <div class="form-grid">
          <?php input('تعداد روز', 'days', '', 'number', true, 4); ?>
          <label>نوع<select name="kind"><option value="gift">روز هدیه</option><option value="manual">تمدید دستی</option></select></label>
        </div>
        <?php input('توضیح (در سوابق پزشک ثبت می‌شود)', 'note', '', 'text', true, 255); ?>
        <p class="muted">روزها از پایان اعتبار فعلی (یا امروز، اگر منقضی شده) اضافه می‌شوند و مبلغی ثبت نمی‌کنند.</p>
        <button>افزودن روز</button>
      </form>
    </section>      <details class="panel">
        <summary>تعیین دستی پایان اشتراک</summary>
        <p class="muted">برای افزودن روز هدیه، صدور پیش‌فاکتور یا ثبت پرداخت، از فرم روز هدیه همین صفحه و بخش <a href="admin.php?tab=subscriptions&amp;view=orders">پیش‌فاکتور و فاکتور</a> استفاده کنید. این فرم پایان اعتبار را مستقیم تعیین می‌کند.</p>
        <form method="post" class="stack">
          <?php form_token('subscription'); ?>
          <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
          <?php jalali_input('پایان اشتراک (شمسی)', 'until', $d['subscription_until']); ?>
          <button>ثبت دسترسی دستی</button>
        </form>
      </details>
<?php endif; ?>
