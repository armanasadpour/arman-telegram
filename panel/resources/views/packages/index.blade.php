<x-layouts.app title="بسته‌ها">
  <div class="page-head">
    <div>
      <h1>بسته‌ها</h1>
      <p class="muted">قیمت‌ها در پنل نگهداری می‌شوند؛ هر بسته اکانت جدید به یک گروه واقعی سرور وصل است. «قیمت امروز» با تخفیف فعال محاسبه شده است.</p>
    </div>
    @can('packages.create')<a class="btn" href="{{ route('packages.create') }}">بسته جدید</a>@endcan
  </div>

  <div class="actions mb-16">
    <a href="{{ route('packages.index') }}" @class(['btn btn-sm', 'btn-secondary' => $type])>همه</a>
    @foreach(\App\Models\Package::TYPES as $k => $v)
      <a href="{{ route('packages.index', ['type' => $k]) }}" @class(['btn btn-sm', 'btn-secondary' => $type !== $k])>{{ $v }}</a>
    @endforeach
  </div>

  <div class="card">
    @if($packages->isEmpty())
      <div class="empty"><x-icon name="box"/><div>هنوز بسته‌ای تعریف نشده است.</div></div>
    @else
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>بسته</th><th>نوع</th><th>مشخصات</th><th>پروتکل / گروه</th><th>قیمت امروز</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          @foreach($packages as $p)
            <tr>
              <td data-label="بسته">{{ $p->name }} @if($p->category)<div class="muted small">{{ $p->category->name }}</div>@endif</td>
              <td data-label="نوع">{{ $p->typeLabel() }} <span class="badge">{{ $p->kindLabel() }}</span></td>
              <td data-label="مشخصات">{{ $p->summary() }}</td>
              <td data-label="پروتکل / گروه">
                <span @class(['badge', 'badge-primary' => $p->isMikrotik()])>{{ $p->protocolLabel() }}</span>
                @if($p->isMikrotik())
                  @if($p->type === 'new')<div class="muted small">{{ $p->mtGroup?->name ?? 'گروه حذف شده' }} ({{ $p->mtGroup?->router?->name }})</div>@endif
                @elseif($p->type === 'new')
                  <bdi>{{ $p->ibs_group_name }}</bdi>
                  @if(! $p->group || ! $p->group->present)<span class="badge badge-danger">در سرور نیست</span>@endif
                @else — @endif
              </td>
              <td data-label="قیمت امروز">@include('packages._price', ['q' => $quotes[$p->id]])</td>
              <td data-label="وضعیت">@if($p->is_active)<span class="badge badge-success">فعال</span>@else<span class="badge">غیرفعال</span>@endif</td>
              <td>@can('packages.update')<a class="btn btn-secondary btn-sm" href="{{ route('packages.edit', $p) }}">ویرایش</a>@endcan</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <div class="card">
    <h2>دسته‌ها</h2>
    @if($categories->isEmpty())
      <p class="muted">دسته‌ای تعریف نشده است. دسته برای گروه‌بندی بسته‌ها در ربات و تخفیف دسته‌ای استفاده می‌شود.</p>
    @else
      <div class="actions mb-12">
        @foreach($categories as $c)
          <span class="badge">{{ $c->name }} ({{ fa($c->packages_count) }})</span>
          @can('packages.delete')
            @if($c->packages_count === 0)
              <form method="post" action="{{ route('packages.categories.destroy', $c) }}" data-confirm="دسته «{{ $c->name }}» حذف شود؟">@csrf @method('delete')<button class="btn-link small" type="submit">حذف</button></form>
            @endif
          @endcan
        @endforeach
      </div>
    @endif
    @can('packages.create')
      <form method="post" action="{{ route('packages.categories.store') }}" class="actions">
        @csrf
        <input name="name" class="input w-280" placeholder="نام دسته جدید، مثلاً «ماهانه»" required maxlength="100" aria-label="نام دسته">
        <button class="btn btn-secondary" type="submit">افزودن دسته</button>
      </form>
    @endcan
  </div>
</x-layouts.app>
