<x-layouts.app title="ویرایش گروه">
  <div class="page-head">
    <h1>ویرایش گروه <bdi class="ltr">{{ $g->name }}</bdi></h1>
    <a class="btn btn-secondary" href="{{ route('ibs.groups') }}">بازگشت</a>
  </div>
  <div class="card">
    @if($accounts > 0)
      <div class="alert alert-warning">این گروه {{ fa($accounts) }} اکانت در پنل دارد. تغییر تنظیمات گروه روی همه اکانت‌هایی که مقدار اختصاصی ندارند اثر می‌گذارد.</div>
    @endif
    <form method="post" action="{{ route('ibs.groups.update', $g->name) }}" data-confirm="تنظیمات این گروه در سرور تغییر کند؟">
      @csrf @method('PUT')
      <div class="form-grid">
      @include('ibs.partials.group-fields', ['g' => $g])
      </div>
      <button class="btn" type="submit">ذخیره در سرور</button>
    </form>
  </div>
</x-layouts.app>
