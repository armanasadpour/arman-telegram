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

  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
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
  // Restore buttons when navigating back (bfcache).
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitted]').forEach(function (f) {
      delete f.dataset.submitted;
      var b = f.querySelector('button[type=submit]');
      if (b && b.dataset.label) { b.disabled = false; b.classList.remove('is-loading'); b.textContent = b.dataset.label; }
    });
  });
})();
