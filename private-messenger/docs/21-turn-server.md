# راه‌اندازی سرور TURN برای تماس‌ها

## چرا لازم است؟
تماس صوتی و تصویری مستقیم بین دو گوشی برقرار می‌شود (WebRTC). اینترنت همراه (همراه اول، ایرانسل، رایتل) و بسیاری از شبکه‌های اداری پشت NAT سخت‌گیر هستند و اتصال مستقیم را اجازه نمی‌دهند. در این حالت تماس روی «در حال اتصال…» می‌ماند و بعد پیام «اتصال تماس برقرار نشد… سرور TURN» می‌آید.

سرور TURN صدا و تصویر را بین دو طرف رله می‌کند. محتوای تماس همچنان سرتاسری رمزنگاری‌شده است (DTLS-SRTP). سرور TURN فقط بسته‌های رمزشده را جابه‌جا می‌کند و نمی‌تواند صدا یا تصویر را ببیند یا بشنود.

هاست اشتراکی cPanel نمی‌تواند TURN اجرا کند، چون به یک برنامه همیشه‌روشن و پورت‌های UDP نیاز دارد. یک سرور مجازی (VPS) لینوکس کوچک کافی است: ۱ هسته و ۱ گیگابایت رم.

**پیشنهاد:** سرور را در **دیتاسنتر داخل ایران** بگیرید. دسترسی گوشی‌های داخل ایران به آن پایدارتر است، فیلتر نمی‌شود و تأخیر صدا کمتر است.

## نصب coturn روی Ubuntu
```bash
sudo apt-get update && sudo apt-get install -y coturn
openssl rand -hex 32        # راز مشترک؛ خروجی را نگه دارید
```

فایل `/etc/turnserver.conf` را با این محتوا بنویسید. `turn.example.ir`، IP و راز را با مقادیر خودتان عوض کنید:
```
listening-port=3478
tls-listening-port=5349
fingerprint
use-auth-secret
static-auth-secret=<همان راز ۶۴ کاراکتری>
realm=turn.example.ir
total-quota=200
stale-nonce=600
no-cli
no-multicast-peers
# اگر سرور پشت NAT است (IP عمومی روی کارت شبکه نیست):  external-ip=<IP عمومی>/<IP داخلی>
# جلوگیری از سوءاستفاده برای دسترسی به شبکه‌های داخلی:
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
# برای turns (TLS) — گواهی Let's Encrypt با certbot:
cert=/etc/letsencrypt/live/turn.example.ir/fullchain.pem
pkey=/etc/letsencrypt/live/turn.example.ir/privkey.pem
```

سپس:
```bash
sudo sed -i 's/^#TURNSERVER_ENABLED=1/TURNSERVER_ENABLED=1/' /etc/default/coturn
sudo systemctl enable --now coturn
# فایروال: 3478 و 5349 (UDP و TCP) و بازه رله UDP
sudo ufw allow 3478 && sudo ufw allow 5349 && sudo ufw allow 49152:65535/udp
```

برای اینکه coturn بتواند فایل‌های گواهی را بخواند:
```bash
sudo certbot certonly --standalone -d turn.example.ir
sudo chmod -R o+rx /etc/letsencrypt/live /etc/letsencrypt/archive
```

## تنظیم در پنل مدیریت
پنل مدیریت ← تنظیمات ← «تماس صوتی و تصویری»:

| فیلد | مقدار |
|---|---|
| سرورهای STUN | `stun:turn.example.ir:3478` (به‌جای STUN گوگل، که ممکن است از ایران در دسترس نباشد) |
| سرور TURN | یک خط `turn:turn.example.ir:3478?transport=udp`<br>یک خط `turn:turn.example.ir:3478?transport=tcp`<br>یک خط `turns:turn.example.ir:5349?transport=tcp` |
| نوع احراز هویت TURN | راز مشترک (coturn use-auth-secret) |
| راز مشترک TURN | همان راز |

ذخیره کنید و دکمه **«آزمایش STUN / TURN از همین مرورگر»** را بزنید. باید هر دو مورد ✅ شوند. برای اطمینان، صفحه را یک بار هم روی یک گوشی با اینترنت همراه باز کنید و آزمایش را تکرار کنید.

برنامه برای هر کاربر رمز TURN کوتاه‌مدت (یک ساعته) می‌سازد. راز مشترک هیچ‌وقت به گوشی‌ها فرستاده نمی‌شود.

## گزینه «همه تماس‌ها فقط از طریق TURN»
با این گزینه IP کاربران از یکدیگر پنهان می‌ماند، ولی همه ترافیک تماس از سرور TURN عبور می‌کند. حدود ۵۰ کیلوبیت بر ثانیه برای صوت و ۱ تا ۲ مگابیت بر ثانیه برای تصویر در هر تماس.
