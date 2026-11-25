<x-layouts.app :title="'روتر '.$r->name">
  @php($info = $r->info ?? [])
  @php($inv = $r->inventory ?? null)
  <div class="page-head">
    <div>
      <h1>{{ $r->name }} <span @class(['badge', 'badge-success' => $r->isUp(), 'badge-danger' => ! $r->isUp() && $r->last_error_at])>{{ $r->isUp() ? 'متصل' : ($r->last_error_at ? 'قطع' : 'تست نشده') }}</span></h1>
      <p class="muted"><bdi class="ltr">{{ $r->host }}:{{ $r->port }}</bdi> — {{ $r->transportLabel() }}</p>
    </div>
    <div class="actions">
      @can('routers.update')<form method="post" action="{{ route('routers.test', $r) }}">@csrf<button class="btn" type="submit">تست اتصال و بررسی آمادگی</button></form>@endcan
      @can('routers.sensitive')<a class="btn btn-secondary" href="{{ route('routers.edit', $r) }}">ویرایش</a>@endcan
      <a class="btn btn-secondary" href="{{ route('routers.index') }}">بازگشت</a>
    </div>
  </div>

  @if($r->last_error && ! $r->isUp())<div class="alert alert-danger">آخرین خطا ({{ fa_ago($r->last_error_at) }}): {{ $r->last_error }}</div>@endif

  @if($r->needsPin())
    <div class="card">
      <h2>اعتماد به گواهی روتر</h2>
      @if($r->cert_pin)
        <p>اثر انگشت ثبت‌شده: <bdi class="ltr small break">{{ $r->cert_pin }}</bdi></p>
        <p class="muted small">پنل فقط به روتری با همین کلید رمز می‌فرستد؛ اگر گواهی روتر را عوض کردید، دوباره دریافت و تأیید کنید.</p>
      @else
        <div class="alert alert-warning">پیش از ارسال رمز، کلید گواهی روتر باید تأیید شود (بدون ارسال هیچ اطلاعاتی دریافت می‌شود).</div>
      @endif
      @if($pinCandidate)
        <div class="card-inset mb-12">
          <p class="m0"><strong>گواهی دریافت‌شده از روتر:</strong></p>
          <dl class="kv">
            <dt>نام گواهی</dt><dd><bdi class="ltr">{{ $pinCandidate['subject'] ?: '—' }}</bdi></dd>
            <dt>انقضا</dt><dd>{{ $pinCandidate['valid_to'] ? fa_value($pinCandidate['valid_to']) : '—' }}</dd>
            <dt>SHA-256</dt><dd><bdi class="ltr small break">{{ $pinCandidate['sha256'] }}</bdi></dd>
          </dl>
          <p class="muted small mt-8">این مقدار SHA-256 را با خروجی دستور <bdi class="ltr">/certificate print detail</bdi> در ترمینال روتر (فیلد fingerprint) مقایسه کنید. اگر یکی است، تأیید کنید.</p>
          <form method="post" action="{{ route('routers.pin.trust', $r) }}">@csrf<input type="hidden" name="pin" value="{{ $pinCandidate['pin'] }}"><button class="btn" type="submit">✓ یکی است؛ به این گواهی اعتماد کن</button></form>
        </div>
      @endif
      @can('routers.sensitive')<form method="post" action="{{ route('routers.pin', $r) }}">@csrf<button class="btn btn-secondary" type="submit">دریافت اثر انگشت گواهی</button></form>@endcan
    </div>
  @endif

  @if($info)
    <div class="kpis mb-16">
      <div class="kpi"><div class="label">نام روتر</div><div class="value">{{ $info['identity'] ?? '—' }}</div><div class="hint">{{ $info['board'] ?? '' }} {{ $info['architecture'] ?? '' }}</div></div>
      <div class="kpi"><div class="label">RouterOS</div><div class="value ltr">{{ $info['version'] ?? '—' }}</div><div class="hint">لایسنس: {{ $info['license'] ?? '—' }}</div></div>
      <div class="kpi"><div class="label">پردازنده</div><div class="value">{{ isset($info['cpu_load']) ? fa($info['cpu_load']).'٪' : '—' }}</div><div class="hint">{{ isset($info['cpu_count']) ? fa($info['cpu_count']).' هسته' : '' }}</div></div>
      @php($memPct = ! empty($info['total_memory']) ? (int) round(100 - $info['free_memory'] * 100 / $info['total_memory']) : null)
      <div class="kpi"><div class="label">حافظه مصرفی</div><div @class(['value', 'text-danger' => $memPct !== null && $memPct > 85])>{{ $memPct !== null ? fa($memPct).'٪' : '—' }}</div><div class="hint">{{ ! empty($info['total_memory']) ? fa_bytes($info['total_memory'] - $info['free_memory']).' از '.fa_bytes($info['total_memory']) : '' }}</div></div>
      <div class="kpi"><div class="label">روشن از</div><div class="value ltr small">{{ $info['uptime'] ?? '—' }}</div><div class="hint">بررسی: {{ isset($info['checked_at']) ? fa_ago(\Carbon\Carbon::parse($info['checked_at'])) : '' }}</div></div>
    </div>
  @endif

  @if($inv)
    <div class="card">
      <h2>آمادگی برای L2TP و WireGuard</h2>
      <ul class="checklist">
        @foreach($inv['checks'] as [$st, $title, $detail])
          <li class="chk chk-{{ $st }}"><span class="chk-ic" aria-hidden="true">{{ ['ok' => '✓', 'warn' => '!', 'fail' => '✕', 'info' => 'i'][$st] ?? '•' }}</span><div><strong>{{ $title }}</strong><div class="muted small">{{ $detail }}</div></div></li>
        @endforeach
      </ul>
      <p class="muted small">بررسی: {{ fa_ago(\Carbon\Carbon::parse($inv['checked_at'])) }} — فقط خواندن؛ روی روتر چیزی تغییر نکرد.</p>
      @can('routers.update')
        <div class="proto-toggles">
          @foreach(['l2tp' => ['L2TP', $r->l2tp_enabled, $inv['l2tp_ready']], 'wireguard' => ['WireGuard', $r->wireguard_enabled, $inv['wg_ready']]] as $p => [$label, $on, $ready])
            <form method="post" action="{{ route('routers.protocol', $r) }}" class="proto {{ $on ? 'is-on' : '' }}">
              @csrf <input type="hidden" name="protocol" value="{{ $p }}"><input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
              <div><strong>{{ $label }}</strong><div class="muted small">{{ $on ? 'برای ساخت اکانت روی این روتر فعال است' : ($ready ? 'آماده؛ هنوز فعال نشده' : 'موارد قرمز بالا را برطرف کنید') }}</div></div>
              <button class="btn {{ $on ? 'btn-secondary' : '' }} btn-sm" type="submit" @disabled(! $on && ! $ready)>{{ $on ? 'غیرفعال کن' : 'فعال کن' }}</button>
            </form>
          @endforeach
        </div>
      @endcan
    </div>
  @endif

  @can('routers.sensitive')
  <details class="card adv">
    <summary class="step-head"><span class="step-no">⚙</span><div><h2>اسکریپت راه‌اندازی اتصال روی روتر</h2><p class="muted small">یک بار در Terminal روتر (Winbox → New Terminal) اجرا کنید.</p></div><span class="adv-toggle" aria-hidden="true">⌄</span></summary>
    @php($ip = $panelIp ?: 'IP-هاست-پنل')
    @php($svc = ['rest' => 'www-ssl', 'api-ssl' => 'api-ssl', 'api' => 'api'][$r->transport])
<textarea class="input code" rows="16" readonly spellcheck="false">
# 1) گروه و کاربر مخصوص پنل (فقط از IP هاست پنل)
/user group add name=panel policy=read,write,api,rest-api,test
/user add name={{ $r->username }} group=panel address={{ $ip }}/32 password="رمز-که-در-پنل-وارد-کردید"
@if($svc !== 'api')
# 2) گواهی برای HTTPS / API-SSL (با یک CA کوچک؛ امضای مستقیم روی برخی نسخه‌ها خطای CA not found می‌دهد)
/certificate add name=panel-ca common-name=panel-ca days-valid=3650 key-usage=key-cert-sign,crl-sign
/certificate sign panel-ca
/certificate add name=panel-cert common-name=panel-cert days-valid=3650 key-usage=digital-signature,key-encipherment,tls-server
/certificate sign panel-cert ca=panel-ca
@endif
# {{ $svc !== 'api' ? '3' : '2' }}) فعال‌سازی سرویس فقط برای IP هاست پنل
/ip service set {{ $svc }} disabled=no port={{ $r->port }} address={{ $ip }}/32{{ $svc !== 'api' ? ' certificate=panel-cert' : '' }}
</textarea>
    <ul class="muted small">
      <li>اگر خطای «rest-api» گرفتید (RouterOS قدیمی‌تر)، آن را از policy حذف کنید.</li>
      <li>دستورها را در Terminal (Winbox → New Terminal) بزنید؛ فرم گواهی Winbox و اپ موبایل روی RouterOS 7.7 هنگام امضا خطای «CA not found» می‌دهد.</li>
      <li>اگر SSTP یا سرویس دیگری روی پورت {{ fa($r->port) }} است، پورت دیگری (مثلاً ۸۴۴۳) انتخاب و در پنل هم همان را وارد کنید.</li>
      <li>IP هاست پنل {{ $panelIp ? 'به‌طور خودکار تشخیص داده شد؛ ' : '' }}آن را با IP خروجی هاست (در cPanel: «Shared IP Address» یا Server Information) مقایسه کنید.</li>
      <li>در فایروال روتر (IP → Firewall)، ورودی از IP هاست پنل به این پورت را مجاز کنید.</li>
    </ul>
  </details>
  <details class="card adv">
    <summary class="step-head"><span class="step-no">W</span><div><h2>اسکریپت راه‌اندازی WireGuard</h2><p class="muted small">یک بار در Terminal روتر بزنید؛ بعد «تست اتصال» و ساخت گروه WireGuard.</p></div><span class="adv-toggle" aria-hidden="true">⌄</span></summary>
<textarea class="input code" rows="14" readonly spellcheck="false">
# اینترفیس WireGuard (کلید سرور خودکار ساخته می‌شود)
/interface wireguard add name=wg1 listen-port=13231 mtu=1420
# شبکه داخلی مشتری‌ها: روتر 10.66.0.1 و مشتری‌ها از 10.66.0.2 به بعد
/ip address add address=10.66.0.1/24 interface=wg1
# خروج مشتری‌ها به اینترنت
/ip firewall nat add chain=srcnat src-address=10.66.0.0/24 action=masquerade comment=wg-panel
# باز کردن پورت WireGuard (اگر فایروال قانون drop دارد، این را بالای آن ببرید)
/ip firewall filter add chain=input protocol=udp dst-port=13231 action=accept comment=wg-panel
/ip firewall filter add chain=forward src-address=10.66.0.0/24 action=accept comment=wg-panel
</textarea>
    <ul class="muted small">
      <li>Peerها (مشتری‌ها) را پنل خودش می‌سازد؛ کلید، IP، فایل ‎.conf و QR هر مشتری در صفحه همان اکانت است.</li>
      <li>کلید عمومی سرور، پورت و شبکه هنگام ساخت «گروه WireGuard» از روتر خوانده می‌شود؛ لازم نیست جایی کپی کنید.</li>
      <li>اگر روتر پشت مودم/NAT است، پورت UDP ‎13231 را روی مودم به روتر Forward کنید و در گروه «آدرس اتصال مشتری» را IP یا دامنه عمومی بگذارید.</li>
    </ul>
  </details>
  <details class="card adv">
    <summary class="step-head"><span class="step-no">L</span><div><h2>اسکریپت راه‌اندازی L2TP/IPsec</h2><p class="muted small">یک بار در Terminal روتر بزنید؛ کلید IPsec را عوض کنید.</p></div><span class="adv-toggle" aria-hidden="true">⌄</span></summary>
<textarea class="input code" rows="14" readonly spellcheck="false">
/ip pool add name=l2tp-pool ranges=10.77.0.2-10.77.3.254
/ppp profile add name=l2tp-profile local-address=10.77.0.1 remote-address=l2tp-pool dns-server=1.1.1.1,8.8.8.8 use-encryption=yes
/interface l2tp-server server set enabled=yes default-profile=l2tp-profile use-ipsec=required ipsec-secret="کلید-IPsec-دلخواه" authentication=mschap2
/ip firewall nat add chain=srcnat src-address=10.77.0.0/22 action=masquerade comment=l2tp-panel
/ip firewall filter add chain=input protocol=udp dst-port=500,1701,4500 action=accept comment=l2tp-panel
/ip firewall filter add chain=input protocol=ipsec-esp action=accept comment=l2tp-panel
</textarea>
    <ul class="muted small">
      <li>کلید IPsec هنگام ساخت «گروه L2TP» از روتر خوانده و به مشتری نمایش داده می‌شود.</li>
      <li>اکانت‌ها (PPP Secret) را پنل می‌سازد؛ دستی نسازید.</li>
    </ul>
  </details>
  <form method="post" action="{{ route('routers.destroy', $r) }}" data-confirm="این روتر از پنل حذف شود؟ (روی خود روتر چیزی تغییر نمی‌کند)" class="mb-16">@csrf @method('DELETE')<button class="btn btn-danger btn-sm" type="submit">حذف روتر از پنل</button></form>
  @endcan
</x-layouts.app>
