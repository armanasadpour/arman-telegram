<x-layouts.app title="تخفیف‌ها">
  <div class="page-head">
    <div>
      <h1>تخفیف‌ها</h1>
      <p class="muted">روی هر قیمت فقط یک تخفیف اعمال می‌شود (هرگز جمع نمی‌شوند). اولویت: مخصوص بسته ← دسته ← همه بسته‌ها؛ در یک سطح، «اولویت» بالاتر و سپس تخفیف بزرگ‌تر.</p>
    </div>
    @can('discounts.create')<a class="btn" href="{{ route('discounts.create') }}">تخفیف جدید</a>@endcan
  </div>
  <div class="card">
    @if($discounts->isEmpty())
      <div class="empty"><x-icon name="tag"/><div>تخفیفی تعریف نشده است.</div></div>
    @else
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>نام</th><th>دامنه</th><th>مقدار</th><th>اولویت</th><th>بازه</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          @foreach($discounts as $d)
            <tr>
              <td data-label="نام">{{ $d->name }}</td>
              <td data-label="دامنه">{{ \App\Models\Discount::SCOPES[$d->scope] }}
                @if($d->scope === 'category')<div class="muted small">{{ $d->category?->name }}</div>@endif
                @if($d->scope === 'package')<div class="muted small">{{ $d->package?->name }}</div>@endif</td>
              <td data-label="مقدار">{{ $d->describe() }}</td>
              <td data-label="اولویت">{{ fa($d->priority) }}</td>
              <td data-label="بازه">{{ $d->starts_at ? 'از '.jdate($d->starts_at, 'Y/m/d') : 'از همیشه' }} — {{ $d->ends_at ? 'تا '.jdate($d->ends_at->subSecond(), 'Y/m/d') : 'بدون پایان' }}</td>
              <td data-label="وضعیت">
                @if(! $d->is_active)<span class="badge">غیرفعال</span>
                @elseif($d->isLiveAt(now()))<span class="badge badge-success">در حال اجرا</span>
                @elseif($d->starts_at && $d->starts_at->isFuture())<span class="badge badge-primary">آینده</span>
                @else<span class="badge badge-warning">پایان‌یافته</span>@endif
              </td>
              <td>
                <div class="actions">
                  @can('discounts.update')<a class="btn btn-secondary btn-sm" href="{{ route('discounts.edit', $d) }}">ویرایش</a>@endcan
                  @can('discounts.delete')
                    <form method="post" action="{{ route('discounts.destroy', $d) }}" data-confirm="تخفیف حذف شود؟">@csrf @method('delete')<button class="btn btn-danger btn-sm" type="submit">حذف</button></form>
                  @endcan
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</x-layouts.app>
