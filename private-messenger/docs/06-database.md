# ۰۶ — طرح دیتابیس

پیش‌نویس کامل: [`database/schema-draft.sql`](../database/schema-draft.sql) (MySQL 8.0+ / MariaDB 10.6+،
InnoDB، utf8mb4). نسخه نهایی به‌صورت migration شماره‌دار در فاز ۲/۳ تولید می‌شود.

## ۱. اصول

1. **هیچ ستونی متن آشکار پیام، فایل یا کلید خصوصی ندارد.** تنها ستون‌های حاوی داده رمزنگاری‌شده:
   `message_envelopes.ciphertext`، `ephemeral_envelopes.ciphertext`، `key_backups.ciphertext`،
   `group_key_epochs.commit_ciphertext`، `admin_users.totp_secret_enc` (این آخری با کلید سرور؛ مربوط به مدیر، نه پیام).
2. **فایل‌ها هرگز در DB نیستند**؛ فقط فراداده blob رمزنگاری‌شده.
3. **حداقل‌سازی فراداده:** نوع رویداد (متن/واکنش/ویرایش/رسید خواندن)، نوع رسانه تماس، نام فایل و MIME ذخیره نمی‌شوند.
4. **حذف بعد از تحویل:** envelope پس از ack دستگاه گیرنده حذف می‌شود؛ تحویل‌نشده‌ها پس از `expires_at` (پیش‌فرض ۳۰ روز).
5. زمان‌ها UTC؛ `last_seen_at` و `last_activity_at` با دقت دقیقه برای کاهش دقت فراداده.
6. اسرار سرور (توکن نشست، OTP، توکن دانلود) فقط به‌صورت هش.

## ۲. نگاشت جداول پیشنهادی سند اولیه

| جدول پیشنهادی | وضعیت در طرح | توضیح |
|---|---|---|
| users | ✔ `users` | + `permanent_file_retention`، `registration_lock_until` |
| user_sessions | ✔ | توکن هش‌شده؛ حالت `device_pending` |
| otp_requests | ✔ | کد به‌صورت HMAC؛ برای شماره ناشناخته پیامک ارسال نمی‌شود |
| devices | ✔ | کلید هویت Olm و گواهی امضاشده با MSK |
| device_public_keys | ✔ | فقط one-time/fallback prekeys |
| conversations | ✔ | `type=direct` در v1 |
| conversation_members | ✔ | archive/mute (mute برای سرکوب push لازم است) |
| messages | ✔ | فقط فراداده؛ بدون نوع و بدون محتوا |
| message_envelopes | ✔ | یک ciphertext برای هر دستگاه مقصد |
| message_attachments | ✔ → `attachments` + `attachment_grants` | فیلدهای سیاست نگهداری طبق بند ۱۲ سند |
| message_receipts | ✔ (فقط «تحویل‌شده») | «خوانده‌شده» رویداد رمزنگاری‌شده است |
| message_reactions | ✘ **عمداً حذف** | واکنش رویداد رمزنگاری‌شده داخل envelope است؛ جدول آشکار فراداده محتوایی نشت می‌دهد |
| calls | ✔ | بدون نوع صوتی/تصویری |
| call_participants | ✔ | |
| groups | ↪ ادغام در `conversations.type='group'` | فاز ۶ |
| group_key_epochs | ✔ رزرو فاز ۶ | کلید اصلی `(conversation_id, epoch)` ترتیب commit را تضمین می‌کند |
| notifications | ✔ | رخدادهای امنیتی/سیستمی بدون محتوا |
| admin_users | ✔ | Argon2id + TOTP |
| roles / permissions | ✔ + `role_permissions` | **هیچ مجوز خواندن محتوا وجود ندارد** |
| audit_logs | ✔ | زنجیره هش، append-only |
| system_settings | ✔ | کلید/مقدار JSON |
| upload_chunks | ✔ | فقط دفترداری؛ داده روی دیسک |
| storage_usage | ✔ | تفکیک سهمیه و فضای پارتیشن |

**جداول افزوده:** `rate_limits`، `user_master_keys`، `key_backups`، `device_link_requests`،
`ephemeral_envelopes`، `push_subscriptions`، `attachment_grants`، `download_tokens`، `admin_sessions`،
`cron_runs`، `job_locks`.

## ۳. نکات طراحی مهم

### ۳.۱ تخصیص one-time key بدون رقابت
```sql
START TRANSACTION;
SELECT id, key_id, public_key, signature FROM device_public_keys
 WHERE device_id = ? AND key_type = 'one_time' AND claimed_at IS NULL
 ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED;
UPDATE device_public_keys SET claimed_at = NOW(3), claimed_by_device = ? WHERE id = ?;
COMMIT;
-- اگر یافت نشد: fallback key برگردانده می‌شود (بدون علامت‌گذاری مصرف).
```

### ۳.۲ اعتبارسنجی fan-out
در `POST /messages` در یک تراکنش: مجموعه `devices.id` با `status='active'` برای همه اعضای فعال گفتگو
(به‌جز دستگاه فرستنده) باید دقیقاً با `recipient_device_id`های ارسالی برابر باشد؛ در غیر این صورت `409`.

### ۳.۳ sync
```sql
SELECT id, message_id, olm_type, ciphertext FROM message_envelopes
 WHERE recipient_device_id = ? AND id > ? ORDER BY id LIMIT 100;
```
پیش از آن، مقایسه سریع `devices.last_event_id` با cursor برای پاسخ `204` بدون کوئری سنگین.

### ۳.۴ سیاست نگهداری فایل (خلاصه؛ جزئیات در سند ۰۸)
- تکمیل آپلود: `uploaded_at = now`؛ اگر `users.permanent_file_retention = 1` ← `retention_permanent=1, expires_at=NULL`، وگرنه `expires_at = uploaded_at + 7d`.
- فعال‌سازی نگهداری دائمی برای حساب: همه فایل‌های `complete` و منقضی‌نشده حساب ← `retention_permanent=1, expires_at=NULL`.
- لغو: `retention_permanent=0, expires_at = now + 7d` (مبنای پیش‌فرض: زمان لغو؛ قابل تنظیم).
- دسترسی دانلود: `upload_status='complete' AND (retention_permanent=1 OR expires_at > NOW(3))` — **مستقل از اجرای Cron**.

### ۳.۵ audit log زنجیره‌ای
`entry_hash = SHA-256(prev_hash ‖ canonical_json(actor,action,target,details,created_at))`؛ درج با قفل
(`GET_LOCK('audit')`) برای ترتیب. بررسی یکپارچگی در پنل و صدور دوره‌ای هش سر زنجیره.

## ۴. برآورد حجم (۲۰۰ کاربر، ۵۰۰۰ پیام/روز، ۲ دستگاه میانگین)

- `messages`: ~۱.۸ میلیون ردیف/سال × ~۸۰ بایت ≈ ۱۵۰MB/سال (قابل پاک‌سازی دوره‌ای فراداده قدیمی با سیاست جدا).
- `message_envelopes`: پس از تحویل حذف می‌شوند؛ اندازه پایدار کوچک.
- فایل‌ها: خارج از DB.
