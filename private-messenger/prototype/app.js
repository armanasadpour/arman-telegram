// Private Messenger — UI prototype (Phase 1).
// Local-only interactions for reviewing layout and flows. No network, no real cryptography.
'use strict';

(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const toLatin = (s) => s.replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));

  // ---------- navigation
  let callTimer = null;
  let otpTimer = null;
  function go(name) {
    $$('.screen').forEach((s) => s.classList.toggle('active', s.id === 's-' + name));
    $$('.proto-bar [data-go]').forEach((b) => b.setAttribute('aria-current', String(b.dataset.go === name)));
    if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    clearInterval(callTimer);
    clearInterval(otpTimer);
    if (name === 'call') startCall();
    if (name === 'otp') startOtpTimer();
    window.scrollTo(0, 0);
  }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-go]');
    if (!t) return;
    e.preventDefault();
    go(t.dataset.go);
  });

  // ---------- theme
  const themes = ['system', 'light', 'dark'];
  const themeLabel = { system: 'سیستم', light: 'روشن', dark: 'تاریک' };
  let theme = 'system';
  try { theme = localStorage.getItem('pm-proto-theme') || 'system'; } catch (_) { /* storage unavailable */ }
  const applyTheme = () => {
    if (theme === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', theme);
    $('#theme').textContent = 'حالت: ' + themeLabel[theme];
  };
  $('#theme').addEventListener('click', () => {
    theme = themes[(themes.indexOf(theme) + 1) % themes.length];
    try { localStorage.setItem('pm-proto-theme', theme); } catch (_) { /* ignore */ }
    applyTheme();
  });
  applyTheme();

  // ---------- OTP
  const otpInputs = $$('#otp input');
  otpInputs.forEach((inp, i) => {
    inp.addEventListener('input', () => {
      inp.value = toLatin(inp.value).replace(/\D/g, '').slice(-1);
      if (inp.value && otpInputs[i + 1]) otpInputs[i + 1].focus();
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && otpInputs[i - 1]) otpInputs[i - 1].focus();
    });
    inp.addEventListener('paste', (e) => {
      const digits = toLatin(e.clipboardData.getData('text')).replace(/\D/g, '').slice(0, 6);
      if (!digits) return;
      e.preventDefault();
      digits.split('').forEach((d, k) => { if (otpInputs[k]) otpInputs[k].value = d; });
    });
  });
  function startOtpTimer() {
    let left = 60;
    const el = $('#otp-timer');
    const tick = () => {
      el.textContent = fa(String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0'));
      if (left-- <= 0) clearInterval(otpTimer);
    };
    tick();
    otpTimer = setInterval(tick, 1000);
  }

  // ---------- recovery key (display sample only; real key is generated in the crypto worker)
  const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32
  const rnd = crypto.getRandomValues(new Uint8Array(52));
  const rk = Array.from(rnd, (b) => alphabet[b & 31]).join('');
  const rkBox = $('#rk');
  for (let i = 0; i < 52; i += 4) {
    const s = document.createElement('span');
    s.textContent = rk.slice(i, i + 4);
    rkBox.appendChild(s);
  }

  // ---------- conversations
  const convs = [
    { name: 'سارا محمدی', last: 'تصویر', time: '۰۹:۱۵', unread: 0, color: '#7c3aed', active: true },
    { name: 'علی رضایی', last: 'جلسه ساعت ۱۰ برگزار می‌شود', time: '۰۸:۳۰', unread: 3, color: '#0891b2' },
    { name: 'مریم احمدی', last: 'پیام صوتی (۰:۴۲)', time: 'دیروز', unread: 0, color: '#db2777' },
    { name: 'واحد فناوری', last: 'گروه‌ها در نسخه ۱ فعال نیستند', time: '', unread: 0, color: '#64748b', disabled: true },
    { name: 'حسین کریمی', last: 'تماس از دست رفته', time: 'شنبه', unread: 1, color: '#ea580c', muted: true },
    { name: 'نرگس صادقی', last: 'قرارداد-نهایی.docx', time: '۱۴۰۵/۰۸/۱۰', unread: 0, color: '#16a34a' },
  ];
  const list = $('#convs');
  function renderConvs(filter = '') {
    list.textContent = '';
    convs
      .filter((c) => !c.disabled)
      .filter((c) => !filter || c.name.includes(filter) || c.last.includes(filter))
      .forEach((c) => {
        const b = document.createElement('button');
        b.className = 'conv';
        b.setAttribute('aria-current', String(!!c.active));
        const av = document.createElement('div');
        av.className = 'avatar';
        av.style.background = c.color;
        av.textContent = c.name[0];
        const body = document.createElement('div');
        body.className = 'conv-body';
        const top = document.createElement('div');
        top.className = 'conv-top';
        const nm = document.createElement('span');
        nm.className = 'conv-name';
        nm.textContent = c.name + (c.muted ? ' 🔕' : '');
        const tm = document.createElement('span');
        tm.className = 'conv-time';
        tm.textContent = c.time;
        top.append(nm, tm);
        const last = document.createElement('div');
        last.className = 'conv-last';
        const l = document.createElement('span');
        l.textContent = c.last;
        last.appendChild(l);
        if (c.unread) {
          const bd = document.createElement('span');
          bd.className = 'badge' + (c.muted ? ' muted-b' : '');
          bd.textContent = fa(c.unread);
          last.appendChild(bd);
        }
        body.append(top, last);
        b.append(av, body);
        b.addEventListener('click', () => {
          convs.forEach((x) => { x.active = x === c; });
          renderConvs($('#q').value.trim());
          $('#app').classList.add('show-chat');
        });
        list.appendChild(b);
      });
    if (!list.children.length) {
      const p = document.createElement('p');
      p.className = 'muted small';
      p.style.padding = '12px';
      p.textContent = 'نتیجه‌ای در پیام‌های این دستگاه یافت نشد.';
      list.appendChild(p);
    }
  }
  renderConvs();
  $('#q').addEventListener('input', (e) => renderConvs(e.target.value.trim()));
  $('#back').addEventListener('click', () => $('#app').classList.remove('show-chat'));

  // ---------- composer (local echo only; text rendered with textContent, never innerHTML)
  const msgs = $('#msgs');
  const text = $('#text');
  $('#composer').addEventListener('submit', (e) => {
    e.preventDefault();
    const v = text.value.trim();
    if (!v) return;
    const m = document.createElement('div');
    m.className = 'msg out';
    m.append(document.createTextNode(v));
    const meta = document.createElement('div');
    meta.className = 'meta';
    const now = new Date();
    meta.textContent = fa(String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')) + ' · در صف ارسال';
    m.appendChild(meta);
    msgs.appendChild(m);
    text.value = '';
    msgs.scrollTop = msgs.scrollHeight;
    setTimeout(() => { meta.textContent = meta.textContent.replace('در صف ارسال', 'ارسال‌شده'); }, 700);
  });
  text.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#composer').requestSubmit(); }
  });

  // ---------- safety number (sample digits) + QR-like pattern
  const sn = $('#sn');
  const d = crypto.getRandomValues(new Uint32Array(12));
  d.forEach((x) => {
    const s = document.createElement('span');
    s.textContent = String(x % 100000).padStart(5, '0');
    sn.appendChild(s);
  });
  const qr = $('#qr');
  const ns = 'http://www.w3.org/2000/svg';
  const bits = crypto.getRandomValues(new Uint8Array(29 * 29));
  const finder = (x, y) => [[0, 0], [22, 0], [0, 22]].some(([fx, fy]) => x >= fx && x < fx + 7 && y >= fy && y < fy + 7);
  for (let y = 0; y < 29; y++) {
    for (let x = 0; x < 29; x++) {
      let on = bits[y * 29 + x] & 1;
      if (finder(x, y)) {
        const lx = x % 22 === x ? x : x - 22;
        const ly = y % 22 === y ? y : y - 22;
        on = (lx === 0 || lx === 6 || ly === 0 || ly === 6 || (lx >= 2 && lx <= 4 && ly >= 2 && ly <= 4)) ? 1 : 0;
      }
      if (!on) continue;
      const r = document.createElementNS(ns, 'rect');
      r.setAttribute('x', x); r.setAttribute('y', y); r.setAttribute('width', 1); r.setAttribute('height', 1);
      r.setAttribute('fill', 'currentColor');
      qr.appendChild(r);
    }
  }
  $('#verify').addEventListener('click', () => {
    $('#verify-state').textContent = 'وضعیت: تأییدشده روی این دستگاه';
    $$('.keywarn').forEach((k) => k.classList.add('hidden'));
  });

  // ---------- switches
  $$('.switch').forEach((s) => s.addEventListener('click', () => {
    s.setAttribute('aria-checked', String(s.getAttribute('aria-checked') !== 'true'));
  }));
  $('#ret').addEventListener('click', () => {
    const on = $('#ret').getAttribute('aria-checked') === 'true';
    $('#ret-desc').textContent = on
      ? 'فعال — فایل‌های این حساب تا لغو این گزینه حذف خودکار نمی‌شوند. (ثبت در گزارش ممیزی)'
      : 'غیرفعال — فایل‌ها ۷ روز پس از آپلود، و فایل‌های موجود ۷ روز پس از زمان لغو، حذف می‌شوند.';
  });

  // ---------- call
  $$('[data-toggle]').forEach((b) => b.addEventListener('click', () => {
    b.setAttribute('aria-pressed', String(b.getAttribute('aria-pressed') !== 'true'));
  }));
  function startCall() {
    const st = $('#call-state');
    st.textContent = 'در حال اتصال…';
    let sec = -2;
    callTimer = setInterval(() => {
      sec++;
      if (sec < 0) return;
      st.textContent = 'متصل (مستقیم) · ' + fa(String(Math.floor(sec / 60)).padStart(2, '0') + ':' + String(sec % 60).padStart(2, '0'));
    }, 1000);
  }

  // ---------- initial screen
  const initial = location.hash.slice(1);
  go($('#s-' + initial) ? initial : 'login');
})();
