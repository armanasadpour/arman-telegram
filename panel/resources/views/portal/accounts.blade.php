<x-layouts.app title="اکانت‌های من">
  <div class="page-head"><h1>اکانت‌های من</h1></div>
  <div class="card">
    <form method="get" class="actions mb-12"><input name="q" value="{{ $q }}" class="input w-280" placeholder="جستجوی نام کاربری" aria-label="جستجو"><button class="btn btn-secondary" type="submit">جستجو</button></form>
    @if($accounts->isEmpty())<div class="empty"><div>{{ $q !== '' ? 'نتیجه‌ای یافت نشد.' : 'هنوز اکانتی نفروخته‌اید.' }}</div></div>@else
    <div class="table-wrap"><table class="table stack">
      <thead><tr><th>نام کاربری</th><th>وضعیت</th><th>انقضا</th><th>حجم باقی‌مانده</th></tr></thead>
      <tbody>
      @foreach($accounts as $a)
        <tr><td data-label="نام کاربری"><a href="{{ route('portal.accounts.show', $a) }}"><bdi class="ltr">{{ $a->username }}</bdi></a></td><td data-label="وضعیت">@include('accounts._status', ['a' => $a])</td>
          <td data-label="انقضا">{{ $a->expires_at ? jdate($a->expires_at, 'Y/m/d').' ('.fa_ago($a->expires_at).')' : 'پس از اولین اتصال' }}</td>
          <td data-label="حجم">{{ $a->isVolumeLimited() ? fa_bytes($a->remainingBytes()) : 'بدون محدودیت' }}</td></tr>
      @endforeach
      </tbody>
    </table></div>
    {{ $accounts->links() }}
    @endif
  </div>
</x-layouts.app>
