@php($editing = $user->exists)
<x-layouts.app :title="$editing ? 'مدیریت کاربر' : 'کاربر جدید'">
  <div class="page-head">
    <div>
      <h1>{{ $editing ? 'مدیریت کاربر «'.$user->name.'»' : 'کاربر جدید' }}</h1>
      @if($editing)
        <p class="muted">نام کاربری: <bdi class="ltr">{{ $user->username }}</bdi> — ایجاد: {{ jdate($user->created_at, 'Y/m/d') }}
          — آخرین ورود: {{ $user->last_login_at ? jdate($user->last_login_at).' از '.$user->last_login_ip : 'هرگز' }}</p>
      @endif
    </div>
    <a class="btn btn-secondary" href="{{ route('staff.index') }}">بازگشت</a>
  </div>

  <div class="card">
    <h2>اطلاعات و نقش‌ها</h2>
    <form method="post" action="{{ $editing ? route('staff.update', $user) : route('staff.store') }}">
      @csrf
      @if($editing) @method('put') @endif
      <div class="form-grid">
        <div class="field">
          <label for="name">نام و نام خانوادگی</label>
          <input id="name" name="name" class="input @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required maxlength="120">
        </div>
        @unless($editing)
          <div class="field">
            <label for="username">نام کاربری</label>
            <input id="username" name="username" class="input ltr @error('username') is-invalid @enderror" value="{{ old('username') }}" required maxlength="60" autocapitalize="none" spellcheck="false">
            <div class="help">حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط. بعداً قابل تغییر نیست.</div>
          </div>
        @endunless
        <div class="field">
          <label for="email">ایمیل (اختیاری)</label>
          <input id="email" name="email" type="email" class="input ltr @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" maxlength="190">
        </div>
        <div class="field">
          <label for="mobile">موبایل (اختیاری)</label>
          <input id="mobile" name="mobile" class="input ltr @error('mobile') is-invalid @enderror" value="{{ old('mobile', $user->mobile) }}" maxlength="20" inputmode="tel">
        </div>
        @unless($editing)
          <div class="field">
            <label for="password">رمز عبور</label>
            <input id="password" name="password" type="password" class="input ltr @error('password') is-invalid @enderror" autocomplete="new-password" required>
            <div class="help">حداقل ۴ کاراکتر.</div>
          </div>
          <div class="field">
            <label for="password_confirmation">تکرار رمز عبور</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="input ltr" autocomplete="new-password" required>
          </div>
        @endunless
      </div>

      <fieldset class="fieldset field">
        <legend class="legend">نقش‌ها</legend>
        @php($current = old('roles', $user->exists ? $user->roles->pluck('id')->all() : []))
        @forelse($roles as $role)
          <label class="check">
            <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, array_map('intval', $current)))>
            <span>{{ $role->title }} @if($role->is_system)<span class="badge badge-primary">سیستمی</span>@endif
              @if($role->description)<span class="muted"> — {{ $role->description }}</span>@endif</span>
          </label>
        @empty
          <p class="muted">هنوز نقشی تعریف نشده است. @can('roles.create')<a href="{{ route('roles.create') }}">ایجاد نقش</a>@endcan</p>
        @endforelse
      </fieldset>
      <button type="submit" class="btn">{{ $editing ? 'ذخیره تغییرات' : 'ایجاد کاربر' }}</button>
    </form>
  </div>

  @if($editing && ! $user->is(auth()->user()))
    @can('staff.sensitive')
      <div class="grid two">
        <div class="card">
          <h2>{{ $user->is_active ? 'غیرفعال‌سازی حساب' : 'فعال‌سازی حساب' }}</h2>
          <p class="muted">{{ $user->is_active ? 'کاربر بلافاصله از همه نشست‌ها خارج می‌شود و نمی‌تواند وارد شود.' : 'کاربر دوباره می‌تواند وارد شود.' }}</p>
          <form method="post" action="{{ route('staff.toggle', $user) }}" data-confirm="{{ $user->is_active ? 'حساب غیرفعال شود؟' : 'حساب فعال شود؟' }}">
            @csrf
            <div class="field">
              <label for="reason_toggle">دلیل (در گزارش رویدادها ثبت می‌شود)</label>
              <input id="reason_toggle" name="reason" class="input" required minlength="3" maxlength="500">
            </div>
            <button type="submit" class="btn {{ $user->is_active ? 'btn-danger' : '' }}">{{ $user->is_active ? 'غیرفعال کن' : 'فعال کن' }}</button>
          </form>
        </div>
        <div class="card">
          <h2>تعیین رمز جدید</h2>
          <p class="muted">کاربر از همه نشست‌ها خارج می‌شود.</p>
          <form method="post" action="{{ route('staff.reset-password', $user) }}" data-confirm="رمز این کاربر تغییر کند؟">
            @csrf
            <div class="field">
              <label for="np">رمز جدید</label>
              <input id="np" name="password" type="password" class="input ltr" autocomplete="new-password" required>
            </div>
            <div class="field">
              <label for="npc">تکرار رمز جدید</label>
              <input id="npc" name="password_confirmation" type="password" class="input ltr" autocomplete="new-password" required>
            </div>
            <div class="field">
              <label for="reason_pw">دلیل</label>
              <input id="reason_pw" name="reason" class="input" required minlength="3" maxlength="500">
            </div>
            <button type="submit" class="btn">ثبت رمز جدید</button>
          </form>
        </div>
      </div>
    @endcan
    @can('staff.delete')
      <div class="card-danger card">
        <h2 class="text-danger">حذف کاربر</h2>
        <p class="muted">حساب حذف می‌شود؛ سوابق او در گزارش رویدادها با نام کاربری‌اش باقی می‌ماند. این کار برگشت‌پذیر نیست.</p>
        <form method="post" action="{{ route('staff.destroy', $user) }}" data-confirm="کاربر «{{ $user->username }}» برای همیشه حذف شود؟">
          @csrf @method('delete')
          <div class="field">
            <label for="reason_del">دلیل</label>
            <input id="reason_del" name="reason" class="input" required minlength="3" maxlength="500">
          </div>
          <button type="submit" class="btn btn-danger">حذف کاربر</button>
        </form>
      </div>
    @endcan
  @endif
</x-layouts.app>
