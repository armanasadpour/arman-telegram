# Private Messenger — پیام‌رسان خصوصی با رمزنگاری سرتاسری

> وضعیت: **فاز ۵ (تماس صوتی و تصویری درون‌برنامه‌ای، جست‌وجو و دنبال کردن کاربر، بارگذاری فونت)** — پیاده‌سازی و تست شد (۲۵۸ تست).
> نصب: فقط فایل `install.php` را در public_html آپلود و باز کنید — [docs/12-installation-guide.md](docs/12-installation-guide.md)
> گزارش‌ها: [فاز ۲](docs/13-phase-2-report.md) · [فاز ۳](docs/14-phase-3-report.md) · [فاز ۴](docs/15-phase-4-report.md) · [فاز ۵](docs/16-phase-5-report.md)

پیام‌رسان خصوصی، فارسی و راست‌چین برای اعضای یک مجموعه، قابل نصب روی هاست cPanel،
با رمزنگاری سرتاسری (E2EE) پیام‌ها و فایل‌ها در دستگاه کاربران.

## اسناد

| سند | موضوع |
|---|---|
| [docs/00-phase-1-summary.md](docs/00-phase-1-summary.md) | خلاصه فاز ۱، تصمیم‌های نیازمند تأیید، ریسک‌ها و معیار ورود به فاز ۲ |
| [docs/01-requirements.md](docs/01-requirements.md) | نیازمندی‌ها، دامنه نسخه ۱، تناقض‌های شناسایی‌شده در سند اولیه |
| [docs/02-architecture.md](docs/02-architecture.md) | معماری سیستم، ساختار پوشه‌ها، جریان درخواست، امنیت وب |
| [docs/03-threat-model.md](docs/03-threat-model.md) | مدل تهدید، دارایی‌ها، مهاجمان، آنچه E2EE تضمین می‌کند و نمی‌کند |
| [docs/04-e2ee-protocol.md](docs/04-e2ee-protocol.md) | انتخاب پروتکل E2EE، سلسله‌مراتب کلید، چنددستگاهی، بازیابی، رمزنگاری فایل |
| [docs/05-cpanel-constraints.md](docs/05-cpanel-constraints.md) | محدودیت‌های واقعی هاست اشتراکی و راهکار هر کدام |
| [docs/06-database.md](docs/06-database.md) | طرح دیتابیس و نگاشت به جداول پیشنهادی سند اولیه |
| [database/migrations/](database/migrations/) | اسکیمای MySQL/MariaDB (migrationها) |
| [docs/07-api.md](docs/07-api.md) | طراحی API نسخه ۱ |
| [docs/08-files-retention-storage.md](docs/08-files-retention-storage.md) | آپلود چندبخشی، سیاست ۷ روزه، نگهداری دائمی، فضای ذخیره‌سازی |
| [docs/09-calls-webrtc.md](docs/09-calls-webrtc.md) | تماس دونفره WebRTC، signaling رمزنگاری‌شده، STUN/TURN |
| [docs/10-ui-ux.md](docs/10-ui-ux.md) | اصول UI/UX، فهرست صفحات، فونت و مجوز آن |
| [docs/11-stealth-and-login.md](docs/11-stealth-and-login.md) | روش ورود قابل انتخاب، آدرس مخفی، 404، عدم ایندکس |
| [docs/12-installation-guide.md](docs/12-installation-guide.md) | راهنمای نصب روی cPanel بدون SSH |
| [docs/13-phase-2-report.md](docs/13-phase-2-report.md) | گزارش فاز ۲، تست‌ها و محدودیت‌ها |
| [docs/14-phase-3-report.md](docs/14-phase-3-report.md) | گزارش فاز ۳: E2EE، نتیجه Spike، تست‌ها و محدودیت‌ها |
| [docs/15-phase-4-report.md](docs/15-phase-4-report.md) | گزارش فاز ۴: امکانات پیام، فایل و رسانه، نگهداری ۷ روزه |
| [docs/16-phase-5-report.md](docs/16-phase-5-report.md) | گزارش فاز ۵: تماس، جست‌وجوی کاربر، بارگذاری فونت |
| [tools/host-probe/](tools/host-probe/) | اسکریپت بررسی واقعی قابلیت‌های هاست cPanel (ابزار فاز ۱) |
| [prototype/](prototype/) | نمونه اولیه رابط کاربری (HTML/CSS/JS ایستا، بدون سرور) |

## ساختار کد

```
app/        کد PHP (خارج از docroot): Http, Auth, Users, Admin, Settings, Audit, Infra, Install, Controllers, Views, Cli
config/     .env (ساخته‌شده توسط نصب‌کننده؛ هرگز commit نمی‌شود)
database/   migrationها
public/     تنها محتوای docroot: index.php و .htaccess
storage/    لاگ‌ها و فایل‌ها (خارج از docroot)
tests/      تست واحد، سرویس و انتها‌به‌انتها
tools/      host-probe، build-release.sh (ZIP + install.php)، make-installer.php
crypto-wasm/ پل Rust → WebAssembly برای vodozemac (خروجی ساخته‌شده در app/resources/assets)
```

اجرای همه تست‌ها (ماشین توسعه): `tests/run-all.sh`

ساخت بسته نصب: `tools/build-release.sh 0.5.0` ← `dist/install.php` و `dist/private-messenger-0.5.0.zip`

## اصل غیرقابل مذاکره

رمزنگاری و رمزگشایی فقط در دستگاه کاربر انجام می‌شود. سرور، مدیرکل، مدیر هاست و مدیر دیتابیس
فقط ciphertext و فراداده حداقلی می‌بینند. محدودیت‌های این تضمین (به‌ویژه درباره تحویل کد
وب‌اپلیکیشن از همان سرور) در [مدل تهدید](docs/03-threat-model.md) صادقانه شرح داده شده است.

## نکته درباره سایر فایل‌های مخزن

فایل‌های `index.html` و `vercel.json` در ریشه مخزن مربوط به این پروژه نیستند و دست‌نخورده
باقی مانده‌اند. همه فایل‌های این پروژه در پوشه `private-messenger/` قرار دارند تا با تنظیمات
فعلی Vercel (که `public/` ریشه را منتشر می‌کند) تداخل نکنند.
پوشه `prototype/` نمونه اولیه ایستای فاز ۱ است و در بسته نصب قرار نمی‌گیرد.
