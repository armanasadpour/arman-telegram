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
      sheet.querySelector('.bs-icon').textContent = card.getAttribute('data-sheet-icon') || '';
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
      t.querySelector('.tile-icon').textContent = card.getAttribute('data-sheet-icon') || '•';
      t.querySelector('.tile-title').textContent = card.getAttribute('data-sheet');
      if (pw) {
        t.classList.add('tile-pw');
        t.innerHTML = '<span class="tile-icon">🔑</span><span class="tile-title">رمز جدید</span><span class="tile-pw-row"><bdi class="tile-pw-val"></bdi>'
          + '<span class="tile-copy" role="button" tabindex="0" aria-label="کپی رمز">⧉ کپی</span></span>';
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
