<x-layouts.app title="کاربران پنل">
  <div class="page-head">
    <h1>کاربران پنل</h1>
    @can('staff.create')
      <a class="btn" href="{{ route('staff.create') }}">کاربر جدید</a>
    @endcan
  </div>
  <div class="card">
    <form method="get" class="mb-12 actions">
      <input name="q" value="{{ $q }}" class="w-280 input" placeholder="جستجو در نام یا نام کاربری" aria-label="جستجو">
      <button class="btn btn-secondary" type="submit">جستجو</button>
      @if($q !== '')<a class="btn btn-secondary" href="{{ route('staff.index') }}">پاک کردن</a>@endif
    </form>
    @if($users->isEmpty())
      <div class="empty"><x-icon name="users"/><div>{{ $q !== '' ? 'نتیجه‌ای یافت نشد.' : 'هنوز کاربری ثبت نشده است.' }}</div></div>
    @else
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش‌ها</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
          <tbody>
          @foreach($users as $u)
            <tr>
              <td data-label="نام">{{ $u->name }} @if($u->is(auth()->user()))<span class="badge badge-primary">شما</span>@endif</td>
              <td data-label="نام کاربری"><bdi class="ltr">{{ $u->username }}</bdi></td>
              <td data-label="نقش‌ها">
                @forelse($u->roles as $r)<span class="badge">{{ $r->title }}</span> @empty <span class="muted">بدون نقش</span> @endforelse
              </td>
              <td data-label="وضعیت">
                @if($u->is_active)<span class="badge badge-success">فعال</span>@else<span class="badge badge-danger">غیرفعال</span>@endif
              </td>
              <td data-label="آخرین ورود">{{ $u->last_login_at ? fa_ago($u->last_login_at) : 'هرگز' }}</td>
              <td>
                @can('staff.update')
                  @if(! $u->isSuperAdmin() || auth()->user()->isSuperAdmin())
                    <a class="btn btn-secondary btn-sm" href="{{ route('staff.edit', $u) }}">مدیریت</a>
                  @endif
                @endcan
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      {{ $users->links() }}
    @endif
  </div>
</x-layouts.app>
