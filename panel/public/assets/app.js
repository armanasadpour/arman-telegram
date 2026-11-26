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
