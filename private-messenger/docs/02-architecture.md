# ۰۲ — معماری سیستم

## ۱. نمای کلی

```
┌──────────────────────── دستگاه کاربر (مرورگر / PWA) ────────────────────────┐
│  UI (Vanilla JS, ES Modules, RTL)                                            │
│  ├─ Crypto Core (Web Worker)                                                 │
│  │   ├─ vodozemac (WASM): Olm sessions, Ed25519, SAS                         │
│  │   ├─ Web Crypto: AES-256-GCM (فایل‌ها), HKDF, SHA-256, کلید غیرقابل‌استخراج  │
│  │   └─ Key Store: IndexedDB (وضعیت رمزنگاری‌شده با کلید Web Crypto)          │
│  ├─ Local DB: IndexedDB (تاریخچه رمزگشایی‌شده، رمزنگاری‌شده در حالت سکون)      │
│  ├─ Sync Engine: polling تطبیقی / long-poll / WebSocket (در صورت وجود)       │
│  ├─ Upload/Download Engine: چندبخشی، قابل ادامه، رمزنگاری جریانی             │
│  ├─ Media: WebCodecs (تبدیل اختیاری ویدیو)، Canvas (HD تصویر، thumbnail)      │
│  ├─ WebRTC: تماس دونفره، DTLS-SRTP                                           │
│  └─ Service Worker: کش پوسته برنامه، بررسی manifest امضاشده، Web Push        │
└───────────────▲──────────────────────────────────────────────────────────────┘
                │ HTTPS (فقط ciphertext + فراداده حداقلی)
┌───────────────┴────────── هاست cPanel ───────────────────────────────────────┐
│ public_html/ (فقط public/: index.php، assets ایستا، sw.js، manifest)          │
│ ~/private-messenger/ (خارج از docroot)                                       │
│   app/      PHP 8.2+: Router → Middleware → Controller → Service → Repository│
│   storage/  attachments/ (ciphertext)، tmp/، logs/                            │
│   config/   .env (اسرار زیرساختی)                                            │
│ MySQL/MariaDB: حساب‌ها، دستگاه‌ها، کلیدهای عمومی، envelopeهای رمزنگاری‌شده،     │
│                فراداده فایل/تماس، تنظیمات، audit                              │
│ Cron: پاک‌سازی فایل‌های منقضی، envelopeهای قدیمی، OTPها، snapshot فضا          │
└──────────────────────────────────────────────────────────────────────────────┘
                │ اختیاری
         TURN خارجی (coturn)  ·  ارائه‌دهنده پیامک  ·  سرویس‌های Web Push مرورگر
```

**اصل جداسازی:** سرور «Delivery Service» گنگ است: احراز هویت، مسیریابی envelope به دستگاه‌ها،
نگهداری blob رمزنگاری‌شده، اعمال مجوز و سیاست‌ها. هیچ کد سروری متن آشکار پیام را دریافت نمی‌کند و
API ورودی متنی برای محتوا ندارد.

## ۲. Backend (PHP)

- **PHP 8.2+**، بدون فریم‌ورک سنگین. ساختار ماژولار سبک (Front Controller + Router + DI ساده).
  دلیل: سازگاری با هاست اشتراکی، کنترل کامل بر سطح حمله، عدم نیاز به Composer روی سرور
  (وابستگی‌ها در `vendor/` داخل ZIP قرار می‌گیرند).
- **وابستگی‌های سروری حداقلی و ممیزی‌پذیر:** فقط کتابخانه Web Push (مثلاً `minishlink/web-push`،
  MIT) در صورت تأیید؛ بقیه با افزونه‌های داخلی PHP (`sodium`، `openssl`، `pdo_mysql`).
- **لایه‌ها:**
  - `Http/` — Request/Response، Router، Middleware (Auth، CSRF، RateLimit، SecurityHeaders، JSON)
  - `Modules/<Name>/` — Controller، Service، Repository برای هر دامنه:
    `Auth`, `Users`, `Devices`, `Keys`, `Conversations`, `Messages`, `Sync`, `Attachments`,
    `Calls`, `Push`, `Admin`, `Settings`, `Audit`, `Retention`, `System`
  - `Infra/` — DB (PDO، prepared statements فقط)، Storage (Local/S3 adapter)، Sms (adapter)، Clock، Crypto (HMAC/token)
  - `Cli/` — اسکریپت‌های Cron (`cron.php <job>`) قابل اجرا هم از CLI و هم از طریق endpoint محافظت‌شده در صورت نبود CLI
- **هر درخواست کوتاه است** (هدف < ۲۰۰ms) تا Entry Processes هاست مصرف نشود؛ long-poll فقط اگر نصب‌کننده تأیید کند.

## ۳. ساختار پوشه‌ها (نهایی برای فاز ۲)

```
private-messenger/
├─ app/
│  ├─ Http/  Modules/  Infra/  Cli/  Support/
│  └─ bootstrap.php
├─ config/
│  ├─ .env              ← فقط روی سرور، خارج از docroot، chmod 600
│  └─ app.php
├─ database/
│  └─ migrations/       ← SQL شماره‌دار، اجرا توسط نصب‌کننده
├─ public/              ← تنها پوشه‌ای که در public_html قرار می‌گیرد
│  ├─ index.php         ← فقط مسیر app را require می‌کند
│  ├─ .htaccess         ← rewrite، هدرهای امنیتی، MIME wasm، منع اجرای اسکریپت در assets
│  ├─ sw.js  manifest.webmanifest  release-manifest.json(.sig)
│  └─ assets/  (js، css، fonts، icons، vendor/vodozemac.wasm)
├─ storage/
│  ├─ attachments/ab/cd/<storage_key>   ← ciphertext، بدون پسوند
│  ├─ tmp/  logs/  .htaccess(Deny all)
├─ installer/           ← پس از نصب قفل/حذف می‌شود
├─ workers/             ← اختیاری: سرور WebSocket برای میزبان‌هایی که اجازه می‌دهند
├─ docs/
└─ .env.example
```

**قرارگیری روی cPanel:** کل بسته در `~/private-messenger/` (کنار `public_html`) استخراج می‌شود و
محتوای `public/` در `public_html/` (یا docroot ساب‌دامین/addon domain که مستقیماً به
`~/private-messenger/public` اشاره کند — روش ترجیحی). جزئیات و حالت جایگزین در سند cPanel.

## ۴. Frontend

- **بدون build روی سرور:** ES Modules بومی مرورگر. در توسعه می‌توان از ابزار bundle استفاده کرد، اما
  خروجی نهایی ایستا و داخل ZIP است.
- **Crypto در Web Worker** جدا از UI: کلیدها هرگز در thread اصلی DOM نگهداری نمی‌شوند؛ UI فقط
  پیام‌های رمزگشایی‌شده را از worker دریافت می‌کند. این XSS را بی‌اثر نمی‌کند (XSS می‌تواند از worker
  بخواهد رمزگشایی کند) اما استخراج مستقیم کلید را سخت‌تر می‌کند.
- **رندر امن:** هرگز `innerHTML` با محتوای کاربر؛ فقط `textContent` و ساخت DOM. لینک‌سازی با
  allowlist پروتکل (`https:` فقط) و `rel="noopener noreferrer"`.
- **ذخیره محلی:** IndexedDB برای کلیدها و تاریخچه؛ `navigator.storage.persist()` درخواست می‌شود.
  کاربر درباره خطر پاک‌شدن داده مرورگر و اهمیت کلید بازیابی آگاه می‌شود.
- **چندزبانه‌پذیر**، ولی v1 فقط فارسی.

## ۵. همگام‌سازی (Sync)

یک endpoint واحد `GET /api/v1/sync?cursor=…` همه رویدادهای دستگاه را برمی‌گرداند (envelopeها،
رویدادهای زودگذر، تغییر دستگاه مخاطبان، رخدادهای امنیتی، وضعیت تحویل). سه حالت انتقال با
رابط یکسان:

| حالت | زمان استفاده | رفتار |
|---|---|---|
| Short polling تطبیقی (پیش‌فرض) | همه هاست‌ها | گفتگوی باز: ۲–۳ ثانیه؛ برنامه باز بدون گفتگوی فعال: ۱۰ ثانیه؛ پس‌زمینه: توقف و اتکا به Web Push؛ حین برقراری تماس: ۱ ثانیه |
| Long polling | اگر نصب‌کننده سقف Entry Process و max_execution_time را مناسب تشخیص دهد | نگه‌داشتن درخواست تا ۲۰ ثانیه با بررسی DB هر ۱ ثانیه |
| WebSocket | فقط اگر هاست اجرای پروسه دائمی یا سرویس جداگانه را اجازه دهد | `workers/ws-server` فقط «اعلان وجود داده جدید» می‌فرستد؛ داده همچنان از `/sync` گرفته می‌شود |

Cursor = بزرگ‌ترین `id` دریافت‌شده؛ تحویل at-least-once با ack صریح؛ کلاینت با `message_id` تکراری را حذف می‌کند.

## ۶. امنیت لایه وب

- **HTTPS اجباری:** ریدایرکت در `.htaccess` + HSTS (`max-age=31536000`). نصب‌کننده بدون HTTPS متوقف می‌شود.
- **CSP:**
  `default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' blob: data:; media-src 'self' blob:; connect-src 'self'; worker-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; require-trusted-types-for 'script'`
  (`wasm-unsafe-eval` برای اجرای WASM لازم است و اجازه `eval` جاوااسکریپت نمی‌دهد.)
- سایر هدرها: `X-Content-Type-Options: nosniff`، `Referrer-Policy: no-referrer`،
  `Permissions-Policy` (فقط camera/microphone برای self)، `Cross-Origin-Opener-Policy: same-origin`.
- **نشست:** توکن تصادفی ۲۵۶ بیتی در کوکی `__Host-pm_session` (HttpOnly، Secure، SameSite=Strict، Path=/).
  در DB فقط `SHA-256(token)` ذخیره می‌شود.
- **CSRF:** SameSite=Strict + الزام هدر سفارشی `X-PM-Request: 1` برای همه درخواست‌های تغییردهنده
  (درخواست cross-origin بدون preflight نمی‌تواند هدر سفارشی بفرستد و preflight رد می‌شود).
- **پنل مدیریت:** مسیر جدا (`/admin`)، کوکی جدا (`__Host-pm_admin`)، TOTP، امکان IP allowlist، محدودیت نرخ.
- **یکپارچگی کد تحویلی:** `release-manifest.json` شامل SHA-256 همه فایل‌های ایستا، امضاشده با کلید
  انتشار Ed25519 که **روی سرور نیست**. Service Worker نسخه‌های جدید را فقط با امضای معتبر می‌پذیرد
  و در غیر این صورت هشدار می‌دهد. محدودیت ذاتی این روش در مدل تهدید (T-01) آمده است.

## ۷. اصول داده

- همه زمان‌ها UTC در DB؛ نمایش با تقویم شمسی در کلاینت.
- شناسه‌های بیرونی تصادفی (ULID/۱۲۸ بیت) — شناسه افزایشی داخلی هرگز در URL نمی‌آید.
- لاگ‌های برنامه هرگز بدنه درخواست، ciphertext، OTP یا توکن ثبت نمی‌کنند؛ IP در لاگ‌ها با سیاست
  نگهداری کوتاه (پیش‌فرض ۱۴ روز) و در نمایش مدیر به‌صورت کوتاه‌شده.

## ۸. وابستگی‌ها و مجوزها (برای تأیید)

| مؤلفه | استفاده | مجوز | وضعیت ممیزی (باید پیش از فاز ۳ بازتأیید شود) |
|---|---|---|---|
| vodozemac (Rust→WASM) | Olm، Ed25519، SAS | Apache-2.0 | ممیزی Least Authority (۲۰۲۲) |
| Web Crypto API | AES-GCM، HKDF، SHA-256، کلید غیرقابل‌استخراج | — (بومی مرورگر) | پیاده‌سازی مرورگر |
| mediabunny یا معادل | mux/demux ویدیو برای تبدیل روی دستگاه (مشروط) | MPL-2.0 (باید بازبینی شود) | بدون نقش امنیتی؛ اجرا روی داده خود فرستنده |
| minishlink/web-push (PHP) | ارسال Web Push بدون محتوا | MIT | بدون نقش در E2EE |
| IRANSans | فونت اصلی | تجاری — مجوز توسط کارفرما تهیه شده | — |
