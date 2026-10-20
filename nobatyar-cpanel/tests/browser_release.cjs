// Requires the disposable v13_release.py fixture. PWA behaviour, CSP compliance and loading feedback.
const { chromium } = require('playwright'), fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/release-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await ctx.addCookies([{ name: 'PHPSESSID', value: f.patient, domain: '127.0.0.1', path: '/' }]);
  const p = await ctx.newPage();
  const csp = [], errors = [];
  p.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) csp.push(m.text()); });
  p.on('pageerror', (e) => errors.push(e.message));
  p.on('dialog', (d) => d.accept());
  for (const url of ['index.php', `doctor.php?id=${f.doctor}`, 'centers.php', 'patient.php', 'notifications.php', 'support.php', 'app-install.php', `receipt.php?id=${f.booking}`]) {
    await p.goto(base + url);
    await p.waitForLoadState('networkidle');
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${url} fits a phone screen`);
  }
  ok(csp.length === 0, 'no Content-Security-Policy violation on any page ' + csp.slice(0, 2).join(' | '));

  // Bottom navigation: visible on phones, hidden on desktop.
  await p.goto(base + 'patient.php');
  ok(await p.locator('.bottom-nav').isVisible(), 'bottom navigation visible on phone');
  const box = await p.locator('.bottom-nav').boundingBox();
  ok(box && Math.abs(box.y + box.height - 844) < 2, 'bottom navigation pinned to the bottom edge');
  await p.setViewportSize({ width: 1440, height: 900 });
  ok(!(await p.locator('.bottom-nav').isVisible()), 'bottom navigation hidden on desktop');
  await p.setViewportSize({ width: 390, height: 844 });

  // Service worker: registered, static cache only.
  await p.goto(base + 'index.php');
  await p.evaluate(() => navigator.serviceWorker.ready);
  await p.reload();
  await p.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15000 });
  ok(true, 'service worker controls the page');
  await p.goto(base + 'patient.php');
  await p.goto(base + `receipt.php?id=${f.booking}`);
  const cached = await p.evaluate(async () => {
    const out = [];
    for (const k of await caches.keys()) for (const r of await (await caches.open(k)).keys()) out.push(r.url);
    return out;
  });
  ok(cached.length >= 10 && cached.some((u) => u.includes('offline.html')), 'static assets and offline page precached');
  ok(!cached.some((u) => /\.php/.test(u)), 'no page, receipt or API response in the cache');

  // Offline: every network request fails (also those made by the service worker; run with
  // PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS=1). Personal pages are unavailable; the offline page explains why.
  await ctx.route('**/*', (r) => r.abort('internetdisconnected'));
  await p.goto(base + 'patient.php').catch(() => {});
  const text = await p.locator('body').innerText();
  ok(/اتصال اینترنت برقرار نیست/.test(text) && !/بیمار مسیر کامل/.test(text), 'offline shows the offline page without personal data');
  ok(await p.evaluate(() => getComputedStyle(document.body).fontFamily.includes('IRANSans') && getComputedStyle(document.querySelector('.offline-box')).borderRadius === '24px'), 'offline page styled from the cache');
  await ctx.unroute('**/*');

  // Connection notice.
  await p.goto(base + 'index.php');
  await ctx.setOffline(true);
  await p.evaluate(() => window.dispatchEvent(new Event('offline')));
  ok(await p.locator('[data-net-status]').isVisible(), 'offline notice appears when the connection drops');
  await ctx.setOffline(false);
  await p.evaluate(() => window.dispatchEvent(new Event('online')));
  ok(!(await p.locator('[data-net-status]').isVisible()), 'notice hides when back online');

  // Busy state on submit and protection against double submission.
  await p.goto(base + 'support.php');
  await p.selectOption('select[name=category]', 'general');
  await p.fill('input[name=subject]', 'آزمون دکمه در حال ارسال');
  await p.fill('textarea[name=body]', 'متن آزمون برای وضعیت در حال ارسال فرم.');
  // A window-level listener runs after the app's document-level one and keeps the page from leaving,
  // so the busy state can be inspected.
  await p.evaluate(() => { window.__submits = 0; window.addEventListener('submit', (e) => { if (!e.defaultPrevented) window.__submits++; e.preventDefault(); }); });
  const btn = p.locator('form:has(textarea[name=body]) button');
  await btn.click();
  await p.waitForTimeout(100);
  ok(await btn.evaluate((b) => b.disabled && b.classList.contains('is-loading') && b.form.getAttribute('aria-busy') === 'true'), 'submit button shows progress and is disabled');
  await p.evaluate(() => document.querySelector('form:has(textarea[name=body])').requestSubmit());
  ok(await p.evaluate(() => window.__submits) === 1, 'second submission of the same form is blocked');

  // Install guide: the button stays hidden until the browser offers installation.
  await p.goto(base + 'app-install.php');
  ok(await p.locator('[data-install]').isHidden(), 'install button hidden without a browser prompt');
  await p.evaluate(() => { const e = new Event('beforeinstallprompt'); e.prompt = () => {}; e.userChoice = Promise.resolve({ outcome: 'dismissed' }); window.dispatchEvent(e); });
  ok(await p.locator('[data-install]').isVisible(), 'install button appears when the browser offers installation');
  ok(errors.length === 0, 'no JavaScript exceptions ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' release browser checks');
})().catch((e) => { console.error(e); process.exit(1); });
