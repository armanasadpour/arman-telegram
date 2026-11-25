// Jalali date picker for inputs marked data-jdate. No dependencies; value format ۱۴۰۵/۰۹/۱۰.
(function () {
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  var WEEK = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
  function fa(n) { return String(n).replace(/[0-9]/g, function (d) { return FA[d]; }); }
  function en(s) { return String(s).replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; }); }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function div(a, b) { return ~~(a / b); }
  function g2j(gy, gm, gd) {
    var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = gm > 2 ? gy + 1 : gy;
    var days = 355666 + 365 * gy + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) + gd + gdm[gm - 1];
    var jy = -1595 + 33 * div(days, 12053); days %= 12053;
    jy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { jy += div(days - 1, 365); days = (days - 1) % 365; }
    return days < 186 ? [jy, 1 + div(days, 31), 1 + days % 31] : [jy, 7 + div(days - 186, 30), 1 + (days - 186) % 30];
  }
  function j2g(jy, jm, jd) {
    jy += 1595;
    var days = -355668 + 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    var gy = 400 * div(days, 146097); days %= 146097;
    if (days > 36524) { gy += 100 * div(--days, 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { gy += div(days - 1, 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var ml = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 0;
    for (gm = 0; gm < 13 && gd > ml[gm]; gm++) gd -= ml[gm];
    return [gy, gm, gd];
  }
  function monthLen(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    var g = j2g(jy, 12, 30), back = g2j(g[0], g[1], g[2]);
    return back[1] === 12 && back[2] === 30 ? 30 : 29;
  }
  function today() { var d = new Date(); return g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); }
  function parse(v) {
    var m = en(v || '').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
    return m ? [+m[1], +m[2], +m[3]] : null;
  }

  var open = null;
  function close() { if (open) { open.pop.remove(); open = null; } }

  function show(input) {
    close();
    var sel = parse(input.value), t = today();
    var cur = sel ? [sel[0], sel[1]] : [t[0], t[1]];
    var pop = document.createElement('div');
    pop.className = 'jdp';
    pop.setAttribute('role', 'dialog');
    input.parentNode.appendChild(pop);
    open = { input: input, pop: pop };

    function btn(label, cls, fn) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = cls; b.textContent = label;
      b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); fn(); });
      return b;
    }
    function render() {
      pop.innerHTML = '';
      var head = document.createElement('div'); head.className = 'jdp-head';
      head.appendChild(btn('›', 'jdp-nav', function () { if (--cur[1] < 1) { cur[1] = 12; cur[0]--; } render(); }));
      var title = document.createElement('span'); title.textContent = MONTHS[cur[1] - 1] + ' ' + fa(cur[0]); head.appendChild(title);
      head.appendChild(btn('‹', 'jdp-nav', function () { if (++cur[1] > 12) { cur[1] = 1; cur[0]++; } render(); }));
      pop.appendChild(head);
      var grid = document.createElement('div'); grid.className = 'jdp-grid';
      WEEK.forEach(function (w) { var s = document.createElement('span'); s.className = 'jdp-w'; s.textContent = w; grid.appendChild(s); });
      var g = j2g(cur[0], cur[1], 1);
      var offset = (new Date(g[0], g[1] - 1, g[2]).getDay() + 1) % 7;   // Saturday first
      for (var i = 0; i < offset; i++) grid.appendChild(document.createElement('span'));
      var len = monthLen(cur[0], cur[1]);
      for (var d = 1; d <= len; d++) {
        (function (day) {
          var cls = 'jdp-d';
          if (sel && sel[0] === cur[0] && sel[1] === cur[1] && sel[2] === day) cls += ' sel';
          if (t[0] === cur[0] && t[1] === cur[1] && t[2] === day) cls += ' today';
          grid.appendChild(btn(fa(day), cls, function () {
            input.value = fa(cur[0] + '/' + pad(cur[1]) + '/' + pad(day));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            close();
          }));
        })(d);
      }
      pop.appendChild(grid);
      var foot = document.createElement('div'); foot.className = 'jdp-foot';
      foot.appendChild(btn('امروز', 'btn btn-secondary btn-sm', function () { cur = [t[0], t[1]]; input.value = fa(t[0] + '/' + pad(t[1]) + '/' + pad(t[2])); close(); }));
      foot.appendChild(btn('پاک کردن', 'btn btn-secondary btn-sm', function () { input.value = ''; close(); }));
      pop.appendChild(foot);
    }
    render();
  }

  document.addEventListener('click', function (e) {
    var el = e.target;
    if (el.matches && el.matches('input[data-jdate]')) { if (!open || open.input !== el) show(el); return; }
    if (open && !open.pop.contains(el)) close();
  });
  document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('input[data-jdate]') && (!open || open.input !== e.target)) show(e.target); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.querySelectorAll('input[data-jdate]').forEach(function (i) { i.setAttribute('autocomplete', 'off'); i.parentNode.classList.add('jdp-host'); });
  window.__jdate = { g2j: g2j, j2g: j2g, monthLen: monthLen };
})();
