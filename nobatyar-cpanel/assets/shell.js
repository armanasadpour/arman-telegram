'use strict';
/*
 * Phone side menu (drawer) and the sign-in sheet / dialog.
 * Both enhance markup that already works without JavaScript: menu entries are ordinary links and every
 * sign-in link goes to login.php, which runs the same three steps as full pages.
 */
(() => {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const toEn = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  const toFa = (s) => String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);

  /* ---------- Shared: scroll lock, focus trap, open/close animation ---------- */
  let locks = 0;
  let savedY = 0;
  const lockScroll = () => {
    if (locks++ > 0) return;
    savedY = window.scrollY;
    document.documentElement.classList.add('scroll-locked');
    document.body.style.top = -savedY + 'px';
  };
  const unlockScroll = () => {
    if (--locks > 0) return;
    locks = 0;
    document.documentElement.classList.remove('scroll-locked');
    document.body.style.top = '';
    window.scrollTo(0, savedY);
  };
  const focusables = (root) =>
    [...root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]):not([tabindex="-1"]),select,textarea,[tabindex]:not([tabindex="-1"])')].filter(
      (n) => !n.closest('[hidden]') && n.getClientRects().length,
    );
  const trap = (root, e) => {
    if (e.key !== 'Tab') return;
    const list = focusables(root);
    if (!list.length) return;
    const first = list[0];
    const last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };
  /* Shows a hidden panel + backdrop, then adds .is-open on the next frame so CSS transitions run. */
  const show = (panel, backdrop) => {
    panel.hidden = false;
    backdrop.hidden = false;
    panel.getBoundingClientRect();
    requestAnimationFrame(() => {
      panel.classList.add('is-open');
      backdrop.classList.add('is-open');
    });
  };
  const hide = (panel, backdrop, done) => {
    panel.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    panel.style.transform = '';
    const finish = () => {
      if (panel.classList.contains('is-open')) return;
      panel.hidden = true;
      backdrop.hidden = true;
      if (done) done();
    };
    if (reduceMotion) finish();
    else setTimeout(finish, 280);
  };

  /* ---------- Side menu ---------- */
  const drawer = document.querySelector('[data-drawer]');
  const drawerBackdrop = document.querySelector('[data-drawer-backdrop]');
  const toggles = document.querySelectorAll('[data-drawer-open]');
  let drawerReturn = null;
  const drawerOpen = () => drawer && !drawer.hidden && drawer.classList.contains('is-open');
  const openDrawer = () => {
    if (!drawer || drawerOpen()) return;
    drawerReturn = document.activeElement;
    show(drawer, drawerBackdrop);
    lockScroll();
    toggles.forEach((t) => t.setAttribute('aria-expanded', 'true'));
    setTimeout(() => drawer.querySelector('[data-drawer-close]').focus(), reduceMotion ? 0 : 60);
  };
  const closeDrawer = (restoreFocus = true) => {
    if (!drawer || drawer.hidden) return;
    toggles.forEach((t) => t.setAttribute('aria-expanded', 'false'));
    hide(drawer, drawerBackdrop);
    unlockScroll();
    if (restoreFocus && drawerReturn && drawerReturn.focus) drawerReturn.focus();
  };
  if (drawer) {
    toggles.forEach((t) => {
      t.hidden = false;
      t.addEventListener('click', openDrawer);
    });
    drawer.querySelector('[data-drawer-close]').addEventListener('click', () => closeDrawer());
    drawerBackdrop.addEventListener('click', () => closeDrawer());
    drawer.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDrawer();
      trap(drawer, e);
    });
    /* Choosing a page closes the menu (the sign-in entry opens the sheet instead). */
    drawer.addEventListener('click', (e) => {
      const link = e.target.closest('a[href]');
      if (link && !link.hasAttribute('data-auth-open') && !e.defaultPrevented) closeDrawer(false);
    });
    /* Accordion groups. */
    drawer.querySelectorAll('[data-drawer-group]').forEach((btn) => {
      const sub = document.getElementById(btn.getAttribute('aria-controls'));
      btn.addEventListener('click', () => {
        const open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', String(open));
        if (open) {
          sub.hidden = false;
          if (!reduceMotion) sub.animate([{ height: '0px', opacity: 0 }, { height: sub.scrollHeight + 'px', opacity: 1 }], { duration: 220, easing: 'cubic-bezier(.2,.7,.2,1)' });
        } else if (reduceMotion) {
          sub.hidden = true;
        } else {
          const a = sub.animate([{ height: sub.scrollHeight + 'px', opacity: 1 }, { height: '0px', opacity: 0 }], { duration: 180, easing: 'ease-in' });
          a.onfinish = () => {
            if (btn.getAttribute('aria-expanded') === 'false') sub.hidden = true;
          };
        }
      });
    });
    /* Swipe toward the right edge to close (the menu sits on the right in RTL). */
    let startX = null;
    let startY = 0;
    let dx = 0;
    let dragging = false;
    drawer.addEventListener('touchstart', (e) => {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      dx = 0;
      dragging = false;
    }, { passive: true });
    drawer.addEventListener('touchmove', (e) => {
      if (startX === null) return;
      const mx = e.touches[0].clientX - startX;
      const my = e.touches[0].clientY - startY;
      if (!dragging && Math.abs(mx) > 10 && Math.abs(mx) > Math.abs(my)) dragging = true;
      if (!dragging) return;
      dx = Math.max(0, mx);
      drawer.style.transition = 'none';
      drawer.style.transform = 'translateX(' + dx + 'px)';
    }, { passive: true });
    drawer.addEventListener('touchend', () => {
      drawer.style.transition = '';
      if (dragging && dx > Math.min(110, drawer.offsetWidth * 0.3)) closeDrawer();
      else drawer.style.transform = '';
      startX = null;
      dragging = false;
    });
    /* A phone rotated or a window widened past the menu breakpoint closes it. */
    window.matchMedia('(min-width: 921px)').addEventListener('change', (m) => {
      if (m.matches) closeDrawer(false);
    });
  }

  /* ---------- Sign-in sheet ---------- */
  const sheet = document.querySelector('[data-auth-sheet]');
  if (!sheet) return;
  const backdrop = document.querySelector('[data-auth-backdrop]');
  const box = sheet.querySelector('[data-auth-box]');
  const status = sheet.querySelector('[data-auth-status]');
  const steps = Object.fromEntries([...sheet.querySelectorAll('[data-step]')].map((s) => [s.dataset.step, s]));
  if (!steps.phone) {
    /* Sign-in disabled by the administrator: the sheet only explains that. */
    document.addEventListener('click', (e) => {
      const link = e.target.closest('[data-auth-open],[data-auth-required]');
      if (!link || e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      closeDrawer(false);
      show(sheet, backdrop);
      lockScroll();
    });
    const off = () => {
      hide(sheet, backdrop);
      unlockScroll();
    };
    sheet.querySelector('[data-auth-close]').addEventListener('click', off);
    backdrop.addEventListener('click', off);
    return;
  }
  const forms = Object.fromEntries([...sheet.querySelectorAll('[data-auth-form]')].map((f) => [f.dataset.authForm, f]));
  const mobileInput = sheet.querySelector('[data-mobile-input]');
  const otpInput = sheet.querySelector('[data-otp-input]');
  const state = { next: '', challenge: '', mobile: '', timer: null, abort: null, returnFocus: null };
  let csrf = null;

  const session = () =>
    fetch('api.php?route=session', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((r) => {
        csrf = r.data.csrf;
        return r.data;
      });
  const post = async (route, body, retry = true) => {
    if (!csrf) await session();
    let res;
    try {
      res = await fetch('api.php?route=' + route, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(body),
      });
    } catch (e) {
      const err = new Error('اتصال برقرار نشد. اینترنت را بررسی کنید و دوباره تلاش کنید.');
      err.network = true;
      throw err;
    }
    let json;
    try {
      json = await res.json();
    } catch (e) {
      throw new Error('پاسخ سرور قابل خواندن نبود. کمی بعد دوباره تلاش کنید.');
    }
    if (json.data && json.data.csrf) csrf = json.data.csrf;
    if (!json.ok) {
      if (res.status === 419 && retry) {
        csrf = null;
        return post(route, body, false);
      }
      const err = new Error((json.error && json.error.message) || 'خطایی رخ داد.');
      err.status = res.status;
      throw err;
    }
    return json.data;
  };

  const errorBox = (step) => steps[step].querySelector('[data-error]');
  const setError = (step, msg) => {
    const el = errorBox(step);
    el.textContent = msg || '';
    steps[step].classList.toggle('has-error', !!msg);
  };
  const busy = (form, on) => {
    const btn = form.querySelector('[data-submit]') || form.querySelector('button');
    if (!btn) return;
    btn.classList.toggle('is-loading', on);
    btn.setAttribute('aria-busy', String(on));
    if (on) btn.disabled = true;
  };
  const goStep = (name) => {
    Object.entries(steps).forEach(([k, s]) => (s.hidden = k !== name));
    box.dataset.stepCurrent = name;
    status.textContent = '';
    const target =
      name === 'phone' ? mobileInput : name === 'code' ? sheet.querySelector('.otp-cells input') : steps.profile.querySelector('input[name=first_name]');
    if (target) setTimeout(() => target.focus(), reduceMotion ? 0 : 80);
    if (name !== 'code' && state.abort) state.abort.abort();
  };

  /* Mobile number: Persian or Latin digits, with or without 0 / 98 / +98. */
  const mobileDigits = () => {
    const d = toEn(mobileInput.value).replace(/\D/g, '');
    const m = d.match(/^(?:0098|98|0)?(9\d{9})$/);
    return m ? '0' + m[1] : '';
  };
  const requestBtn = forms.request.querySelector('[data-submit]');
  const syncMobile = () => {
    requestBtn.disabled = !mobileDigits();
  };
  mobileInput.addEventListener('input', () => {
    setError('phone', '');
    syncMobile();
  });
  mobileInput.addEventListener('blur', () => {
    if (mobileInput.value.trim() && !mobileDigits()) setError('phone', 'شماره موبایل معتبر نیست؛ ۱۱ رقم با ۰۹ وارد کنید (مثل ۰۹۱۲۳۴۵۶۷۸۹).');
  });
  syncMobile();

  /* Code: six boxes built over the original input, which keeps the value that is sent. */
  const cellsWrap = document.createElement('div');
  cellsWrap.className = 'otp-cells';
  cellsWrap.dir = 'ltr';
  cellsWrap.setAttribute('role', 'group');
  cellsWrap.setAttribute('aria-label', 'کد تأیید شش‌رقمی');
  const cells = Array.from({ length: 6 }, (_, i) => {
    const c = document.createElement('input');
    c.type = 'text';
    c.inputMode = 'numeric';
    c.autocomplete = i === 0 ? 'one-time-code' : 'off';
    c.maxLength = i === 0 ? 6 : 1;
    c.setAttribute('aria-label', 'رقم ' + toFa(i + 1) + ' از ۶');
    c.className = 'otp-cell';
    cellsWrap.appendChild(c);
    return c;
  });
  otpInput.after(cellsWrap);
  otpInput.classList.add('sr-only');
  otpInput.tabIndex = -1;
  otpInput.setAttribute('aria-hidden', 'true');
  const verifyBtn = forms.verify.querySelector('[data-submit]');
  const code = () => cells.map((c) => c.value).join('');
  const syncCode = () => {
    otpInput.value = code();
    verifyBtn.disabled = otpInput.value.length !== 6;
    cells.forEach((c) => c.classList.toggle('filled', c.value !== ''));
  };
  const fill = (from, digits) => {
    digits.split('').slice(0, 6 - from).forEach((d, k) => (cells[from + k].value = d));
    syncCode();
    const nextEmpty = cells.findIndex((c) => c.value === '');
    (cells[nextEmpty === -1 ? 5 : nextEmpty] || cells[5]).focus();
    if (code().length === 6) forms.verify.requestSubmit();
  };
  const clearCode = () => {
    cells.forEach((c) => (c.value = ''));
    syncCode();
  };
  cells.forEach((c, i) => {
    c.addEventListener('input', () => {
      const digits = toEn(c.value).replace(/\D/g, '');
      setError('code', '');
      if (digits.length > 1) {
        c.value = '';
        fill(i, digits);
        return;
      }
      c.value = digits;
      syncCode();
      if (digits && i < 5) cells[i + 1].focus();
      if (code().length === 6) forms.verify.requestSubmit();
    });
    c.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !c.value && i > 0) {
        cells[i - 1].value = '';
        cells[i - 1].focus();
        syncCode();
        e.preventDefault();
      } else if (e.key === 'ArrowLeft' && i > 0) {
        cells[i - 1].focus();
      } else if (e.key === 'ArrowRight' && i < 5) {
        cells[i + 1].focus();
      }
    });
    c.addEventListener('paste', (e) => {
      const digits = toEn((e.clipboardData || window.clipboardData).getData('text')).replace(/\D/g, '');
      if (!digits) return;
      e.preventDefault();
      fill(digits.length >= 6 ? 0 : i, digits);
    });
    c.addEventListener('focus', () => c.select());
  });
  syncCode();

  /* Resend countdown. */
  const countdown = steps.code.querySelector('[data-countdown]');
  const countdownValue = steps.code.querySelector('[data-countdown-value]');
  const resendBtn = steps.code.querySelector('[data-resend-button]');
  const startCountdown = (seconds) => {
    clearInterval(state.timer);
    let left = Math.max(0, seconds | 0);
    const tick = () => {
      countdownValue.textContent = toFa(String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0'));
      countdown.hidden = left <= 0;
      resendBtn.hidden = left > 0;
      if (left <= 0) clearInterval(state.timer);
      left--;
    };
    tick();
    state.timer = setInterval(tick, 1000);
  };

  /* Browsers that support WebOTP offer to fill the code from the SMS after the user agrees. */
  const listenWebOtp = () => {
    if (!('OTPCredential' in window) || !navigator.credentials) return;
    if (state.abort) state.abort.abort();
    state.abort = new AbortController();
    navigator.credentials
      .get({ otp: { transport: ['sms'] }, signal: state.abort.signal })
      .then((otp) => {
        if (otp && otp.code && box.dataset.stepCurrent === 'code') fill(0, toEn(otp.code).replace(/\D/g, ''));
      })
      .catch(() => {});
  };

  const showMobile = (m) => {
    state.mobile = m;
    sheet.querySelectorAll('[data-mobile-view]').forEach((n) => {
      if ('value' in n && n.tagName === 'INPUT') n.value = toFa(m);
      else n.textContent = toFa(m);
    });
  };
  const sendCode = async (form) => {
    const mobile = state.mobile && form === forms.resend ? state.mobile : mobileDigits();
    if (!mobile) {
      setError('phone', 'شماره موبایل معتبر نیست؛ ۱۱ رقم با ۰۹ وارد کنید (مثل ۰۹۱۲۳۴۵۶۷۸۹).');
      mobileInput.focus();
      return;
    }
    busy(form, true);
    try {
      const r = await post('auth/request', { mobile });
      state.challenge = r.challenge_id;
      showMobile(r.mobile || mobile);
      clearCode();
      setError('code', '');
      goStep('code');
      startCountdown(r.resend_after);
      status.textContent = 'کد تأیید پیامک شد.';
      listenWebOtp();
    } catch (e) {
      if (form === forms.resend) setError('code', e.message);
      else setError('phone', e.message);
    } finally {
      busy(form, false);
      syncMobile();
    }
  };
  const done = (r) => {
    status.textContent = 'ورود انجام شد؛ در حال انتقال…';
    sheet.classList.add('is-done');
    if (state.next) location.assign(state.next);
    else location.reload();
  };
  forms.request.addEventListener('submit', (e) => {
    e.preventDefault();
    sendCode(forms.request);
  });
  forms.resend.addEventListener('submit', (e) => {
    e.preventDefault();
    sendCode(forms.resend);
  });
  forms.change.addEventListener('submit', (e) => {
    e.preventDefault();
    clearInterval(state.timer);
    setError('phone', '');
    goStep('phone');
  });
  forms.verify.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (code().length !== 6 || verifyBtn.classList.contains('is-loading')) return;
    busy(forms.verify, true);
    try {
      const r = await post('auth/verify', { challenge_id: state.challenge, code: code() });
      clearInterval(state.timer);
      if (state.abort) state.abort.abort();
      if (r.status === 'logged_in') done(r);
      else goStep('profile');
    } catch (err) {
      setError('code', err.message);
      cellsWrap.classList.remove('shake');
      cellsWrap.getBoundingClientRect();
      cellsWrap.classList.add('shake');
      clearCode();
      cells[0].focus();
    } finally {
      busy(forms.verify, false);
      syncCode();
    }
  });
  const completeBtn = forms.complete.querySelector('[data-submit]');
  const syncProfile = () => {
    const f = forms.complete;
    completeBtn.disabled = !(f.first_name.value.trim().length >= 2 && f.last_name.value.trim().length >= 2 && f.accept_terms.checked);
  };
  forms.complete.addEventListener('input', () => {
    setError('profile', '');
    syncProfile();
  });
  forms.complete.addEventListener('change', syncProfile);
  syncProfile();
  forms.complete.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = forms.complete;
    busy(f, true);
    try {
      const r = await post('auth/complete', { first_name: f.first_name.value, last_name: f.last_name.value, accept_terms: f.accept_terms.checked });
      done(r);
    } catch (err) {
      setError('profile', err.message);
      if (err.status === 401) setTimeout(() => goStep('phone'), 1500);
    } finally {
      busy(f, false);
      syncProfile();
    }
  });

  /* Keep the active field and the main button above the on-screen keyboard. */
  const vv = window.visualViewport;
  const fitKeyboard = () => {
    if (!vv || sheet.hidden) return;
    const kb = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
    sheet.style.setProperty('--kb', kb + 'px');
  };
  if (vv) {
    vv.addEventListener('resize', fitKeyboard);
    vv.addEventListener('scroll', fitKeyboard);
  }
  sheet.addEventListener('focusin', (e) => {
    if (e.target.matches('input') && window.innerWidth < 720) setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' }), 250);
  });

  const sameOrigin = (url) => {
    if (!url) return '';
    try {
      const u = new URL(url, document.baseURI);
      return u.origin === location.origin ? u.href : '';
    } catch (e) {
      return '';
    }
  };
  const openSheet = (next) => {
    state.next = sameOrigin(next);
    state.returnFocus = document.activeElement;
    closeDrawer(false);
    sheet.classList.remove('is-done');
    show(sheet, backdrop);
    lockScroll();
    goStep(box.dataset.stepCurrent === 'profile' ? 'profile' : box.dataset.stepCurrent === 'code' && state.challenge ? 'code' : 'phone');
  };
  const closeSheet = () => {
    if (sheet.hidden) return;
    hide(sheet, backdrop);
    unlockScroll();
    clearCode();
    sheet.style.removeProperty('--kb');
    if (state.abort) state.abort.abort();
    if (state.returnFocus && state.returnFocus.focus) state.returnFocus.focus();
  };
  sheet.querySelector('[data-auth-close]').addEventListener('click', closeSheet);
  backdrop.addEventListener('click', closeSheet);
  sheet.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSheet();
    trap(sheet, e);
  });
  /* Links that need an account: sign in here, then continue to the page that was asked for. */
  document.addEventListener('click', (e) => {
    const link = e.target.closest('[data-auth-open],[data-auth-required]');
    if (!link || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    const next = link.hasAttribute('data-auth-required') ? link.href : link.getAttribute('data-auth-open');
    openSheet(next);
  });
})();
