// Admin → Settings → Calls: tests the saved STUN/TURN servers from THIS browser (the admin's own network).
// It asks the browser to gather connection candidates with the same servers the app uses for calls:
//  • «srflx» candidate = a STUN server answered (your public address was learned) → direct calls possible
//  • «relay» candidate = the TURN server accepted the credentials and gave a relay address → calls work
//    even through mobile-operator NAT and strict firewalls.
(() => {
  const btn = document.getElementById('ice-test');
  const out = document.getElementById('ice-result');
  if (!btn || !out) return;
  const line = (ok, text) => { const p = document.createElement('p'); p.className = 'small'; p.textContent = (ok === null ? '• ' : ok ? '✅ ' : '❌ ') + text; out.append(p); };
  btn.addEventListener('click', async () => {
    btn.disabled = true; out.replaceChildren(); line(null, 'در حال آزمایش (حداکثر ۱۰ ثانیه)…');
    let cfg;
    try { const r = await fetch(btn.dataset.url, { credentials: 'same-origin' }); cfg = await r.json(); }
    catch { out.replaceChildren(); line(false, 'دریافت تنظیمات از سرور ممکن نشد.'); btn.disabled = false; return; }
    const stunUrls = [], turnUrls = [];
    for (const s of cfg.ice_servers || []) for (const u of [].concat(s.urls)) (/^turns?:/i.test(u) ? turnUrls : stunUrls).push(u);
    const found = { host: 0, srflx: 0, relay: 0 }, errors = [];
    let pc;
    try {
      pc = new RTCPeerConnection({ iceServers: cfg.ice_servers || [] });
      pc.createDataChannel('test');
      pc.onicecandidate = (e) => { const c = e.candidate?.candidate; const m = c && / typ (host|srflx|relay)/.exec(c); if (m) found[m[1]]++; };
      pc.onicecandidateerror = (e) => { if (e.url && !errors.some((x) => x.url === e.url && x.code === e.errorCode)) errors.push({ url: e.url, code: e.errorCode, text: e.errorText || '' }); };
      await pc.setLocalDescription(await pc.createOffer());
      await new Promise((res) => { const t = setTimeout(res, 10000); pc.onicegatheringstatechange = () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); } }; });
    } catch (e) { errors.push({ url: '', code: 0, text: String(e?.message || e) }); }
    try { pc?.close(); } catch { /* closed */ }
    out.replaceChildren();
    if (!stunUrls.length) line(false, 'هیچ سرور STUN تنظیم نشده است.');
    else line(found.srflx > 0, found.srflx > 0 ? 'STUN کار می‌کند (' + stunUrls.join('، ') + ')' : 'STUN جواب نداد (' + stunUrls.join('، ') + ') — احتمالاً از این شبکه در دسترس نیست یا فیلتر است.');
    if (cfg.turn_error) line(false, 'دریافت رمز TURN از Cloudflare ممکن نشد: ' + cfg.turn_error + ' — Key ID و API Token را بررسی کنید؛ اگر هاست به اینترنت خارج دسترسی ندارد، «پراکسی اعلان» را در بخش اعلان‌ها تنظیم کنید (Cloudflare هم از همان استفاده می‌کند).');
    if (!turnUrls.length || !cfg.has_turn) line(false, 'سرور TURN تنظیم نشده است. بدون TURN، تماس بین اینترنت همراه (مثلاً همراه اول/ایرانسل) و شبکه‌های دیگر اغلب وصل نمی‌شود.');
    else line(found.relay > 0, found.relay > 0 ? 'TURN کار می‌کند — نشانی رله گرفته شد (' + turnUrls.join('، ') + ')' : 'TURN نشانی رله نداد (' + turnUrls.join('، ') + ') — نشانی، پورت، فایروال یا راز مشترک را بررسی کنید.');
    for (const e of errors.slice(0, 6)) line(null, 'خطا از ' + (e.url || 'مرورگر') + (e.code ? ' — کد ' + e.code : '') + (e.text ? ' (' + e.text + ')' : ''));
    line(null, 'نتیجه: ' + (found.relay > 0 ? 'تماس‌ها باید روی همه شبکه‌ها برقرار شوند.' : found.srflx > 0 ? 'تماس روی بیشتر وای‌فای‌ها کار می‌کند، اما روی اینترنت همراه ممکن است وصل نشود (TURN لازم است).' : 'فقط تماس داخل یک شبکه محلی ممکن است.'));
    line(null, 'این آزمایش از شبکه همین مرورگر انجام شد؛ برای اطمینان آن را یک بار هم روی گوشی با اینترنت همراه باز کنید.');
    btn.disabled = false;
  });
})();
