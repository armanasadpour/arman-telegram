<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.2 — homepage redesign: FAQ, health articles, editable site pages (guides, terms, privacy,
 * about, contact), featured doctors, specialties shown on the homepage. The homepage layout itself lives
 * in settings (home_layout / home_layout_draft) and starts from the built-in defaults. Additive and
 * restartable; FAQ answers describe how this system actually works and stay editable. Terms and privacy
 * start as unpublished drafts until the administrator reviews them.
 */

function migrate_v15(PDO $p): void
{
    mig_lock($p, 'v15');
    try {
        if (mig_applied($p, 15)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/015.sql');
        mig_column($p, 'doctors', 'featured', 'TINYINT NOT NULL DEFAULT 0');
        mig_column($p, 'doctors', 'featured_order', 'INT NOT NULL DEFAULT 0');
        mig_column($p, 'specialties', 'show_on_home', 'TINYINT NOT NULL DEFAULT 1');
        mig_index($p, 'doctors', 'doctor_featured', 'INDEX doctor_featured(featured,featured_order)');
        if (!(int) $p->query('SELECT COUNT(*) FROM faqs')->fetchColumn()) {
            $q = $p->prepare('INSERT INTO faqs(question,answer,sort_order) VALUES(?,?,?)');
            foreach (v15_faqs() as $i => [$question, $answer]) {
                $q->execute([$question, $answer, ($i + 1) * 10]);
            }
        }
        $page = $p->prepare('INSERT IGNORE INTO site_pages(slug,title,body,published,updated_at) VALUES(?,?,?,?,NOW())');
        foreach (v15_pages() as $slug => [$title, $body, $published]) {
            $page->execute([$slug, $title, $body, $published]);
        }
        foreach (['footer_about' => 'نوبت‌دهی اینترنتی پزشکان و مراکز درمانی؛ هزینه ویزیت در مطب پرداخت می‌شود.', 'home_show_medical_code' => '0', 'social_links' => '{}', 'trust_badges' => '[]', 'support_address' => ''] as $key => $value) {
            mig_setting_default($p, $key, $value);
        }
        // Hero text saved in 0.12 (home_hero_title / home_hero_text) moves into the published layout.
        $old = $p->query("SELECT `key`,value FROM settings WHERE `key` IN ('home_hero_title','home_hero_text','home_layout')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (empty($old['home_layout']) && (($old['home_hero_title'] ?? '') !== '' || ($old['home_hero_text'] ?? '') !== '')) {
            $hero = array_filter(['title' => $old['home_hero_title'] ?? '', 'subtitle' => $old['home_hero_text'] ?? ''], fn($v) => $v !== '');
            mig_setting_default($p, 'home_layout', json_encode(['sections' => ['hero' => $hero]], JSON_UNESCAPED_UNICODE));
        }
        mig_mark($p, 15, '1.0.0-rc.2');
    } finally {
        mig_unlock($p, 'v15');
    }
}

/** Answers use {booking_fee} and {cancel_hours}, replaced with the live settings when shown. */
function v15_faqs(): array
{
    return [
        ['چطور نوبت پزشک رزرو کنم؟', "ابتدا شهرتان را برگزینید، پزشک یا تخصص را جستجو کنید، در صفحه پزشک روز و ساعت آزاد را بزنید و با شماره موبایل وارد شوید. پس از تکمیل، نوبت قطعی و کد رهگیری نمایش داده و پیامک می‌شود."],
        ['آیا هزینه ویزیت آنلاین پرداخت می‌شود؟', 'خیر. هزینه ویزیت فقط در مطب و به خود پزشک پرداخت می‌شود و نوبت‌یار از آن سهمی برنمی‌دارد.'],
        ['کارمزد رزرو نوبت چقدر است؟', 'کارمزد خدمات رزرو اینترنتی در حال حاضر {booking_fee} است و پیش از پرداخت در صفحه تکمیل رزرو نمایش داده می‌شود. برای بعضی شهرها یا پزشکان ممکن است مبلغ متفاوت یا رایگان باشد.'],
        ['چطور نوبتم را لغو کنم؟', 'در «حساب من ← نوبت‌های من»، نوبت را باز کنید و «لغو نوبت» را بزنید. لغو اینترنتی تا {cancel_hours} ساعت پیش از زمان نوبت ممکن است.'],
        ['آیا می‌توانم برای اعضای خانواده نوبت بگیرم؟', 'بله. در حساب خود اعضای خانواده را اضافه کنید و هنگام رزرو، نام همان عضو را انتخاب کنید.'],
        ['اگر پزشک نوبت را لغو کند چه می‌شود؟', 'با پیامک و اعلان خبر می‌گیرید. اگر کارمزد رزرو پرداخت کرده باشید، طبق قوانین سامانه برای بازگشت وجه ثبت می‌شود و می‌توانید از همان صفحه پزشک زمان دیگری انتخاب کنید.'],
    ];
}

function v15_pages(): array
{
    return [
        'patient-guide' => ['راهنمای بیماران', "## انتخاب شهر\nدر اولین ورود شهر خود را انتخاب کنید. پزشکان و مراکز همان شهر نمایش داده می‌شوند و هر زمان از بالای صفحه قابل تغییر است.\n\n## رزرو نوبت\nپزشک را جستجو کنید، در صفحه پزشک روز و ساعت آزاد را انتخاب کنید و با کد پیامکی وارد شوید. اگر کارمزد رزرو فعال باشد، پس از پرداخت نوبت قطعی می‌شود.\n\n## هزینه ویزیت\nهزینه ویزیت در مطب پرداخت می‌شود.\n\n## لغو و جابه‌جایی\nاز «حساب من» نوبت را لغو یا جابه‌جا کنید.\n\n## صف انتظار\nاگر زمان آزادی نبود، «خبرم کن» را بزنید تا با آزاد شدن ظرفیت مطلع شوید.", 1],
        'doctor-guide' => ['راهنمای پزشکان', "## ثبت‌نام\nاز «ثبت‌نام پزشک» اطلاعات و کد نظام پزشکی را وارد کنید. پس از بررسی و تأیید مدیر، پروفایل شما فعال می‌شود.\n\n## اشتراک\nبرای نمایش در جستجو و دریافت نوبت، اشتراک ماهانه لازم است. از هزینه ویزیت هیچ درصدی کسر نمی‌شود.\n\n## برنامه کاری\nدر پنل پزشک، مطب‌ها، خدمات، ساعت کاری و روزهای تعطیل را تعیین کنید.\n\n## منشی\nبرای منشی حساب جدا با دسترسی پذیرش تعریف کنید.", 1],
        'about' => ['درباره ما', 'متن معرفی خود را در «مدیریت ← مدیریت صفحه اصلی ← صفحه‌ها» بنویسید.', 0],
        'contact' => ['تماس با ما', 'راه‌های تماس از «تنظیمات عمومی» خوانده می‌شود. متن دلخواه را اینجا اضافه کنید.', 1],
        'terms' => ['قوانین و مقررات', 'پیش‌نویس: این متن باید پیش از انتشار توسط مشاور حقوقی شما نوشته یا بازبینی شود.', 0],
        'privacy' => ['حریم خصوصی', "پیش‌نویس (پیش از انتشار با مشاور حقوقی بازبینی شود)\n\n## اطلاعاتی که نگه می‌داریم\nشماره موبایل، نام بیمار و اعضای خانواده، نوبت‌ها و تراکنش‌های کارمزد رزرو.\n\n## روی دستگاه شما\nبرنامه نصب‌شده فقط فایل‌های ظاهری را نگه می‌دارد و اطلاعات پزشکی و مالی را ذخیره نمی‌کند.\n\n## دسترسی‌ها\nپزشک و منشی فقط نوبت‌های مطب خود را می‌بینند و مراکز درمانی فقط در محدوده مجوز پزشک.", 0],
    ];
}
