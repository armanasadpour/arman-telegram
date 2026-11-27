{{-- Connection details: secrets. Rendered only right after creation or behind mt_accounts.sensitive + password confirmation. --}}
@php($g = $a->group)
<section class="card connect-card mb-16">
  <header class="step-head"><span class="step-no">🔐</span><div><h2>اطلاعات اتصال برای مشتری</h2><p class="muted small">این اطلاعات محرمانه است؛ فقط برای خود مشتری بفرستید.</p></div></header>
  @if($a->isWireguard() && ! $a->wg_private_key)
    <div class="alert alert-warning">این Peer از روتر وارد شده و روتر کلید خصوصی مشتری را نگه نمی‌دارد؛ مشتری با همان فایل قبلی‌اش وصل می‌شود.
      برای ساخت فایل/QR در پنل: کلید خصوصی را از فایل فعلی مشتری (خط PrivateKey) پایین همین صفحه ثبت کنید، یا «ساخت کلید جدید» بزنید و فایل جدید بدهید.</div>
  @elseif($a->isWireguard())
    @foreach($a->allDevices() as $dev)
      @php($conf = \App\Services\Mikrotik\WgConfig::build($a, $dev instanceof \App\Models\MtDevice ? $dev : null))
      @if($a->deviceCount() > 1)<h3 class="mt-8">دستگاه {{ fa($dev->deviceNo()) }} از {{ fa($a->deviceCount()) }}</h3>@endif
      <div class="connect-grid mb-16">
        @php($qrPng = \App\Support\QrCode::png($conf, 8))
        @php($qrName = preg_replace('/\.conf$/', '', \App\Services\Mikrotik\WgConfig::filename($a, $dev instanceof \App\Models\MtDevice ? $dev : null)).'-qr.png')
        <div class="qr-box">
          @if($qrPng)
            <img class="qr-img" src="data:image/png;base64,{{ base64_encode($qrPng) }}" alt="QR {{ $a->username }}" width="320" height="320">
            <div class="qr-actions">
              <button type="button" class="btn btn-sm" data-save-img="{{ $qrName }}">📥 ذخیره عکس QR</button>
            </div>
            <div class="muted small">روی آیفون می‌توانید روی عکس هم انگشت نگه دارید ← Save to Photos.</div>
          @else
            {!! \App\Support\QrCode::svg($conf, 4) !!}
          @endif
          <div class="muted small">در برنامه WireGuard: ➕ → Scan from QR code</div>
        </div>
        <div>
          <pre class="conf ltr" id="wgconf{{ $dev->deviceNo() }}">{{ $conf }}</pre>
          <div class="actions">
            <button type="button" class="btn btn-secondary btn-sm" data-copy="#wgconf{{ $dev->deviceNo() }}">کپی متن</button>
            <a class="btn btn-sm" download="{{ \App\Services\Mikrotik\WgConfig::filename($a, $dev instanceof \App\Models\MtDevice ? $dev : null) }}" href="data:application/octet-stream;base64,{{ base64_encode($conf) }}">دانلود فایل ‎.conf</a>
          </div>
        </div>
      </div>
    @endforeach
    <p class="help">هر فایل فقط روی یک دستگاه؛ برنامه رسمی WireGuard برای اندروید، iOS، ویندوز و مک.</p>
  @else
    <dl class="kv connect-kv">
      <dt>نوع VPN</dt><dd>L2TP/IPsec PSK</dd>
      <dt>سرور</dt><dd><bdi class="ltr" id="c_srv">{{ $g->endpoint() }}</bdi> <button type="button" class="chip" data-copy="#c_srv">کپی</button></dd>
      <dt>نام کاربری</dt><dd><bdi class="ltr" id="c_user">{{ $a->username }}</bdi> <button type="button" class="chip" data-copy="#c_user">کپی</button></dd>
      <dt>رمز</dt><dd>@if($a->password !== null && $a->password !== '')<bdi class="ltr" id="c_pass">{{ $a->password }}</bdi> <button type="button" class="chip" data-copy="#c_pass">کپی</button>@else<span class="muted">از روتر خوانده نشد (کاربر panel دسترسی sensitive ندارد)؛ با «تغییر رمز» رمز تازه بگذارید.</span>@endif</dd>
      <dt>کلید IPsec (PSK)</dt><dd>@if($g->ipsec_psk)<bdi class="ltr" id="c_psk">{{ $g->ipsec_psk }}</bdi> <button type="button" class="chip" data-copy="#c_psk">کپی</button>@else<span class="muted">در گروه ثبت نشده</span>@endif</dd>
    </dl>
    <pre class="sr-only" id="l2all">نوع: L2TP/IPsec PSK
سرور: {{ $g->endpoint() }}
نام کاربری: {{ $a->username }}
رمز: {{ $a->password }}@if($g->ipsec_psk)

کلید IPsec: {{ $g->ipsec_psk }}@endif</pre>
    <button type="button" class="btn btn-secondary btn-sm" data-copy="#l2all">کپی همه برای ارسال</button>
  @endif
</section>
