// 1.0.0-rc.8 in a real browser (after v20_schedule.py): weekly editor (turn a day on, add a shift, copy hours,
// live estimate, save and undo), closures form, services list, «موقعیت فعلی من» filling the map fields.
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/schedule-fixture.json'));
  let checks = 0;
  const ok = (v, s) => {
    if (!v) throw Error(s);
    console.log('PASS ' + s);
    checks++;
  };
  const base = 'http://127.0.0.1:8088/';
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const errors = [];
  const csp = [];
  for (const w of [390, 1440]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 860 }, serviceWorkers: 'block', geolocation: { latitude: 27.18321, longitude: 56.26661 }, permissions: ['geolocation'] });
    await ctx.addCookies([{ name: 'PHPSESSID', value: f.session, domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('console', (m) => /Content Security Policy|Refused to/i.test(m.text()) && csp.push(m.text()));
    p.on('dialog', (d) => d.accept());
    const sched = `${base}doctor-panel.php?doctor=${f.doctor}&tab=schedule&clinic=${f.clinic}`;
    await p.goto(sched);
    await p.evaluate(() => document.fonts.ready);
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${w}px schedule: no horizontal scroll`);
    const before = await p.locator('.sc-day.on').count();
    const idx = await p.evaluate((free) => {
      const order = [6, 0, 1, 2, 3, 4, 5];
      const days = [...document.querySelectorAll('.sc-day')];
      return days.findIndex((d, i) => !d.classList.contains('on') && free.includes(order[i]));
    }, f.free);
    ok(idx >= 0, 'a free day to switch on');
    const day = p.locator('.sc-day').nth(idx);
    ok(!(await day.locator('[data-shifts]').isVisible()) && (await day.locator('.sc-off').isVisible()), 'day off: shows «تعطیل», no time fields');
    await day.locator('.sc-switch').click();
    ok(await day.locator('[data-shift]').first().isVisible(), 'switching the day on shows its hours');
    ok((await day.locator('[data-shift] select').first().inputValue()) === '09:00', 'new day starts with 09:00–13:00');
    ok((await day.locator('[data-count]').innerText()).includes('نوبت'), 'live estimate of appointments');
    const c1 = await day.locator('[data-count]').innerText();
    await day.locator('[data-shift-add]').click();
    ok((await day.locator('[data-shift]:not([hidden])').count()) === 2, 'second shift added');
    ok((await day.locator('[data-count]').innerText()) !== c1, 'estimate grows with the second shift');
    await day.locator('[data-shift]:not([hidden]) [data-shift-remove]').click();
    ok((await day.locator('[data-shift]:not([hidden])').count()) === 1, 'second shift removed');
    if (w === 390) await p.screenshot({ path: 'runtime/rc8-schedule-390.png', fullPage: false });
    await Promise.all([p.waitForNavigation(), day.locator('xpath=ancestor::form').locator('.sc-save button').click()]);
    ok((await p.locator('body').innerText()).includes('برنامه هفتگی ذخیره شد') && (await p.locator('.sc-day.on').count()) === before + 1, 'week saved');
    await p.locator('.sc-day').nth(idx).locator('.sc-switch').click();
    await Promise.all([p.waitForNavigation(), p.locator('.sc-save button').click()]);
    ok((await p.locator('.sc-day.on').count()) === before, 'day switched off again (original week back)');
    ok(!(await p.locator('[data-partial-times]').isVisible()), 'closure hours hidden until «فقط چند ساعت»');
    await p.locator('[data-partial]').check();
    ok(await p.locator('[data-partial-times]').isVisible(), 'closure hours shown on demand');

    await p.goto(`${base}doctor-panel.php?doctor=${f.doctor}&tab=services`);
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${w}px services: no horizontal scroll`);
    await p.locator('.svc-row summary').first().click();
    ok(await p.locator('.svc-row details[open] .svc-form').isVisible(), 'service opens for editing in place');

    await p.goto(`${base}doctor-panel.php?doctor=${f.doctor}&tab=clinics`);
    const picker = p.locator('[data-map-picker]').last();
    await picker.locator('[data-locate]').waitFor({ state: 'visible', timeout: 8000 });
    await picker.locator('[data-locate]').click();
    await p.waitForFunction(() => [...document.querySelectorAll('[data-map-picker]')].pop().querySelector('[name=latitude]').value !== '', null, { timeout: 8000 });
    ok((await picker.locator('[name=latitude]').inputValue()).startsWith('27.1832') && (await picker.locator('[name=longitude]').inputValue()).startsWith('56.2666'), '«موقعیت فعلی من» fills latitude and longitude');
    ok((await picker.locator('[data-locate-status]').innerText()).includes('موقعیت ثبت شد'), 'confirmation shown');
    await ctx.close();
  }
  // Refused permission: a clear message, nothing filled.
  const ctx = await browser.newContext({ viewport: { width: 390, height: 860 }, serviceWorkers: 'block' });
  await ctx.addCookies([{ name: 'PHPSESSID', value: f.session, domain: '127.0.0.1', path: '/' }]);
  const p = await ctx.newPage();
  await p.addInitScript(() => {
    navigator.geolocation.getCurrentPosition = (okf, fail) => setTimeout(() => fail({ code: 1, message: 'denied' }), 30);
  });
  await p.goto(`${base}doctor-panel.php?doctor=${f.doctor}&tab=clinics`);
  const picker = p.locator('[data-map-picker]').last();
  await picker.locator('[data-locate]').waitFor({ state: 'visible', timeout: 8000 });
  await picker.locator('[data-locate]').click();
  await p.waitForFunction(() => /اجازه/.test([...document.querySelectorAll('[data-locate-status]')].pop().textContent));
  ok((await picker.locator('[name=latitude]').inputValue()) === '', 'permission refused: message, fields stay empty');
  ok(csp.length === 0, 'no CSP violations ' + csp.join(' | '));
  ok(errors.length === 0, 'no JavaScript errors ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' rc.8 schedule browser checks');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
