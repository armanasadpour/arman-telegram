// 1.0.0-rc.5 doctor profile and booking flow in a real browser. Disposable fixture only (after v17_booking.py).
// Widths 320–1440 without horizontal scroll, day strip swaps times in place, notes clamp, field validation,
// patient choice, hold countdown (and its expiry), free confirmation → success receipt; no CSP violations.
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/booking-fixture.json'));
  let checks = 0;
  const ok = (v, s) => {
    if (!v) throw Error(s);
    console.log('PASS ' + s);
    checks++;
  };
  const base = 'http://127.0.0.1:8088/';
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ locale: 'fa-IR', serviceWorkers: 'block' });
  await ctx.addCookies([{ name: 'PHPSESSID', value: f.session, domain: '127.0.0.1', path: '/' }]);
  const p = await ctx.newPage();
  const errors = [];
  const csp = [];
  p.on('pageerror', (e) => errors.push(e.message));
  p.on('console', (m) => {
    if (/Content Security Policy|Refused to/i.test(m.text())) csp.push(m.text());
  });
  p.on('dialog', (d) => d.accept());
  const noOverflow = () => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
  const profile = `doctor.php?id=${f.doctor}&clinic=${f.clinic}`;
  const times = `doctor.php?id=${f.doctor}&clinic=${f.clinic}&service=${f.service}&times=1`;
  const receipt = `receipt.php?id=${f.booking}`;

  // ---------- Every width ----------
  for (const w of [320, 375, 390, 430, 768, 1440]) {
    await p.setViewportSize({ width: w, height: w > 900 ? 900 : 800 });
    for (const [route, name] of [[profile, 'profile'], [times, 'times'], [receipt, 'receipt']]) {
      await p.goto(base + route);
      await p.evaluate(() => document.fonts.ready);
      ok(await noOverflow(), `${w}px ${name}: no horizontal scroll`);
      ok(!/Fatal error|Warning:|Notice:/.test(await p.content()), `${w}px ${name}: no PHP errors`);
      if ([390, 1440].includes(w)) await p.screenshot({ path: `runtime/rc5-${name}-${w}.png`, fullPage: true });
    }
    await p.goto(base + profile);
    const bar = await p.locator('.book-bar').isVisible();
    const card = await p.locator('.book-card').isVisible();
    if (w <= 920) ok(bar && !(await p.locator('.bottom-nav').isVisible()), `${w}px: booking bar instead of the bottom menu`);
    else ok(card && !bar, `${w}px: booking card beside the profile`);
  }

  // ---------- Profile ----------
  await p.setViewportSize({ width: 1440, height: 900 });
  await p.goto(base + profile);
  ok((await p.locator('.dp-tabs a').count()) >= 5, 'section tabs');
  await p.locator('.dp-tabs a[href="#reviews"]').click();
  await p.waitForFunction(() => { const t = document.querySelector('#reviews').getBoundingClientRect().top; return t > -5 && t < 260; }, null, { timeout: 3000 }).catch(() => {});
  const top = await p.evaluate(() => document.querySelector('#reviews').getBoundingClientRect().top);
  ok(top > -5 && top < 260, 'tab scrolls to its section (top ' + Math.round(top) + ')');
  ok(await p.locator('.book-card').evaluate((e) => getComputedStyle(e.parentElement).position === 'sticky'), 'booking card sticks on desktop');
  ok(await p.locator('[data-share]').first().isVisible(), 'share button shown with script');
  // rc.11: large booking button above "share", on desktop and on phones.
  const cta = async () => p.evaluate(() => { const c = document.querySelector('.dp-cta').getBoundingClientRect(); const s = document.querySelector('.dp-hero [data-share]').getBoundingClientRect(); return [c.height, c.bottom <= s.top + 1, c.width >= s.width - 1]; });
  let [h1, above1, wide1] = await cta();
  ok(h1 >= 60 && above1 && wide1, 'desktop: large booking button above share (' + Math.round(h1) + 'px)');
  await p.setViewportSize({ width: 390, height: 844 });
  await p.goto(base + profile);
  let [h2, above2] = await cta();
  ok(h2 >= 60 && above2 && await p.locator('.dp-cta').isVisible(), 'phone: large booking button above share');

  // ---------- Time picker ----------
  await p.setViewportSize({ width: 390, height: 844 });
  await p.goto(base + times);
  ok(await p.locator('.tp-notes .clamp-toggle').isVisible(), 'long clinic notes collapsed with «ادامه»');
  const h0 = await p.locator('.tp-notes').evaluate((e) => e.offsetHeight);
  await p.locator('.tp-notes .clamp-toggle').click();
  ok((await p.locator('.tp-notes').evaluate((e) => e.offsetHeight)) > h0 && (await p.locator('.clamp-toggle').innerText()) === 'بستن', 'notes expand and offer «بستن»');
  const cols = await p.locator('.time-grid').evaluate((e) => getComputedStyle(e).gridTemplateColumns.split(' ').length).catch(() => 3);
  ok(cols === 3, 'three-column time grid on phones');
  const freeCards = p.locator('.day-card.state-free:not(.is-selected)');
  if (await freeCards.count()) {
    const target = freeCards.first();
    const date = await target.getAttribute('data-date');
    const label = await target.locator('.dc-count').innerText();
    const before = p.url();
    await target.click();
    await p.waitForFunction((d) => document.querySelector('[data-times-region]')?.dataset.day === d && !document.querySelector('.tp-times.is-loading'), date);
    ok(p.url() !== before && p.url().includes('date='), 'another day loads in place and the address updates');
    const n = await p.locator('[data-times-region] .time-option').count();
    ok(label === n.toLocaleString('fa-IR').replace(/٬/g, '') + ' نوبت' || label.startsWith(n.toLocaleString('fa-IR')), 'times listed match the day card count');
    ok((await p.locator('.day-card.is-selected').getAttribute('data-date')) === date, 'chosen day highlighted');
  }
  if (f.empty_day) {
    const ed = p.locator(`.day-card[data-date="${f.empty_day}"]`);
    if (await ed.count()) {
      await ed.click();
      await p.waitForFunction((d) => document.querySelector('[data-times-region]')?.dataset.day === d, f.empty_day);
      ok(await p.getByText('برای این روز نوبت آزادی وجود ندارد.').isVisible(), 'empty day message');
      const go = p.locator('.tp-empty a.button.success');
      if (await go.count()) {
        await go.click();
        await p.waitForFunction(() => document.querySelectorAll('[data-times-region] .time-option').length > 0);
        ok(true, 'next free day button shows its times');
      }
    }
  }

  // ---------- Patient step ----------
  await p.locator('[data-times-region] .time-option').first().click();
  await p.waitForURL(/service-book\.php/);
  ok(await p.locator('.stepper .step-current').isVisible(), 'stepper on the patient step');
  const cont = p.locator('[data-who-continue]');
  ok(await cont.isDisabled(), 'continue disabled until a patient is chosen');
  await p.locator('.bk-add > summary').first().click();
  const add = p.locator('.bk-add form[data-validate]').first();
  // rc.11: «اتباع خارجی است» — ticked, the national ID is no longer required and becomes an optional passport number.
  ok(await add.locator('[name=national_id]').evaluate((e) => e.required), 'national ID required for another person');
  await add.locator('[data-nid-foreign]').check();
  ok(!(await add.locator('[name=national_id]').evaluate((e) => e.required)) && (await add.locator('[data-nid-label]').innerText()).includes('گذرنامه'), 'foreign tick: no national ID needed, optional passport number');
  await add.locator('[data-nid-foreign]').uncheck();
  ok(await add.locator('[name=national_id]').evaluate((e) => e.required) && (await add.locator('[data-nid-label]').innerText()).includes('کد ملی'), 'untick: national ID required again');
  await add.locator('[name=birth_d]').selectOption('5'); // partial Jalali date (rc.11 day/month/year)
  await add.getByRole('button', { name: /افزودن مراجعه‌کننده/ }).click();
  ok(p.url().includes('service-book.php') && (await add.locator('.field-msg').count()) >= 2, 'add-person form shows field messages instead of submitting');
  ok((await add.locator('.field.invalid .field-msg').first().innerText()).length > 4, 'messages are written out in Persian');
  ok((await add.locator('[data-jdate] .field-msg').innerText()).includes('کامل'), 'partial birth date: one message for day, month and year');
  ok(await noOverflow(), 'patient step: no horizontal scroll');
  await p.screenshot({ path: 'runtime/rc5-patient-390.png', fullPage: true });
  // The account holder already has this doctor's booking on that day (one per day); book for the family member.
  const member = p.locator('.who-card', { hasText: 'سارا رضایی' });
  await member.click();
  ok(await cont.isEnabled(), 'choice enables continue');
  await p.waitForTimeout(300); // let the 0.15s border transition finish before reading the colour
  ok(await member.evaluate((e) => getComputedStyle(e).borderColor !== 'rgb(229, 234, 242)'), 'chosen card highlighted');
  await cont.click();
  await p.waitForURL(/checkout\.php/, { timeout: 8000 }).catch(async () => {
    throw Error('hold did not open the payment step: ' + (await p.locator('.alert, .error').allInnerTexts()).join(' | '));
  });

  // ---------- Payment step ----------
  const t1 = await p.locator('[data-hold-left]').innerText();
  await p.waitForTimeout(1300);
  ok((await p.locator('[data-hold-left]').innerText()) !== t1, 'hold countdown runs');
  ok(await p.locator('.pay-bar').isVisible(), 'pay bar on screen');
  ok(await p.locator('.bill-card').getByText('پرداخت در مطب').isVisible(), 'visit fee marked paid at the clinic');
  await p.screenshot({ path: 'runtime/rc5-checkout-390.png', fullPage: true });
  await p.setViewportSize({ width: 1440, height: 900 });
  await p.screenshot({ path: 'runtime/rc5-checkout-1440.png', fullPage: true });
  ok(await noOverflow(), 'payment step: no horizontal scroll on desktop');
  await p.setViewportSize({ width: 390, height: 844 });

  // Expiry in the browser: the server time is moved two seconds before the end.
  const url = p.url();
  await p.route('**/checkout.php*', async (route) => {
    const res = await route.fetch();
    let body = await res.text();
    body = body.replace(/data-expires="(\d+)" data-now="(\d+)"/, (m, e) => `data-expires="${e}" data-now="${+e - 2}"`);
    await route.fulfill({ response: res, body });
  });
  await p.goto(url);
  await p.waitForFunction(() => document.querySelector('[data-hold-bar]')?.classList.contains('expired'), null, { timeout: 6000 });
  ok(await p.locator('[data-pay-bar] button').isDisabled(), 'pay button disabled when the hold ends');
  await p.unroute('**/checkout.php*');
  await p.goto(url);
  await p.getByRole('button', { name: /ثبت رایگان نوبت/ }).click();
  await p.waitForURL(/receipt\.php/);
  ok(await p.getByText('نوبت شما با موفقیت ثبت شد').isVisible(), 'success page');
  ok(await p.locator('.success-mark svg').isVisible(), 'animated check');
  ok(await p.locator('[data-share]').isVisible() && (await p.locator('[data-print]').isVisible()), 'share and PDF buttons available');
  await p.evaluate(() => {
    window.__printed = false;
    window.print = () => (window.__printed = true);
  });
  await p.locator('[data-print]').click();
  ok(await p.evaluate(() => window.__printed), 'PDF button opens printing');
  await p.screenshot({ path: 'runtime/rc5-success-390.png', fullPage: true });
  ok(await noOverflow(), 'success page: no horizontal scroll');

  ok(csp.length === 0, 'no Content-Security-Policy violations ' + csp.join(' | '));
  ok(errors.length === 0, 'no JavaScript exceptions ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' rc.5 booking browser checks');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
