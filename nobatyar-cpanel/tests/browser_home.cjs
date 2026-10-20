// Homepage in a real browser: responsive layout at three widths, sticky header, search suggestions with the
// keyboard, FAQ accordion, rails, phone bottom navigation, admin drag-and-drop ordering. Needs v14_home.py data.
const { chromium } = require('playwright'), fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/phase6-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const errors = [], csp = [];
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(e.message));
  p.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) csp.push(m.text()); });
  p.on('dialog', (d) => d.accept());
  const contained = () => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  for (const [w, h, label] of [[1440, 1000, 'desktop'], [820, 1180, 'tablet'], [390, 844, 'mobile']]) {
    await p.setViewportSize({ width: w, height: h });
    for (const url of ['index.php', 'index.php?view=doctors', 'specialties.php', 'articles.php', 'centers.php', 'page.php?slug=patient-guide', 'app-install.php']) {
      await p.goto(base + url);
      await p.evaluate(() => document.fonts.ready);
      ok(await contained(), `${label} ${url} fits the screen`);
      ok(!/Fatal error|Warning:/.test(await p.locator('body').innerText()), `${label} ${url} renders`);
    }
    await p.goto(base + 'index.php');
    await p.screenshot({ path: `runtime/home-${label}.png`, fullPage: true });
    const nav = await p.locator('.bottom-nav').isVisible();
    ok(label === 'mobile' ? nav : !nav, `${label} bottom navigation ${label === 'mobile' ? 'shown' : 'hidden'}`);
    if (label === 'mobile') ok((await p.locator('.bottom-nav a').count()) === 4, 'bottom navigation has home, search, my bookings and account');
    ok((await p.locator('.site-nav').isVisible()) === (label === 'desktop'), `${label} main menu ${label === 'desktop' ? 'visible' : 'collapsed'}`);
  }
  await p.setViewportSize({ width: 1440, height: 900 });
  await p.goto(base + 'index.php');
  await p.mouse.wheel(0, 1500);
  await p.waitForTimeout(300);
  const top = await p.locator('.site-header').evaluate((e) => e.getBoundingClientRect().top);
  ok(Math.abs(top) < 1 && (await p.locator('.site-header.scrolled').count()) === 1, 'header stays on top while scrolling');
  await p.mouse.wheel(0, -3000);
  // Suggestions with the keyboard.
  const input = p.locator('[data-suggest-input]').first();
  await input.fill('دکتر');
  await p.waitForSelector('[data-suggest-list] a[role=option]', { timeout: 5000 });
  ok(await input.getAttribute('aria-expanded') === 'true', 'suggestion list opens while typing');
  await input.press('ArrowDown');
  ok((await p.locator('[data-suggest-list] [aria-selected=true]').count()) === 1, 'arrow key selects a suggestion');
  const href = await p.locator('[data-suggest-list] [aria-selected=true]').getAttribute('href');
  await Promise.all([p.waitForNavigation(), input.press('Enter')]);
  ok(p.url().includes(href.split('?')[0]), 'Enter opens the selected suggestion');
  await p.goto(base + 'index.php');
  await input.fill('دکتر');
  await p.waitForSelector('[data-suggest-list] a[role=option]');
  await input.press('Escape');
  ok(await p.locator('[data-suggest-list]').isHidden(), 'Escape closes suggestions');
  // FAQ accordion.
  const faq = p.locator('details.faq').nth(1);
  if (await faq.count()) {
    await faq.locator('summary').click();
    ok(await faq.evaluate((d) => d.open), 'FAQ item opens');
  }
  // Admin: drag and drop the section order, then save the draft.
  const admin = await browser.newPage();
  admin.on('pageerror', (e) => errors.push(e.message));
  admin.on('dialog', (d) => d.accept());
  await admin.setViewportSize({ width: 1440, height: 1000 });
  await admin.goto(base + 'admin.php');
  await admin.fill('input[name=email]', 'admin@example.test');
  await admin.fill('input[name=password]', 'Test-password-012345');
  await admin.click('form button');
  await admin.goto(base + 'admin.php?tab=home');
  const order = () => admin.locator('[data-sortable] input[name="order[]"]').evaluateAll((els) => els.map((e) => e.value));
  const before = await order();
  const items = admin.locator('[data-sortable] [data-sort-item]');
  // Headless Chromium does not start native drags from synthetic mouse moves; dispatch the drag events.
  await admin.evaluate(() => {
    const els = document.querySelectorAll('[data-sortable] [data-sort-item]');
    const dt = new DataTransfer();
    const top = els[0].getBoundingClientRect().top + 2;
    els[2].dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
    els[0].dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientY: top }));
    els[2].dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
  });
  const after = await order();
  ok(after[0] === before[2], 'drag and drop moves a section');
  await items.nth(0).locator('[data-move=down]').click();
  ok((await order())[1] === before[2], 'keyboard button moves a section down');
  await admin.screenshot({ path: 'runtime/admin-home-manager.png', fullPage: true });
  for (const v of ['featured', 'specialties', 'faq', 'pages', 'footer']) {
    await admin.goto(base + 'admin.php?tab=home&view=' + v);
    ok(!/Fatal error|Warning:/.test(await admin.locator('body').innerText()), `admin homepage ${v} renders`);
  }
  await admin.goto(base + 'admin.php?tab=articles&edit=0');
  ok(await admin.locator('textarea[name=body]').isVisible(), 'article editor opens');
  // rc.12: province and city chosen inside the search card; the city applies at once on the home page.
  const visitor = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  visitor.on('pageerror', (e) => errors.push(e.message));
  await visitor.goto(base + 'index.php');
  ok(await visitor.locator('.hero-loc select[name=province]').isVisible() && await visitor.locator('.hero-loc select[name=city]').isVisible(), 'home: province and city selects in the search card');
  const w = await visitor.evaluate(() => [...document.querySelectorAll('.hero-sel')].map((e) => Math.round(e.getBoundingClientRect().width)));
  ok(w.length === 2 && Math.abs(w[0] - w[1]) <= 2, 'home: the two selects are the same size ' + w.join('/'));
  const prov = await visitor.evaluate(() => [...document.querySelectorAll('.hero-loc select[name=province] option')].map((o) => o.value).filter(Boolean)[0]);
  await visitor.selectOption('.hero-loc select[name=province]', prov);
  await visitor.waitForFunction(() => document.querySelectorAll('.hero-loc select[name=city] option').length > 1, null, { timeout: 5000 });
  const pick = await visitor.locator('.hero-loc select[name=city] option').nth(1).getAttribute('value');
  await Promise.all([visitor.waitForURL(/index\.php\?city=/), visitor.selectOption('.hero-loc select[name=city]', pick)]);
  ok(visitor.url().endsWith('index.php?city=' + pick) && (await visitor.locator('.hero-loc select[name=city]').inputValue()) === pick && await visitor.locator('.hero').isVisible(), 'home: choosing the city applies it and stays on the home page');
  ok(await visitor.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'home picker: no horizontal scroll at 390px');
  ok(csp.length === 0, 'no Content-Security-Policy violations ' + csp.slice(0, 2).join(' | '));
  ok(errors.length === 0, 'no JavaScript exceptions ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' homepage browser checks');
})().catch((e) => { console.error(e); process.exit(1); });
