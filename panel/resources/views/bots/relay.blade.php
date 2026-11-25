<x-layouts.app title="واسط تلگرام">
  <div class="page-head">
    <h1>واسط تلگرام — {{ $kind === 'cloudflare' ? 'Cloudflare Worker' : 'Google Apps Script' }}</h1>
    <a class="btn btn-secondary" href="{{ route('bots.index') }}">بازگشت</a>
  </div>
  <div class="alert alert-success">همان Worker یا Apps Script که برای افزونه خبر (بستک‌نیوز) ساخته‌اید با این پنل هم کار می‌کند؛ کافی است آدرسش را در «آدرس واسط» بگذارید. لازم نیست کد جدید بسازید.</div>
  <div class="card">
    <h2>مراحل</h2>
    @if($kind === 'cloudflare')
      <ol>
        <li>در <bdi class="ltr">dash.cloudflare.com</bdi> وارد شوید (حساب رایگان کافی است) و به <b>Workers & Pages</b> بروید.</li>
        <li><b>Create</b> ← <b>Create Worker</b>؛ یک نام بدهید و <b>Deploy</b> کنید.</li>
        <li><b>Edit code</b> را بزنید، همه کد پیش‌فرض را پاک کنید، کد زیر را جای‌گذاری و <b>Deploy</b> کنید.</li>
        <li>آدرس Worker (مثل <bdi class="ltr">https://name.account.workers.dev</bdi>) را در صفحه ربات‌ها، در «آدرس واسط» وارد کنید، حالت را «Cloudflare Worker» بگذارید و ذخیره کنید.</li>
        <li>دکمه «اتصال» را بزنید.</li>
      </ol>
    @else
      <ol>
        <li>در <bdi class="ltr">script.google.com</bdi> یک پروژه جدید بسازید.</li>
        <li>همه محتوای فایل <bdi class="ltr">Code.gs</bdi> را پاک کنید و کد زیر را جای‌گذاری و ذخیره کنید.</li>
        <li><b>Deploy</b> ← <b>New deployment</b> ← نوع <b>Web app</b>؛ <b>Execute as: Me</b> و <b>Who has access: Anyone</b>؛ دسترسی‌ها را تأیید کنید.</li>
        <li>آدرس <bdi class="ltr">…/exec</bdi> را در «آدرس واسط» وارد کنید، حالت را «Google Apps Script» بگذارید، ذخیره و «اتصال» را بزنید.</li>
        <li>هر بار کد را تغییر دادید، باید <b>Manage deployments</b> ← نسخه جدید بسازید.</li>
      </ol>
    @endif
    <p class="muted small">پیام‌های کاربران را خود پنل هر چند ثانیه از تلگرام می‌گیرد (Cron باید هر دقیقه اجرا شود)؛ تلگرام لازم نیست به هاست ایران دسترسی داشته باشد. توکن ربات فقط از مسیر HTTPS و از داخل همین واسط شما عبور می‌کند.</p>
  </div>
  <div class="card">
    <h2>کد</h2>
    <textarea class="input ltr code" rows="24" readonly spellcheck="false">{{ $code }}</textarea>
  </div>
</x-layouts.app>
