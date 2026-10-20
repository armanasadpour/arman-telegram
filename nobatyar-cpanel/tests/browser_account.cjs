// 1.0.0-rc.7 in a real browser (after v19_account.py): account hub and lists at 320–1440 px without
// horizontal scroll, every visible button styled, light body text, tables stacked as cards on phones only.
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/account-fixture.json'));
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
  const page = async (sid, w) => {
    const ctx = await browser.newContext({ viewport: { width: w, height: 860 }, serviceWorkers: 'block' });
    await ctx.addCookies([{ name: 'PHPSESSID', value: sid, domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('console', (m) => /Content Security Policy|Refused to/i.test(m.text()) && csp.push(m.text()));
    return p;
  };
  const unstyled = (p) =>
    p.evaluate(() =>
      [...document.querySelectorAll('main button, main a.button')]
        .filter((e) => e.offsetParent && !e.matches('.link-button,.icon-button,.clamp-toggle,.tool'))
        .filter((e) => {
          const s = getComputedStyle(e);
          return ((s.backgroundColor === 'rgba(0, 0, 0, 0)' || s.backgroundColor === 'transparent') && parseFloat(s.borderTopWidth) === 0) || !s.fontFamily.includes('IRANSans');
        })
        .map((e) => e.textContent.trim().slice(0, 30)),
    );
  for (const w of [320, 390, 1440]) {
    for (const [who, url] of [['patient', 'panel.php'], ['patient', 'patient.php'], ['patient', 'notifications.php'], ['patient', `receipt.php?id=${f.booking}`], ['doctor', 'panel.php'], ['doctor', 'panel.php?role=doctor'], ['secretary', 'panel.php']]) {
      const p = await page(f[who], w);
      await p.goto(base + url);
      await p.evaluate(() => document.fonts.ready);
      ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${w}px ${who} ${url}: no horizontal scroll`);
      const bad = await unstyled(p);
      ok(bad.length === 0, `${w}px ${who} ${url}: every button styled ${bad.join(' | ')}`);
      if (w === 390 && url === 'panel.php' && who === 'patient') await p.screenshot({ path: 'runtime/rc7-account-390.png', fullPage: true });
      await p.context().close();
    }
  }
  const p = await page(f.patient, 390);
  await p.goto(base + 'panel.php');
  ok((await p.evaluate(() => getComputedStyle(document.body).fontWeight)) === '300', 'body text uses the light weight');
  ok((await p.evaluate(() => getComputedStyle(document.querySelector('.acc-id h1')).fontWeight)) === '700', 'titles stay bold');
  ok((await p.locator('.acc-tiles a').count()) === 6, 'six shortcuts');
  await p.locator('.acc-tiles a', { hasText: 'اعضای خانواده' }).click();
  ok(p.url().includes('patient.php?tab=family'), 'shortcut opens the family tab');
  await p.goto(base + 'notifications.php');
  const label = await p.evaluate(() => {
    const td = document.querySelector('table.stackable td[data-label]:not([data-label=""])');
    return td ? getComputedStyle(td, '::before').content : '';
  });
  ok(label.length > 2 && label !== 'none', 'phone: table cells show their column title (cards)');
  await p.setViewportSize({ width: 1440, height: 900 });
  const wide = await p.evaluate(() => getComputedStyle(document.querySelector('table.stackable td'), '::before').content);
  ok(wide === 'none' || wide === 'normal', 'desktop: regular table');
  await p.setViewportSize({ width: 390, height: 860 });
  await p.goto(base + 'patient.php');
  ok(await p.locator('.page-tabs').evaluate((e) => getComputedStyle(e).flexWrap === 'nowrap' && e.scrollWidth >= e.clientWidth), 'tab bar scrolls sideways instead of wrapping');
  await p.locator('[data-drawer-open]').click();
  const drawer = await p.locator('[data-drawer]').innerText();
  ok(!drawer.includes('پزشکان') || !/ثبت‌نام پزشکان|پنل پزشک/.test(drawer), 'phone menu has no doctor entries for a patient');
  ok(csp.length === 0, 'no CSP violations ' + csp.join(' | '));
  ok(errors.length === 0, 'no JavaScript errors ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' rc.7 account browser checks');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
