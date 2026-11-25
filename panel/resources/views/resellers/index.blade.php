<x-layouts.app title="همکاران فروش">
  <div class="page-head">
    <div><h1>همکاران فروش</h1><p class="muted">هر همکار پنل و کیف پول اختصاصی دارد و فقط داده‌های خودش را می‌بیند.</p></div>
    @can('resellers.create')<a class="btn" href="{{ route('resellers.create') }}">همکار جدید</a>@endcan
  </div>
  @if($review)<div class="alert alert-warning">{{ fa($review) }} فروش همکار نیازمند رسیدگی است (وضعیت سرور نامشخص ماند).</div>@endif
  <div class="card">
    @if($resellers->isEmpty())
      <div class="empty"><x-icon name="users"/><div>هنوز همکاری ثبت نشده است.</div></div>
    @else
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>همکار</th><th>کد</th><th>موجودی</th><th>فروش موفق</th><th>سود همکار</th><th>تخفیف سراسری</th><th>وضعیت</th></tr></thead>
        <tbody>
        @foreach($resellers as $r)
          <tr>
            <td data-label="همکار"><a href="{{ route('resellers.show', $r) }}">{{ $r->name }}</a> <div class="muted small"><bdi class="ltr">{{ $r->user->username }}</bdi></div></td>
            <td data-label="کد"><bdi class="ltr">{{ $r->code }}</bdi></td>
            <td data-label="موجودی">{{ fa_money($r->wallet?->balance ?? 0) }}</td>
            <td data-label="فروش موفق">{{ fa($r->sales_ok) }}</td>
            <td data-label="سود همکار">{{ fa_money((int) $r->profit) }}</td>
            <td data-label="تخفیف سراسری">{{ fa((string) (float) $r->global_discount_percent) }}٪</td>
            <td data-label="وضعیت">@if($r->isActive())<span class="badge badge-success">فعال</span>@else<span class="badge badge-warning">تعلیق</span>@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    @endif
  </div>
</x-layouts.app>
