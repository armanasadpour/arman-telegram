# ابزار اثبات اتصال IBSng (فاز ۱)

یک صفحه PHP مستقل و بدون وابستگی برای اثبات اتصال واقعی هاست cPanel به IBSng A1.24.

## چه کاری می‌کند
1. **محیط هاست:** نسخه PHP، افزونه‌ها، محدودیت‌ها (برای تأیید سازگاری با سامانه نهایی).
2. **شبکه:** اتصال TCP به `host:port`.
3. **خواندنی:** `admin.getAdminInfo` (احراز هویت)، `group.listGroups`، `group.getGroupInfo`، `charge.listCharges` و `listChargeRules`، `report.getOnlineUsers`، و در صورت تعیین، `user.getUserInfo` برای یک اکانت نمونه.
4. **نوشتن کنترل‌شده (اختیاری):** ساخت یک کاربر **قفل‌شده** با نام تصادفی `ibsprobe_xxxx` در گروه آزمایشی، ثبت ویژگی‌ها، خواندن مجدد، افزایش اعتبار ۱٫۵، و **حذف قطعی** (در بلوک `finally`) و تأیید حذف.

## نصب روی cPanel (بدون SSH)
1. پوشه `ibsng-probe` را ZIP کرده و در File Manager داخل `public_html` آپلود و Extract کنید.
2. `config.sample.php` را Copy کرده و نام کپی را `config.php` بگذارید؛ آن را Edit و تکمیل کنید (`host`، `port`، نام و رمز مدیر IBSng، `access_token` تصادفی حداقل ۲۴ کاراکتر).
3. آدرس `https://دامنه-شما/ibsng-probe/?token=توکن-شما` را باز کنید و «اجرای تست‌ها» را بزنید.
4. برای تست نوشتن: `allow_write_test` را `true` و `write_test_group` را نام یک گروه آزمایشی بگذارید، تیک را بزنید و «تایید» را تایپ کنید.
5. «اجرا و دریافت گزارش JSON» را بزنید و فایل را برای ما بفرستید (رمزها در گزارش حذف شده‌اند).
6. **پس از پایان، کل پوشه را حذف کنید.**

## پیش‌نیاز سمت IBSng
- **اگر cPanel و IBSng روی یک ماشین هستند:** در `config.php` مقدار `host` را `127.0.0.1` بگذارید؛ هیچ تغییری در IBSng و فایروال لازم نیست.
- IBSng به‌طور پیش‌فرض فقط روی `127.0.0.1` گوش می‌دهد. برای تست مستقیم از هاست، `IBS_SERVER_IP` را (از پنل IBSng → Advanced Configuration یا جدول defs) به IP شبکه تغییر دهید، IBSng را ری‌استارت کنید و در فایروال پورت 1235 را **فقط** برای IP هاست باز کنید. اگر ترجیح می‌دهید پورت باز نشود، گزینه Relay را در گزارش فاز ببینید.
- اگر مدیر IBSng محدودیت IP دارد، IP هاست cPanel را مجاز کنید.

## پیش‌نیاز سمت MikroTik (اختیاری، فقط خواندنی — RouterOS 7.7)
در Terminal میکروتیک (IP هاست cPanel را جایگزین `CPANEL_IP` و IP روتر را جایگزین `ROUTER_IP` کنید):
```
/certificate add name=rest-ca common-name=rest-ca key-usage=key-cert-sign,crl-sign
/certificate sign rest-ca
/certificate add name=rest common-name=ROUTER_IP subject-alt-name=IP:ROUTER_IP
/certificate sign rest ca=rest-ca
/ip service set www-ssl certificate=rest address=CPANEL_IP/32 disabled=no
/user group add name=panel-ro policy=read,api,rest-api
/user add name=panel-ro group=panel-ro address=CPANEL_IP/32 password=یک-رمز-قوی
```
سپس بخش `mikrotik` در `config.php` را با `enabled => true` تکمیل کنید و `cert_pin` را خالی بگذارید. بار اول ابزار **بدون ارسال رمز** اثر انگشت گواهی را نشان می‌دهد؛ آن را در `cert_pin` بگذارید و دوباره اجرا کنید. ابزار هیچ تغییری روی روتر ایجاد نمی‌کند و کلیدها/Secretها را از گزارش حذف می‌کند.

## امنیت
- صفحه بدون توکن خطای 403 می‌دهد؛ `config.php` و `src/` با `.htaccess` مسدودند.
- رمز مدیر در خروجی و گزارش با `***` جایگزین می‌شود؛ رمز کاربران IBSng هم حذف می‌شود.
- پروتکل IBSng رمزنگاری ندارد: تست مستقیم را فقط روی شبکه مطمئن یا به‌صورت موقت انجام دهید.

## اجرای تست‌های خودکار (توسعه‌دهنده)
```bash
bash tests/run_tests.sh   # نیازمند php و python3
```
`tests/mock_ibsng_server.py` شبیه‌ساز رفتار IBSng (بر اساس کد A1.24) است و **جایگزین اثبات روی سرور واقعی نیست**.
