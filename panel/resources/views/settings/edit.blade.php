<x-layouts.app title="تنظیمات">
  <div class="page-head"><h1>تنظیمات عمومی</h1></div>
  <div class="w-640 card">
    <form method="post" action="{{ route('settings.update') }}">
      @csrf @method('put')
      @php($f = fn ($k) => str_replace('.', '__', $k))
      <div class="field">
        <label for="site">{{ $fields['general.site_name'][0] }}</label>
        <input id="site" name="{{ $f('general.site_name') }}" class="input" value="{{ old($f('general.site_name'), $values['general.site_name']) }}" required maxlength="80" @cannot('settings.update') disabled @endcannot>
      </div>
      <div class="field">
        <label for="support">{{ $fields['general.support_contact'][0] }}</label>
        <input id="support" name="{{ $f('general.support_contact') }}" class="input" value="{{ old($f('general.support_contact'), $values['general.support_contact']) }}" maxlength="200" @cannot('settings.update') disabled @endcannot>
        <div class="help">در فازهای بعد در ربات و پیام‌ها نمایش داده می‌شود.</div>
      </div>
      <div class="field">
        <label for="tz">{{ $fields['general.display_timezone'][0] }}</label>
        <select id="tz" name="{{ $f('general.display_timezone') }}" class="input" @cannot('settings.update') disabled @endcannot>
          @foreach($timezones as $tz)
            <option value="{{ $tz }}" @selected(old($f('general.display_timezone'), $values['general.display_timezone']) === $tz)>{{ $tz }}</option>
          @endforeach
        </select>
        <div class="help">همه زمان‌ها در دیتابیس به UTC ذخیره و با این منطقه زمانی نمایش داده می‌شوند.</div>
      </div>
      <div class="field">
        <label for="cur">{{ $fields['pricing.display_currency'][0] }}</label>
        <select id="cur" name="{{ $f('pricing.display_currency') }}" class="input" @cannot('settings.update') disabled @endcannot>
          <option value="toman" @selected(old($f('pricing.display_currency'), $values['pricing.display_currency']) === 'toman')>تومان</option>
          <option value="rial" @selected(old($f('pricing.display_currency'), $values['pricing.display_currency']) === 'rial')>ریال</option>
        </select>
        <div class="help">قیمت‌ها همیشه به ریال ذخیره می‌شوند؛ این گزینه فقط نمایش را تغییر می‌دهد.</div>
      </div>
      <div class="field">
        <label for="round">{{ $fields['pricing.rounding_rial'][0] }}</label>
        <select id="round" name="{{ $f('pricing.rounding_rial') }}" class="input" @cannot('settings.update') disabled @endcannot>
          @foreach(['1' => 'بدون گرد کردن', '10' => '۱ تومان', '100' => '۱۰ تومان', '1000' => '۱۰۰ تومان', '10000' => '۱٬۰۰۰ تومان'] as $k => $v)
            <option value="{{ $k }}" @selected((string) old($f('pricing.rounding_rial'), $values['pricing.rounding_rial']) === $k)>{{ $v }}</option>
          @endforeach
        </select>
        <div class="help">قیمت پس از تخفیف رو به پایین گرد می‌شود (به نفع مشتری).</div>
      </div>
      @can('settings.update')
        <button type="submit" class="btn">ذخیره تنظیمات</button>
      @else
        <p class="muted">شما فقط اجازه مشاهده تنظیمات را دارید.</p>
      @endcan
    </form>
  </div>
</x-layouts.app>
