<?php /** @var string $siteName */ ?>
<section class="install-hero">
  <img src="assets/icons/icon-192.png" alt="" width="96" height="96">
  <div>
    <h1>نصب <?= esc($siteName) ?> روی دستگاه شما</h1>
    <p class="muted">بدون فروشگاه برنامه و بدون حجم اضافه؛ با یک لمس از صفحه اصلی گوشی باز می‌شود و اعلان یادآوری نوبت را هم دریافت می‌کند.</p>
    <button type="button" data-install hidden><?= icon('install') ?> نصب برنامه</button>
    <p class="notice" data-installed hidden role="status">برنامه روی این دستگاه نصب است.</p>
  </div>
</section>
<div class="install-grid">
  <article class="card">
    <h2><?= icon('phone') ?> اندروید (Chrome، Edge، Samsung Internet)</h2>
    <ol>
      <li>اگر دکمه «نصب برنامه» بالای این صفحه را می‌بینید، آن را بزنید.</li>
      <li>در غیر این صورت منوی سه‌نقطه مرورگر را باز کنید.</li>
      <li>«نصب برنامه» یا «افزودن به صفحه اصلی» را انتخاب کنید.</li>
    </ol>
  </article>
  <article class="card">
    <h2><?= icon('phone') ?> آیفون و آیپد (Safari)</h2>
    <ol>
      <li>این صفحه را در Safari باز کنید.</li>
      <li>دکمه اشتراک‌گذاری (مربع با فلش رو به بالا) را بزنید.</li>
      <li>«Add to Home Screen» یا «افزودن به صفحه اصلی» را انتخاب و تأیید کنید.</li>
      <li>برای دریافت اعلان یادآوری، برنامه را از صفحه اصلی باز کنید و در بخش اعلان‌ها فعال کنید (iOS 16.4 به بعد).</li>
    </ol>
  </article>
  <article class="card">
    <h2><?= icon('home') ?> رایانه (Chrome و Edge)</h2>
    <ol>
      <li>در نوار نشانی، نماد نصب (رایانه با فلش) را بزنید یا دکمه «نصب برنامه» همین صفحه را.</li>
      <li>برنامه در پنجره جدا باز می‌شود و در فهرست برنامه‌های سیستم قرار می‌گیرد.</li>
    </ol>
  </article>
</div>
<p class="muted small">حریم خصوصی: برنامه نصب‌شده فقط فایل‌های ظاهری (طرح، فونت، آیکن) را روی دستگاه نگه می‌دارد. نوبت‌ها، پرونده‌ها و پرداخت‌ها همیشه از سرور خوانده می‌شوند و در حالت آفلاین نمایش داده نمی‌شوند.</p>
