// Minimal behaviour: mobile menu, confirmation prompts, double-submit protection.
(function () {
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('backdrop');
  var btn = document.getElementById('menu-btn');
  function toggle(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('open', open);
    backdrop.classList.toggle('open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (btn) btn.addEventListener('click', function () { toggle(!sidebar.classList.contains('open')); });
  if (backdrop) backdrop.addEventListener('click', function () { toggle(false); });

  // Confirm popup (instead of the browser's dialog): data-confirm, optional data-confirm-ok / data-confirm-cancel.
  function askConfirm(msg, okText, cancelText, done) {
    var wrap = document.createElement('div');
    wrap.className = 'cfm-backdrop';
    wrap.innerHTML = '<div class="cfm" role="alertdialog" aria-modal="true"><div class="cfm-icon">!</div><p class="cfm-msg"></p>'
      + '<div class="cfm-actions"><button type="button" class="btn cfm-ok"></button><button type="button" class="btn btn-secondary cfm-cancel"></button></div></div>';
    wrap.querySelector('.cfm-msg').textContent = msg;
    wrap.querySelector('.cfm-ok').textContent = okText || 'باشه';
    wrap.querySelector('.cfm-cancel').textContent = cancelText || 'منصرف شدم';
    function close(ok) { document.removeEventListener('keydown', onKey); wrap.remove(); done(ok); }
    function onKey(ev) { if (ev.key === 'Escape') close(false); }
    wrap.addEventListener('click', function (ev) { if (ev.target === wrap) close(false); });
    wrap.querySelector('.cfm-ok').addEventListener('click', function () { close(true); });
    wrap.querySelector('.cfm-cancel').addEventListener('click', function () { close(false); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);
    requestAnimationFrame(function () { wrap.classList.add('open'); wrap.querySelector('.cfm-ok').focus(); });
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && form.dataset.confirmed !== '1') {
      e.preventDefault();
      askConfirm(msg, form.getAttribute('data-confirm-ok'), form.getAttribute('data-confirm-cancel'), function (ok) {
        if (!ok) return;
        form.dataset.confirmed = '1';
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
      });
      return;
    }
    // Prevent double submission (duplicate operations).
    if (form.dataset.submitted === '1') { e.preventDefault(); return; }
    form.dataset.submitted = '1';
    var b = form.querySelector('button[type=submit]');
    if (b) { b.disabled = true; b.classList.add('is-loading'); b.dataset.label = b.textContent; b.textContent = 'در حال انجام…'; }
  });
  // Payment hand-off: forms marked data-autosubmit post themselves (the button stays as a fallback).
  var auto = document.querySelector('form[data-autosubmit]');
  if (auto) { setTimeout(function () { auto.submit(); }, 300); }
  // Usage meters: start full and drain down to the real value.
  document.querySelectorAll('progress[data-animate]').forEach(function (bar) {
    var target = +bar.getAttribute('value'); bar.value = 100;
    var start = null;
    function step(ts) {
      if (start === null) start = ts;
      var k = Math.min(1, (ts - start) / 900), e = 1 - Math.pow(1 - k, 3);
      bar.value = 100 - (100 - target) * e;
      if (k < 1) requestAnimationFrame(step);
    }
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { bar.value = target; return; }
    setTimeout(function () { requestAnimationFrame(step); }, 150);
  });
  // Remaining days / volume rings: draw the arc and count the number up.
  (function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fa = function (s) { return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }).replace('.', '٫'); };
    document.querySelectorAll('.ring-arc[data-ring]').forEach(function (arc) {
      var c = +arc.getAttribute('data-c'), pct = Math.max(0, Math.min(100, +arc.getAttribute('data-ring')));
      var to = c * (1 - pct / 100);
      if (reduce) { arc.style.transition = 'none'; arc.style.strokeDashoffset = to; return; }
      requestAnimationFrame(function () { setTimeout(function () { arc.style.strokeDashoffset = to; }, 120); });
    });
    document.querySelectorAll('.ring-num[data-count]').forEach(function (el) {
      var target = +el.getAttribute('data-count'), dec = +el.getAttribute('data-dec') || 0;
      if (reduce || !isFinite(target)) return;
      var start = null;
      function step(ts) {
        if (start === null) start = ts;
        var k = Math.min(1, (ts - start) / 1400), e = 1 - Math.pow(1 - k, 3);
        el.textContent = fa((target * e).toFixed(dec));
        if (k < 1) requestAnimationFrame(step);
      }
      el.textContent = fa((0).toFixed(dec));
      setTimeout(function () { requestAnimationFrame(step); }, 120);
    });
  })();
  // Save a QR image to the gallery: share sheet with the PNG on phones (→ Save Image), download elsewhere.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-save-img]');
    if (!b) return;
    var img = b.closest('.qr-box').querySelector('img'), name = b.getAttribute('data-save-img') || 'qr.png';
    var download = function () { var a = document.createElement('a'); a.href = img.src; a.download = name; document.body.appendChild(a); a.click(); a.remove(); };
    fetch(img.src).then(function (r) { return r.blob(); }).then(function (blob) {
      var file = new File([blob], name, { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file] }).catch(function (err) { if (err && err.name !== 'AbortError') download(); });
      } else { download(); }
    }).catch(download);
  });
  // Notifications: poll every minute; new notices show a toast, a sound and (if allowed) a system notification.
  (function () {
    var bell = document.querySelector('.bell[data-poll]');
    var enableBtn = document.querySelector('[data-enable-notify]'), onBadge = document.querySelector('[data-notify-on]');
    var canNotify = 'Notification' in window;
    function refreshBtn() {
      if (!enableBtn) return;
      var granted = canNotify && Notification.permission === 'granted';
      enableBtn.hidden = !canNotify || granted || Notification.permission === 'denied';
      if (onBadge) onBadge.hidden = !granted;
    }
    refreshBtn();
    if (enableBtn) enableBtn.addEventListener('click', function () { Notification.requestPermission().then(refreshBtn); });
    if (!bell) return;
    var last = +bell.getAttribute('data-last') || 0, count = bell.querySelector('.bell-count');
    function beep() {
      try { var C = window.AudioContext || window.webkitAudioContext, ctx = new C(), o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = 880; g.gain.value = .08; o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + .18); } catch (x) {}
    }
    function toast(n) {
      var t = document.createElement('a'); t.href = bell.href; t.className = 'toast toast-' + n.level;
      t.innerHTML = '<strong></strong><span></span>'; t.querySelector('strong').textContent = n.title; t.querySelector('span').textContent = n.body || '';
      document.body.appendChild(t); requestAnimationFrame(function () { t.classList.add('show'); });
      setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 400); }, 9000);
    }
    function system(n) {
      if (!canNotify || Notification.permission !== 'granted') return;
      var opts = { body: n.body || '', icon: '/pwa/icon-192.png', tag: 'notice-' + n.id, data: { url: bell.href } };
      if (navigator.serviceWorker && navigator.serviceWorker.getRegistration) {
        navigator.serviceWorker.getRegistration().then(function (r) { if (r) r.showNotification(n.title, opts); else new Notification(n.title, opts); }).catch(function () {});
      } else { try { new Notification(n.title, opts); } catch (x) {} }
    }
    function poll() {
      fetch(bell.getAttribute('data-poll') + '?after=' + last, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
          if (!d) return;
          count.hidden = !d.unread; count.textContent = d.unread > 9 ? '۹+' : String(d.unread).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[x]; });
          if (d.new && d.new.length) { beep(); d.new.forEach(function (n) { toast(n); system(n); }); }
          last = Math.max(last, d.last || 0);
        }).catch(function () {});
    }
    setInterval(function () { if (!document.hidden || 'serviceWorker' in navigator) poll(); }, 60000);
    if (bell.hasAttribute('data-poll-now')) setTimeout(poll, 1200);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  })();
  // Bar widths come from data-w (inline style attributes are blocked by the CSP).
  document.querySelectorAll('.bar i[data-w]').forEach(function (i) { i.style.width = Math.max(0, Math.min(100, +i.getAttribute('data-w'))) + '%'; });
  // Money inputs: show thousands separators while typing (1,250,000); the server strips them (NormalizeMoneyInput).
  (function () {
    var toEn = function (v) { return String(v).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); };
    var fmt = function (v) { var d = toEn(v).replace(/\D/g, '').replace(/^0+(?=\d)/, ''); return d.replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
    document.querySelectorAll('input[data-money]').forEach(function (el) {
      el.setAttribute('type', 'text'); el.setAttribute('dir', 'ltr'); el.setAttribute('autocomplete', 'off');
      if (el.value) el.value = fmt(el.value);
      el.addEventListener('input', function () {
        var before = el.value, pos = el.selectionStart || before.length, digitsLeft = toEn(before.slice(0, pos)).replace(/\D/g, '').length;
        el.value = fmt(before);
        var i = 0, n = 0; while (i < el.value.length && n < digitsLeft) { if (/\d/.test(el.value[i])) n++; i++; }
        try { el.setSelectionRange(i, i); } catch (x) {}
      });
    });
  })();
  // Receipt image preview.
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var img = document.querySelector(inp.getAttribute('data-preview')), f = inp.files && inp.files[0];
      if (!img || !f) return; img.src = URL.createObjectURL(f); img.hidden = false;
      var hint = img.parentNode.querySelector('.upload-hint'); if (hint) hint.lastChild.textContent = f.name;
    });
  });
  // Package dropdown (reseller sell page).
  document.querySelectorAll('[data-dd]').forEach(function (dd) {
    var trig = dd.querySelector('[data-dd-trigger]'), panel = dd.querySelector('.dd-panel'), cur = dd.querySelector('.dd-current');
    function sync() {
      var r = dd.querySelector('input[type=radio]:checked') || dd.querySelector('input[type=radio]');
      if (!r) return; r.checked = true;
      var row = r.parentNode.querySelector('.dd-row').cloneNode(true); var ck = row.querySelector('.dd-check'); if (ck) ck.remove();
      cur.innerHTML = ''; cur.appendChild(row);
    }
    function open(v) { panel.hidden = !v; trig.setAttribute('aria-expanded', String(v)); dd.classList.toggle('open', v); }
    trig.addEventListener('click', function () { open(panel.hidden); });
    dd.addEventListener('change', function (e) { if (e.target.name === 'package_id') { sync(); open(false); } });
    document.addEventListener('click', function (e) { if (!dd.contains(e.target)) open(false); });
    sync();
  });
  // Copy a literal value (bank card / sheba: English digits).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy-text]');
    if (!b) return;
    var text = b.getAttribute('data-copy-text'), old = b.textContent;
    var done = function () { b.textContent = 'کپی شد ✓'; b.classList.add('ok'); setTimeout(function () { b.textContent = old; b.classList.remove('ok'); }, 1500); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done);
    else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} ta.remove(); }
  });
  // Username with a fixed reseller prefix: the prefix stays at the start of the field and cannot be deleted.
  document.querySelectorAll('input[data-lock-prefix]').forEach(function (el) {
    var pre = el.getAttribute('data-lock-prefix');
    function fix() {
      var v = el.value.toLowerCase();
      if (v.indexOf(pre) !== 0) {
        var rest = v; for (var i = pre.length; i > 0; i--) { if (v.indexOf(pre.slice(0, i)) === 0) { rest = v.slice(i); break; } }
        el.value = pre + rest;
      } else if (v !== el.value) { el.value = v; }
      if ((el.selectionStart || 0) < pre.length) { try { el.setSelectionRange(pre.length, Math.max(pre.length, el.selectionEnd || 0)); } catch (x) {} }
    }
    el.addEventListener('input', fix);
    el.addEventListener('keydown', function (e) {
      var s = el.selectionStart || 0, en = el.selectionEnd || 0;
      if ((e.key === 'Backspace' && s <= pre.length && en <= pre.length) || (e.key === 'Delete' && s < pre.length) || (e.key === 'Home')) {
        e.preventDefault(); try { el.setSelectionRange(pre.length, pre.length); } catch (x) {}
      }
    });
    ['focus', 'click', 'select'].forEach(function (ev) { el.addEventListener(ev, function () { setTimeout(function () { if ((el.selectionStart || 0) < pre.length) { try { el.setSelectionRange(pre.length, Math.max(pre.length, el.selectionEnd || 0)); } catch (x) {} } }, 0); }); });
    fix();
  });
  // Small helpers that would otherwise be inline handlers (the CSP blocks inline scripts).
  document.addEventListener('click', function (e) {
    var tg = e.target.closest('[data-toggle]');
    if (tg) {
      var box = document.querySelector(tg.getAttribute('data-toggle'));
      if (box) { box.hidden = !box.hidden; tg.setAttribute('aria-expanded', String(!box.hidden)); }
      return;
    }
    var sel = e.target.closest('[data-select-all]');
    if (sel) { sel.select(); return; }
    var cap = e.target.closest('[data-captcha-url]');
    if (cap) {
      fetch(cap.getAttribute('data-captcha-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) { document.getElementById('captcha-img').src = d.img; var i = document.getElementById('captcha'); i.value = ''; i.focus(); })
        .catch(function () { location.reload(); });
    }
  });
  // Create-account helpers: random password, quick-value chips.
  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-gen-pass]');
    if (g) {
      var input = document.querySelector(g.getAttribute('data-gen-pass'));
      var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', out = '';
      var rnd = new Uint32Array(10); (window.crypto || window.msCrypto).getRandomValues(rnd);
      for (var i = 0; i < 10; i++) out += chars[rnd[i] % chars.length];
      if (input) { input.value = out; input.focus(); input.select(); }
      return;
    }
    var cp = e.target.closest('[data-copy]');
    if (cp) {
      var src = document.querySelector(cp.getAttribute('data-copy'));
      var text = src ? (src.value !== undefined && src.tagName !== 'PRE' ? src.value : src.textContent) : '';
      var done = function () { var old = cp.textContent; cp.textContent = 'کپی شد ✓'; setTimeout(function () { cp.textContent = old; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); }
      else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} document.body.removeChild(ta); }
      return;
    }
    var c = e.target.closest('[data-set]');
    if (c) {
      var t = document.querySelector(c.getAttribute('data-set'));
      if (t) { t.value = c.getAttribute('data-value'); t.dispatchEvent(new Event('input', { bubbles: true })); }
      c.parentNode.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('active', x === c); });
    }
  });
  // Router form: switching transport moves the port to that transport's default.
  document.querySelectorAll('input[name=transport][data-port]').forEach(function (r) {
    r.addEventListener('change', function () {
      var port = document.getElementById('port');
      var defaults = Array.prototype.map.call(document.querySelectorAll('input[name=transport][data-port]'), function (x) { return x.getAttribute('data-port'); });
      if (port && (port.value === '' || defaults.indexOf(port.value) !== -1)) port.value = r.getAttribute('data-port');
    });
  });
  // Technical one-line fields (tokens, URLs, keys, usernames): a paste replaces the field instead of
  // being added to what is there, so pasting again after a slow phone paste does not double the value.
  document.querySelectorAll('input.input.ltr:not([type=number]):not([type=password]):not([type=file]), input[data-paste-replace]').forEach(function (inp) {
    inp.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData);
      t = t ? t.getData('text') : null;
      if (t === null || t === undefined) return;
      e.preventDefault();
      var keepSpaces = /password|comment/.test(inp.name);
      inp.value = keepSpaces ? t.replace(/[\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]/g, '').trim()
        : t.replace(/[\s\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]+/g, '');
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      try { inp.setSelectionRange(inp.value.length, inp.value.length); inp.scrollLeft = inp.scrollWidth; } catch (x) {}
    });
  });
  // Installable app: service worker + "install" button (Android/desktop); iOS gets a hint on the settings page.
  if ('serviceWorker' in navigator && window.isSecureContext) {
    var swLink = document.querySelector('link[rel=manifest][data-sw]');
    if (swLink) navigator.serviceWorker.register(swLink.getAttribute('data-sw')).catch(function () {});
  }
  var installBtn = document.getElementById('install-btn'), deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e;
    document.querySelectorAll('#install-btn, [data-install]').forEach(function (b) { b.hidden = false; });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('#install-btn, [data-install]');
    if (!b || !deferred) return;
    deferred.prompt();
    deferred.userChoice.finally(function () { deferred = null; document.querySelectorAll('#install-btn, [data-install]').forEach(function (x) { x.hidden = true; }); });
  });
  // Colored line icons for the account tiles (keyed by the card's emoji).
  var TILE_ICONS = (function () {
    var p = function (d) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + d + '</svg>'; };
    return {
      'ℹ️': ['indigo', p('<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>')],
      '🟢': ['green', p('<path d="M3 12h4l2-5 4 10 2-5h6"/>')],
      '🌐': ['blue', p('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/>')],
      '✏️': ['amber', p('<path d="M4 20l4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20z"/><path d="M14 7l3 3"/>')],
      '📦': ['teal', p('<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/>')],
      '📅': ['rose', p('<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M9 15l2 2 4-4"/>')],
      '📶': ['violet', p('<path d="M6 20v-5M11 20v-9M16 20V7M21 20V4"/>')],
      '📊': ['violet', p('<path d="M4 20h16M7 16v-4M12 16V8M17 16v-7"/>')],
      '↺': ['sky', p('<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/>')],
      '⛔': ['red', p('<rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="16" r="1.2"/>')],
      '⚙️': ['slate', p('<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>')],
      '🔑': ['amber', p('<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l2 2M15 8l2 2"/>')],
      '🗝': ['amber', p('<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l2 2M15 8l2 2"/>')],
      '👁': ['violet', p('<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>')],
      '🗑': ['red', p('<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/>')]
    };
  })();
  function setIcon(el, emoji) {
    var ic = TILE_ICONS[emoji];
    if (ic) { el.innerHTML = ic[1]; el.classList.add('ti-' + ic[0]); } else { el.textContent = emoji || '•'; }
  }
  // Account pages: every [data-sheet] card becomes a tile; tapping it opens the card in a bottom sheet.
  (function () {
    var holder = document.querySelector('[data-sheet-tiles]');
    if (!holder) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-sheet]'));
    if (!cards.length) return;
    var backdrop = document.createElement('div'); backdrop.className = 'bs-backdrop';
    var sheet = document.createElement('div'); sheet.className = 'bs'; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML = '<div class="bs-grip" aria-hidden="true"></div><div class="bs-head"><span class="bs-icon"></span><h2 class="bs-title"></h2>'
      + '<button type="button" class="bs-close" aria-label="بستن">✕</button></div><div class="bs-body"></div>';
    document.body.appendChild(backdrop); document.body.appendChild(sheet);
    var body = sheet.querySelector('.bs-body'), current = null, home = null;
    function close() {
      if (!current) return;
      sheet.classList.remove('open'); backdrop.classList.remove('open'); document.documentElement.classList.remove('bs-lock');
      var c = current; current = null;
      setTimeout(function () { if (!current) { home.appendChild(c); c.hidden = true; } }, 220);
    }
    function open(card) {
      if (current) { home.appendChild(current); current.hidden = true; }
      current = card; home = card.parentNode;
      sheet.querySelector('.bs-title').textContent = card.getAttribute('data-sheet');
      var bi = sheet.querySelector('.bs-icon'); bi.className = 'bs-icon'; setIcon(bi, card.getAttribute('data-sheet-icon') || '');
      sheet.classList.toggle('bs-danger', card.getAttribute('data-sheet-tone') === 'danger');
      card.hidden = false; body.innerHTML = ''; body.appendChild(card);
      document.documentElement.classList.add('bs-lock');
      requestAnimationFrame(function () { backdrop.classList.add('open'); sheet.classList.add('open'); });
    }
    backdrop.addEventListener('click', close);
    sheet.querySelector('.bs-close').addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && current && !document.querySelector('.cfm-backdrop')) close(); });
    var y0 = null;
    sheet.addEventListener('touchstart', function (e) { y0 = body.scrollTop <= 0 ? e.touches[0].clientY : null; }, { passive: true });
    sheet.addEventListener('touchmove', function (e) { if (y0 !== null && e.touches[0].clientY - y0 > 0) sheet.style.transform = 'translateY(' + (e.touches[0].clientY - y0) + 'px)'; }, { passive: true });
    sheet.addEventListener('touchend', function (e) {
      var d = y0 !== null ? e.changedTouches[0].clientY - y0 : 0; sheet.style.transform = ''; y0 = null;
      if (d > 90) close();
    });
    cards.forEach(function (card) {
      var t = document.createElement('button'); t.type = 'button';
      t.className = 'tile' + (card.getAttribute('data-sheet-tone') === 'danger' ? ' tile-danger' : '');
      var pw = card.getAttribute('data-sheet-pw');
      t.innerHTML = '<span class="tile-icon"></span><span class="tile-title"></span>';
      setIcon(t.querySelector('.tile-icon'), card.getAttribute('data-sheet-icon'));
      if (card.getAttribute('data-sheet-wide') !== null || card.getAttribute('data-sheet-icon') === '🗑') t.classList.add('tile-wide');
      t.querySelector('.tile-title').textContent = card.getAttribute('data-sheet');
      if (pw) {
        t.classList.add('tile-pw');
        t.innerHTML = '<span class="tile-icon"></span><span class="tile-title">رمز جدید</span><span class="tile-pw-row"><bdi class="tile-pw-val"></bdi>'
          + '<span class="tile-copy" role="button" tabindex="0" aria-label="کپی رمز">⧉ کپی</span></span>';
        setIcon(t.querySelector('.tile-icon'), '🔑');
        t.querySelector('.tile-pw-val').textContent = pw;
        t.querySelector('.tile-copy').addEventListener('click', function (ev) {
          ev.stopPropagation();
          var el = ev.currentTarget, done = function () { el.textContent = '✓ کپی شد'; setTimeout(function () { el.textContent = '⧉ کپی'; }, 1500); };
          if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(pw).then(done);
          else { var ta = document.createElement('textarea'); ta.value = pw; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} ta.remove(); }
        });
      }
      t.addEventListener('click', function () { open(card); });
      holder.appendChild(t);
      card.hidden = true;
    });
    // hide wrappers that only held moved cards
    document.querySelectorAll('.grid.two').forEach(function (g) {
      if (!Array.prototype.some.call(g.children, function (c) { return !c.hidden; })) g.hidden = true;
    });
  })();
  // "Select all" checkboxes.
  document.querySelectorAll('[data-check-all]').forEach(function (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll(all.getAttribute('data-check-all')).forEach(function (c) { c.checked = all.checked; });
    });
  });
  // Restore buttons when navigating back (bfcache).
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitted]').forEach(function (f) {
      delete f.dataset.submitted;
      var b = f.querySelector('button[type=submit]');
      if (b && b.dataset.label) { b.disabled = false; b.classList.remove('is-loading'); b.textContent = b.dataset.label; }
    });
  });
})();
