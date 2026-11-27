<x-layouts.app title="اعلان‌ها">
  <div class="page-head">
    <div><h1>اعلان‌ها و گزارش‌ها</h1><p class="muted">از طریق ربات‌ها ارسال می‌شوند؛ هر ۱۰ دقیقه با Cron بررسی می‌شود. {{ fa($customers) }} مشتری اکانت خود را به ربات وصل کرده‌اند.</p></div>
    <form method="post" action="{{ route('alerts.test') }}">@csrf<button class="btn btn-secondary" type="submit">ارسال گزارش روزانه آزمایشی به من</button></form>
  </div>
  @unless($linked)
    <div class="alert alert-warning">برای دریافت گزارش‌ها و هشدارها، از <a href="{{ route('bot-link') }}">اتصال به ربات</a> حساب خود را به ربات وصل کنید.</div>
  @endunless
  @can('settings.update')
  <form method="post" action="{{ route('alerts.settings.update') }}">
    @csrf @method('PUT')
    <div class="card">
      <h2>مشتریان (اکانت‌های متصل به ربات)</h2>
      <label class="check"><input type="checkbox" name="expiry_enabled" value="1" @checked($v['expiry_enabled'] === '1')> یادآوری نزدیک شدن انقضا</label>
      <div class="form-grid">
        <div class="field"><label for="expiry_days">چند روز مانده به انقضا (با کاما)</label><input class="input ltr" id="expiry_days" name="expiry_days" value="{{ old('expiry_days', $v['expiry_days']) }}"><div class="help">مثلاً 3,1 — برای هر عدد یک بار.</div></div>
      </div>
      <label class="check"><input type="checkbox" name="expired_enabled" value="1" @checked($v['expired_enabled'] === '1')> اعلام منقضی شدن اکانت</label>
      <label class="check"><input type="checkbox" name="volume_enabled" value="1" @checked($v['volume_enabled'] === '1')> هشدار حجم کم و اتمام حجم</label>
      <div class="form-grid">
        <div class="field"><label for="volume_pct">وقتی حجم باقی‌مانده کمتر از (٪)</label><input class="input ltr" type="number" id="volume_pct" name="volume_pct" min="1" max="90" value="{{ old('volume_pct', $v['volume_pct']) }}"></div>
        <div class="field"><label for="volume_mb">یا کمتر از (مگابایت)</label><input class="input ltr" type="number" id="volume_mb" name="volume_mb" min="0" value="{{ old('volume_mb', $v['volume_mb']) }}"></div>
      </div>
    </div>
    <div class="card">
      <h2>همکاران</h2>
      <label class="check"><input type="checkbox" name="reseller_digest_enabled" value="1" @checked($v['reseller_digest_enabled'] === '1')> خلاصه روزانه (اکانت‌های در آستانه انقضا، منقضی‌شده‌ها، موجودی)</label>
      <div class="form-grid">
        <div class="field"><label for="reseller_low_balance_toman">هشدار موجودی کم زیر (تومان؛ ۰ = خاموش)</label><input class="input ltr" data-money inputmode="numeric" id="reseller_low_balance_toman" name="reseller_low_balance_toman" min="0" value="{{ old('reseller_low_balance_toman', $v['reseller_low_balance_toman']) }}"></div>
      </div>
    </div>
    <div class="card">
      <h2>مدیران</h2>
      <label class="check"><input type="checkbox" name="admin_report_enabled" value="1" @checked($v['admin_report_enabled'] === '1')> گزارش روزانه (فروش، پرداخت‌ها، اکانت‌های جدید، موارد نیازمند رسیدگی)</label>
      <div class="form-grid">
        <div class="field"><label for="report_hour">ساعت ارسال گزارش‌های روزانه (به وقت ایران)</label>
          <select class="input" id="report_hour" name="report_hour">@for($h = 0; $h < 24; $h++)<option value="{{ $h }}" @selected((int) old('report_hour', $v['report_hour']) === $h)>{{ fa(str_pad((string) $h, 2, '0', STR_PAD_LEFT)) }}:۰۰</option>@endfor</select></div>
      </div>
      <label class="check"><input type="checkbox" name="server_enabled" value="1" @checked($v['server_enabled'] === '1')> هشدار قطع و وصل شدن ارتباط با سرور</label>
      <label class="check"><input type="checkbox" name="topup_enabled" value="1" @checked(($v['topup_enabled'] ?? '1') === '1')> اعلان درخواست شارژ حساب همکاران و واریزهای کارت‌به‌کارت (در پنل و ربات)</label>
    </div>
    <button class="btn" type="submit">ذخیره</button>
  </form>
  @endcan
</x-layouts.app>
