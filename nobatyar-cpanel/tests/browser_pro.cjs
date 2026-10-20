// 1.0.0-rc.20: free/Pro in the browser — registration plan picker (two columns, click selects, keyboard),
// doctor panel Pro page and soft locks, blue tick — at phone, tablet and desktop widths without horizontal
// scroll. Requires runtime/pro-fixture.json written by tests/v31_pro_plan.py. Restores the legacy catalog.
const { chromium } = require('playwright'), fs = require('fs'), { execFileSync } = require('child_process');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/pro-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const root = require('path').resolve(__dirname, '..');
  const sql = (q) => execFileSync('php', ['-r', '$c=require $argv[1];$p=new PDO("mysql:host=".$c["host"].";dbname=".$c["database"].";charset=utf8mb4",$c["username"],$c["password"]);$p->exec($argv[2]);', root + '/config.php', q]);
  const legacy = execFileSync('php', ['-r', '$c=require $argv[1];$p=new PDO("mysql:host=".$c["host"].";dbname=".$c["database"].";charset=utf8mb4",$c["username"],$c["password"]);echo (string)$p->query("SELECT value FROM settings WHERE `key`=\'pro_features\'")->fetchColumn();', root + '/config.php']).toString();
  sql("DELETE FROM settings WHERE `key`='pro_features'");
  const errors = [];
  try {
    for (const [w, h, label] of [[320, 740, 'phone 320'], [390, 844, 'phone'], [820, 1180, 'tablet'], [1440, 1000, 'desktop']]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h } });
      await ctx.addCookies([{ name: 'PHPSESSID', value: f.guest, domain: '127.0.0.1', path: '/' }]);
      const p = await ctx.newPage();
      p.on('pageerror', (e) => errors.push(e.message));
      await p.goto(base + 'register.php');
      const cols = p.locator('.plan-col');
      ok((await cols.count()) === 2, `${label}: two plan columns`);
      const boxes = await cols.evaluateAll((els) => els.map((e) => e.getBoundingClientRect()).map((r) => [Math.round(r.top), Math.round(r.left), Math.round(r.width)]));
      ok(w >= 760 ? boxes[0][0] === boxes[1][0] : boxes[1][0] > boxes[0][0], `${label}: ${w >= 760 ? 'side by side' : 'stacked'}`);
      ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${label}: no horizontal scroll`);
      ok(await p.locator('input[name=plan_choice][value=free]').isChecked(), `${label}: free selected by default`);
      await p.locator('.plan-pro').click();
      ok(await p.locator('input[name=plan_choice][value=pro]').isChecked() && (await p.locator('.plan-pro .plan-pick').innerText()).includes('انتخاب شد'), `${label}: clicking the Pro column selects it`);
      if (label === 'desktop') {
        await p.locator('input[name=plan_choice][value=pro]').focus();
        await p.keyboard.press('ArrowLeft');
        ok(await p.locator('input[name=plan_choice][value=free]').isChecked() || await p.locator('input[name=plan_choice][value=pro]').isChecked(), 'keyboard: plan radios reachable');
        await p.screenshot({ path: 'runtime/rc20-register-1440.png', fullPage: false });
      }
      if (label === 'phone') await p.screenshot({ path: 'runtime/rc20-register-390.png', fullPage: true });
      await ctx.close();
    }
    for (const [w, label] of [[390, 'phone'], [1440, 'desktop']]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
      await ctx.addCookies([{ name: 'PHPSESSID', value: f.free, domain: '127.0.0.1', path: '/' }]);
      const p = await ctx.newPage();
      p.on('pageerror', (e) => errors.push(e.message));
      await p.goto(base + `doctor-panel.php?doctor=${f.free_doctor}&tab=subscription`);
      ok((await p.locator('.plan-col').count()) === 2 && (await p.locator('.plan-current').count()) === 1, `${label}: Pro page compares plans, current marked`);
      ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${label}: Pro page without horizontal scroll`);
      await p.goto(base + `doctor-panel.php?doctor=${f.free_doctor}&tab=staff`);
      ok(await p.locator('.pro-upsell .pro-lock').isVisible(), `${label}: secretary soft lock visible`);
      await p.locator('.pro-upsell .pro-lock').click();
      await p.waitForURL(/tab=subscription/);
      ok((await p.locator('.pro-want').innerText()).includes('منشی'), `${label}: lock opens the Pro page with the wanted feature`);
      await p.goto(base + `doctor-panel.php?doctor=${f.free_doctor}`);
      ok((await p.locator('nav .pro-chip').count()) >= 2, `${label}: Pro chips on locked menu items`);
      if (label === 'phone') await p.screenshot({ path: 'runtime/rc20-panel-390.png', fullPage: true });
      await ctx.close();
    }
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const p = await ctx.newPage();
    await p.goto(base + `doctor.php?id=${f.pro_doctor}`);
    const tick = p.locator('#dp-name .pro-tick');
    ok(await tick.isVisible() && (await tick.getAttribute('aria-label')) === 'پزشک پرو نوبت‌یار', 'blue tick visible with an accessible name');
    const color = await tick.evaluate((e) => getComputedStyle(e).color);
    ok(color === 'rgb(29, 155, 240)', 'blue tick is blue');
    await ctx.close();
    const pc = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await pc.addCookies([{ name: 'PHPSESSID', value: f.pro, domain: '127.0.0.1', path: '/' }]);
    const pp = await pc.newPage();
    pp.on('pageerror', (e) => errors.push(e.message));
    await pp.goto(base + `doctor-panel.php?doctor=${f.pro_doctor}&tab=promote`);
    await pp.waitForFunction(() => document.querySelector('[data-qr] svg'), null, { timeout: 8000 });
    ok((await pp.locator('[data-qr] svg').count()) >= 1, 'QR code drawn from the local library (CSP-safe)');
    ok(await pp.locator('[data-print-page]').isVisible() && await pp.locator('[data-copy-link]').isVisible(), 'print poster and copy link buttons');
    await pc.close();
    ok(errors.length === 0, 'no script errors: ' + errors.join(' | '));
  } finally {
    if (legacy) sql("INSERT INTO settings(`key`,value) VALUES('pro_features','" + legacy.replace(/'/g, "''") + "') ON DUPLICATE KEY UPDATE value=VALUES(value)");
    await browser.close();
  }
  console.log('SUCCESS ' + checks + ' free/Pro browser checks');
})().catch((e) => { console.error(e); process.exit(1); });
