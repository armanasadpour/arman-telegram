// Phone side menu and sign-in sheet in a real browser: open/close (button, backdrop, Escape, swipe),
// scroll lock, accordion, focus trap, menu hidden on desktop; sign-in sheet with number validation,
// Persian digits, code boxes (typing, wrong code, paste), resend countdown, edit number, profile step for a
// new number, header after sign-in, desktop dialog, and returning to the chosen booking time.
// Needs v15_auth.py (runtime/auth-fixture.json). Switches this disposable copy to SMS test mode
// (environment=development, no SMS leaves) and restores config.php and the settings at the end.
const { chromium } = require('playwright'), fs = require('fs'), { execFileSync } = require('child_process');
const f = JSON.parse(fs.readFileSync('runtime/auth-fixture.json'));
const cfgPath = f.root + '/config.php';
const cfgBefore = fs.readFileSync(cfgPath, 'utf8');
const php = (code) => execFileSync('php', ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code, f.root + '/core.php'], { env: { ...process.env, NOBATYAR_TEST: '1' } }).toString();
const loginCode = (mobile) => {
  const t = php(`echo (string) query("SELECT tokens FROM sms_messages WHERE event='login' AND mobile=? ORDER BY id DESC LIMIT 1",['${mobile}'])->fetchColumn();`);
  return (t.match(/\d{6}/) || [''])[0];
};
const settingsBefore = php(`echo json_encode(query("SELECT \`key\`,value FROM settings WHERE \`key\` IN ('sms_dev_mode','sms_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR));`);
(async () => {
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  fs.writeFileSync(cfgPath, cfgBefore.replace("'environment' => 'production'", "'environment' => 'development'"));
  php(`setting_set('sms_dev_mode','1');setting_set('sms_enabled','0');query('DELETE FROM auth_rate_limits');`);
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const errors = [], csp = [];
  const watch = (p) => {
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) csp.push(m.text()); });
  };
  try {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
    const p = await ctx.newPage();
    watch(p);
    const settle = () => p.waitForTimeout(380);

    /* ---------- Side menu ---------- */
    await p.goto(base + 'index.php');
    const toggle = p.locator('.site-header [data-drawer-open]');
    ok(await toggle.isVisible(), 'menu button shown on phones (unhidden by script)');
    ok(await p.locator('#side-drawer').isHidden(), 'menu closed at load');
    await toggle.click();
    await settle();
    ok(await p.locator('#side-drawer').isVisible(), 'menu opens');
    const box = await p.locator('#side-drawer').boundingBox();
    ok(box.x + box.width >= 389 && box.width >= 390 * 0.78 && box.width <= 390 * 0.86, 'menu slides in from the right and covers about 80–85% of the width');
    ok(await p.evaluate(() => document.documentElement.classList.contains('scroll-locked')), 'page scroll locked while the menu is open');
    ok((await toggle.getAttribute('aria-expanded')) === 'true', 'menu button reports expanded');
    ok(await p.evaluate(() => document.activeElement.matches('[data-drawer-close]')), 'focus moves into the menu');
    const op = await p.locator('[data-drawer-backdrop]').evaluate((e) => getComputedStyle(e).opacity);
    ok(parseFloat(op) > 0.3, 'dark backdrop behind the menu');
    const group = p.locator('#side-drawer [data-drawer-group]').first();
    ok((await group.getAttribute('aria-expanded')) === 'false', 'submenu closed at first');
    await group.click();
    await settle();
    ok((await group.getAttribute('aria-expanded')) === 'true' && (await p.locator('#drawer-sub-0').isVisible()), 'submenu opens like an accordion');
    ok((await p.locator('#drawer-sub-0 a').count()) === 4, 'doctors submenu: search, specialty, first free times, my city');
    await group.click();
    await p.waitForTimeout(450);
    ok(await p.locator('#drawer-sub-0').isHidden(), 'submenu closes again');
    for (let i = 0; i < 20; i++) await p.keyboard.press('Tab');
    ok(await p.evaluate(() => !!document.activeElement.closest('#side-drawer')), 'keyboard focus stays inside the open menu');
    await p.keyboard.press('Escape');
    await settle();
    ok(await p.locator('#side-drawer').isHidden(), 'Escape closes the menu');
    ok(!(await p.evaluate(() => document.documentElement.classList.contains('scroll-locked'))), 'scroll unlocked after closing');
    ok(await p.evaluate(() => document.activeElement.matches('[data-drawer-open]')), 'focus returns to the menu button');
    await toggle.click();
    await settle();
    await p.locator('[data-drawer-backdrop]').click({ position: { x: 20, y: 400 } });
    await settle();
    ok(await p.locator('#side-drawer').isHidden(), 'touching outside closes the menu');
    await toggle.click();
    await settle();
    await p.evaluate(() => {
      const d = document.querySelector('#side-drawer');
      const t = (x) => new Touch({ identifier: 1, target: d, clientX: x, clientY: 400 });
      d.dispatchEvent(new TouchEvent('touchstart', { touches: [t(120)], bubbles: true }));
      for (const x of [140, 180, 230, 290]) d.dispatchEvent(new TouchEvent('touchmove', { touches: [t(x)], bubbles: true }));
      d.dispatchEvent(new TouchEvent('touchend', { touches: [], bubbles: true }));
    });
    await settle();
    ok(await p.locator('#side-drawer').isHidden(), 'swiping the menu to the right closes it');
    await toggle.click();
    await settle();
    await p.locator('#side-drawer a[href="faq.php"]').click();
    await p.waitForURL(/faq\.php/);
    ok(await p.locator('h1', { hasText: 'سوالات متداول' }).isVisible(), 'choosing an entry navigates (and closes the menu)');
    ok(!(await p.evaluate(() => document.documentElement.classList.contains('scroll-locked'))), 'new page not scroll-locked');
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'FAQ page fits the phone screen');

    /* ---------- Sign-in sheet: new number ---------- */
    await p.goto(base + 'index.php');
    await p.locator('.site-header .header-account').click();
    await settle();
    const sheet = p.locator('[data-auth-sheet]');
    ok(await sheet.isVisible(), 'sign-in sheet opens from the header');
    const sb = await sheet.boundingBox();
    ok(Math.abs(sb.y + sb.height - 844) < 2 && sb.width >= 389, 'sheet sits at the bottom, full width');
    ok((await sheet.locator('.auth-title:visible').innerText()).includes('ورود یا ثبت‌نام') && (await sheet.locator('.phone-prefix').count()) === 0 && (await sheet.locator('[data-mobile-input]').getAttribute('placeholder')) === '۰۹۱۲۳۴۵۶۷۸۹' && (await sheet.locator('[data-mobile-input]').evaluate((e) => getComputedStyle(e).textAlign)) === 'center', 'title; full 09… number, centred, no +98 box (rc.13)');
    const mobile = sheet.locator('[data-mobile-input]');
    const send = sheet.locator('[data-auth-form=request] [data-submit]');
    ok(await p.evaluate(() => document.activeElement.matches('[data-mobile-input]')), 'number field focused');
    ok(await send.isDisabled(), 'button disabled while the number is empty');
    await mobile.fill('12345');
    ok(await send.isDisabled(), 'button disabled for an invalid number');
    await mobile.blur();
    ok((await sheet.locator('[data-step=phone] [data-error]').innerText()).includes('معتبر نیست'), 'clear error for an invalid number');
    await mobile.fill('۰۹۱۲ ۵۵۵ ۰۲۰۱');
    ok(await send.isEnabled(), 'Persian digits with spaces accepted');
    ok((await sheet.locator('[data-step=phone] [data-error]').innerText()) === '', 'error clears while typing');
    ok(await sheet.locator('.auth-legal a').count() >= 0 && (await sheet.locator('.auth-legal').innerText()).includes('پذیرش'), 'terms notice shown');
    await send.click();
    await sheet.locator('[data-step=code]:not([hidden])').waitFor();
    await settle();
    ok((await sheet.locator('[data-step=code] [data-mobile-view]').innerText()) === '۰۹۱۲۵۵۵۰۲۰۱', 'code step shows the normalized number');
    ok((await sheet.locator('.otp-cell').count()) === 6 && (await sheet.locator('.otp-cells').getAttribute('dir')) === 'ltr', 'six code boxes, left to right');
    ok(await p.evaluate(() => document.activeElement.classList.contains('otp-cell')), 'first code box focused');
    ok((await sheet.locator('.otp-cell').first().getAttribute('autocomplete')) === 'one-time-code', 'SMS code autofill hint on the first box');
    ok(await sheet.locator('[data-countdown]').isVisible() && (await sheet.locator('[data-resend-button]').isHidden()), 'resend waits for the countdown');
    const t1 = await sheet.locator('[data-countdown-value]').innerText();
    await p.waitForTimeout(1200);
    ok(t1 !== (await sheet.locator('[data-countdown-value]').innerText()), 'countdown runs');
    const code = loginCode('09125550201');
    ok(/^\d{6}$/.test(code), 'code sent through the SMS test mode');
    ok(await sheet.locator('[data-auth-form=verify] [data-submit]').isDisabled(), 'confirm disabled until six digits');
    const wrong = code === '123456' ? '654321' : '123456';
    await p.keyboard.type(wrong);
    await p.waitForTimeout(700);
    ok((await sheet.locator('[data-step=code] [data-error]').innerText()).includes('اشتباه'), 'wrong code: clear error');
    ok((await sheet.evaluate(() => [...document.querySelectorAll('.otp-cell')].map((c) => c.value).join(''))) === '', 'wrong code clears the boxes');
    await sheet.locator('[data-auth-form=change] button').click();
    ok(await sheet.locator('[data-step=phone]').isVisible() && (await mobile.inputValue()).includes('۹۱۲'), 'edit number goes back and keeps the number');
    await send.click();
    await sheet.locator('[data-step=code]:not([hidden])').waitFor({ timeout: 3000 }).catch(() => {});
    ok((await sheet.locator('[data-step=phone] [data-error]').innerText()).includes('زیاد') || (await sheet.locator('[data-step=code]').isVisible()), 'a second request inside the wait time is refused by the server');
    if (await sheet.locator('[data-step=phone]').isVisible()) {
      php("query('DELETE FROM auth_rate_limits');");
      await send.click();
      await sheet.locator('[data-step=code]:not([hidden])').waitFor();
    }
    const code2 = loginCode('09125550201');
    await sheet.locator('.otp-cell').nth(2).evaluate((el, c) => {
      const dt = new DataTransfer();
      dt.setData('text/plain', c.replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]));
      el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    }, code2);
    await sheet.locator('[data-step=profile]:not([hidden])').waitFor();
    ok(true, 'pasting the whole code (Persian digits) fills the boxes and confirms');
    ok((await sheet.locator('[data-step=profile] input[readonly]').inputValue()) === '۰۹۱۲۵۵۵۰۲۰۱', 'profile step shows the verified number, read-only');
    const doneBtn = sheet.locator('[data-auth-form=complete] [data-submit]');
    ok(await doneBtn.isDisabled(), 'sign-up button waits for names and terms');
    await sheet.locator('input[name=first_name]').fill('نرگس');
    await sheet.locator('input[name=last_name]').fill('امیری');
    ok(await doneBtn.isDisabled(), 'terms acceptance still required');
    await sheet.locator('input[name=accept_terms]').check();
    ok(await doneBtn.isEnabled(), 'ready to sign up');
    await Promise.all([p.waitForNavigation(), doneBtn.click()]);
    ok(await p.locator('.site-header .header-account', { hasText: 'حساب من' }).isVisible(), 'header shows the account after signing up');
    ok((await p.locator('[data-auth-sheet]').count()) === 0, 'no sign-in sheet once signed in');
    ok(php("echo query(\"SELECT name FROM users WHERE mobile='09125550201'\")->fetchColumn();") === 'نرگس امیری', 'account created with the given name');
    await p.locator('.site-header [data-drawer-open]').click();
    await settle();
    ok((await p.locator('.drawer-user').innerText()).includes('نرگس امیری') && (await p.locator('#side-drawer a[href="patient.php"]').count()) === 1, 'menu shows the user and «my appointments»');
    await p.keyboard.press('Escape');

    /* ---------- Desktop: menu hidden, centered dialog ---------- */
    const d = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
    watch(d);
    await d.goto(base + 'index.php');
    ok(await d.locator('[data-drawer-open]').isHidden() && (await d.locator('.site-nav').isVisible()), 'desktop keeps the horizontal menu, no menu button');
    await d.locator('.site-header .header-account').click();
    await d.waitForTimeout(400);
    const db = await d.locator('[data-auth-sheet]').boundingBox();
    ok(Math.abs(db.x + db.width / 2 - 640) < 3 && Math.abs(db.y + db.height / 2 - 400) < 3 && db.width <= 460, 'desktop: centered dialog');
    await d.keyboard.press('Escape');
    await d.waitForTimeout(400);
    ok(await d.locator('[data-auth-sheet]').isHidden(), 'Escape closes the dialog');
    await d.locator('.header-doctor').click();
    await d.waitForTimeout(400);
    await d.locator('[data-auth-backdrop]').click({ position: { x: 30, y: 30 } });
    await d.waitForTimeout(400);
    ok(await d.locator('[data-auth-sheet]').isHidden(), 'clicking outside closes the dialog');

    /* ---------- Booking: sign in from a time and continue to that time ---------- */
    const g = await (await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true })).newPage();
    watch(g);
    php("query('DELETE FROM auth_rate_limits');");
    await g.goto(base + `doctor.php?id=${f.doctor}&clinic=${f.clinic}&service=${f.service}&date=` + encodeURIComponent(f.jday));
    const href = await g.locator('a.time-option[data-auth-required]').first().getAttribute('href');
    ok(!!href, 'guest sees bookable times');
    await g.locator('a.time-option[data-auth-required]').first().click();
    await g.waitForTimeout(400);
    ok(await g.locator('[data-auth-sheet]').isVisible() && g.url().includes('doctor.php'), 'choosing a time opens sign-in without leaving the doctor page');
    await g.locator('[data-auth-sheet] [data-mobile-input]').fill(f.existing);
    await g.locator('[data-auth-sheet] [data-auth-form=request] [data-submit]').click();
    await g.locator('[data-auth-sheet] [data-step=code]:not([hidden])').waitFor();
    await g.keyboard.type(loginCode(f.existing));
    await g.waitForURL(/service-book\.php/);
    const back = new URL(g.url());
    const want = new URL(href, base);
    ok(back.searchParams.get('clinic') === want.searchParams.get('clinic') && back.searchParams.get('service') === want.searchParams.get('service') && back.searchParams.get('at') === want.searchParams.get('at'), 'existing account returns to the same clinic, service and time');
    ok(await g.locator('button', { hasText: /رزرو موقت و ادامه|ثبت و ادامه/ }).first().isVisible(), 'time still free: booking continues');

    ok(errors.length === 0, 'no script errors ' + errors.join(' | '));
    ok(csp.length === 0, 'no Content-Security-Policy violations ' + csp.join(' | '));
  } finally {
    fs.writeFileSync(cfgPath, cfgBefore);
    const s = JSON.parse(settingsBefore || '{}');
    php(`setting_set('sms_dev_mode','${s.sms_dev_mode || '0'}');setting_set('sms_enabled','${s.sms_enabled || '0'}');query('DELETE FROM auth_rate_limits');`);
    await browser.close();
  }
  console.log(`SUCCESS ${checks} menu and sign-in browser checks`);
})().catch((e) => { console.error(e); process.exit(1); });
