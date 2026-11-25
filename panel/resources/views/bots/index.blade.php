<x-layouts.app title="ربات‌ها">
  <div class="page-head">
    <div>
      <h1>ربات‌ها</h1>
      <p class="muted">تلگرام، بله و روبیکا — منوی مشتری (اکانت‌های من، تعرفه‌ها)، فروش و تمدید همکاران از کیف پول، و اعلان مدیران.</p>
    </div>
    <div class="actions">
      <a class="btn btn-secondary" href="{{ route('bots.outbox') }}">صف پیام‌ها</a>
      <form method="post" action="{{ route('bots.reach') }}">@csrf<button class="btn btn-secondary" type="submit">تست دسترسی هاست به پیام‌رسان‌ها</button></form>
    </div>
  </div>

  @if($reach = session('reach'))
    <div class="card">
      <h2>نتیجه تست دسترسی از همین هاست</h2>
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>مقصد</th><th>نتیجه</th><th>جزئیات</th><th>زمان</th></tr></thead>
        <tbody>
        @foreach($reach as $k => $r)
          <tr>
            <td data-label="مقصد">{{ $k === 'relay' ? 'واسط تلگرام' : (\App\Models\Bot::PLATFORMS[$k] ?? $k) }}</td>
            <td data-label="نتیجه">@if($r['ok'])<span class="badge badge-success">در دسترس</span>@else<span class="badge badge-danger">در دسترس نیست</span>@endif</td>
            <td data-label="جزئیات"><bdi class="ltr small">{{ $r['detail'] }}</bdi></td>
            <td data-label="زمان">{{ fa($r['ms']) }} ms</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
      <p class="muted small">اگر تلگرام در دسترس نیست، حالت «Cloudflare Worker» یا «Google Apps Script» را برای ربات تلگرام انتخاب کنید.</p>
    </div>
  @endif

  @if($outbox['failed'] > 0)
    <div class="alert alert-warning">{{ fa($outbox['failed']) }} اعلان پس از چند تلاش ارسال نشد. <a href="{{ route('bots.outbox', ['status' => 'failed']) }}">مشاهده</a></div>
  @endif

  @foreach($bots as $platform => $bot)
    @php($c = $counts[$bot->id] ?? null)
    <div class="card">
      <div class="page-head">
        <h2>{{ $bot->label() }} @if($bot->bot_username)<bdi class="ltr muted small">{{ '@'.$bot->bot_username }}</bdi>@endif</h2>
        <div>
          @if(! $bot->isConfigured())<span class="badge">تنظیم نشده</span>
          @elseif(! $bot->is_active)<span class="badge badge-warning">غیرفعال</span>
          @elseif($bot->webhook_set_at)<span class="badge badge-success">فعال و وصل</span>
          @else<span class="badge badge-warning">فعال — «اتصال» را بزنید</span>@endif
        </div>
      </div>
      <p class="muted small">
        کاربران ربات: {{ fa((int) ($c->c ?? 0)) }} — وصل به حساب پنل: {{ fa((int) ($c->linked ?? 0)) }}
        @if($bot->last_update_at) — آخرین پیام: {{ fa_ago($bot->last_update_at) }}@endif
      </p>
      @if($bot->last_error)
        <div class="alert alert-danger small">آخرین خطا ({{ fa_ago($bot->last_error_at) }}): {{ $bot->last_error }}</div>
      @endif
      @if(($probe = session('probe')) && isset($probe[$platform]) && $probe[$platform]['raw'] !== null)
        <details class="mb-12"><summary>پاسخ واقعی سرور {{ $bot->label() }} (getMe)</summary><pre class="code">{{ json_encode($probe[$platform]['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
      @endif

      @can('bots.sensitive')
        <form method="post" action="{{ route('bots.update', $platform) }}">
          @csrf @method('PUT')
          <div class="form-grid">
            <div class="field">
              <label for="token_{{ $platform }}">توکن ربات</label>
              <input class="input ltr" id="token_{{ $platform }}" name="token" autocomplete="off" spellcheck="false" placeholder="{{ $bot->maskedToken() ? 'ذخیره شده: '.$bot->maskedToken().' — برای تغییر، توکن جدید را وارد کنید' : 'توکن را از '.($platform === 'telegram' ? '@BotFather' : ($platform === 'bale' ? '@botfather در بله' : 'بات‌فادر روبیکا')).' بگیرید' }}">
            </div>
            @if($platform === 'telegram')
              <div class="field">
                <label for="relay_mode">مسیر ارتباط با تلگرام</label>
                <select class="input" id="relay_mode" name="relay_mode">
                  @foreach(\App\Models\Bot::RELAYS as $k => $l)<option value="{{ $k }}" @selected(old('relay_mode', $bot->relay_mode) === $k)>{{ $l }}</option>@endforeach
                </select>
                <div class="help">هاست ایران معمولاً به تلگرام دسترسی ندارد؛ یکی از دو واسط را بسازید.</div>
              </div>
              @php($receive = app(\App\Services\Settings::class)->get('bots.telegram_receive', 'webhook'))
              <div class="field">
                <label for="receive">دریافت پیام کاربران (وقتی واسط دارید)</label>
                <select class="input" id="receive" name="receive">
                  <option value="webhook" @selected(old('receive', $receive) === 'webhook')>سریع — تلگرام مستقیم به هاست می‌فرستد (پیشنهادی)</option>
                  <option value="poll" @selected(old('receive', $receive) === 'poll')>دوره‌ای — پنل از مسیر واسط می‌گیرد (کندتر، با Cron)</option>
                </select>
                <div class="help">فقط ارسال به تلگرام از هاست ایران بسته است؛ رسیدن پیام از تلگرام به هاست معمولاً باز است. اگر بعد از «اتصال» خطای دریافت دیدید، «دوره‌ای» را انتخاب کنید.</div>
              </div>
              <div class="field">
                <label for="relay_url">آدرس واسط</label>
                <input class="input ltr" id="relay_url" name="relay_url" value="{{ old('relay_url', $bot->relay_url) }}" placeholder="https://xxx.workers.dev یا https://script.google.com/macros/s/…/exec">
                <div class="help">همان آدرس Worker یا Apps Script افزونه خبر (بستک‌نیوز) هم کار می‌کند.<br><a href="{{ route('bots.relay', 'cloudflare') }}">کد و راهنمای Cloudflare Worker</a> — <a href="{{ route('bots.relay', 'gas') }}">کد و راهنمای Google Apps Script</a></div>
              </div>
            @endif
            <div class="field">
              <label class="check"><input type="checkbox" name="is_active" value="1" @checked($bot->is_active)> ربات فعال باشد</label>
            </div>
          </div>
          <button class="btn" type="submit">ذخیره</button>
        </form>
      @endcan
      @can('bots.update')
        <div class="actions mt-8">
          @if($bot->isConfigured() && $bot->is_active)
            <form method="post" action="{{ route('bots.connect', $platform) }}" class="inline">@csrf<button class="btn btn-secondary" type="submit">اتصال (ثبت آدرس دریافت پیام)</button></form>
            <form method="post" action="{{ route('bots.test', $platform) }}" class="inline">@csrf<button class="btn btn-secondary" type="submit">ارسال پیام آزمایشی به خودم</button></form>
          @endif
          @can('bots.sensitive')
            <form method="post" action="{{ route('bots.rotate', $platform) }}" class="inline" data-confirm="آدرس مخفی دریافت پیام عوض شود؟ بعد از آن باید دوباره «اتصال» را بزنید.">@csrf<button class="btn btn-secondary" type="submit">تعویض آدرس مخفی</button></form>
          @endcan
        </div>
      @endcan
    </div>
  @endforeach

  @can('bots.update')
    <div class="card">
      <h2>متن‌های ربات</h2>
      <form method="post" action="{{ route('bots.texts') }}">
        @csrf @method('PUT')
        <div class="field"><label for="welcome">پیام خوش‌آمد</label><textarea class="input" id="welcome" name="welcome" rows="3" maxlength="1000">{{ old('welcome', $welcome) }}</textarea></div>
        <div class="field"><label for="support">متن پشتیبانی (آیدی، شماره تماس…)</label><textarea class="input" id="support" name="support" rows="3" maxlength="1000">{{ old('support', $support) }}</textarea></div>
        <button class="btn" type="submit">ذخیره متن‌ها</button>
      </form>
    </div>
  @endcan
</x-layouts.app>
