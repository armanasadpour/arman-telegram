# ۰۷ — طراحی API نسخه ۱

## ۱. قراردادهای عمومی

- پایه: `https://<host>/<APP_PATH>/api/v1/` (کاربر) و `https://<host>/<ADMIN_PATH>/…` (مدیریت). هر دو مسیر مخفی و تصادفی‌اند؛ هر آدرس دیگر 404 می‌دهد (سند ۱۱).
- قالب: JSON (`application/json; charset=utf-8`)؛ داده باینری داخل JSON با **base64url**؛ chunk فایل با
  `application/octet-stream` خام.
- احراز هویت: کوکی `__Host-pm_session` (وب). برای کلاینت بومی آینده: `Authorization: Bearer`.
- همه درخواست‌های تغییردهنده: هدر `X-PM-Request: 1` (CSRF) و در صورت امکان `Idempotency-Key`.
- شناسه‌ها: `public_id` تصادفی.
- خطا:
  ```json
  {"error":{"code":"devices_mismatch","message":"…فارسی…","details":{…}}}
  ```
  کدهای HTTP: 400 ورودی، 401 احراز، 403 مجوز/وضعیت حساب، 404 (برای منابع غیرمجاز هم 404 تا وجودشان افشا نشود)،
  409 تعارض، 410 منقضی، 413 بزرگ‌تر از ظرفیت، 422 اعتبارسنجی، 429 محدودیت نرخ (با `Retry-After`)، 507 فضای ناکافی.
- **قاعده:** هیچ endpoint‌ای فیلد متنی برای محتوای پیام ندارد؛ محتوا فقط `ciphertext` است.

## ۲. احراز هویت

| متد | مسیر | توضیح |
|---|---|---|
| POST | `/login` (فرم) | ورود با نام کاربری/رمز عبور در حالت `password` و `password_otp` |
| POST | `/auth/otp/request` | `{phone}` ← همیشه `202` با پاسخ یکسان (حتی برای شماره ناشناخته/غیرفعال). rate limit: شماره ۱/۶۰ثانیه و ۵/ساعت؛ IP ۲۰/ساعت |
| POST | `/auth/otp/verify` | `{phone, code, device_label}` ← نشست `device_pending` + `{account_state, has_identity, has_backup}`؛ ۵ تلاش برای هر OTP |
| POST | `/auth/logout` | باطل‌سازی نشست فعلی |
| GET | `/auth/sessions` | نشست‌های من |
| DELETE | `/auth/sessions/{id}` | خروج نشست دیگر |

## ۳. هویت، دستگاه و کلید

| متد | مسیر | توضیح |
|---|---|---|
| PUT | `/me/identity` | ثبت MSK عمومی (فقط اگر هویتی ندارد یا پس از reset). `{msk_public}` |
| POST | `/devices` | ثبت دستگاه: `{identity_key, signing_key, certificate, certificate_sig, one_time_keys[], fallback_key}`؛ گواهی باید با MSK فعال کاربر معتبر باشد (سرور با `sodium_crypto_sign_verify_detached` بررسی می‌کند — دفاع اضافه) |
| GET | `/devices` | دستگاه‌های من |
| DELETE | `/devices/{id}` | لغو دستگاه |
| PUT | `/me/device-list` | `{version, device_ids[], sig}` فهرست امضاشده با MSK |
| POST | `/devices/current/one-time-keys` | تکمیل OTKها |
| GET | `/devices/current/key-count` | تعداد OTK باقی‌مانده |
| GET | `/users/{id}/keys` | `{msk_public, msk_history_ids, device_list{version,sig}, devices[{id, identity_key, signing_key, certificate, sig, protocols}]}` |
| POST | `/keys/claim` | `{device_ids[]}` ← برای هر دستگاه یک OTK یا fallback |
| PUT | `/me/key-backup` | `{version, algorithm, kdf_params?, ciphertext}` |
| GET | `/me/key-backup` | آخرین پشتیبان (فقط برای نشست احرازشده) |
| POST | `/device-links` | ایجاد درخواست اتصال توسط دستگاه جدید |
| GET | `/device-links/{id}` | وضعیت (polling دستگاه جدید) |
| POST | `/device-links/{id}/approve` | توسط دستگاه قدیمی، همراه گواهی امضاشده |

## ۴. کاربران، پروفایل و گفتگوها

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/me` | پروفایل و تنظیمات حریم خصوصی |
| PATCH | `/me/profile` | نام نمایشی، توضیحات، avatar (avatar در v1 رمزنگاری‌نشده است و در UI گفته می‌شود) |
| GET | `/directory?q=` | فهرست اعضای فعال مجموعه (نام و avatar) |
| GET | `/conversations` | فهرست گفتگوها با اعضا، archive، mute |
| POST | `/conversations` | `{type:"direct", user_id}` ← idempotent بر اساس جفت کاربر |
| PATCH | `/conversations/{id}/me` | `{archived?, muted_until?}` |

## ۵. پیام و همگام‌سازی

### `POST /messages`
```json
{
  "conversation_id": "01J…",
  "client_msg_id": "base64url(16 bytes)",
  "protocol_version": 1,
  "notify": true,
  "attachment_ids": ["9f2c…"],
  "envelopes": [
    {"device_id": "01J…", "olm_type": 0, "ciphertext": "base64url…"},
    {"device_id": "01J…", "olm_type": 1, "ciphertext": "base64url…"}
  ]
}
```
پاسخ‌ها: `201 {message_id, created_at}`؛ `409 devices_mismatch {missing:[…], stale:[…]}`؛ تکراری بودن
`client_msg_id` ← همان پاسخ قبلی. ثبت `attachment_grants` برای `attachment_ids` (فقط اگر فرستنده مالک
آن یا عضو گفتگوی دارای grant باشد).

### `GET /sync?cursor=<opaque>&wait=0|20`
```json
{
  "cursor": "…",
  "envelopes": [{"id":"…","message_id":"…","conversation_id":"…","sender_user_id":"…",
                 "sender_device_id":"…","olm_type":1,"ciphertext":"…","created_at":"…"}],
  "ephemeral": [{"id":"…","channel":"call","call_id":"…","sender_device_id":"…","ciphertext":"…"}],
  "receipts":  [{"message_id":"…","user_id":"…","delivered_at":"…"}],
  "notifications": [{"type":"contact_identity_changed","subject_user_id":"…"}],
  "key_changes": ["<user_id>"],
  "next_poll_ms": 2500
}
```
`wait>0` فقط اگر long-poll در تنظیمات فعال باشد. `next_poll_ms` از سرور برای کنترل بار.

### سایر
| متد | مسیر | توضیح |
|---|---|---|
| POST | `/sync/ack` | `{envelope_ids[], ephemeral_ids[]}` ← حذف envelopeها و ثبت receipt تحویل |
| DELETE | `/messages/{id}/pending` | حذف envelopeهای هنوز تحویل‌نشده پیام خودم (مکمل رویداد رمزنگاری‌شده «حذف برای همه») |
| POST | `/ephemeral` | `{conversation_id, channel, call_id?, envelopes[]}` (تایپ و signaling) |

## ۶. پیوست‌ها

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/attachments/limits` | `{chunk_size, max_parallel, quota_known, free_bytes_estimate}` — بدون سقف نرم‌افزاری حجم |
| POST | `/attachments` | `{conversation_id, size_bytes, chunk_count, purpose}` ← `201 {attachment_id, chunk_size}`؛ `507` اگر فضای کافی نیست |
| PUT | `/attachments/{id}/chunks/{index}` | بدنه خام؛ هدر `X-Chunk-SHA256`؛ idempotent (همان chunk با همان هش ← 200) |
| GET | `/attachments/{id}/status` | `{received:[0,1,2,5…], upload_status}` برای ادامه آپلود |
| POST | `/attachments/{id}/complete` | `{ciphertext_sha256}` ← بررسی کامل بودن و هش؛ تعیین `uploaded_at` و `expires_at` |
| DELETE | `/attachments/{id}` | لغو/حذف توسط آپلودکننده |
| POST | `/attachments/{id}/download-token` | بررسی عضویت در یکی از گفتگوهای grant و عدم انقضا ← `{token, expires_in:300}` |
| GET | `/blobs/{id}?t=<token>` | دانلود ciphertext با پشتیبانی `Range`؛ `410` برای منقضی |

## ۷. تماس

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/calls/ice-config` | `{ice_servers:[{urls:["stun:…"]},{urls:["turn:…"],username,credential}], relay_only_allowed, ttl}` — اعتبار TURN موقت (HMAC، سبک coturn `use-auth-secret`) |
| POST | `/calls` | `{conversation_id}` ← `{call_id}` (وضعیت ringing) |
| PATCH | `/calls/{id}` | `{status: active|ended|rejected|cancelled|failed, end_reason?}` |
| GET | `/calls?before=` | تاریخچه تماس‌های من |

SDP و ICE فقط از `/ephemeral` با `channel=call` و **رمزنگاری Olm** ارسال می‌شوند.

## ۸. اعلان و سیستم

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/system/config` | نام، لوگو، رنگ، قابلیت‌های فعال (`long_poll`، `turn`، `video_transcode_client`…)، حداقل نسخه کلاینت |
| POST/DELETE | `/push/subscriptions` | ثبت/حذف Web Push (payload push بدون محتوا) |
| GET | `/notifications` | رخدادهای امنیتی |

## ۹. API مدیریت (`/admin/api/v1`)

| متد | مسیر | مجوز |
|---|---|---|
| POST | `/auth/login` `{username,password,totp}` | — |
| GET | `/dashboard` | `dashboard.view` — شمارش‌ها، آنلاین تقریبی، تماس‌ها، فضا، خطاها |
| GET/POST | `/users` | `users.view` / `users.create` |
| PATCH | `/users/{id}` | `users.edit` (نام، شماره، توضیحات) |
| POST | `/users/{id}/activate`، `/suspend`، `/block`، `/delete` | مجوز مربوط |
| GET | `/users/{id}/sessions`، `/devices` | `users.view` |
| DELETE | `/users/{id}/sessions/{sid}`، `/devices/{did}` | `users.sessions.revoke`، `users.devices.revoke` |
| POST | `/users/{id}/identity-reset` `{confirm_text}` | `users.identity.reset` — هشدار عدم بازیابی پیام‌ها |
| PUT | `/users/{id}/retention` `{permanent: bool}` | `users.retention.manage` (فقط Super) — ثبت در audit |
| GET/PATCH | `/settings` | `settings.view` / `settings.edit` |
| GET | `/storage` | `storage.view` — سهمیه، مصرف، منقضی‌شده‌های در انتظار حذف، آخرین Cron |
| POST | `/storage/cleanup` | `storage.cleanup.run` — اجرای دستی پاک‌سازی |
| GET | `/audit?…` و `/audit/verify` | `audit.view` |
| GET | `/system/capabilities` | `system.capabilities.view` — آخرین نتیجه بررسی هاست |
| CRUD | `/admins`، `/roles` | `admins.manage` (فقط Super) |

**هیچ endpoint مدیریتی‌ای ciphertext، envelope یا محتوای blob برنمی‌گرداند.**
