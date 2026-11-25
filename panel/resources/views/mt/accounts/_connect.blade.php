{{-- Connection details: secrets. Rendered only right after creation or behind mt_accounts.sensitive + password confirmation. --}}
@php($g = $a->group)
<section class="card connect-card mb-16">
  <header class="step-head"><span class="step-no">🔐</span><div><h2>اطلاعات اتصال برای مشتری</h2><p class="muted small">این اطلاعات محرمانه است؛ فقط برای خود مشتری بفرستید.</p></div></header>
  @if($a->isWireguard())
    @php($conf = \App\Services\Mikrotik\WgConfig::build($a))
    <div class="connect-grid">
      <div class="qr-box">{!! \App\Support\QrCode::svg($conf, 4) !!}<div class="muted small">در برنامه WireGuard: ➕ → Scan from QR code</div></div>
      <div>
        <pre class="conf ltr" id="wgconf">{{ $conf }}</pre>
        <div class="actions">
          <button type="button" class="btn btn-secondary btn-sm" data-copy="#wgconf">کپی متن</button>
          <a class="btn btn-sm" download="{{ \App\Services\Mikrotik\WgConfig::filename($a) }}" href="data:application/octet-stream;base64,{{ base64_encode($conf) }}">دانلود فایل ‎.conf</a>
        </div>
        <p class="help">برنامه رسمی WireGuard برای اندروید، iOS، ویندوز و مک. فایل را Import کنید یا QR را اسکن کنید.</p>
      </div>
    </div>
  @else
    <dl class="kv connect-kv">
      <dt>نوع VPN</dt><dd>L2TP/IPsec PSK</dd>
      <dt>سرور</dt><dd><bdi class="ltr" id="c_srv">{{ $g->endpoint() }}</bdi> <button type="button" class="chip" data-copy="#c_srv">کپی</button></dd>
      <dt>نام کاربری</dt><dd><bdi class="ltr" id="c_user">{{ $a->username }}</bdi> <button type="button" class="chip" data-copy="#c_user">کپی</button></dd>
      <dt>رمز</dt><dd><bdi class="ltr" id="c_pass">{{ $a->password }}</bdi> <button type="button" class="chip" data-copy="#c_pass">کپی</button></dd>
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
