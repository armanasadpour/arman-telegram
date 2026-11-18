# یافته‌های فنی IBSng A1.24 و ماتریس قابلیت‌ها

> منبع: **کد منبع رسمی** `IBSng-A1.24.tar.bz2` از پروژه SourceForge «IBS» (همان IBS NextGeneration).
> هر متد و پارامتر این سند مستقیماً از فایل‌های `core/*_handler.py` همین نسخه استخراج شده و در ستون «منبع» آدرس فایل آمده است.
> **هیچ‌چیز حدسی نیست؛ اما تا اجرای ابزار اثبات اتصال روی سرور واقعی شما، وضعیت هر قابلیت «اثبات‌شده در کد» است، نه «اثبات‌شده در عمل».**

---

## ۱. شناسنامه نسخه

| مورد | یافته | منبع |
|---|---|---|
| نسخه | A1.24 (آخرین تغییر ثبت‌شده: 2006-07-12) | `docs/CHANGE_LOG` |
| زبان سرور | Python 2 (هسته) + PHP (رابط وب مدیریت) + PostgreSQL | ساختار پروژه |
| مجوز | GPL — در کد هیچ بررسی لایسنس، محدودیت تعداد کاربر یا قفل «Free Edition» وجود ندارد. CHANGE_LOG همین نسخه را «A1.24 Enterprise Release» نامیده است. | `docs/COPYING`، جست‌وجوی کامل کد |
| احراز هویت VPN | سرور RADIUS داخلی (پورت 1812/1813)؛ NAS (میکروتیک، PPTP/L2TP، Cisco و …) به آن وصل می‌شود | `defs_defaults.py` |

**نتیجه مهم:** محدودیت عملی نسخه Free، محدودیت لایسنس نیست؛ محدودیت **قدمت نرم‌افزار** است (Python 2، بدون TLS، بدون API مدرن). اگر نسخه نصب‌شده روی سرور شما پچ یا تغییر داده شده باشد، ابزار اثبات اتصال آن را آشکار می‌کند.

---

## ۲. روش ارتباط واقعی

| مورد | مقدار | منبع |
|---|---|---|
| پروتکل | **XML-RPC روی HTTP ساده** (بدون TLS) | `core/server/xmlrpcserver.py` |
| آدرس | `POST http://<IBS_SERVER_IP>:1235/` | `IBS_SERVER_PORT=1235` |
| IP پیش‌فرض گوش‌دادن | **`127.0.0.1`** — یعنی در حالت پیش‌فرض از بیرون سرور قابل دسترسی نیست | `IBS_SERVER_IP` در `defs_defaults.py` |
| ساختار هر فراخوانی | یک پارامتر struct شامل `auth_name`، `auth_pass`، `auth_type="ADMIN"` + آرگومان‌های متد | `core/server/request.py` |
| نشست | ندارد؛ **هر درخواست جداگانه احراز هویت می‌شود** | همان |
| محدودیت IP مدیر | `admin.checkAuth(pass, remote_addr)` → `canLogin(remote_addr)`؛ مدیر می‌تواند به IP خاص محدود باشد | `core/admin/admin.py` |
| خطای منطقی | XML-RPC Fault با `faultCode=1` و متن خطا | `handlers_manager.py` |
| خطای داخلی | HTTP 500 با بدنه خالی | `xmlrpcserver.py` |
| مقدار None | به رشته خالی `""` تبدیل می‌شود (پروتکل `<nil/>` پشتیبانی نمی‌شود) | `__returnResponse` |
| فرمت تاریخ | پارامتر اختیاری `date_type`: `gregorian` (پیش‌فرض) / `jalali` / `relative` | `request.getDateType` |
| TRUSTED_CLIENTS | فقط برای پذیرفتن `auth_remoteaddr`؛ کلاینت‌های دیگر هم می‌توانند وصل شوند | `request.getRemoteAddr` |

### پیامدهای امنیتی (الزامی برای طراحی)

1. **رمز مدیر IBSng به‌صورت متن ساده در هر درخواست** ارسال می‌شود. ارسال آن روی اینترنت عمومی **مجاز نیست**.
2. برای اتصال هاست cPanel به IBSng یکی از این مسیرها لازم است (انتخاب با شما — بخش تصمیم‌ها در گزارش فاز):
   - **الف) هر دو روی یک شبکه خصوصی/تونل** (هاست و IBSng در یک دیتاسنتر یا VPN سایت‌به‌سایت) + باز کردن 1235 فقط برای IP هاست.
   - **ب) Relay امن روی خود سرور IBSng (پیشنهادی):** یک فایل PHP کوچک روی وب‌سرور همان سرور IBSng (که برای پنل IBSng از قبل Apache+PHP دارد) پشت HTTPS، با امضای HMAC و محدودیت IP؛ این فایل درخواست را به `127.0.0.1:1235` می‌فرستد. پورت 1235 هرگز به اینترنت باز نمی‌شود.
   - **ج) باز کردن مستقیم 1235 با محدودیت IP در فایروال:** فقط به‌عنوان راه موقت تست؛ رمز روی شبکه رمزنگاری نمی‌شود.
3. یک **مدیر اختصاصی** در IBSng برای سامانه بسازید، به IP هاست محدودش کنید و فقط مجوزهای لازم را بدهید.

---

## ۳. فهرست کامل متدهای API (استخراج‌شده از کد)

| Handler | متدها | فایل |
|---|---|---|
| `user` | addNewUsers, getUserInfo, updateUserAttrs, changeCredit, searchUser, delUser, killUser, calcApproxDuration | `core/user/user_handler.py` |
| `normal_user` | checkNormalUsernameForAdd, changePassword | `core/user/plugins/normal_user.py` |
| `group` | addNewGroup, listGroups, getGroupInfo, updateGroup, updateGroupAttrs, delGroup | `core/group/group_handler.py` |
| `charge` | addNewCharge, listCharges, getChargeInfo, updateCharge, addInternetChargeRule, updateInternetChargeRule, listChargeRules, delChargeRule, delCharge, … | `core/charge/charge_handler.py` |
| `report` | getOnlineUsers, getConnections, getDurations, getGroupUsages, getRasUsages, getInOutUsages, getCreditUsages, getDurationUsages, getCreditChanges, getUserAuditLogs, getAdminDepositChangeLogs, … | `core/report/report_handler.py` |
| `admin` | addNewAdmin, getAdminInfo, changePassword, getAllAdminUsernames, updateAdminInfo, changeDeposit, deleteAdmin, lockAdmin, unlockAdmin | `core/admin/admin_handler.py` |
| `perm` | hasPerm, canDo, getPermsOfAdmin, getAllPerms, changePermission, … | `core/admin/perm_handler.py` |
| `login` | login | `core/login/login_handler.py` |
| `snapshot` / `stat` | getRealTimeSnapShot, getOnlinesSnapShot, getBWSnapShot, getStatistics | `core/snapshot`, `core/stats` |
| `ras`, `ippool`, `bw`, `message`, `ibs_defs`, `voip_*`, `ias`, `util` | زیرساخت/خارج از دامنه پروژه (به‌جز `message` که اختیاری است) | — |

### آرگومان‌های متدهای کلیدی

| متد | آرگومان‌های الزامی | خروجی |
|---|---|---|
| `user.addNewUsers` | `count, credit, owner_name, group_name, credit_comment` | لیست `user_id` (کاربر **خام و بدون نام کاربری**) |
| `user.updateUserAttrs` | `user_id, attrs{…}, to_del_attrs[…]` | `""` |
| `user.getUserInfo` | یکی از `user_id` / `normal_username` / `voip_username` (چندتایی با MultiStr مجاز) | `{ "<user_id>": {basic_info, attrs, raw_attrs?} }` |
| `user.changeCredit` | `user_id, credit, credit_comment` (credit **افزایشی**) | `""` |
| `user.delUser` | `user_id, delete_comment, del_connection_logs, del_audit_logs` | `""` |
| `user.killUser` | `user_id, ras_ip, unique_id_val, kill` | — |
| `user.searchUser` | `conds, from, to, order_by, desc` | `(تعداد, لیست user_id)` |
| `normal_user.checkNormalUsernameForAdd` | `normal_username, current_username` | — |
| `report.getOnlineUsers` | `normal_sort_by, normal_desc, voip_sort_by, voip_desc, conds` | `[ لیست آنلاین اینترنتی, لیست VoIP ]` (شامل `in_bytes`, `out_bytes`, `login_time_epoch`, `current_credit` …) |
| `group.getGroupInfo` | `group_name` | اطلاعات + attrs گروه |

`basic_info` شامل: `user_id, owner_id, credit, group_id, creation_date, group_name, owner_name, online_status`.

### ویژگی‌های (attrs) کاربر — کلید دقیق

| کلید(ها) | معنا |
|---|---|
| `normal_username`, `normal_password`, `normal_generate_password`, `normal_generate_password_len`, `normal_save_usernames` | نام کاربری/رمز اینترنت (هر پنج کلید با هم ارسال می‌شوند) |
| `rel_exp_date`, `rel_exp_date_unit` (`Hours`/`Days`/`Months`/`Years`) | انقضای نسبی **از اولین اتصال** (`first_login` خودکار ثبت می‌شود) |
| `abs_exp_date`, `abs_exp_date_unit` | تاریخ انقضای مطلق |
| `multi_login` (0 تا 255) | حداکثر اتصال همزمان |
| `lock` (متن دلیل) | غیرفعال‌سازی؛ وجود کلید = قفل |
| `normal_charge` | تعرفه (Charge) اینترنت |
| `traffic_periodic_accounting_daily/monthly(_limit)` و نسخه‌های `time_…` | سقف دوره‌ای روزانه/ماهانه (با ریست دوره‌ای) |
| `group_name`, `owner_name`, `comment`, `name`, `phone`, `email_address` | اطلاعات عمومی |
| `idle_timeout`, `session_timeout`, `ippool`, `assign_ip`, `limit_mac`, `limit_station_ip`, `radius_attrs` | تنظیمات فنی اتصال |

---

## ۴. ماتریس قابلیت‌ها

وضعیت‌ها: ✅ پشتیبانی مستقیم · 🟡 پشتیبانی با روش جایگزین/وابسته به پیکربندی · ❌ وجود ندارد.
**همه موارد تا اجرای ابزار اثبات روی سرور شما «اثبات در کد» هستند.**

| قابلیت پروژه | وضعیت | روش در IBSng | ملاحظات |
|---|---|---|---|
| ایجاد اکانت | ✅ | `addNewUsers` + `updateUserAttrs` | **دو مرحله‌ای و غیراتمیک** → نیاز به Saga و Reconciliation (بخش ۵) |
| مشاهده اکانت | ✅ | `getUserInfo` (تکی و دسته‌ای) | |
| جستجو | ✅ | `searchUser` | ساختار `conds` در فاز ۳ با تست تکمیل می‌شود |
| ویرایش ویژگی‌ها | ✅ | `updateUserAttrs` | |
| غیرفعال/فعال‌سازی | ✅ | افزودن/حذف `lock` | |
| حذف | ✅ | `delUser` | اعتبار باقی‌مانده به deposit مدیر برمی‌گردد |
| تغییر رمز | ✅ | `updateUserAttrs` با کلیدهای `normal_*` | باید نام کاربری فعلی هم ارسال شود؛ در تست کنترل‌شده تأیید می‌شود |
| تاریخ انقضا | ✅ | `abs_exp_date` یا `rel_exp_date` | سیاست «شروع از اولین اتصال» با `rel_exp_date` ممکن است |
| روزهای باقی‌مانده | ✅ | محاسبه از `abs_exp_date` یا `first_login + rel_exp_date` | |
| تمدید | ✅ | به‌روزرسانی تاریخ انقضا | سیاست (از امروز / از انقضای فعلی) در سامانه ما قابل تنظیم است |
| **حجم کل / باقی‌مانده** | 🟡 | **مدل اعتبار (credit) + تعرفه حجمی**: قانون تعرفه با `cpm=0` و `cpk>0` (اعتبار به‌ازای هر کیلوبایت). مثلاً `cpk = 1/1024` ⇒ هر واحد credit = ۱ مگابایت | IBSng ویژگی «سقف حجم کل» مستقل ندارد. باید در IBSng یک Charge حجمی تعریف شود و NAS گزارش Accounting (Interim-Update) بفرستد |
| افزایش حجم | 🟡 | `changeCredit` (افزایشی) | وابسته به مدل credit بالا |
| حجم مصرف‌شده | 🟡 | (حجم خریداری‌شده − credit فعلی) + گزارش `getInOutUsages`/`getConnections` | دقت وابسته به Accounting NAS |
| سقف ماهانه با ریست | ✅ | `traffic_periodic_accounting_monthly_limit` | برای بسته‌های «ماهانه با سهمیه» |
| تعداد اتصال همزمان | ✅ | `multi_login` | NAS هم باید multi-login را مجاز کند |
| وضعیت آنلاین/آفلاین | ✅ | `getOnlineUsers` و `online_status` | |
| قطع اتصال کاربر | ✅ | `killUser` | |
| گروه‌ها | ✅ | `listGroups`, `getGroupInfo`, `updateGroupAttrs` | بسته‌های تجاری به گروه نگاشت می‌شوند |
| تعرفه‌ها | ✅ | `listCharges`, `listChargeRules` | |
| گزارش اتصال و مصرف | ✅ | `getConnections`, `getInOutUsages`, `getCreditChanges` | |
| محدودیت سرعت | 🟡 | ماژول `bw` (tc روی سرور IBSng) یا `radius_attrs` میکروتیک | خارج از دامنه نسخه اول مگر به تأیید شما |
| Webhook / رویداد | ❌ | — | همگام‌سازی فقط با Polling از Cron |
| کلید Idempotency | ❌ | — | در سامانه ما پیاده‌سازی می‌شود |
| TLS / توکن API | ❌ | — | Relay یا شبکه خصوصی (بخش ۲) |
| تراکنش اتمیک چندمرحله‌ای | ❌ | — | Saga + جبران (delUser) |

---

## ۵. ریسک غیراتمیک بودن ساخت اکانت و راه‌حل

ساخت اکانت = (۱) `addNewUsers` → user_id خام، (۲) `updateUserAttrs` → نام کاربری، رمز، انقضا، multi_login، credit. اگر مرحله ۲ شکست بخورد یا پاسخ مرحله ۱ به ما نرسد (Timeout)، کاربر «یتیم» در IBSng می‌ماند.

راه‌حل طراحی‌شده:
1. نام کاربری **قبل از هر فراخوانی** در جدول `provisioning_jobs` ثبت و قفل می‌شود (یکتا).
2. شروع هر تلاش: ابتدا `getUserInfo(normal_username)`؛ اگر وجود داشت و `comment` آن شناسه job ما بود → همان را می‌پذیریم (بدون ساخت دوباره).
3. `credit_comment` در `addNewUsers` حاوی شناسه job است ← در `getCreditChanges` قابل ردیابی برای یافتن کاربران خام یتیم.
4. user_id بلافاصله پس از مرحله ۱ ذخیره می‌شود؛ مرحله ۲ با همان user_id تکرار می‌شود.
5. کاربر خام تا تکمیل مرحله ۲ بدون نام کاربری است و نمی‌تواند وصل شود. شکست نهایی → `delUser` جبرانی + بازگشت اعتبار کیف پول (در فاز ۵).
6. Cron تطبیق دوره‌ای، کاربران خامِ ساخته‌شده توسط مدیر سامانه را گزارش می‌کند.

---

## ۶. سایر محدودیت‌ها

- **کارایی:** سرور تک‌پردازه با Thread Pool در Python 2؛ همگام‌سازی باید دسته‌ای (`getUserInfo` با چند user_id) و با نرخ محدود باشد.
- **Deposit مدیر:** ساخت کاربر با credit و `changeCredit` از deposit مدیر کم می‌کند (مگر مدیر God باشد) — مدیر سامانه باید deposit کافی یا دسترسی مناسب داشته باشد.
- **کاراکترهای نام کاربری** توسط `checkNormalUsernameChars` محدود است؛ سامانه فقط `[a-z0-9_]` تولید می‌کند.
- **منطقه زمانی:** تاریخ‌ها بر اساس ساعت سرور IBSng هستند؛ اختلاف ساعت دو سرور در فاز ۳ بررسی و ثبت می‌شود.
