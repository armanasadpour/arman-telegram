<x-layouts.app title="واسط تلگرام">
  <div class="page-head">
    <h1>واسط تلگرام — {{ $kind === 'cloudflare' ? 'Cloudflare Worker' : 'Google Apps Script' }}</h1>
    <a class="btn btn-secondary" href="{{ route('bots.index') }}">بازگشت</a>
  </div>
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
      <p class="muted small">نکته: Apps Script به تلگرام پاسخ «انتقال (302)» می‌دهد و تلگرام ممکن است پیام را چند بار بفرستد؛ پنل هر پیام را فقط یک بار پردازش می‌کند. اگر کندی دیدید، Cloudflare را ترجیح دهید.</p>
    @endif
    <div class="alert alert-warning small">این کد شامل کلید مخفی ارتباط با پنل است؛ آن را در اختیار کسی قرار ندهید. اگر لو رفت، «تعویض آدرس مخفی» را بزنید و کد را دوباره بسازید.</div>
  </div>
  <div class="card">
    <h2>کد</h2>
    <textarea class="input ltr code" rows="24" readonly spellcheck="false">{{ $code }}</textarea>
  </div>
</x-layouts.app>
