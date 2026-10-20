# API نسخه ۱ — فاز چهارم

نشانی نسبی `api.php?route=...`؛ مبتنی بر نشست هم‌مبدأ و کوکی HttpOnly/SameSite=Lax، بدون CORS عمومی یا کلید دسترسی شخص ثالث. مسیرهای فاز سوم حفظ شده‌اند.

## قرارداد

- خروجی موفق `{"ok":true,"data":...}`؛ خطا `{"ok":false,"error":{"message":"متن فارسی"}}`.
- ابتدا `GET session` برای CSRF و اطلاعات کاربر. همه POSTها JSON با هدر `X-CSRF-Token` و کوکی نشست هستند.
- پس از ورود/خروج، از CSRF جدید پاسخ استفاده کنید. حداکثر بدنه JSON برابر ۶۴ کیلوبایت است.
- کدهای خطای 400، 401، 403، 404، 405، 409، 413، 415، 419، 422، 429، 500، 503 متناسب با خطا.
- شناسه‌ها و زمان ماشینی با ارقام لاتین ارسال می‌شوند. تاریخ ماشین `YYYY-MM-DD` و زمان `YYYY-MM-DD HH:mm:ss` به وقت تهران است؛ زمان UTC نیست. فرم‌ها و فیلدهای صریحاً شمسی از `YYYY/MM/DD HH:mm` استفاده می‌کنند.
- هیچ فیلد ورودی role پزشک یا مدیر به حساب نمی‌دهد. پزشک فقط می‌تواند برای حساب تأییدشده موجود، دسترسی منشیِ مطب خود را تعیین کند.

| مسیر | روش | ورودی | خروجی |
|---|---|---|---|
| session | GET | — | csrf و user یا null |
| auth/request | POST | mobile | challenge_id، expires_in، resend_after |
| auth/verify | POST | challenge_id، code | id، csrf تازه، roles |
| auth/logout | POST | {} | csrf تازه |
| profile | POST | name | نتیجه ذخیره نام حساب عمومی |
| bookings | GET | role، page، clinic اختیاری | total و items؛ هر صفحه ۳۰ مورد |
| availability | GET | clinic، service، date میلادی | آرایه زمان‌های قابل رزرو؛ عمومی |
| booking/create | POST | clinic_id، service_id، starts_at میلادی، name | id رزرو؛ بیمار واردشده |
| doctor/calendar | GET | doctor، from و to میلادی، clinic اختیاری، history اختیاری | نوبت‌های محدوده مجاز |
| doctor/action | POST | doctor_id، action و فیلدهای جدول زیر | message؛ عملیات محدود به مالک/منشی مجاز |
| notifications | GET | — | آخرین ۱۰۰ اعلان مجاز کاربر |
| notifications/read | POST | id | ثبت خواندن فقط اعلان خود کاربر |

`availability` شامل starts_at، ends_at، label شمسی، duration، gap و service است. این پاسخ تضمین نگهداری ظرفیت نیست؛ `booking/create` تمام شرایط را مجدداً داخل تراکنش بررسی می‌کند. کارمزد مثبتِ فعال، تا تکمیل درگاه رزرو را متوقف می‌کند. هیچ مبلغ ویزیتی آنلاین دریافت نمی‌شود.

## عملیات پنل پزشک

همه سطرها از `doctor/action` استفاده می‌کنند. checkboxها را به‌صورت ۰/۱ ارسال کنید. شناسه id خالی/صفر در فرم خدمت یا مطب یعنی ایجاد. حذف فیزیکی خدمت یا مطب از پنل پزشک ارائه نشده؛ وضعیت active را تغییر دهید.

| action | فیلدها | دسترسی |
|---|---|---|
| profile | name، specialty_id، professional_title، education، bio، experience_years | پزشک مالک |
| photo_remove | ندارد | پزشک مالک |
| clinic | id اختیاری، clinic_name، province_id، city_id، address، phone، active، is_primary | پزشک مالک |
| clinic_settings | clinic_id، default_duration ۵..۱۸۰، gap_minutes ۰..۱۲۰، daily_capacity ۱..۵۰۰ | مالک یا منشی با مدیریت برنامه |
| service | id اختیاری، name، duration_minutes صفر یا ۵..۱۸۰، active | مالک |
| hours | clinic_id، weekday صفر=یکشنبه تا ۶=شنبه، opens_at و closes_at با قالب HH:mm | مالک یا منشی با مدیریت برنامه |
| hours_remove | clinic_id، id بازه حضور | همان |
| block | clinic_id، starts_at و ends_at **شمسی**، reason، kind از holiday/leave/closed، all_clinics اختیاری | همان؛ همه مطب‌ها فقط مالک |
| block_remove | clinic_id، id تعطیلی | مالک یا منشی مجاز همان مطب |
| staff | clinic_id، mobile، can_manage_schedule، can_manage_bookings، can_view_history | مالک |
| staff_remove | clinic_id، id کاربر منشی | مالک |
| booking | id نوبت، revision، operation، reason و موارد زیر | مالک یا منشی با مدیریت نوبت همان مطب |

تصویر از فرم multipart در `doctor-panel.php?doctor=ID&tab=profile` با action=photo، csrf و فایل photo بارگذاری می‌شود؛ JSON آپلود باینری ندارد. قالب JPEG/PNG، سقف ۳ مگابایت، ضلع حداکثر ۵۰۰۰ و حداکثر ۱۲ مگاپیکسل. GD تصویر را با ضلع حداکثر ۹۰۰ به JPEG تبدیل می‌کند. تصویر عمومی از `photo.php?id=ID` ارائه می‌شود.

### تغییر نوبت

- operation یکی از `cancelled`, `reschedule`, `completed`, `no_show` است.
- reason الزامی، حداکثر ۵۰۰ نویسه. لغو/جابه‌جایی به `patient_contacted=1` نیاز دارد؛ این تأیید عامل انسانی است و اثبات دریافت پیام توسط بیمار نیست.
- فقط وضعیت confirmed قابل تغییر است. revision باید برابر مقدار فعلی باشد؛ تعارض، HTTP 409 می‌دهد.
- completed و no_show قبل از شروع نوبت مجاز نیستند.
- reschedule فقط برای نوبت آینده و با `target_clinic_id`, `service_id`, `starts_at` **شمسی**؛ مقصد باید در زمان‌های قابل رزرو همان پزشک باشد. منشی به مطب مبدأ و مقصد هر دو نیاز به مجوز مدیریت نوبت دارد.
- ثبت تغییر، رویداد و اعلان داخلی در یک تراکنش انجام می‌شود؛ ارسال پیامک یا Push انجام نمی‌شود.

نمونه بدنه جابه‌جایی:

```json
{
  "doctor_id": 12,
  "action": "booking",
  "id": 125,
  "revision": 1,
  "operation": "reschedule",
  "reason": "با هماهنگی بیمار",
  "patient_contacted": 1,
  "target_clinic_id": 8,
  "service_id": 4,
  "starts_at": "1405/08/02 10:30"
}
```

شناسه‌ها و تاریخ مثال باید با داده واقعی و زمان آزاد جایگزین شوند. نتیجه درخواست قدیمی را کورکورانه تکرار نکنید؛ پس از 409، تقویم را دوباره دریافت کنید.

## محدوده مشاهده

بیمار فقط رزروهای patient_user_id خودش را می‌بیند. role در مسیر قدیمی bookings باید واقعاً به حساب تعلق داشته باشد. کلینیک فقط محدوده نقش کلینیک خودش را می‌بیند. منشی بدون can_view_history به نوبت‌ها و اعلان‌های مربوط به قبل از امروز دسترسی ندارد؛ این قاعده در API قدیمی نیز اعمال شده است.

API تقویم و پنل اختصاصی مالک، حتی در انقضای اشتراک یا انتظار تأیید مجدد، سوابق را حفظ و قابل مشاهده می‌کنند؛ پذیرش رزرو تازه شرایط انتشار و اشتراک را لازم دارد. مسیر قدیمی bookings برای نقش پزشک/کارمند، شرط تأیید و فعال‌بودن قبلی خود را حفظ می‌کند.

تقویم حداکثر ۹۳ روز و ۲۰۰۰ رکورد برمی‌گرداند؛ بازه بزرگ یا نتیجه بیش از سقف خطا می‌دهد. خروجی دارای starts_at_jalali و status_label فارسی است. جدول داده و شرایط مهاجرت در DATABASE-CHANGES.md شرح داده شده‌اند.

## امنیت و حفظ داده
- OTP شش‌رقمی با random_int، اعتبار ۱۸۰ ثانیه، حداکثر ۵ تلاش ناموفق و اتصال به نشست درخواست‌دهنده.
- درخواست دوباره: حداقل ۶۰ ثانیه، حداکثر ۵ درخواست برای هر شماره در ساعت و ۱۰ درخواست هر IP در ۱۵ دقیقه.
- سقف کل ارسال در بازه ۲۴ساعته از اولین درخواست، از پنل مدیر قابل تنظیم است؛ پیش‌فرض ۵۰۰.
- سقف بررسی کد هر IP: ۴۰ درخواست در ۱۵ دقیقه.
- کد خام به API یا لاگ داده نمی‌شود؛ فقط هش آن ذخیره می‌شود.
- نشست کاربر حداکثر ۱۲ ساعت اعتبار دارد. تعلیق کاربر نسخه نشست را تغییر می‌دهد و در درخواست بعدی همه نشست‌های قبلی باطل می‌شوند.
- رکوردهای قدیمی بدون احراز موبایل بر اساس شباهت شماره به حساب جدید منتسب نمی‌شوند.
- ارسال آزمایشی فقط با تزریق تابع از CLI در آزمون‌ها امکان‌پذیر است؛ هیچ کد ثابت، کد مادر یا حالت دورزدن ورود در صفحات وب وجود ندارد.


## مسیرهای جدید نسخه 0.6.1

همه POSTها JSON و CSRF نشست لازم دارند. کاربر باید OTP تأییدشده داشته باشد. شناسه ارسالی، مالکیت، ظرفیت، اشتراک و قوانین مجدداً سمت سرور بررسی می‌شوند.

| مسیر | روش | ورودی |
|---|---|---|
| doctors/search | GET عمومی | q، province، city، specialty_id، sort=newest/name/first، days=7/14/30/90، available=1، page |
| patient/family | GET | اعضای فعال حساب |
| patient/family/save | POST | name، relationship، birth_date شمسی اختیاری؛ id برای ویرایش؛ operation=archive برای آرشیو |
| patient/bookings | GET | filter=upcoming/past/held، page؛ صفحه ۲۰تایی |
| booking/hold | POST | clinic_id، service_id، starts_at میلادی Y-m-d H:i:s، name، family_member_id اختیاری؛ یا slot_id برای زمان دستی قدیمی |
| booking/confirm | POST | id؛ فقط کارمزد صفر، تکرار روی رزرو قطعی بدون ایجاد رکورد جدید |
| booking/payment | POST | id؛ پاسخ url درگاه، بدون قطعی‌کردن |
| patient/change | POST | id، revision، operation=release/cancelled/reschedule؛ reason برای لغو/انتقال؛ clinic_id، service_id، starts_at میلادی برای انتقال |

booking/create قبلی برای سازگاری رزرو رایگان حفظ شده است؛ برای جریان جدید ابتدا hold و سپس confirm یا payment استفاده کنید. رزرو با کارمزد مثبت هرگز با confirm قطعی نمی‌شود. callback در payment-callback.php مستقل از نشست، فقط authority ثبت‌شده را با تأیید سروری بررسی می‌کند.

clinic_settings اکنون slot_capacity از ۱ تا ۲۰ نیز دارد. API availability ظرفیت باقیمانده با احتساب hold زنده را برمی‌گرداند. همه زمان‌های API که صریحاً شمسی ذکر نشده‌اند، میلادی و به وقت تهران هستند. کد رهگیری به تنهایی مجوز دریافت رسید نیست.


## API پنل منشی 0.6.1

همه POSTها JSON + X-CSRF-Token و نشست معتبر می‌خواهند؛ همه عملیات در محدوده پزشک/مطب مجاز کنترل می‌شوند. ثبت و تغییر نیازمند can_manage_bookings، سوابق قبل از امروز نیازمند can_view_history است. مالک پزشک نیز به مطب‌های خودش دسترسی دارد.

| مسیر | روش | ورودی و نتیجه |
|---|---|---|
| reception/patients | GET | clinic، q حداقل ۲ نویسه؛ patients دفتر پذیرش و visitors سابقه همان مطب، هر بخش تا ۵۰ |
| reception/patient | POST | clinic_id، name، mobile، parent_id اختیاری و متعلق به همین مطب؛ id جدید |
| reception/calendar | GET | clinic، date میلادی، view=day/week، status اختیاری؛ حداکثر ۲۰۰۰ نوبت، در بیشتر از سقف خطا |
| reception/reserve | POST | clinic_id، patient_id، channel=phone/in_person؛ service_id و starts_at میلادی، یا slot_id دستی؛ نوبت قطعی معاف یا hold پرداخت |
| reception/change | POST | id، revision، reason، operation=arrived/in_visit/completed/no_show/cancelled/reschedule/release |
| reception/delay | POST | clinic_id، starts_at و ends_at شمسی با ساعت، minutes ۱..۳۶۰، reason؛ شناسه تأخیر |
| reception/contact | POST | id مخاطب تأخیر، note، contacted=1؛ ثبت تماس انسانی |
| reception/sms | POST | id مخاطب تأخیر؛ فقط ارسال نخست، وضعیت پذیرش سرویس مستقل از تحویل |

reschedule نیازمند target_clinic_id، service_id و starts_at **شمسی** است. cancelled و reschedule نیازمند patient_contacted=1 هستند. release فقط برای hold است. انتقال به مطب غیرمجاز، ظرفیت پر، تعطیلی، اشتراک نامعتبر یا زمان نامعتبر رد می‌شود. تاریخ GET calendar و زمان POST reserve میلادی‌اند؛ فرم‌های نمایشی شمسی تبدیل می‌کنند.

فیلتر status: waiting،arrived،in_visit،completed،cancelled،no_show،held،expired. هفته هفت روز از date انتخابی است. نگهداری status=confirmed هنگام حضور برای سازگاری موتور ظرفیت عمدی است.

رسید منشی secretary-receipt.php?id=... نیازمند همان مجوز محدوده است. پیوند بیمار reception-payment.php?code=... به‌تنهایی مجوز دیدن اطلاعات نیست؛ موبایل احرازشده یکسان و POST با CSRF برای اتصال لازم است. پس از اتصال، APIهای عادی بیمار و پرداخت استفاده می‌شوند. درگاه یا کارمزد به درخواست منشی واگذار نمی‌شود و فقط تنظیم مدیر معتبر است.


## مسیرهای جدید 0.7.0

همه مسیرها با `api.php?route=...` و پاسخ `{"ok":true,"data":...}` هستند. درخواست‌های POST بدنه JSON و هدر `X-CSRF-Token` لازم دارند.

| مسیر | روش | توضیح |
|---|---|---|
| `geo/provinces` | GET | استان‌های فعال به ترتیب نمایش |
| `geo/cities&province=ID` | GET | شهرهای فعال استان؛ مرکز استان اول. مدیر با `all=1` شهرهای غیرفعال را هم می‌گیرد |
| `geo/search&q=...` | GET | جستجوی سریع شهر؛ «ي» و «ك» عربی یکسان‌سازی می‌شوند |
| `geo/nearby&city=ID` | GET | شهرهای اطراف در شعاع تنظیم‌شده با فاصله به کیلومتر |
| `geo/select` | POST | `{city_id}` شهر بازدیدکننده را در کوکی و حساب ذخیره می‌کند |
| `specialties&city=ID` | GET | تخصص‌های دارای پزشک فعال در شهر، با تعداد پزشک |
| `auth/logout-all` | POST | باطل‌کردن همه نشست‌های حساب در همه دستگاه‌ها |

پاسخ `auth/request` اکنون `expires_in` و `resend_after` را از تنظیمات پنل مدیر برمی‌گرداند.
