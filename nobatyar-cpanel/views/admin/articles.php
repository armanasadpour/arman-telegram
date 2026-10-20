<?php /** @var ?array $edit @var array $rows @var array $specialties */
$e = $post && ($post['action'] ?? '') === 'article_save' ? $post + ($edit ?? []) : $edit;
?>
<?php if ($e !== null): ?>
<p><a href="admin.php?tab=articles">← همه مقالات</a></p>
<form method="post" enctype="multipart/form-data" class="article-editor">
  <?php form_token('article_save'); ?>
  <input type="hidden" name="id" value="<?= esc($e['id'] ?? '') ?>">
  <section class="panel">
    <h2><?= !empty($e['id']) ? 'ویرایش مقاله' : 'مقاله جدید' ?></h2>
    <label>عنوان<input name="title" value="<?= esc($e['title'] ?? '') ?>" maxlength="190" required></label>
    <label>نامک (بخش انتهایی نشانی؛ خالی = از روی عنوان)<input name="slug" value="<?= esc($e['slug'] ?? '') ?>" maxlength="120" dir="auto"></label>
    <label>خلاصه (در کارت‌ها و نتایج جستجو)<textarea name="summary" maxlength="400" rows="3"><?= esc($e['summary'] ?? '') ?></textarea></label>
    <label>متن<textarea name="body" class="tall" required><?= esc($e['body'] ?? '') ?></textarea></label>
    <p class="muted small">قالب‌بندی ساده: خط خالی بین پاراگراف‌ها، «## » در ابتدای خط برای عنوان و «- » برای فهرست. HTML پذیرفته نمی‌شود.</p>
  </section>
  <section class="panel">
    <div class="form-grid">
      <label>تخصص مرتبط (اختیاری)<select name="specialty_id"><option value="">بدون تخصص</option><?php foreach ($specialties as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected_attr((int) ($e['specialty_id'] ?? 0) === (int) $s['id']) ?>><?= esc($s['name']) ?></option><?php endforeach; ?></select></label>
      <label>وضعیت<select name="status"><option value="draft"<?= selected_attr(($e['status'] ?? 'draft') === 'draft') ?>>پیش‌نویس</option><option value="published"<?= selected_attr(($e['status'] ?? '') === 'published') ?>>منتشرشده</option></select></label>
      <?php $pub = $e['published_at'] ?? ''; ?>
      <label>تاریخ انتشار (اختیاری؛ برای انتشار زمان‌بندی‌شده)<input data-jdate name="published_at" value="<?= esc($pub && preg_match('/^\d{4}-/', (string) $pub) ? jdate($pub) : (string) $pub) ?>" dir="ltr" maxlength="10" placeholder="۱۴۰۵/۰۸/۰۱"></label>
    </div>
    <div class="image-field">
      <?php if (!empty($e['cover_file'])): ?><img src="media.php?kind=article&amp;file=<?= esc($e['cover_file']) ?>" alt="" width="240" height="126"><?php endif; ?>
      <div>
        <label>تصویر شاخص (برش به ۱۲۰۰×۶۳۰؛ حداکثر ۲ مگابایت)<input type="file" name="cover" accept="image/png,image/jpeg,image/webp"></label>
        <?php if (!empty($e['cover_file'])): ?><label class="check"><input type="checkbox" name="remove_cover" value="1"> حذف تصویر</label><?php endif; ?>
      </div>
    </div>
    <div class="row-actions">
      <button><?= icon('check') ?> ذخیره</button>
      <?php if (!empty($e['slug'])): ?><a class="button secondary" href="<?= esc(article_url($e['slug'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> پیش‌نمایش</a><?php endif; ?>
    </div>
  </section>
</form>
<?php if (!empty($e['id'])): ?>
  <form method="post" class="inline-form" data-confirm="این مقاله حذف شود؟"><?php form_token('article_delete'); ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="danger small">حذف مقاله</button></form>
<?php endif; ?>
<?php else: ?>
<section class="panel">
  <div class="panel-head"><h2>مقالات سلامت</h2><a class="button" href="admin.php?tab=articles&amp;edit=0"><?= icon('plus') ?> مقاله جدید</a></div>
  <?php if (!$rows): ?>
    <p class="empty-state">هنوز مقاله‌ای نوشته نشده است. تا انتشار اولین مقاله، بخش مقالات در صفحه اصلی نمایش داده نمی‌شود.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>عنوان</th><th>تخصص</th><th>وضعیت</th><th>انتشار</th><th>بازدید</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): $live = $a['status'] === 'published' && $a['published_at'] && strtotime($a['published_at']) <= time(); ?>
        <tr>
          <td><a href="admin.php?tab=articles&amp;edit=<?= (int) $a['id'] ?>"><?= esc($a['title']) ?></a></td>
          <td><?= esc($a['specialty'] ?? '—') ?></td>
          <td><span class="badge <?= $live ? 'status-confirmed' : 'status-held' ?>"><?= $a['status'] === 'draft' ? 'پیش‌نویس' : ($live ? 'منتشرشده' : 'زمان‌بندی‌شده') ?></span></td>
          <td><bdi><?= esc(jdate($a['published_at'])) ?></bdi></td>
          <td><?= fa_number((int) $a['views']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
