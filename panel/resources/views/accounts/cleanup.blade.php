<x-layouts.app title="حذف اکانت‌های منقضی">
  <div class="page-head"><h1>حذف گروهی اکانت‌های منقضی</h1><a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a></div>

  @if($state && $state['running'])
    <div class="alert alert-warning">
      <div>حذف گروهی در حال انجام است: {{ fa((int) $state['deleted']) }} حذف شد از {{ fa((int) $state['total']) }}
        — {{ fa((int) $state['skipped']) }} رد شد (تمدید شده یا آنلاین){{ $state['failed'] ? ' — '.fa((int) $state['failed']).' خطا' : '' }}.</div>
      <div class="mt-8">با Cron هر دقیقه ادامه پیدا می‌کند.
        <form method="post" action="{{ route('accounts.cleanup.continue') }}" class="inline">@csrf<button class="btn btn-secondary btn-sm" type="submit">ادامه همین الان</button></form>
        <form method="post" action="{{ route('accounts.cleanup.cancel') }}" class="inline" data-confirm="حذف گروهی متوقف شود؟">@csrf<button class="btn btn-secondary btn-sm" type="submit">توقف</button></form>
      </div>
    </div>
  @elseif($state && isset($state['finished_at']))
    <div class="alert alert-success">آخرین حذف گروهی ({{ jdate(\Carbon\Carbon::parse($state['finished_at'])) }}): {{ fa((int) $state['deleted']) }} حذف، {{ fa((int) $state['skipped']) }} رد، {{ fa((int) $state['failed']) }} خطا.</div>
  @endif

  <div class="card">
    <form method="get" class="form-grid mb-12">
      <div class="field"><label for="days">منقضی شده از بیش از (روز)</label><input class="input ltr" type="number" id="days" name="days" min="{{ \App\Services\Ibs\ExpiredCleanup::MIN_DAYS }}" value="{{ $days }}"></div>
      <div class="actions"><button class="btn btn-secondary" type="submit">نمایش</button></div>
    </form>
    <p>اکانت‌هایی که قبل از <strong>{{ jdate($cutoff, 'Y/m/d') }}</strong> منقضی شده و تمدید نشده‌اند: <strong>{{ fa($count) }}</strong> اکانت.</p>
    <p class="muted small">قبل از حذف هر اکانت، اطلاعاتش دوباره از سرور خوانده می‌شود؛ اگر در این فاصله تمدید شده یا آنلاین باشد حذف نمی‌شود. فقط اکانت‌هایی که در پنل هستند بررسی می‌شوند. حذف از سرور برگشت‌پذیر نیست و هر حذف در گزارش رویدادها ثبت می‌شود.</p>
    @if($sample->isNotEmpty())
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>نام کاربری</th><th>گروه</th><th>تاریخ انقضا</th></tr></thead>
        <tbody>
        @foreach($sample as $a)
          <tr><td data-label="نام کاربری"><a href="{{ route('accounts.show', $a) }}"><bdi>{{ $a->username }}</bdi></a></td><td data-label="گروه"><bdi>{{ $a->group_name }}</bdi></td><td data-label="انقضا">{{ jdate($a->expires_at, 'Y/m/d') }}</td></tr>
        @endforeach
        </tbody>
      </table></div>
      @if($count > $sample->count())<p class="muted small">و {{ fa($count - $sample->count()) }} اکانت دیگر…</p>@endif
    @endif
  </div>

  @if($count > 0 && ! ($state['running'] ?? false))
    <div class="card">
      <h2>حذف همه</h2>
      <form method="post" action="{{ route('accounts.cleanup.start') }}" data-confirm="{{ fa($count) }} اکانت از سرور حذف شود؟ این کار برگشت‌پذیر نیست.">
        @csrf
        <input type="hidden" name="days" value="{{ $days }}">
        <div class="form-grid">
          <div class="field"><label for="confirm_count">برای تأیید، عدد {{ fa($count) }} را وارد کنید</label><input class="input ltr" id="confirm_count" name="confirm_count" inputmode="numeric" required autocomplete="off"></div>
        </div>
        <button class="btn btn-danger" type="submit">حذف {{ fa($count) }} اکانت از سرور</button>
      </form>
    </div>
  @endif
</x-layouts.app>
