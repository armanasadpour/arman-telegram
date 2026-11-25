@php($editing = $d->exists)
<x-layouts.app :title="$editing ? 'ویرایش تخفیف' : 'تخفیف جدید'">
  <div class="page-head">
    <h1>{{ $editing ? 'ویرایش تخفیف' : 'تخفیف جدید' }}</h1>
    <a class="btn btn-secondary" href="{{ route('discounts.index') }}">بازگشت</a>
  </div>
  <div class="card w-640">
    <form method="post" action="{{ $editing ? route('discounts.update', $d) : route('discounts.store') }}">
      @csrf @if($editing) @method('put') @endif
      <div class="form-grid">
        <div class="field"><label for="name">نام</label><input id="name" name="name" class="input" value="{{ old('name', $d->name) }}" required maxlength="120" placeholder="مثلاً جشنواره یلدا"></div>
        <div class="field"><label for="scope">دامنه</label>
          <select id="scope" name="scope" class="input">@foreach(\App\Models\Discount::SCOPES as $k => $v)<option value="{{ $k }}" @selected(old('scope', $d->scope) === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="field"><label for="category_id">دسته (اگر دامنه «یک دسته» است)</label>
          <select id="category_id" name="category_id" class="input"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected((int) old('category_id', $d->category_id) === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label for="package_id">بسته (اگر دامنه «مخصوص یک بسته» است)</label>
          <select id="package_id" name="package_id" class="input"><option value="">—</option>@foreach($packages as $p)<option value="{{ $p->id }}" @selected((int) old('package_id', $d->package_id) === $p->id)>{{ $p->name }} ({{ $p->typeLabel() }})</option>@endforeach</select></div>
        <div class="field"><label for="kind">نوع</label>
          <select id="kind" name="kind" class="input"><option value="percent" @selected(old('kind', $d->kind) === 'percent')>درصدی</option><option value="amount" @selected(old('kind', $d->kind) === 'amount')>مبلغ ثابت (تومان)</option></select></div>
        <div class="field"><label for="value">مقدار</label><input id="value" name="value" type="number" min="1" class="input ltr" value="{{ old('value', $d->exists ? ($d->kind === 'amount' ? intdiv($d->value, 10) : $d->value) : '') }}" required><div class="help">درصد (۱ تا ۱۰۰) یا مبلغ به تومان.</div></div>
        <div class="field"><label for="priority">اولویت (−۱۰۰ تا ۱۰۰)</label><input id="priority" name="priority" type="number" class="input ltr" value="{{ old('priority', $d->priority) }}"><div class="help">فقط بین تخفیف‌های هم‌سطح تعیین‌کننده است.</div></div>
        <div></div>
        <div class="field"><label for="starts_at">از تاریخ (شمسی، اختیاری)</label><input id="starts_at" name="starts_at" class="input ltr" data-jdate readonly value="{{ old('starts_at', $d->starts_at ? jdate($d->starts_at, 'Y/m/d') : '') }}" placeholder="۱۴۰۵/۰۹/۱۰"><div class="help">از ابتدای این روز.</div></div>
        <div class="field"><label for="ends_at">تا تاریخ (شمسی، اختیاری)</label><input id="ends_at" name="ends_at" class="input ltr" data-jdate readonly value="{{ old('ends_at', $d->ends_at ? jdate($d->ends_at->subSecond(), 'Y/m/d') : '') }}" placeholder="۱۴۰۵/۰۹/۳۰"><div class="help">تا پایان این روز.</div></div>
      </div>
      <label class="check mb-16"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $d->is_active))> فعال</label>
      <button class="btn" type="submit">{{ $editing ? 'ذخیره' : 'ایجاد تخفیف' }}</button>
    </form>
  </div>
</x-layouts.app>
