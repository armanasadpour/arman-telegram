<?php
/** Admin subscriptions: overview, plans, discount codes, orders/invoices, revenue report, settings. */
$views = ['overview' => 'نمای کلی', 'manual' => 'فعال‌سازی دستی پزشک', 'pro' => 'امکانات پرو', 'plans' => 'پلن‌ها و قیمت', 'codes' => 'کدهای تخفیف', 'orders' => 'پیش‌فاکتور و فاکتور', 'report' => 'گزارش درآمد', 'settings' => 'تنظیمات'];
$kinds = subscription_order_kinds();
?>
<?php if (!subscriptions_on()): ?>
  <p class="notice"><?= icon('info') ?> <strong>فروش اشتراک غیرفعال است.</strong> منوی اشتراک در پنل پزشکان و مراکز پنهان است، اشتراک شرطِ نمایش و نوبت‌دهی نیست و درآمد سامانه فقط کارمزد رزرو بیماران است. برای روشن کردن: <a href="admin.php?tab=subscriptions&amp;view=settings">اشتراک پرو ← تنظیمات</a>.</p>
<?php endif; ?>
<nav class="chip-tabs pro-admin-tabs" aria-label="بخش‌های اشتراک">
  <?php foreach ($views as $key => $label): ?>
    <a href="admin.php?tab=subscriptions&amp;view=<?= $key ?>"<?= $view === $key ? ' class="current" aria-current="page"' : '' ?>><?= esc($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($view === 'pro'): $set = pro_settings(); $hour = daily_summary_hour(); ?>
  <section class="panel">
    <div class="panel-head"><h2>امکانات نسخه پرو پزشکان</h2><span class="muted"><?= fa_number((int) ($proStats['pro'] ?? 0)) ?> پزشک پرو از <?= fa_number((int) ($proStats['total'] ?? 0)) ?> پزشک تأییدشده • <?= fa_number((int) ($proStats['picked'] ?? 0)) ?> نفر هنگام ثبت‌نام پرو را انتخاب کردند</span></div>
    <p class="muted">هر امکانی که تیک «فقط پرو» بخورد فقط با اشتراک پرو (پرداخت، دوره آزمایشی یا اشتراک هدیه) فعال است؛ بقیه برای همه پزشکان رایگان است. برای تعداد مطب، خدمت و منشی، حد نسخه رایگان را بنویسید (خالی = نامحدود)؛ حد نسخه پرو در هر پلن تعیین می‌شود (خالی = نامحدود). پزشک در هر حال بدون اشتراک منتشر می‌شود و نوبت می‌گیرد. مراکز درمانی نسخه رایگان ندارند و فقط با اشتراک مرکز کار می‌کنند.</p>
    <?php if (!subscriptions_on()): ?><p class="notice"><?= icon('info') ?> فروش اشتراک در «اشتراک پرو ← تنظیمات» خاموش است؛ تا وقتی خاموش است همه پزشکان همه امکانات را بدون محدودیت دارند.</p><?php endif; ?>
    <form method="post" class="stack">
      <?php form_token('pro_catalog'); ?>
      <div class="table-wrap"><table class="pro-catalog">
        <thead><tr><th>امکان</th><th>فقط پرو</th><th>نسخه رایگان</th></tr></thead>
        <tbody>
          <?php foreach (pro_catalog() as $key => $f): ?>
            <tr>
              <td><?= icon($f['icon']) ?> <?= esc($f['label']) ?></td>
              <?php if ($f['kind'] === 'base'): ?>
                <td><span class="muted small">پایه</span></td><td><span class="badge status-confirmed">همیشه رایگان</span></td>
              <?php else: ?>
                <td><label class="check"><input type="checkbox" name="pro[<?= esc($key) ?>]" value="1"<?= checked_attr($set['pro'][$key]) ?>><span class="sr-only">فقط پرو: <?= esc($f['label']) ?></span></label></td>
                <td>
                  <?php if ($f['kind'] === 'limit'): ?>
                    <label class="inline-limit">حد رایگان <input name="limit[<?= esc($key) ?>]" value="<?= $set['limits'][$key] === null ? '' : fa_number($set['limits'][$key]) ?>" inputmode="numeric" dir="ltr" maxlength="3" placeholder="نامحدود"> <?= esc($f['unit']) ?></label>
                  <?php else: ?>
                    <span class="muted small"><?= $set['pro'][$key] ? 'ندارد' : 'دارد' ?></span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <h3>پیامک خلاصه پایان روز</h3>
      <label>ساعت ارسال<select name="daily_summary_hour"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= selected_attr($h === $hour) ?>><?= fa_number(sprintf('%02d:00', $h)) ?></option><?php endfor; ?></select></label>
      <p class="muted small">الگوی متن پیامک (کلید «خلاصه پایان روز پزشک») در <a href="admin.php?tab=sms_templates">الگوهای پیامک</a> تنظیم می‌شود. یادآوری پیامکی بیمار جزو اشتراک پرو نیست.</p>
      <button>ذخیره امکانات پرو</button>
    </form>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>قیمت نسخه پرو</h2><a href="admin.php?tab=subscriptions&amp;view=plans">مدیریت پلن‌ها و قیمت‌ها</a></div>
    <?php $doctorPlans = plans_list(false, 'doctor'); ?>
    <?php if (!$doctorPlans): ?>
      <p class="empty-state">هنوز پلن پرو تعریف نشده است. در «پلن‌ها و قیمت» برای مثال پلن‌های ۱، ۳، ۶ و ۱۲ ماهه بسازید؛ تا آن زمان در فرم ثبت‌نام «قیمت به‌زودی اعلام می‌شود» نمایش داده می‌شود.</p>
    <?php else: ?>
      <ul class="plain-list">
        <?php foreach ($doctorPlans as $p): ?><li><strong><?= esc($p['name']) ?></strong> — <?= fa_number($p['duration_days']) ?> روز • <?= esc(toman((int) $p['price_toman'])) ?><?= $p['active'] ? '' : ' <span class="badge">غیرفعال</span>' ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="muted small">دوره آزمایشی رایگان، مهلت پیش‌فاکتور و روزهای فرجه در بخش «تنظیمات» همین صفحه است.</p>
  </section>
<?php endif; ?>

<?php if ($view === 'overview'): ?>
  <div class="stat-grid">
    <article class="stat-card"><?= icon('check') ?><span>اشتراک فعال</span><strong><?= fa_number((int) $counts['active']) ?></strong></article>
    <article class="stat-card"><?= icon('clock') ?><span>در دوره فرجه</span><strong><?= fa_number((int) $counts['grace']) ?></strong></article>
    <article class="stat-card"><?= icon('alert') ?><span>منقضی</span><strong><?= fa_number((int) $counts['expired']) ?></strong></article>
    <article class="stat-card"><?= icon('wallet') ?><span>درآمد اشتراک از ابتدای ماه</span><strong class="small"><?= esc(toman($month['revenue'])) ?></strong><small><?= fa_number($month['orders']) ?> فاکتور</small></article>
  </div>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2>اشتراک‌های نزدیک انقضا یا در فرجه</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>پزشک</th><th>پایان اعتبار</th><th>باقی‌مانده</th></tr></thead>
          <tbody>
          <?php foreach ($expiring as $e): ?>
            <tr>
              <td><a href="admin.php?tab=doctors&amp;doctor=<?= (int) $e['id'] ?>"><?= esc($e['name']) ?></a></td>
              <td><bdi><?= esc(jdate($e['subscription_until'])) ?></bdi></td>
              <td><?= (int) $e['days_left'] >= 0 ? fa_number((int) $e['days_left'] + 1) . ' روز' : '<span class="badge status-cancelled">فرجه</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$expiring): ?><p class="empty-state">اشتراکی در ۱۴ روز آینده تمام نمی‌شود.</p><?php endif; ?>
    </section>
    <section class="panel"><h2>فعال‌سازی دستی پرو</h2><p>به پزشک اشتراک زمان‌دار یا پرو بدون تاریخ پایان بدهید و امکانات اختصاصی او را تنظیم کنید.</p><a class="button" href="admin.php?tab=subscriptions&amp;view=manual">انتخاب پزشک و فعال‌سازی</a></section>
  </div>

<?php elseif ($view === 'manual'): ?>
  <?php render('admin/pro-manual', ['doctors'=>$doctors, 'd'=>$d]); ?>
<?php elseif ($view === 'plans'): ?>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2>پلن‌ها</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>پلن</th><th>مدت</th><th>قیمت</th><th>محدودیت</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($plans as $p): ?>
            <tr>
              <td><strong><?= esc($p['name']) ?></strong><?= $p['highlight'] ? ' <span class="tag">پیشنهادی</span>' : '' ?><br><small><?= $p['audience'] === 'center' ? 'مراکز درمانی' : 'پزشکان' ?></small></td>
              <td><?= fa_number($p['duration_days']) ?> روز</td>
              <td><?= esc(toman($p['price_toman'])) ?></td>
              <td><small><?= $p['max_clinics'] ? fa_number($p['max_clinics']) . ' مطب' : 'مطب نامحدود' ?> • <?= $p['max_secretaries'] ? fa_number($p['max_secretaries']) . ' منشی' : 'منشی نامحدود' ?></small></td>
              <td><?= $p['active'] ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال</span>' ?></td>
              <td><a href="admin.php?tab=subscriptions&amp;view=plans&amp;edit=<?= (int) $p['id'] ?>">ویرایش</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted">پلن‌های پیش‌فرض بدون قیمت و غیرفعال ساخته شده‌اند؛ قیمت را تعیین و فعال کنید.</p>
    </section>
    <section class="panel">
      <h2><?= $edit ? 'ویرایش پلن' : 'پلن جدید' ?></h2>
      <form method="post" class="stack">
        <?php form_token('plan_save'); ?>
        <input type="hidden" name="id" value="<?= esc($edit['id'] ?? '') ?>">
        <?php input('نام پلن', 'name', $edit['name'] ?? '', 'text', true, 120); ?>
        <label>مخاطب پلن<select name="audience">
          <option value="doctor"<?= selected_attr(($edit['audience'] ?? 'doctor') === 'doctor') ?>>پزشکان</option>
          <option value="center"<?= selected_attr(($edit['audience'] ?? '') === 'center') ?>>کلینیک‌ها و مراکز درمانی</option>
        </select></label>
        <div class="form-grid">
          <?php input('مدت اعتبار (روز)', 'duration_days', (string) ($edit['duration_days'] ?? '30'), 'number', true, 4); ?>
          <?php input('قیمت (تومان)', 'price_toman', isset($edit['price_toman']) ? fa_number(number_format((int) $edit['price_toman'], 0, '.', '٬')) : '', 'text', true, 20); ?>
          <?php input('حداکثر مطب فعال (خالی = نامحدود)', 'max_clinics', (string) ($edit['max_clinics'] ?? ''), 'number', false, 4); ?>
          <?php input('حداکثر منشی (خالی = نامحدود)', 'max_secretaries', (string) ($edit['max_secretaries'] ?? ''), 'number', false, 4); ?>
          <?php input('حداکثر خدمت فعال (خالی = نامحدود)', 'max_services', (string) ($edit['max_services'] ?? ''), 'number', false, 4); ?>
        </div>
        <label>امکانات (هر خط یک مورد)<textarea name="features" maxlength="3000"><?= esc($edit['features'] ?? '') ?></textarea></label>
        <?php input('ترتیب نمایش', 'sort_order', (string) ($edit['sort_order'] ?? ''), 'number', false, 6); ?>
        <label class="check"><input type="checkbox" name="highlight"<?= checked_attr(!empty($edit['highlight'])) ?>> نمایش به‌عنوان پلن پیشنهادی</label>
        <label class="check"><input type="checkbox" name="active"<?= checked_attr(!empty($edit['active'])) ?>> فعال (قابل خرید)</label>
        <div class="actions"><button>ذخیره پلن</button> <a href="admin.php?tab=subscriptions&amp;view=plans">فرم جدید</a></div>
      </form>
    </section>
  </div>

<?php elseif ($view === 'codes'): ?>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2>کدهای تخفیف</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>کد</th><th>تخفیف</th><th>کاربرد</th><th>استفاده</th><th>اعتبار</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($codes as $c): ?>
            <tr>
              <td><code dir="ltr"><?= esc($c['code']) ?></code><br><small><?= esc($c['title']) ?><?= $c['doctor_name'] ? ' • اختصاصی ' . esc($c['doctor_name']) : ' • عمومی' ?></small></td>
              <td><?= $c['kind'] === 'percent' ? fa_number($c['value']) . '٪' : esc(toman($c['value'])) ?></td>
              <td><?= esc(['subscription' => 'اشتراک پزشک', 'center' => 'اشتراک مرکز', 'booking' => 'کارمزد رزرو', 'all' => 'همه'][$c['scope']] ?? $c['scope']) ?><?= $c['plan_name'] ? '<br><small>' . esc($c['plan_name']) . '</small>' : '' ?></td>
              <td><?= fa_number($c['used']) ?><?= $c['reserved'] ? ' <small>(+' . fa_number($c['reserved']) . ' رزرو)</small>' : '' ?> / <?= $c['max_uses'] === null ? '∞' : fa_number($c['max_uses']) ?></td>
              <td><small><?= $c['ends_at'] ? 'تا ' . esc(jdate($c['ends_at'])) : 'بدون پایان' ?></small><br><?= $c['active'] ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال</span>' ?></td>
              <td><a href="admin.php?tab=subscriptions&amp;view=codes&amp;edit=<?= (int) $c['id'] ?>">ویرایش</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$codes): ?><p class="empty-state">کد تخفیفی تعریف نشده است.</p><?php endif; ?>
    </section>
    <section class="panel">
      <h2><?= $edit ? 'ویرایش کد' : 'کد تخفیف جدید' ?></h2>
      <form method="post" class="stack">
        <?php form_token('discount_save'); ?>
        <input type="hidden" name="id" value="<?= esc($edit['id'] ?? '') ?>">
        <div class="form-grid">
          <?php input('کد (انگلیسی)', 'code', $edit['code'] ?? '', 'text', true, 40); ?>
          <?php input('عنوان داخلی', 'title', $edit['title'] ?? '', 'text', false, 190); ?>
          <label>نوع<select name="kind"><option value="percent"<?= selected_attr(($edit['kind'] ?? '') === 'percent') ?>>درصدی</option><option value="fixed"<?= selected_attr(($edit['kind'] ?? '') === 'fixed') ?>>مبلغ ثابت (تومان)</option></select></label>
          <?php input('مقدار (درصد یا تومان)', 'value', (string) ($edit['value'] ?? ''), 'text', true, 12); ?>
          <?php input('سقف تخفیف (تومان، اختیاری)', 'max_discount_toman', (string) ($edit['max_discount_toman'] ?? ''), 'text', false, 12); ?>
          <?php input('حداقل مبلغ خرید (تومان)', 'min_amount_toman', (string) ($edit['min_amount_toman'] ?? ''), 'text', false, 12); ?>
          <?php input('سقف کل استفاده (خالی = نامحدود)', 'max_uses', (string) ($edit['max_uses'] ?? ''), 'number', false, 8); ?>
          <?php input('سقف استفاده هر پزشک/کاربر', 'per_owner_limit', (string) ($edit['per_owner_limit'] ?? '1'), 'number', true, 4); ?>
          <label>کاربرد<select name="scope">
            <?php foreach (['subscription' => 'اشتراک پزشک', 'center' => 'اشتراک مرکز درمانی', 'booking' => 'کارمزد رزرو بیمار', 'all' => 'همه'] as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr(($edit['scope'] ?? 'subscription') === $k) ?>><?= $l ?></option><?php endforeach; ?>
          </select></label>
          <label>فقط برای پلن<select name="plan_id"><option value="">همه پلن‌ها</option>
            <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected_attr((int) ($edit['plan_id'] ?? 0) === (int) $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?>
          </select></label>
          <label>اختصاصی پزشک<select name="doctor_id"><option value="">عمومی (همه)</option>
            <?php foreach ($doctors as $dd): ?><option value="<?= (int) $dd['id'] ?>"<?= selected_attr((int) ($edit['doctor_id'] ?? 0) === (int) $dd['id']) ?>><?= esc($dd['name']) ?></option><?php endforeach; ?>
          </select></label>
          <?php input('شروع اعتبار (شمسی، اختیاری)', 'starts_at', !empty($edit['starts_at']) ? jdate($edit['starts_at']) : '', 'text', false, 10, 'data-jdate'); ?>
          <?php input('پایان اعتبار (شمسی، اختیاری)', 'ends_at', !empty($edit['ends_at']) ? jdate($edit['ends_at']) : '', 'text', false, 10, 'data-jdate'); ?>
        </div>
        <label class="check"><input type="checkbox" name="active"<?= checked_attr((bool) ($edit['active'] ?? true)) ?>> فعال</label>
        <div class="actions"><button>ذخیره کد</button> <a href="admin.php?tab=subscriptions&amp;view=codes">فرم جدید</a></div>
      </form>
    </section>
  </div>

<?php elseif ($view === 'orders'): ?>
  <nav class="chip-tabs pro-admin-tabs">
    <?php foreach (['' => 'همه', 'proforma' => 'پیش‌فاکتور', 'paid' => 'پرداخت‌شده', 'expired' => 'منقضی', 'cancelled' => 'لغوشده'] as $k => $l): ?>
      <a href="admin.php?tab=subscriptions&amp;view=orders<?= $k ? '&amp;status=' . $k : '' ?>"<?= $status === $k ? ' class="current"' : '' ?>><?= $l ?></a>
    <?php endforeach; ?>
  </nav>
  <section class="panel">
    <div class="table-wrap">
      <table>
        <thead><tr><th>شماره</th><th>پزشک</th><th>پلن / نوع</th><th>قابل پرداخت</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><bdi><?= esc($o['number'] ?? ('#' . $o['id'])) ?></bdi><br><small><bdi><?= esc(jdate($o['paid_at'] ?? $o['created_at'], true)) ?></bdi></small></td>
            <td><?= esc($o['doctor_name']) ?></td>
            <td><?= esc($o['plan_name']) ?><br><small><?= esc($kinds[$o['kind']] ?? '') ?></small></td>
            <td><?= esc(toman($o['payable_toman'])) ?><?= $o['discount_toman'] ? '<br><small>تخفیف ' . esc(toman($o['discount_toman'])) . '</small>' : '' ?></td>
            <td><span class="badge order-<?= esc($o['status']) ?>"><?= esc(order_status_label($o['status'])) ?></span><?= $o['payment_method'] ? '<br><small>' . esc(subscription_kind_label($o['payment_method'])) . '</small>' : '' ?></td>
            <td>
              <a href="subscription-invoice.php?id=<?= (int) $o['id'] ?>">نمایش</a>
              <?php if ($o['status'] === 'proforma'): ?>
                <details class="inline-details"><summary>ثبت پرداخت خارج از درگاه</summary>
                  <form method="post" class="stack">
                    <?php form_token('sub_offline'); ?>
                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                    <?php input('شماره پیگیری واریز', 'reference', '', 'text', true, 100); ?>
                    <button class="small">تأیید و صدور فاکتور</button>
                  </form>
                </details>
                <form method="post" class="inline-form" data-confirm="پیش‌فاکتور لغو شود؟"><?php form_token('sub_admin_cancel'); ?><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="danger small">لغو</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$orders): ?><p class="empty-state">سفارشی یافت نشد.</p><?php endif; ?>
  </section>
  <details class="panel">
    <summary>صدور پیش‌فاکتور برای پزشک</summary>
    <form method="post" class="stack">
      <?php form_token('sub_admin_order'); ?>
      <div class="form-grid">
        <?php select_field('پزشک', 'doctor_id', $doctors); ?>
        <?php select_field('پلن', 'plan_id', $plans); ?>
        <label>نوع<select name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label>
        <?php input('کد تخفیف (اختیاری)', 'discount_code', '', 'text', false, 40); ?>
      </div>
      <p class="muted">پزشک پیش‌فاکتور را در پنل خود می‌بیند و می‌تواند آنلاین بپردازد؛ یا پس از واریز، پرداخت را با شماره پیگیری ثبت کنید.</p>
      <button>صدور پیش‌فاکتور</button>
    </form>
  </details>

<?php elseif ($view === 'report'): $r = $report; ?>
  <form method="get" class="filter-bar">
    <input type="hidden" name="tab" value="subscriptions"><input type="hidden" name="view" value="report">
    <label>از تاریخ<input data-jdate name="from" value="<?= esc(jdate($from)) ?>" dir="ltr" placeholder="۱۴۰۵/۰۷/۰۱"></label>
    <label>تا تاریخ<input data-jdate name="to" value="<?= esc(jdate($to)) ?>" dir="ltr"></label>
    <label>استان<select name="province"><option value="0">همه</option><?php foreach ($provinces as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected_attr($province === (int) $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?></select></label>
    <button><?= icon('filter') ?> نمایش</button>
    <a class="button secondary" href="admin.php?<?= esc(http_build_query(['tab' => 'subscriptions', 'view' => 'report', 'from' => jdate($from), 'to' => jdate($to), 'province' => $province, 'export' => 'xlsx'])) ?>"><?= icon('download') ?> خروجی اکسل</a>
  </form>
  <div class="stat-grid">
    <article class="stat-card"><span>درآمد اشتراک</span><strong class="small"><?= esc(toman($r['totals']['revenue'])) ?></strong></article>
    <article class="stat-card"><span>تعداد فاکتور</span><strong><?= fa_number($r['totals']['orders']) ?></strong></article>
    <article class="stat-card"><span>جمع تخفیف‌ها</span><strong class="small"><?= esc(toman($r['totals']['discounts'])) ?></strong></article>
    <article class="stat-card"><span>مالیات دریافتی</span><strong class="small"><?= esc(toman($r['totals']['vat'])) ?></strong></article>
  </div>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2>به تفکیک شهر</h2>
      <div class="table-wrap"><table><thead><tr><th>استان / شهر</th><th>فاکتور</th><th>درآمد</th></tr></thead><tbody>
        <?php foreach ($r['by_city'] as $c): ?><tr><td><?= esc($c['province'] . ' / ' . $c['city']) ?></td><td><?= fa_number($c['orders']) ?></td><td><?= esc(toman($c['revenue'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if (!$r['by_city']): ?><p class="empty-state">در این بازه درآمدی ثبت نشده است.</p><?php endif; ?>
    </section>
    <section class="panel">
      <h2>به تفکیک پلن و روز</h2>
      <div class="table-wrap"><table><thead><tr><th>پلن</th><th>فاکتور</th><th>درآمد</th></tr></thead><tbody>
        <?php foreach ($r['by_plan'] as $c): ?><tr><td><?= esc($c['plan_name']) ?></td><td><?= fa_number($c['orders']) ?></td><td><?= esc(toman($c['revenue'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="table-wrap"><table><thead><tr><th>روز</th><th>فاکتور</th><th>درآمد</th></tr></thead><tbody>
        <?php foreach ($r['by_day'] as $c): ?><tr><td><bdi><?= esc(jdate($c['day'])) ?></bdi></td><td><?= fa_number($c['orders']) ?></td><td><?= esc(toman($c['revenue'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
  </div>

<?php elseif ($view === 'settings'): ?>
  <section class="panel narrow">
    <h2>تنظیمات اشتراک و فاکتور</h2>
    <form method="post" class="stack">
      <?php form_token('subscription_settings'); ?>
      <input type="hidden" name="subscription_sales_form" value="1">
      <label class="check"><input type="checkbox" name="subscriptions_enabled" value="1"<?= checked_attr(subscriptions_on()) ?>> فروش اشتراک پرو پزشکان و اشتراک مراکز فعال باشد</label>
      <p class="muted small">با خاموش کردن، فروش و منوی اشتراک غیرفعال و امکانات پزشکان بدون محدودیت ارائه می‌شود. تاریخ اعتبار اشتراک‌های فعلی حفظ می‌شود. پزشکان در حالت روشن نیز با نسخه رایگان امکان نوبت‌دهی دارند.</p>

      <div class="form-grid">
        <?php input('دوره فرجه پس از پایان اعتبار (روز)', 'subscription_grace_days', setting('subscription_grace_days', '3'), 'number', true, 2); ?>
        <?php input('روزهای هشدار پیش از پایان (مثلاً ۷,۳,۱)', 'subscription_warning_thresholds', setting('subscription_warning_thresholds', '7,3,1'), 'text', true, 30); ?>
        <?php input('مدت دوره آزمایشی (روز)', 'subscription_trial_days', setting('subscription_trial_days', '14'), 'number', true, 2); ?>
        <?php input('اعتبار پیش‌فاکتور (ساعت)', 'proforma_valid_hours', setting('proforma_valid_hours', '48'), 'number', true, 3); ?>
      </div>
      <label class="check"><input type="checkbox" name="subscription_trial_enabled"<?= checked_attr(setting('subscription_trial_enabled') === '1') ?>> امکان فعال‌سازی دوره آزمایشی رایگان (یک بار برای هر پزشک تأییدشده)</label>
      <p class="muted">در دوره فرجه، امکانات پرو هنوز فعال است. پس از آن پزشک به نسخه رایگان برمی‌گردد: پروفایل و نوبت‌دهی ادامه دارد، هیچ داده‌ای پاک نمی‌شود و نوبت‌های قطعی حفظ می‌شوند؛ فقط امکانات پرو قطع و مطب، خدمت و منشی بیش از حد رایگان تا تمدید «بدون رزرو جدید» می‌شوند.</p>
      <p class="muted small">مالیات مشترک رزرو و اشتراک و اطلاعات فروشنده در <a href="admin.php?tab=settings#revenue">درآمد و مالیات</a> تنظیم می‌شود.</p>
      <button>ذخیره تنظیمات</button>
    </form>
  </section>
<?php endif; ?>
