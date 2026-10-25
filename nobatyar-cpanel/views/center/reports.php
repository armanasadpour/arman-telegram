<?php /** @var array $c @var array $s @var array $f @var array $report @var array $doctors @var array $branches */
$t = $report['totals'];
$query = ['center' => (int) $c['id'], 'tab' => 'reports', 'from' => jdate($f['from']), 'to' => jdate($f['to']), 'branch' => $f['branch'], 'doctor' => $f['doctor']];
$table = function (array $rows, string $label): void { ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= $label ?></th><th>کل</th><th>ویزیت‌شده</th><th>عدم مراجعه</th><th>لغو</th><th>آینده</th><th>بدون ثبت وضعیت</th><th>آنلاین / پذیرش</th><th>نرخ عدم مراجعه</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= esc($r['name'] ?? jdate($r['day'] ?? '')) ?></td><td><?= fa_number($r['total']) ?></td><td><?= fa_number($r['completed']) ?></td><td><?= fa_number($r['no_show']) ?></td><td><?= fa_number($r['cancelled']) ?></td><td><?= fa_number($r['upcoming']) ?></td><td><?= fa_number($r['unresolved']) ?></td><td><?= fa_number($r['online']) ?> / <?= fa_number($r['staff']) ?></td>
          <td><span class="rate-bar" style="--rate:<?= (float) $r['no_show_rate'] ?>%"><?= fa_number($r['no_show_rate']) ?>٪</span></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!$rows): ?><p class="empty-state">داده‌ای در این بازه نیست.</p><?php endif; ?>
<?php };
?>
<form method="get" class="filter-bar panel">
  <input type="hidden" name="center" value="<?= (int) $c['id'] ?>"><input type="hidden" name="tab" value="reports">
  <label>از تاریخ<input data-jdate name="from" value="<?= esc(jdate($f['from'])) ?>" dir="ltr"></label>
  <label>تا تاریخ<input data-jdate name="to" value="<?= esc(jdate($f['to'])) ?>" dir="ltr"></label>
  <?php if (!$s['branch_id']): ?>
    <label>شعبه<select name="branch"><option value="0">همه</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected_attr($f['branch'] === (int) $b['id']) ?>><?= esc($b['name']) ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <label>پزشک<select name="doctor"><option value="0">همه</option><?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected_attr($f['doctor'] === (int) $d['id']) ?>><?= esc(doctor_display_name((string) $d['name'])) ?></option><?php endforeach; ?></select></label>
  <button><?= icon('filter') ?> نمایش</button>
  <a class="button secondary" href="center.php?<?= esc(http_build_query($query + ['export' => 'xlsx'])) ?>"><?= icon('download') ?> خروجی اکسل</a>
</form>
<div class="stat-grid">
  <article class="stat-card"><span>کل نوبت‌ها</span><strong><?= fa_number($t['total']) ?></strong></article>
  <article class="stat-card"><span>ویزیت‌شده</span><strong><?= fa_number($t['completed']) ?></strong></article>
  <article class="stat-card"><span>عدم مراجعه</span><strong><?= fa_number($t['no_show']) ?></strong></article>
  <article class="stat-card"><span>نرخ عدم مراجعه</span><strong><?= fa_number($t['no_show_rate']) ?>٪</strong></article>
</div>
<section class="panel"><h2>عملکرد هر پزشک</h2><?php $table($report['by_doctor'], 'پزشک'); ?></section>
<section class="panel"><h2>عملکرد هر شعبه</h2><?php $table($report['by_branch'], 'شعبه'); ?></section>
<section class="panel"><h2>روزبه‌روز</h2><?php $table($report['by_day'], 'روز'); ?></section>
<p class="muted">نرخ عدم مراجعه = عدم مراجعه ÷ (ویزیت‌شده + عدم مراجعه). نوبت‌های گذشته‌ای که وضعیتشان ثبت نشده در «بدون ثبت وضعیت» آمده‌اند. فقط پزشکانی که اجازه گزارش یا مدیریت نوبت داده‌اند در آمار حساب می‌شوند.</p>
