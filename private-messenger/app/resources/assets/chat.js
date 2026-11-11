// Chat UI (main thread). Holds no keys: all cryptography happens in e2ee-worker.js.
// Every piece of user content is inserted with textContent / attributes — never as HTML.
const root = document.getElementById('app');
const worker = new Worker(document.body.dataset.worker, { type: 'module' });
const BASE = new URL('.', location.href).pathname;            // the app's secret path
const fa = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' });
const faDay = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: 'long', day: 'numeric' });
const faShort = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { month: 'short', day: 'numeric' });
const faWeek = new Intl.DateTimeFormat('fa-IR', { weekday: 'long' });
const faNum = new Intl.NumberFormat('fa-IR');
const EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
const EMOJI_PANEL = '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😜 🤪 🤗 🤭 🤔 🤐 😐 😏 😒 🙄 😬 😌 😔 😴 😷 🤒 🥳 😎 🤓 😕 😟 😮 😲 😳 🥺 😢 😭 😱 😖 😞 😓 😩 😡 😠 🤬 👍 👎 👏 🙌 🙏 🤝 💪 👌 ✌️ 🤞 👋 ❤️ 🧡 💛 💚 💙 💜 🖤 💔 💯 🔥 ⭐ ✨ 🎉 🎁 🌹 🌸 ☀️ 🌙 ☕ 🍰 🍉 ⚽ 🏆 📌 ✅ ❌ ⚠️ ❓ 💬 📞 📷 🎵'.split(' ');
let seq = 0, me = null, convs = [], current = null, refreshTimer = null, readTimer = null, typingTimer = null, presenceTimer = null;
let reply = null, editing = null, notifyCfg = { enabled: true, preview: 'none' }, info = { retention_days: 7, version: '' };
let tab = 'chats', filter = 'all', installEvt = null, swReg = null;
const names = new Map(); // user id -> display name (group members, contacts)
const pid = () => current?.peer?.id || null;           // null for groups
const cname = () => current?.peer?.display_name || current?.name || 'گروه';
const nameOf = (u) => u === me?.id ? 'شما' : supportUserView() ? (current.name || 'پشتیبانی') : names.get(u) || (current?.peer?.id === u ? current.peer.display_name : 'عضو گروه');
const isGroup = () => current?.type === 'group';
const pending = new Map(), byMid = new Map(), urls = new Map();

// ------------------------------------------------------------------ preferences (this device only; no content)
const PREF_DEFAULTS = { theme: 'system', accent: '', font: 15, sound: true, vibrate: true, inapp: true, preview: 'sender', quiet: null, pinned: [], wallpaper: true, enter: true };
let prefs = { ...PREF_DEFAULTS };
try { prefs = { ...PREF_DEFAULTS, ...JSON.parse(localStorage.getItem('pm-ui') || '{}') }; } catch { /* storage blocked */ }
function savePrefs() { try { localStorage.setItem('pm-ui', JSON.stringify(prefs)); } catch { /* storage blocked */ } applyPrefs(); syncSwMeta(); }
function applyPrefs() {
  const h = document.documentElement;
  if (prefs.theme === 'system') h.removeAttribute('data-theme'); else h.dataset.theme = prefs.theme;
  if (prefs.accent) { h.style.setProperty('--accent', prefs.accent); h.style.setProperty('--accent-soft', prefs.accent + '1a'); }
  else { h.style.removeProperty('--accent'); h.style.removeProperty('--accent-soft'); }
  h.style.setProperty('--fs', prefs.font + 'px');
  h.classList.toggle('no-wall', !prefs.wallpaper);
}
applyPrefs();

// ------------------------------------------------------------------ DOM helpers
function el(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children) if (c !== null && c !== undefined && c !== false) e.append(c);
  return e;
}
// Static icon set (our own markup, never user content).
const ICONS = {
  chat: '<path d="M21 12a8.5 8.5 0 0 1-12.4 7.6L3 21l1.4-5.2A8.5 8.5 0 1 1 21 12z"/>',
  phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
  video: '<path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
  user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  userPlus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  send: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
  mic: '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4"/>',
  smile: '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
  back: '<path d="m9 18 6-6-6-6"/>',
  chevron: '<path d="m15 18-6-6 6-6"/>',
  more: '<circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
  bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
  bellOff: '<path d="M8.7 3A6 6 0 0 1 18 8a21 21 0 0 0 .6 5M17 17H3s3-2 3-9a5.6 5.6 0 0 1 .3-1.7M10.3 21a1.9 1.9 0 0 0 3.4 0M2 2l20 20"/>',
  pin: '<path d="M12 17v5"/><path d="M9 10.8V4h6v6.8l3 3.2v2H6v-2z"/>',
  archive: '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
  trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
  camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/>',
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
  music: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
  devices: '<rect x="2" y="4" width="14" height="11" rx="2"/><path d="M6 19h6M9 15v4"/><rect x="17" y="8" width="5" height="12" rx="1"/>',
  key: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
  palette: '<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2a10 10 0 0 0 0 20c.9 0 1.6-.7 1.6-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2A5.5 5.5 0 0 0 22 11c0-5-4.5-9-10-9z"/>',
  storage: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
  info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
  logout: '<path d="M15 21h4a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-4M8 17l-5-5 5-5M3 12h12"/>',
  channel: '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  checks: '<path d="M18 6 7 17l-5-5M22 10l-7.5 7.5L13 16"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  play: '<path d="M7 4v16l13-8z" fill="currentColor"/>',
  pause: '<path d="M7 4h4v16H7zM13 4h4v16h-4z" fill="currentColor"/>',
  out: '<path d="M7 17 17 7M7 7h10v10"/>',
  in: '<path d="M17 7 7 17M17 17H7V7"/>',
  reply: '<path d="M9 17 4 12l5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
  copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  forward: '<path d="m15 17 5-5-5-5"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/>',
  type: '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
  calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  smartphone: '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
  refresh: '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
  speaker: '<path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/>',
  wifiOff: '<path d="M2 2l20 20M8.5 16.4a5 5 0 0 1 7 0M5 12.9a10 10 0 0 1 5.2-2.8M19 12.9a10 10 0 0 0-2.3-1.8M2 8.8a15 15 0 0 1 4.2-2.7M22 8.8A15 15 0 0 0 11 5M12 20h.01"/>',
};
function ico(name, cls = '') {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('class', 'i ' + cls);
  s.innerHTML = ICONS[name] || '';
  return s;
}
const verified = () => { const b = el('span', { class: 'verified', title: 'حساب رسمی', 'aria-label': 'حساب رسمی' }); const sv = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  sv.setAttribute('viewBox', '0 0 24 24'); sv.innerHTML = '<path d="M12 1.5l2.6 2 3.3-.3 1 3.1 2.8 1.8-1 3.1 1 3.1-2.8 1.8-1 3.1-3.3-.3-2.6 2-2.6-2-3.3.3-1-3.1-2.8-1.8 1-3.1-1-3.1 2.8-1.8 1-3.1 3.3.3z"/><path class="ck" d="m8 12.2 2.7 2.7L16.3 9"/>'; b.append(sv); return b; };
const sr = (t) => el('span', { class: 'sr', text: t });            // text for screen readers (and tests)
const iconBtn = (name, label, onclick, cls = '') => el('button', { class: 'ib ' + cls, 'aria-label': label, title: label, onclick }, ico(name));
const call = (cmd, args) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); worker.postMessage({ id, cmd, args }); });
const size = (n) => n >= 1073741824 ? faNum.format((n / 1073741824).toFixed(1)) + ' گیگابایت' : n >= 1048576 ? faNum.format((n / 1048576).toFixed(1)) + ' مگابایت' : faNum.format(Math.max(1, Math.round(n / 1024))) + ' کیلوبایت';
const dbDate = (s) => new Date(String(s).replace(' ', 'T') + 'Z');
const ERR = {
  key_changed: 'کلید امنیتی مخاطب تغییر کرده است', file_expired: 'فایل از سرور حذف شده است (مهلت نگهداری تمام شده)', insufficient_storage: 'فضای سرور کافی نیست؛ با مدیر تماس بگیرید',
  file_too_large: 'حجم فایل از سقف تعیین‌شده توسط مدیر بیشتر است', file_missing: 'برای ارسال دوباره، فایل را دوباره انتخاب کنید', attachment_not_found: 'فایل دیگر روی سرور نیست',
  peer_has_no_devices: 'مخاطب هنوز وارد برنامه نشده است', target_not_found: 'پیام پیدا نشد',
  rate_limited: 'درخواست‌های زیادی ارسال شده؛ کمی بعد دوباره تلاش کنید', too_many_devices: 'حداکثر ۵ دستگاه برای هر حساب مجاز است',
  not_group_admin: 'فقط مدیران این کار را می‌توانند انجام دهند', group_state_missing: 'اطلاعات رمزنگاری‌شده گروه هنوز دریافت نشده است', link_not_found: 'درخواست منقضی شده است', query_too_short: 'حداقل ۳ حرف وارد کنید',
  channel_read_only: 'در کانال فقط مدیران می‌توانند پیام بفرستند', push_service_not_allowed: 'سرویس اعلان این مرورگر پشتیبانی نمی‌شود', push_disabled: 'اعلان پس‌زمینه توسط مدیر غیرفعال است',
  'Failed to fetch': 'اتصال به سرور برقرار نیست',
  service_disabled: 'این امکان توسط مدیر سامانه غیرفعال شده است',
  user_quota_exceeded: 'فضای ذخیره‌سازی شما روی سرور پر است؛ تا حذف خودکار فایل‌های قدیمی صبر کنید یا از مدیر سهمیه بیشتر بخواهید', conversation_banned: 'این گروه/کانال توسط مدیر سامانه مسدود شده است',
  support_closed: 'چت پشتیبانی در حال حاضر غیرفعال است', invalid_report: 'گزارش نامعتبر است',
};
const errText = (e) => ERR[e] || ERR[e?.message] || 'خطا: ' + (e?.message || e);
function toast(text) {
  document.querySelector('.c-toast')?.remove();
  const t = el('div', { class: 'c-toast', role: 'status', text });
  document.body.append(t); setTimeout(() => t.remove(), 4000);
}
// Avatars: initials on a gradient chosen from the id (no photos are uploaded anywhere).
function hash(s) { let h = 0; for (const ch of String(s)) h = (h * 31 + ch.codePointAt(0)) >>> 0; return h; }
function initials(n) { const w = String(n || '?').trim().split(/\s+/); return (w.length > 1 ? w[0][0] + '‌' + w[1][0] : [...w[0]][0] || '?'); }
const avatarUrls = new Map(); // user id -> Promise<data URL | null> (decrypted in the worker)
const loadAvatar = (id) => { if (!avatarUrls.has(id)) avatarUrls.set(id, call('avatar', { user_id: id }).catch(() => null)); return avatarUrls.get(id); };
function avatar(name, id, kind = 'user', cls = '') {
  const a = el('span', { class: `c-av g${hash(id || name) % 8} ${cls}`, 'aria-hidden': 'true', 'data-uid': kind === 'user' && id ? id : null });
  if (kind === 'support') { a.classList.add('photo', 'support'); a.append(el('img', { src: brand('icon/192') || '', alt: '' })); return a; }
  if (kind === 'group' || kind === 'channel') a.append(ico(kind === 'channel' ? 'channel' : 'users'));
  else a.textContent = initials(name);
  if (kind === 'user' && id && me) loadAvatar(id).then((url) => { if (url) { a.classList.add('photo'); a.replaceChildren(el('img', { src: url, alt: '' })); } });
  return a;
}
function refreshAvatars(uid) {
  avatarUrls.delete(uid);
  loadAvatar(uid).then((url) => document.querySelectorAll(`.c-av[data-uid="${CSS.escape(uid)}"]`).forEach((a) => {
    if (url) { a.classList.add('photo'); a.replaceChildren(el('img', { src: url, alt: '' })); }
    else { a.classList.remove('photo'); a.textContent = initials(names.get(uid) || (uid === me?.id ? me.display_name : '?')); }
  }));
}
function when(ts) {
  const d = new Date(ts), now = new Date();
  if (d.toDateString() === now.toDateString()) return fa.format(d);
  if (now - d < 6 * 86400000) return faWeek.format(d);
  return faShort.format(d);
}
function lastSeen(s) {
  if (!s) return '';
  const d = dbDate(s), mins = (Date.now() - d) / 60000;
  if (mins < 2) return 'آنلاین';
  if (mins < 60) return `آخرین بازدید ${faNum.format(Math.round(mins))} دقیقه پیش`;
  if (new Date().toDateString() === d.toDateString()) return 'آخرین بازدید امروز ' + fa.format(d);
  return 'آخرین بازدید ' + faShort.format(d);
}
/** Is a service switched off by the admin (globally, for me, or in this conversation)? */
const svcOff = (name, c = current) => !!(info.services_off?.[name] || c?.blocked_services?.includes(name));
const isSupport = (c = current) => c?.type === 'support';
const supportUserView = (c = current) => isSupport(c) && c.verified; // the user (not an agent) looking at their support chat
const bannedConv = (c = current) => c?.banned || null;
const isMuted = (c) => !!(c?.muted_until && dbDate(c.muted_until) > new Date());
const kindOf = (c) => c.type === 'support' ? 'support' : c.type === 'group' ? (c.channel ? 'channel' : 'group') : 'user';
const titleOf = (c) => c.peer ? c.peer.display_name : c.name;

// ------------------------------------------------------------------ worker events
worker.onmessage = ({ data }) => {
  if (data.id) { const p = pending.get(data.id); pending.delete(data.id); data.ok ? p.resolve(data.result) : p.reject(new Error(data.error)); return; }
  const { event, data: d } = data;
  if (event === 'message') { if (current && d.cid === current.id) { upsertMessage(d); if (d.dir === 'in') scheduleRead(); } scheduleRefresh(); if (d.status === 'received') notifyIncoming(d); }
  if (event === 'status') { const m = byMid.get(d.mid); if (m) { m.status = d.status; upsertMessage(m, false); } }
  if (event === 'removed') { byMid.delete(d.mid); document.querySelector(`[data-mid="${CSS.escape(d.mid)}"]`)?.remove(); }
  if (event === 'progress') { const b = document.querySelector(`[data-mid="${CSS.escape(d.mid)}"] progress`); if (b) { b.max = d.total; b.value = d.done; } }
  if (event === 'typing' && current?.id === d.cid) showTyping();
  if (event === 'key_changed' && current?.peer?.id === d.user_id) renderBanner();
  if (event === 'conversations_changed') scheduleRefresh();
  if (event === 'state') handleState(d);
  if (event === 'call') onCallSignal(d);
  if (event === 'link_request') { document.querySelector('.c-tab[data-tab=settings]')?.classList.add('dot'); document.querySelector('.c-devbtn')?.classList.add('dot'); toast('درخواست اتصال دستگاه جدید — تنظیمات ← «دستگاه‌های متصل».'); }
  if (event === 'device_added' && d.user_id !== me?.id) toast(`دستگاه جدیدی برای ${names.get(d.user_id) || 'یک مخاطب'} اضافه شد.`);
  if (event === 'skipped') toast(`پیام برای ${d.users.map((u) => names.get(u) || 'یک عضو').join('، ')} ارسال نشد (کلید تغییر کرده؛ در «اطلاعات گروه» تأیید کنید).`);
  if (event === 'avatar') refreshAvatars(d.user_id);
  if (event === 'group_changed') { scheduleRefresh(); if (current?.id === d.cid) call('conversations').then((l) => { const c = l.find((x) => x.id === d.cid); if (c) { Object.assign(current, { name: c.name, has_state: c.has_state, is_admin: c.is_admin, channel: c.channel, member_count: c.member_count }); renderHead(); renderBanner(); } }); }
};
worker.onerror = () => status('خطا در بارگذاری موتور رمزنگاری. صفحه را دوباره بارگذاری کنید.');

// ------------------------------------------------------------------ notification system
// In-app banner + sound + vibration while the app is open; system notifications when it is in the background;
// Web Push (service worker) when it is closed. Never the message text outside the app; names only if allowed.
const previewLevel = () => notifyCfg.preview === 'sender' && prefs.preview === 'sender' ? 'sender' : 'none';
function inQuiet() {
  const q = prefs.quiet; if (!q || q.from === q.to) return false;
  const d = new Date(), n = d.getHours() * 60 + d.getMinutes();
  return q.from < q.to ? n >= q.from && n < q.to : n >= q.from || n < q.to;
}
let audioCtx = null;
function chime() {
  if (!prefs.sound || inQuiet()) return;
  try {
    audioCtx ||= new AudioContext();
    const t = audioCtx.currentTime;
    for (const [f, at] of [[880, 0], [1320, 0.09]]) {
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(audioCtx.destination);
      g.gain.setValueAtTime(0.0001, t + at); g.gain.exponentialRampToValueAtTime(0.12, t + at + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + at + 0.25);
      o.start(t + at); o.stop(t + at + 0.3);
    }
  } catch { /* autoplay policy */ }
}
function buzz(p = [40]) { if (prefs.vibrate && !inQuiet()) navigator.vibrate?.(p); }
async function systemNotify(title, opts) {
  if (window.Notification?.permission !== 'granted') return;
  const o = { icon: brand('icon/192'), badge: brand('icon/badge'), lang: 'fa', dir: 'rtl', silent: !prefs.sound || inQuiet(), ...opts };
  try { if (swReg) await swReg.showNotification(title, o); else new Notification(title, o); } catch { /* not allowed here */ }
}
async function notifyIncoming(d) {
  if (!notifyCfg.enabled || d.dir !== 'in' || d.flag) return;
  let c = convs.find((x) => x.id === d.cid);
  if (!c) { await refreshList().catch(() => {}); c = convs.find((x) => x.id === d.cid); } // a brand-new chat
  if (isMuted(c)) return;
  if (document.visibilityState === 'visible') {
    if (current?.id === d.cid) return;
    chime(); buzz();
    if (prefs.inapp) inAppBanner(c, d);
    return;
  }
  const who = previewLevel() === 'sender' && c ? titleOf(c) : '';
  systemNotify(who ? 'پیام جدید از ' + who : 'پیام جدید', { body: 'برای خواندن، برنامه را باز کنید', tag: 'msg-' + d.cid, renotify: true, data: { c: d.cid } });
}
function inAppBanner(c, d) {
  document.querySelector('.c-banner-n')?.remove();
  const text = d.att ? KIND[d.att.kind] : (d.text || 'پیام جدید');
  const b = el('button', { class: 'c-banner-n', role: 'status', onclick: () => { b.remove(); if (c) openConversation(c); } },
    c ? avatar(titleOf(c), c.id, kindOf(c)) : avatar('?', d.cid),
    el('span', { class: 'c-body' }, el('b', { text: c ? titleOf(c) : 'پیام جدید' }),
      el('span', { class: 'c-prev', text: (c?.type === 'group' && names.has(d.from) ? names.get(d.from) + ': ' : '') + text.replace(/\s+/g, ' ').slice(0, 90) })));
  document.body.append(b);
  setTimeout(() => b.classList.add('out'), 4200); setTimeout(() => b.remove(), 4600);
}
function updateBadges() {
  const unread = convs.filter((c) => !c.archived && !isMuted(c)).reduce((a, c) => a + (c.unread || 0), 0);
  document.title = (unread ? `(${faNum.format(unread)}) ` : '') + (document.body.dataset.title || document.title.replace(/^\([^)]*\)\s*/, ''));
  if (navigator.setAppBadge) (unread ? navigator.setAppBadge(unread) : navigator.clearAppBadge()).catch(() => {});
  const b = document.querySelector('.c-tab[data-tab=chats] .badge');
  if (b) { b.textContent = unread ? faNum.format(unread) : ''; b.hidden = !unread; }
}
const brand = (p) => document.body.dataset.brand ? `${document.body.dataset.brand}/${p}?v=${document.body.dataset.logov || ''}` : undefined;
const swTarget = () => navigator.serviceWorker?.controller || swReg?.active || null;
const swPost = (msg) => swTarget()?.postMessage(msg);
function syncSwMeta() {
  const sw = swTarget();
  if (!sw) return;
  const allowNames = previewLevel() === 'sender';
  sw.postMessage({ type: 'meta', preview: allowNames ? 'sender' : 'none', sound: prefs.sound, quiet: prefs.quiet, icon: brand('icon/192'), badge: brand('icon/badge'),
    names: allowNames ? Object.fromEntries(convs.map((c) => [c.id, titleOf(c)])) : {},
    muted: Object.fromEntries(convs.filter(isMuted).map((c) => [c.id, dbDate(c.muted_until).getTime()])) });
}
async function enablePush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) throw new Error('این مرورگر از اعلان پس‌زمینه پشتیبانی نمی‌کند' + (/iPhone|iPad/.test(navigator.userAgent) ? ' (در آیفون ابتدا برنامه را به صفحه اصلی اضافه کنید).' : '.'));
  if (await Notification.requestPermission() !== 'granted') throw new Error('اجازه نمایش اعلان داده نشد.');
  const cfg = await call('push_config');
  if (!cfg.enabled) throw new Error(ERR.push_disabled);
  const reg = swReg || await navigator.serviceWorker.ready;
  const key = Uint8Array.from(atob(cfg.public_key.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((cfg.public_key.length + 3) % 4)), (ch) => ch.charCodeAt(0));
  let sub = await reg.pushManager.getSubscription();
  if (sub && btoa(String.fromCharCode(...new Uint8Array(sub.options.applicationServerKey || []))).replace(/=+$/, '') !== btoa(String.fromCharCode(...key)).replace(/=+$/, '')) { await sub.unsubscribe(); sub = null; }
  sub ||= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
  await call('push_subscribe', { sub: sub.toJSON() });
}
const SVC = (h) => /fcm|google/.test(h) ? 'گوگل' : /mozilla/.test(h) ? 'موزیلا' : /apple/.test(h) ? 'اپل' : /windows/.test(h) ? 'مایکروسافت' : h;
/** Asks the server to push to this device now and explains the answer in plain words. */
async function pushDiagnose() {
  const r = await call('push_test');
  const svc = SVC(r.host || '');
  if (!r.subscribed) return { ok: false, text: 'این دستگاه هنوز برای اعلان پس‌زمینه ثبت نشده است. «اعلان وقتی برنامه بسته است» را روشن کنید.' };
  if (r.status >= 200 && r.status < 300) return { ok: true, text: `سرور اعلان را با موفقیت به سرویس اعلان ${svc} تحویل داد و تا چند ثانیه باید اعلان «آزمایشی» را ببینید. اگر ندیدید، مشکل از سمت گوشی است: در تنظیمات گوشی اعلان‌های مرورگر (یا برنامه نصب‌شده) و «بهینه‌سازی باتری» آن را بررسی کنید؛ در اندروید، گوشی باید به سرویس‌های گوگل (Google Play) وصل باشد.` };
  if (r.status === 0) return { ok: false, text: `سرور نتوانست به سرویس اعلان ${svc} وصل شود (${r.error}). احتمالاً هاست شما به سرورهای ${svc} دسترسی ندارد — مثلاً هاست داخل ایران. مدیر سامانه می‌تواند در «پنل مدیریت ← تنظیمات ← پراکسی اعلان» یک سرور واسط تنظیم کند.` };
  if (r.status === 403) return { ok: false, text: `سرویس اعلان ${svc} درخواست سرور را رد کرد (۴۰۳). معمولاً یعنی IP سرور شما (مثلاً دیتاسنتر داخل ایران) توسط ${svc} مسدود شده است. راه‌حل: «پراکسی اعلان» در پنل مدیریت.` };
  if (r.status === 404 || r.status === 410 || r.status === 400 || r.status === 401) {
    await disablePush().catch(() => {}); await enablePush().catch(() => {});
    return { ok: false, retry: true, text: 'اشتراک اعلان این دستگاه منقضی یا نامعتبر بود و دوباره ساخته شد. یک بار دیگر «تست» را بزنید.' };
  }
  return { ok: false, text: `پاسخ غیرمنتظره از سرویس اعلان ${svc}: ${r.status} ${r.error || ''}` };
}
/** Subscribes silently when the user already allowed notifications (e.g. after reinstall or re-login). */
async function ensurePush() {
  if (!notifyCfg.enabled || window.Notification?.permission !== 'granted' || !('PushManager' in window)) return;
  try { const cfg = await call('push_config'); if (cfg.enabled && !cfg.subscribed) await enablePush(); } catch { /* push service unreachable */ }
}
async function disablePush() {
  try { await (await (swReg || await navigator.serviceWorker.ready).pushManager.getSubscription())?.unsubscribe(); } catch { /* none */ }
  await call('push_remove');
}

// ------------------------------------------------------------------ status / onboarding screens
function status(text, ...actions) {
  root.replaceChildren(el('div', { class: 'chat-status' }, el('div', { class: 'onb' },
    el('div', { class: 'onb-logo' }, ico('chat')), el('p', { text }), ...actions)));
}
const resetButton = () => el('button', { class: 'btn', text: 'ساخت کلیدهای جدید روی این دستگاه', onclick: async () => {
  if (!confirm('با این کار دستگاه قبلی شما از کار می‌افتد و مخاطبان پیام «کلید امنیتی تغییر کرد» می‌بینند. پیام‌های قبلی روی این دستگاه نمایش داده نمی‌شوند. ادامه می‌دهید؟')) return;
  status('در حال ساخت کلیدهای جدید روی همین دستگاه…');
  try { handleState(await call('reset_identity')); } catch (e) { status('خطا: ' + e.message); }
} });
function handleState(s) {
  switch (s.state) {
    case 'ready': me = s.me; if (s.notify) notifyCfg = s.notify; if (s.info) info = s.info; if (s.needs_recovery) return showRecovery(true); renderApp(); return;
    case 'link_waiting': me = s.me; return linkWaiting(s);
    case 'unsupported': return status('این مرورگر امکانات لازم برای رمزنگاری سرتاسری را ندارد. از نسخه جدید Chrome، Firefox یا Safari استفاده کنید.');
    case 'locked': return status('برنامه در یک تب یا پنجره دیگر باز است. برای جلوگیری از خرابی کلیدها فقط یک تب در هر زمان مجاز است. آن تب را ببندید و این صفحه را دوباره بارگذاری کنید.',
      el('button', { class: 'btn secondary', text: 'بارگذاری دوباره', onclick: () => location.reload() }));
    case 'identity_elsewhere': me = s.me; return status('حساب شما روی دستگاه دیگری فعال است. این دستگاه را چطور اضافه کنیم؟',
      el('button', { class: 'btn block', text: '۱. اتصال با دستگاه قبلی (پیشنهادی — پیام‌های جدید روی هر دو)', onclick: startLink }),
      el('button', { class: 'btn secondary block mt', text: '۲. بازیابی با کلید بازیابی', onclick: restoreScreen }),
      el('details', { class: 'mt small' }, el('summary', { text: '۳. به هیچ‌کدام دسترسی ندارم' }),
        el('p', { class: 'muted', text: 'ساخت کلید جدید یعنی دستگاه‌های قبلی از کار می‌افتند و مخاطبان هشدار «کلید امنیتی تغییر کرد» می‌بینند.' }), resetButton()));
    case 'revoked': return status('کلیدهای رمزنگاری این دستگاه دیگر معتبر نیستند (دستگاه لغو شده یا هویت بازنشانی شده است).',
      el('button', { class: 'btn secondary block', text: 'بازیابی با کلید بازیابی', onclick: restoreScreen }), el('div', { class: 'mt' }, resetButton()));
    case 'logged_out': location.reload(); return;
    default: status('وضعیت ناشناخته');
  }
}

// ------------------------------------------------------------------ recovery key, device linking, devices
async function showRecovery(first) {
  let key;
  try { key = await call('recovery_create'); } catch (e) { toast(errText(e)); return renderApp(); }
  const last = key.slice(-4);
  const input = el('input', { type: 'text', class: 'ltr', placeholder: 'چهار کاراکتر آخر', maxlength: '4', autocomplete: 'off' });
  const err = el('p', { class: 'small', role: 'alert' });
  const box = el('div', { class: 'secret c-rk', text: key });
  const done = () => { if (input.value.trim().toUpperCase() !== last) { err.textContent = 'درست نیست؛ کلید را دوباره بررسی کنید.'; return; } dlg?.close(); dlg?.remove(); if (first) renderApp(); else toast('کلید بازیابی جدید ذخیره شد؛ کلید قبلی دیگر کار نمی‌کند.'); };
  const body = [
    el('p', { text: 'این کلید تنها راه بازگرداندن حساب شما روی گوشی یا مرورگر جدید است، اگر دستگاه فعلی در دسترس نباشد. سرور و مدیر سامانه آن را ندارند و نمی‌توانند بازیابی کنند.' }),
    box,
    el('div', { class: 'row mt' },
      el('button', { class: 'btn secondary sm', text: 'کپی', onclick: () => navigator.clipboard?.writeText(key).then(() => toast('کپی شد')) }),
      el('a', { class: 'btn secondary sm', href: URL.createObjectURL(new Blob([`کلید بازیابی\n${key}\n`], { type: 'text/plain' })), download: 'recovery-key.txt', text: 'ذخیره در فایل' })),
    el('p', { class: 'alert warn small', text: 'آن را روی کاغذ یا در مدیر رمز نگه دارید و به کسی ندهید.' }),
    el('label', { class: 'field' }, el('span', { text: 'برای اطمینان، چهار کاراکتر آخر کلید را بنویسید' }), input), err,
    el('button', { class: 'btn block', text: 'ذخیره کردم', onclick: done })];
  let dlg = null;
  if (first) root.replaceChildren(el('div', { class: 'chat-status' }, el('div', { class: 'onb' }, el('div', { class: 'onb-logo' }, ico('key')), el('h2', { text: 'کلید بازیابی' }), ...body)));
  else { dlg = el('dialog', { class: 'c-dialog' }, el('h2', { text: 'کلید بازیابی جدید' }), ...body); document.body.append(dlg); dlg.showModal(); }
}
function restoreScreen() {
  const input = el('textarea', { class: 'ltr', rows: '3', placeholder: 'XXXX-XXXX-…', autocomplete: 'off', spellcheck: 'false' });
  const err = el('p', { class: 'small', role: 'alert' });
  status('کلید بازیابی ۵۲ کاراکتری را وارد کنید (فاصله و خط تیره مهم نیست).', input, err,
    el('button', { class: 'btn block mt', text: 'بازیابی', onclick: async (e) => {
      e.target.disabled = true; err.textContent = 'در حال بازیابی…';
      try { handleState(await call('recovery_restore', { code: input.value })); }
      catch (x) { err.textContent = x.message === 'wrong_recovery_key' || x.message === 'invalid_recovery_key' ? 'کلید بازیابی درست نیست.' : x.message === 'no_backup' ? 'برای این حساب پشتیبانی وجود ندارد.' : errText(x); e.target.disabled = false; }
    } }),
    el('button', { class: 'btn secondary block mt', text: 'بازگشت', onclick: () => location.reload() }));
}
async function startLink() {
  status('در حال ساخت کلیدهای این دستگاه…');
  try { linkWaiting(await call('link_start')); } catch (e) { status('خطا: ' + errText(e), el('button', { class: 'btn', text: 'بازگشت', onclick: () => location.reload() })); }
}
function linkWaiting(s) {
  status('روی دستگاه قبلی‌تان (همان‌جا که برنامه باز است) به «تنظیمات ← دستگاه‌های متصل» بروید و درخواست اتصال را تأیید کنید. پیش از تأیید، مطمئن شوید این کد روی هر دو صفحه یکسان است:',
    el('div', { class: 'secret c-code', text: s.code }),
    el('p', { class: 'small muted', text: 'این درخواست ۱۰ دقیقه اعتبار دارد.' }),
    el('button', { class: 'btn secondary block', text: 'لغو', onclick: async () => { await call('link_cancel'); location.reload(); } }));
  const poll = async () => {
    try {
      const r = await call('link_poll');
      if (r.status === 'approved') { toast('این دستگاه به حساب شما متصل شد.'); return handleState(r); }
      if (r.status === 'rejected' || r.status === 'expired' || r.status === 'none') return status(r.status === 'rejected' ? 'درخواست رد شد.' : 'زمان درخواست تمام شد.', el('button', { class: 'btn', text: 'دوباره', onclick: () => location.reload() }));
    } catch (e) { return status('اتصال ناموفق بود: ' + errText(e), el('button', { class: 'btn', text: 'دوباره', onclick: () => location.reload() })); }
    setTimeout(poll, 2000);
  };
  setTimeout(poll, 2000);
}
async function showDevices() {
  document.querySelectorAll('.c-devbtn, .c-tab[data-tab=settings]').forEach((x) => x.classList.remove('dot'));
  const [devices, requests] = await Promise.all([call('my_devices'), call('link_requests').catch(() => [])]);
  const dlg = dialog('دستگاه‌های متصل',
    ...requests.map((r) => el('div', { class: 'alert warn' },
      el('b', { text: 'درخواست اتصال دستگاه جدید: ' + r.label }),
      el('p', { class: 'small', text: 'فقط اگر خودتان این درخواست را داده‌اید و کد زیر دقیقاً با کد روی دستگاه جدید یکسان است، تأیید کنید:' }),
      el('div', { class: 'secret c-code', text: r.code }),
      el('div', { class: 'row mt' },
        el('button', { class: 'btn', text: 'کد یکسان است — تأیید', onclick: async () => { try { await call('link_approve', { id: r.id }); close(dlg); toast('دستگاه جدید متصل شد.'); } catch (e) { toast(errText(e)); } } }),
        el('button', { class: 'btn danger', text: 'رد', onclick: async () => { await call('link_reject', { id: r.id }); close(dlg); } })))),
    el('div', { class: 'c-dir' }, ...devices.map((d) => el('div', { class: 'c-item' }, el('span', { class: 'c-ico-box' }, ico(/Android|iPhone|iPad|Mobile/i.test(d.label) ? 'smartphone' : 'devices')),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: d.label + (d.current ? ' (همین دستگاه)' : '') }),
        el('span', { class: 'c-prev', text: 'فعال از ' + faDay.format(dbDate(d.created_at)) + (d.last_seen_at && !d.current ? ' · ' + lastSeen(d.last_seen_at) : '') })),
      d.current ? el('span', { class: 'tag ok', text: 'فعلی' }) : el('button', { class: 'btn danger sm', text: 'خروج', onclick: async () => {
        if (!confirm('این دستگاه از حساب شما جدا شود؟ (برای گوشی گم‌شده)')) return;
        try { await call('revoke_device', { id: d.id }); close(dlg); toast('دستگاه جدا شد.'); } catch (e) { toast(errText(e)); }
      } })))),
    el('p', { class: 'small muted', text: 'برای افزودن گوشی یا مرورگر دیگر: روی آن وارد شوید و «اتصال با دستگاه قبلی» را بزنید. حداکثر ۵ دستگاه. پیام‌های قدیمی به دستگاه جدید منتقل نمی‌شوند.' }),
    el('button', { class: 'btn secondary block', text: '🔑 ساخت کلید بازیابی جدید', onclick: () => { close(dlg); showRecovery(false); } }));
  dlg.showModal();
}

// ------------------------------------------------------------------ groups & channels
function userPicker(onPick, excluded = new Set()) {
  const listBox = el('div', { class: 'c-dir' });
  const input = el('input', { type: 'search', placeholder: 'جست‌وجو: حداقل ۳ حرف از نام یا نام کاربری', 'aria-label': 'جست‌وجوی کاربر' });
  const show = (users) => listBox.replaceChildren(...users.filter((u) => !excluded.has(u.id) && u.has_identity).map((u) => {
    names.set(u.id, u.display_name);
    return el('div', { class: 'c-item c-user' }, avatar(u.display_name, u.id),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: u.display_name }), el('span', { class: 'c-prev', text: '@' + u.username })),
      el('button', { class: 'btn sm', text: 'افزودن', onclick: () => { excluded.add(u.id); onPick(u); show(users); } }));
  }));
  let t;
  input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { const q = input.value.trim(); show(q.length >= 3 ? await call('search_users', { q }).catch(() => []) : await call('contacts')); }, 300); });
  call('contacts').then(show);
  return [input, listBox];
}
function createGroup(channel = false) {
  const picked = new Map();
  const word = channel ? 'کانال' : 'گروه';
  const nameIn = el('input', { type: 'text', placeholder: 'نام ' + word, maxlength: '100' });
  const chips = el('div', { class: 'c-chips' });
  const renderChips = () => chips.replaceChildren(...[...picked.values()].map((u) => el('button', { class: 'tag', text: u.display_name + ' ✕', onclick: () => { picked.delete(u.id); renderChips(); } })));
  const [search, list] = userPicker((u) => { picked.set(u.id, u); renderChips(); });
  const dlg = dialog(word + ' جدید',
    el('div', { class: 'c-newhead' }, avatar(word, String(Date.now()), channel ? 'channel' : 'group', 'lg'),
      el('label', { class: 'field grow' }, el('span', { text: `نام ${word} (فقط برای اعضا رمزنگاری می‌شود؛ سرور آن را نمی‌بیند)` }), nameIn)),
    channel ? el('p', { class: 'alert small', text: 'در کانال فقط شما و مدیرانی که تعیین می‌کنید پیام می‌فرستید؛ بقیه فقط می‌خوانند. حداکثر ۶۴ عضو.' }) : null,
    el('p', { class: 'small muted', text: channel ? 'مشترکان:' : 'اعضا:' }), chips, search, list,
    el('button', { class: 'btn block mt', text: channel ? 'ساخت کانال' : 'ساخت گروه', onclick: async (e) => {
      if (!nameIn.value.trim() || !picked.size) return toast(`نام ${word} و حداقل یک عضو لازم است.`);
      e.target.disabled = true;
      try {
        const id = await call('create_group', { name: nameIn.value, members: [...picked.keys()], channel });
        close(dlg); await refreshList(); const c = convs.find((x) => x.id === id); if (c) openConversation(c);
      } catch (x) { toast(errText(x)); e.target.disabled = false; }
    } }));
  dlg.showModal();
}
async function groupInfo() {
  const g = await call('group_info', { cid: current.id });
  for (const m of g.members) names.set(m.id, m.display_name);
  const word = g.channel ? 'کانال' : 'گروه';
  const owner = g.members.find((m) => m.role === 'owner')?.id === g.me;
  const role = (m) => m.role === 'owner' ? 'سازنده' : g.admins.includes(m.id) ? 'مدیر' : '';
  const nameIn = el('input', { type: 'text', value: g.name, maxlength: '100' });
  const dlg = dialog('اطلاعات ' + word,
    el('div', { class: 'c-profile' }, avatar(g.name, current.id, g.channel ? 'channel' : 'group', 'xl'), el('h3', { text: g.name }),
      el('p', { class: 'muted small', text: `${faNum.format(g.members.length)} ${g.channel ? 'مشترک' : 'عضو'} · 🔒 رمزنگاری سرتاسری` })),
    g.is_admin ? el('div', { class: 'row' }, nameIn, el('button', { class: 'btn sm', text: 'تغییر نام', onclick: async () => { try { await call('group_rename', { cid: current.id, name: nameIn.value }); close(dlg); refreshList(); } catch (e) { toast(errText(e)); } } })) : null,
    muteRow(current),
    el('p', { class: 'small muted', text: 'پیام‌ها برای هر دستگاه هر عضو جداگانه رمزنگاری می‌شوند؛ عضو حذف‌شده پیام‌های بعدی را نمی‌تواند بخواند.' }),
    el('div', { class: 'c-dir' }, ...g.members.map((m) => el('div', { class: 'c-item' },
      avatar(m.display_name, m.id),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: m.display_name + (m.id === g.me ? ' (شما)' : '') }),
        el('span', { class: 'c-prev', text: ['@' + m.username, role(m)].filter(Boolean).join(' · ') }),
        !m.in_roster ? el('span', { class: 'c-flag', text: '⚠ عضویت او توسط هیچ مدیری (به‌صورت رمزنگاری‌شده) اعلام نشده؛ پیامی برایش ارسال نمی‌شود.' }) : null,
        m.key_changed ? el('span', { class: 'c-flag' }, el('span', { text: 'کلید امنیتی او تغییر کرده؛ تا تأیید شما پیامی برایش ارسال نمی‌شود. ' }),
          el('button', { class: 'btn sm', text: 'پذیرفتن', onclick: async () => { await call('accept_key', { user_id: m.id }); close(dlg); groupInfo(); } })) : null),
      g.is_admin && m.id !== g.me && m.role !== 'owner' ? el('button', { class: 'btn danger sm', text: 'حذف', onclick: async () => {
        if (!confirm(m.display_name + ` از ${word} حذف شود؟`)) return;
        try { await call('group_remove', { cid: current.id, user_id: m.id }); close(dlg); groupInfo(); } catch (e) { toast(errText(e)); } } }) : null,
      owner && m.id !== g.me ? el('button', { class: 'btn secondary sm', text: g.admins.includes(m.id) ? 'لغو مدیر' : 'مدیر کن', onclick: async () => {
        try { await call('group_admin', { cid: current.id, user_id: m.id, admin: !g.admins.includes(m.id) }); close(dlg); groupInfo(); } catch (e) { toast(errText(e)); } } }) : null))),
    g.is_admin ? el('details', { class: 'mt' }, el('summary', { text: '+ افزودن عضو' }), ...userPicker(async (u) => {
      try { await call('group_add', { cid: current.id, user_id: u.id }); toast(u.display_name + ' اضافه شد.'); } catch (e) { toast(errText(e)); }
    }, new Set(g.members.map((m) => m.id)))) : null,
    el('button', { class: 'btn secondary block mt', text: '⚑ گزارش تخلف این ' + word, onclick: () => { close(dlg); reportDialog('conversation', current.id, g.name); } }),
    el('button', { class: 'btn danger block mt', text: 'ترک ' + word, onclick: async () => {
      if (!confirm(`از این ${word} خارج شوید؟`)) return;
      try { await call('group_leave', { cid: current.id }); close(dlg); closeConversation(); } catch (e) { toast(errText(e)); } } }));
  dlg.showModal();
}

// ------------------------------------------------------------------ app shell: side panel with bottom tabs + main
let sideEl, listEl, mainEl, searchEl, headEl, tabsEl, subEl;
const TABS = [['chats', 'گفتگوها', 'chat'], ['calls', 'تماس‌ها', 'phone'], ['contacts', 'مخاطبین', 'users'], ['settings', 'تنظیمات', 'settings']];
function renderApp() {
  sideEl = el('aside', { class: 'c-side' });
  mainEl = el('main', { class: 'c-main' }, emptyMain());
  tabsEl = el('nav', { class: 'c-tabs', 'aria-label': 'بخش‌ها' }, ...TABS.map(([k, label, icon]) =>
    el('button', { class: 'c-tab' + (tab === k ? ' on' : ''), 'data-tab': k, onclick: () => setTab(k) }, ico(icon), el('span', { text: label }),
      k === 'chats' ? el('span', { class: 'badge', hidden: true }) : null)));
  root.replaceChildren(el('div', { class: 'c-app' }, sideEl, mainEl, tabsEl));
  renderSide();
  installHint();
  ensurePush();
}
function emptyMain() {
  return el('div', { class: 'c-empty' }, el('div', { class: 'onb-logo' }, ico('chat')),
    el('h2', { text: document.body.dataset.title || 'پیام‌رسان' }), el('p', { text: 'یک گفتگو را انتخاب کنید یا گفتگوی جدیدی شروع کنید.' }),
    el('p', { class: 'c-e2ee small', text: '🔒 همه پیام‌ها و تماس‌ها رمزنگاری سرتاسری دارند.' }));
}
function setTab(k) {
  tab = k; subEl = null;
  tabsEl.querySelectorAll('.c-tab').forEach((b) => b.classList.toggle('on', b.dataset.tab === k));
  if (current && mobile()) closeConversation(false);
  renderSide();
}
function sideHead(title, ...actions) {
  headEl = el('div', { class: 'c-side-head' }, el('h1', { text: title }), el('div', { class: 'row' }, ...actions));
  return headEl;
}
function renderSide() {
  listEl = el('div', { class: 'c-list', role: 'list' });
  if (tab === 'chats') renderChatsTab();
  else if (tab === 'calls') renderCallsTab();
  else if (tab === 'contacts') renderContactsTab();
  else renderSettingsTab();
}

// ------------------------------------------------------------------ chats tab
const FILTERS = [['all', 'همه'], ['direct', 'شخصی'], ['groups', 'گروه‌ها'], ['channels', 'کانال‌ها'], ['archive', 'آرشیو']];
function renderChatsTab() {
  searchEl = el('input', { type: 'search', class: 'c-search', placeholder: 'جست‌وجوی گفتگو یا پیام…', 'aria-label': 'جست‌وجو', oninput: onSearch });
  const chips = el('div', { class: 'c-filters', role: 'tablist' }, ...FILTERS.map(([k, l]) =>
    el('button', { class: 'chip' + (filter === k ? ' on' : ''), role: 'tab', 'data-f': k, onclick: () => { filter = k; chips.querySelectorAll('.chip').forEach((x) => x.classList.toggle('on', x.dataset.f === k)); refreshList(); } }, el('span', { text: l }))));
  sideEl.replaceChildren(
    sideHead('گفتگوها', iconBtn('edit', 'گفتگوی جدید', openDirectory)),
    el('div', { class: 'c-search-wrap' }, ico('search', 'c-search-ico'), searchEl),
    chips, installSlot(), listEl,
    el('p', { class: 'c-note', text: '🔒 پیام‌ها فقط روی دستگاه‌های شما و به‌صورت رمزنگاری‌شده نگهداری می‌شوند.' }));
  refreshList();
}
function scheduleRefresh() { clearTimeout(refreshTimer); refreshTimer = setTimeout(refreshList, 300); }
const KIND = { image: '📷 تصویر', video: '🎬 ویدیو', audio: '🎤 پیام صوتی', file: '📎 فایل' };
function preview(c) {
  const l = c.last;
  if (!l && supportUserView(c)) return 'سؤال یا مشکلی دارید؟ اینجا بنویسید.';
  if (!l) return c.type === 'group' ? (c.channel ? 'کانال رمزنگاری‌شده' : 'گروه رمزنگاری‌شده') : 'رمزنگاری سرتاسری';
  if (l.deleted) return 'پیام حذف شد';
  if (l.flag && l.dir === 'in' && !l.text && !l.att) return '⚠ پیام قابل نمایش نیست';
  return (l.dir === 'out' ? 'شما: ' : '') + (l.att ? KIND[l.att] + (l.text ? ' — ' : '') : '') + (l.text || '').replace(/\s+/g, ' ').slice(0, 60);
}
function matchFilter(c) {
  if (filter === 'support') return isSupport(c);
  if (filter === 'archive') return c.archived;
  if (c.archived) return false;
  if (isSupport(c) && !supportUserView(c)) return filter === 'all';
  if (filter === 'direct') return c.type === 'direct';
  if (filter === 'groups') return c.type === 'group' && !c.channel;
  if (filter === 'channels') return c.type === 'group' && c.channel;
  return true;
}
async function refreshList() {
  if (!sideEl) return;
  const fresh = await call('conversations');
  convs = fresh.filter((c) => c.peer || c.type === 'group' || c.type === 'support');
  for (const c of convs) if (c.peer) names.set(c.peer.id, c.peer.display_name);
  updateBadges(); syncSwMeta();
  if (current) { const c = convs.find((x) => x.id === current.id); if (c) { current.muted_until = c.muted_until; current.archived = c.archived; } }
  if (tab !== 'chats' || !listEl || (searchEl && searchEl.value.trim().length >= 2)) return;
  const pinned = new Set(prefs.pinned);
  const shown = convs.filter(matchFilter).sort((a, b) => (supportUserView(b) - supportUserView(a)) || (pinned.has(b.id) - pinned.has(a.id)) || ((b.last?.ts || 0) - (a.last?.ts || 0)));
  if (info.support?.agent && !document.querySelector('.chip[data-f=support]')) {
    document.querySelector('.c-filters')?.append(el('button', { class: 'chip' + (filter === 'support' ? ' on' : ''), 'data-f': 'support', onclick: (e) => { filter = 'support'; e.currentTarget.parentNode.querySelectorAll('.chip').forEach((x) => x.classList.toggle('on', x === e.currentTarget)); refreshList(); } }, el('span', { text: 'پشتیبانی' })));
  }
  const archived = convs.filter((c) => c.archived).length;
  const arch = document.querySelector('.chip[data-f=archive] span');
  if (arch) arch.textContent = 'آرشیو' + (archived ? ` (${faNum.format(archived)})` : '');
  listEl.replaceChildren(...shown.map((c) => convItem(c, pinned.has(c.id))));
  if (!shown.length) listEl.append(el('div', { class: 'c-hint' }, ico(filter === 'archive' ? 'archive' : 'chat', 'big'),
    el('p', { text: filter === 'archive' ? 'گفتگوی آرشیوشده‌ای ندارید.' : convs.length ? 'گفتگویی در این دسته نیست.' : 'هنوز گفتگویی ندارید. دکمه ✎ را بزنید.' })));
}
function convItem(c, isPinned) {
  const unread = c.unread && current?.id !== c.id;
  const item = el('button', { class: 'c-item' + (current?.id === c.id ? ' on' : '') + (unread ? ' unread' : ''), role: 'listitem', 'data-cid': c.id, onclick: () => openConversation(c),
    oncontextmenu: (e) => { e.preventDefault(); quickActions(c); } },
    avatar(titleOf(c), c.peer?.id || c.id, kindOf(c)),
    el('span', { class: 'c-body' },
      el('span', { class: 'c-name' }, el('span', { class: 'c-title', text: titleOf(c) }), c.verified ? verified() : null, c.banned ? el('span', { class: 'tag fail', text: 'مسدود' }) : null, isMuted(c) ? ico('bellOff', 'mini') : null),
      el('span', { class: 'c-prev', text: preview(c) })),
    el('span', { class: 'c-side-meta' }, el('span', { class: 'c-time', text: c.last ? when(c.last.ts) : '' }),
      unread ? el('span', { class: 'badge' + (isMuted(c) ? ' muted' : ''), text: faNum.format(c.unread) })
        : isPinned || supportUserView(c) ? ico('pin', 'mini pinned') : c.last?.dir === 'out' ? ico('checks', 'mini tick') : null));
  longPress(item, () => quickActions(c));
  return item;
}
function longPress(node, fn) {
  let t = null, moved = false;
  node.addEventListener('touchstart', () => { moved = false; t = setTimeout(() => { if (!moved) { t = 'fired'; buzz([15]); fn(); } }, 520); }, { passive: true });
  node.addEventListener('touchmove', () => { moved = true; clearTimeout(t); }, { passive: true });
  node.addEventListener('touchend', (e) => { if (t === 'fired') e.preventDefault(); clearTimeout(t); t = null; });
}
function sheet(title, ...items) {
  document.querySelector('.c-sheet-wrap')?.remove();
  const wrap = el('div', { class: 'c-sheet-wrap', onclick: (e) => { if (e.target === wrap) wrap.remove(); } },
    el('div', { class: 'c-sheet', role: 'menu' }, el('div', { class: 'grab' }), title ? el('h3', { text: title }) : null, ...items));
  document.body.append(wrap);
  const esc = (e) => { if (e.key === 'Escape') { wrap.remove(); document.removeEventListener('keydown', esc); } };
  document.addEventListener('keydown', esc);
  return wrap;
}
const sheetItem = (icon, label, fn, cls = '') => el('button', { class: 'c-sheet-item ' + cls, role: 'menuitem', onclick: async () => { document.querySelector('.c-sheet-wrap')?.remove(); try { await fn(); } catch (e) { toast(errText(e)); } } }, ico(icon), el('span', { text: label }));
async function setPrefs(c, p) { const r = await call('conv_prefs', { cid: c.id, prefs: p }); Object.assign(c, { archived: !!+r.archived, muted_until: r.muted_until }); scheduleRefresh(); syncSwMeta(); return r; }
function muteSheet(c) {
  sheet('بی‌صدا کردن «' + titleOf(c) + '»',
    sheetItem('bellOff', 'یک ساعت', () => setPrefs(c, { muted_until: 3600 })),
    sheetItem('bellOff', 'هشت ساعت', () => setPrefs(c, { muted_until: 8 * 3600 })),
    sheetItem('bellOff', 'یک روز', () => setPrefs(c, { muted_until: 86400 })),
    sheetItem('bellOff', 'یک هفته', () => setPrefs(c, { muted_until: 7 * 86400 })),
    sheetItem('bellOff', 'همیشه', () => setPrefs(c, { muted_until: -1 })));
}
function quickActions(c) {
  const pinned = prefs.pinned.includes(c.id);
  sheet(titleOf(c),
    sheetItem('pin', pinned ? 'برداشتن سنجاق' : 'سنجاق کردن بالای فهرست', () => { prefs.pinned = pinned ? prefs.pinned.filter((x) => x !== c.id) : [c.id, ...prefs.pinned].slice(0, 10); savePrefs(); refreshList(); }),
    supportUserView(c) ? null : sheetItem('archive', c.archived ? 'خارج کردن از آرشیو' : 'آرشیو', () => setPrefs(c, { archived: !c.archived }).then(() => toast(c.archived ? 'به آرشیو منتقل شد.' : 'از آرشیو خارج شد.'))),
    isMuted(c) ? sheetItem('bell', 'روشن کردن اعلان', () => setPrefs(c, { muted_until: null })) : sheetItem('bellOff', 'بی‌صدا…', () => muteSheet(c)),
    sheetItem('trash', 'پاک کردن تاریخچه روی این دستگاه', async () => {
      if (!confirm('همه پیام‌های این گفتگو فقط از همین دستگاه پاک شوند؟ (روی دستگاه‌های دیگر و طرف مقابل باقی می‌مانند)')) return;
      await call('clear_chat', { cid: c.id }); if (current?.id === c.id) openConversation(c); toast('تاریخچه این دستگاه پاک شد.');
    }, 'danger'));
}
// Search: chats by name + messages on this device (nothing is sent to the server), with type filters.
let searchTimer, searchKind = '';
function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const q = searchEl.value.trim();
    if (q.length < 2 && !searchKind) return refreshList();
    const res = await call('search', { q, kind: searchKind });
    const byName = q.length >= 2 ? convs.filter((c) => titleOf(c).toLowerCase().includes(q.toLowerCase())) : [];
    const kinds = el('div', { class: 'c-filters' }, ...[['', 'همه'], ['image', 'عکس'], ['video', 'ویدیو'], ['file', 'فایل'], ['audio', 'صدا'], ['link', 'لینک']].map(([k, l]) =>
      el('button', { class: 'chip' + (searchKind === k ? ' on' : ''), text: l, onclick: () => { searchKind = k; onSearch(); } })));
    listEl.replaceChildren(kinds,
      byName.length ? el('p', { class: 'c-sec', text: 'گفتگوها' }) : '', ...byName.map((c) => convItem(c, false)),
      el('p', { class: 'c-sec', text: res.length ? `پیام‌ها — ${faNum.format(res.length)} نتیجه (فقط در همین دستگاه)` : 'پیامی یافت نشد.' }),
      ...res.map((r) => { const c = convs.find((x) => x.id === r.cid); return el('button', { class: 'c-item', onclick: () => { searchEl.value = ''; searchKind = ''; if (c) openConversation(c, r.mid); } },
        r.thumb ? el('img', { class: 'c-mini', src: r.thumb, alt: '' }) : avatar(c ? titleOf(c) : '?', c?.peer?.id || r.cid, c ? kindOf(c) : 'user'),
        el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: c ? titleOf(c) : '' }), el('span', { class: 'c-prev', text: (r.kind !== 'text' && r.kind !== 'link' ? KIND[r.kind] + ' ' : '') + r.text })),
        el('span', { class: 'c-time', text: faShort.format(r.ts) })); }));
  }, 250);
}

/** New chat: your contacts (people you follow) + search by name/username. There is no list of all members. */
async function openDirectory() {
  const listBox = el('div', { class: 'c-dir' });
  const hint = el('p', { class: 'small muted' });
  const input = el('input', { type: 'search', placeholder: 'جست‌وجوی کاربر: حداقل ۳ حرف از نام یا نام کاربری', 'aria-label': 'جست‌وجوی کاربر' });
  const dlg = dialog('شروع گفتگو',
    el('div', { class: 'c-newbtns' },
      svcOff('create_group', null) ? null : el('button', { class: 'c-newbtn', onclick: () => { close(dlg); createGroup(false); } }, el('span', { class: 'c-ico-box' }, ico('users')), el('span', { text: 'گروه جدید' })),
      svcOff('create_channel', null) ? null : el('button', { class: 'c-newbtn', onclick: () => { close(dlg); createGroup(true); } }, el('span', { class: 'c-ico-box' }, ico('channel')), el('span', { text: 'کانال جدید' }))),
    input, hint, listBox);
  const row = userRow(listBox, () => close(dlg));
  const showContacts = async () => {
    const users = await call('contacts');
    hint.textContent = users.length ? 'مخاطبین شما (افرادی که دنبال کرده‌اید):' : 'هنوز کسی را دنبال نکرده‌اید. نام یا نام کاربری فرد را جست‌وجو کنید.';
    listBox.replaceChildren(...users.map(row));
  };
  let t;
  input.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const q = input.value.trim();
      if (q.length < 3) return showContacts();
      try {
        const users = await call('search_users', { q });
        hint.textContent = users.length ? 'نتیجه جست‌وجو:' : 'کاربری با این مشخصات پیدا نشد.';
        listBox.replaceChildren(...users.map(row));
      } catch (e) { hint.textContent = errText(e); }
    }, 300);
  });
  dlg.showModal();
  input.focus();
  showContacts();
}
function userRow(listBox, beforeOpen = () => {}) {
  const row = (u) => {
    names.set(u.id, u.display_name);
    const followBtn = el('button', { class: 'btn sm c-follow ' + (u.is_contact ? 'secondary' : ''), text: u.is_contact ? '✓ دنبال‌شده' : '+ دنبال کردن',
      onclick: async () => { try { await call('follow', { user_id: u.id, on: !u.is_contact }); u.is_contact = !u.is_contact; followBtn.replaceWith(row(u).querySelector('.c-follow')); } catch (e) { toast(errText(e)); } } });
    return el('div', { class: 'c-item c-user' }, avatar(u.display_name, u.id),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: u.display_name }), el('span', { class: 'c-prev', text: u.has_identity ? '@' + u.username : 'هنوز وارد برنامه نشده است' })),
      followBtn,
      el('button', { class: 'btn sm', text: 'گفتگو', disabled: !u.has_identity, onclick: () => openDirect(u.id, beforeOpen) }));
  };
  return row;
}
async function openDirect(userId, beforeOpen = () => {}) {
  beforeOpen();
  const id = await call('open_direct', { user_id: userId });
  if (tab !== 'chats') setTab('chats');
  await refreshList();
  const c = convs.find((x) => x.id === id);
  if (c) openConversation(c);
  return c;
}
function dialog(title, ...body) {
  const dlg = el('dialog', { class: 'c-dialog' }, el('div', { class: 'grab' }), el('h2', { text: title }), ...body, el('button', { class: 'btn secondary block mt', text: 'بستن', onclick: () => close(dlg) }));
  dlg.addEventListener('close', () => dlg.remove());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) close(dlg); });
  document.body.append(dlg);
  return dlg;
}
const close = (dlg) => { dlg.close(); dlg.remove(); };

// ------------------------------------------------------------------ calls tab
let callFilter = 'all';
async function renderCallsTab() {
  const chips = el('div', { class: 'c-filters' }, ...[['all', 'همه'], ['missed', 'ازدست‌رفته'], ['out', 'خروجی'], ['in', 'ورودی']].map(([k, l]) =>
    el('button', { class: 'chip' + (callFilter === k ? ' on' : ''), text: l, onclick: () => { callFilter = k; renderSide(); } })));
  sideEl.replaceChildren(sideHead('تماس‌ها'), chips, listEl,
    el('p', { class: 'c-note', text: 'روی سرور فقط زمان، مدت و نتیجه تماس ثبت می‌شود؛ صوتی یا تصویری بودن و محتوای تماس ثبت نمی‌شود.' }));
  const calls = (await call('call_history')).filter((x) => callFilter === 'all' || (callFilter === 'missed' ? x.status === 'missed' && !x.outgoing : callFilter === 'out' ? x.outgoing : !x.outgoing));
  const label = (x) => x.status === 'missed' ? (x.outgoing ? 'بی‌پاسخ' : 'ازدست‌رفته') : x.status === 'rejected' ? 'رد شد' : x.status === 'cancelled' ? 'لغو شد' : x.status === 'failed' ? 'ناموفق' : x.status === 'ringing' || x.status === 'active' ? 'در جریان' : '';
  listEl.replaceChildren(...calls.map((x) => {
    const d = dbDate(x.created_at), missed = x.status === 'missed' && !x.outgoing;
    return el('div', { class: 'c-item c-call' }, avatar(x.peer.display_name, x.peer.id),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name' + (missed ? ' c-missed' : ''), text: x.peer.display_name }),
        el('span', { class: 'c-prev' + (missed ? ' c-missed' : '') }, ico(x.outgoing ? 'out' : 'in', 'mini'),
          el('span', { text: ' ' + when(d.getTime()) + ' · ' + (x.duration != null && x.status === 'ended' ? dur(x.duration) : label(x)) }))),
      iconBtn('phone', 'تماس صوتی دوباره', async () => { const c = convs.find((y) => y.id === x.conversation_id); if (c) { setTab('chats'); await openConversation(c); startCall(false); } }),
      iconBtn('video', 'تماس تصویری دوباره', async () => { const c = convs.find((y) => y.id === x.conversation_id); if (c) { setTab('chats'); await openConversation(c); startCall(true); } }));
  }));
  if (!calls.length) listEl.append(el('div', { class: 'c-hint' }, ico('phone', 'big'), el('p', { text: 'هنوز تماسی ندارید.' })));
}

// ------------------------------------------------------------------ contacts tab
async function renderContactsTab() {
  const input = el('input', { type: 'search', class: 'c-search', placeholder: 'جست‌وجوی کاربر (حداقل ۳ حرف)…', 'aria-label': 'جست‌وجوی کاربر' });
  const hint = el('p', { class: 'c-sec' });
  sideEl.replaceChildren(sideHead('مخاطبین', iconBtn('userPlus', 'افزودن مخاطب', () => input.focus())), el('div', { class: 'c-search-wrap' }, ico('search', 'c-search-ico'), input), hint, listEl,
    el('p', { class: 'c-note', text: 'فهرست همه کاربران نمایش داده نمی‌شود؛ هرکس را با نام یا نام کاربری‌اش پیدا و دنبال کنید.' }));
  const row = userRow(listEl);
  const showContacts = async () => {
    const users = await call('contacts');
    hint.textContent = users.length ? `مخاطبین من — ${faNum.format(users.length)}` : '';
    listEl.replaceChildren(...users.map(row));
    if (!users.length) listEl.append(el('div', { class: 'c-hint' }, ico('users', 'big'), el('p', { text: 'هنوز کسی را دنبال نکرده‌اید. نام یا نام کاربری را جست‌وجو کنید.' })));
  };
  let t;
  input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => {
    const q = input.value.trim();
    if (q.length < 3) return showContacts();
    try { const users = await call('search_users', { q }); hint.textContent = users.length ? 'نتیجه جست‌وجو' : 'کاربری پیدا نشد.'; listEl.replaceChildren(...users.map(row)); } catch (e) { hint.textContent = errText(e); }
  }, 300); });
  showContacts();
}

// ------------------------------------------------------------------ settings tab
const row = (icon, label, sub, onclick, cls = '') => el('button', { class: 'c-row ' + cls, onclick }, el('span', { class: 'c-ico-box' }, ico(icon)),
  el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: label }), sub ? el('span', { class: 'c-prev', text: sub }) : null), ico('chevron', 'chev'));
const group = (...rows) => el('div', { class: 'c-group' }, ...rows);
function subPage(title, ...content) {
  subEl = title;
  sideEl.replaceChildren(el('div', { class: 'c-side-head' }, iconBtn('back', 'بازگشت', () => { subEl = null; renderSide(); }), el('h1', { text: title })),
    el('div', { class: 'c-list c-settings' }, ...content));
}
function toggle(label, sub, on, onchange) {
  const input = el('input', { type: 'checkbox', role: 'switch', checked: on || null, onchange: (e) => onchange(e.target.checked, e.target) });
  return el('label', { class: 'c-row c-toggle' }, el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: label }), sub ? el('span', { class: 'c-prev', text: sub }) : null), input, el('span', { class: 'sw' }));
}
function renderSettingsTab() {
  sideEl.replaceChildren(sideHead('تنظیمات'),
    el('div', { class: 'c-list c-settings' },
      el('div', { class: 'c-me' }, el('button', { class: 'c-me-av', 'aria-label': 'تغییر عکس پروفایل', onclick: photoSheet }, avatar(me.display_name, me.id, 'user', 'xl'), el('span', { class: 'c-cam' }, ico('camera'))),
        el('div', {}, el('h2', { text: me.display_name }), el('p', { class: 'ltr', text: '@' + me.username }))),
      group(
        row('devices', 'دستگاه‌های متصل', 'گوشی‌ها و مرورگرهای حساب شما', showDevices, 'c-devbtn'),
        row('shield', 'حریم خصوصی و امنیت', 'رمزنگاری، کلید بازیابی، نشست‌ها', privacyPage),
        row('bell', 'اعلان‌ها', notifyCfg.enabled ? 'صدا، لرزش، اعلان پس‌زمینه' : 'توسط مدیر غیرفعال است', notificationsPage),
        row('palette', 'ظاهر و پوسته', 'حالت روشن/تیره، رنگ، اندازه متن', appearancePage),
        row('storage', 'داده و ذخیره‌سازی', 'حجم اشغال‌شده روی این دستگاه', storagePage)),
      group(
        row('download', 'نصب برنامه', installEvt ? 'روی صفحه اصلی یا دسکتاپ' : isStandalone() ? 'برنامه نصب شده است' : 'راهنمای نصب', installApp),
        row('info', 'درباره', 'نسخه ' + (info.version || '—'), aboutPage)),
      group(row('logout', 'خروج از حساب', 'فقط از همین دستگاه', logout, 'danger'))));
}
// ---- profile photo: cropped to a square on this device, re-encoded (no EXIF/location), encrypted before upload
function photoSheet() {
  const pickInput = (capture) => {
    const i = el('input', { type: 'file', accept: 'image/*', hidden: true, capture: capture || null, onchange: () => { const f = i.files[0]; i.remove(); if (f) photoEditor(f); } });
    document.body.append(i); i.click();
  };
  loadAvatar(me.id).then((has) => sheet('عکس پروفایل',
    sheetItem('image', 'انتخاب از گالری', () => pickInput(null)),
    sheetItem('camera', 'گرفتن عکس', () => pickInput('user')),
    has ? sheetItem('trash', 'حذف عکس', async () => { await call('remove_avatar'); refreshAvatars(me.id); toast('عکس پروفایل حذف شد.'); }, 'danger') : null,
    el('p', { class: 'small muted', text: '🔒 عکس روی همین دستگاه رمزنگاری می‌شود؛ فقط مخاطبان و هم‌گروهی‌هایتان آن را می‌بینند — سرور و مدیر سامانه نه.' })));
}
async function photoEditor(file) {
  let bmp;
  try { bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch { return toast('این فایل تصویر قابل خواندن نیست.'); }
  const side = Math.min(bmp.width, bmp.height);
  let zoom = 1, ox = 0.5, oy = 0.5;
  const c = el('canvas', { width: '512', height: '512', class: 'c-crop' });
  const draw = () => {
    const s2 = side / zoom, sx = (bmp.width - s2) * ox, sy = (bmp.height - s2) * oy;
    c.getContext('2d').drawImage(bmp, sx, sy, s2, s2, 0, 0, 512, 512);
  };
  draw();
  let drag = null;
  c.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, y: e.clientY, ox, oy }; c.setPointerCapture(e.pointerId); });
  c.addEventListener('pointermove', (e) => { if (!drag) return; const r = c.getBoundingClientRect(), k = 1 / zoom;
    ox = Math.min(1, Math.max(0, drag.ox - (e.clientX - drag.x) / r.width * k)); oy = Math.min(1, Math.max(0, drag.oy - (e.clientY - drag.y) / r.height * k)); draw(); });
  c.addEventListener('pointerup', () => { drag = null; });
  const dlg = dialog('عکس پروفایل',
    el('div', { class: 'c-crop-wrap' }, c),
    el('label', { class: 'field' }, el('span', { text: 'بزرگ‌نمایی (برای جابه‌جایی، تصویر را بکشید)' }),
      el('input', { type: 'range', min: '1', max: '3', step: '0.05', value: '1', oninput: (e) => { zoom = +e.target.value; draw(); } })),
    el('button', { class: 'btn block', text: 'ذخیره عکس', onclick: async (e) => {
      e.target.disabled = true; e.target.textContent = 'در حال رمزنگاری و ارسال…';
      const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.86));
      try { await call('set_avatar', { blob }); close(dlg); refreshAvatars(me.id); toast('عکس پروفایل ذخیره شد (رمزنگاری‌شده).'); }
      catch (x) { toast(errText(x)); e.target.disabled = false; e.target.textContent = 'ذخیره عکس'; }
    } }));
  dlg.showModal();
}
function privacyPage() {
  subPage('حریم خصوصی و امنیت',
    el('div', { class: 'c-hero' }, el('div', { class: 'onb-logo' }, ico('shield')), el('h3', { text: 'رمزنگاری سرتاسری فعال است' }),
      el('p', { class: 'small muted', text: 'پیام‌ها، فایل‌ها و تماس‌ها روی همین دستگاه رمزنگاری و فقط روی دستگاه مخاطب باز می‌شوند. سرور و مدیران سامانه متن آن‌ها را ندارند.' })),
    group(
      row('key', 'ساخت کلید بازیابی جدید', 'کلید قبلی از کار می‌افتد', () => showRecovery(false)),
      row('devices', 'دستگاه‌های متصل', null, showDevices, 'c-devbtn'),
      row('lock', 'نشست‌های ورود', 'مرورگرهایی که وارد حساب شده‌اند', () => { location.href = BASE + 'sessions'; }),
      row('key', 'تغییر رمز عبور', null, () => { location.href = BASE + 'password'; })),
    el('div', { class: 'c-group c-text-block' },
      el('p', { text: '• شماره امنیتی: در هر گفتگو روی نام مخاطب بزنید و ۶۰ رقم را حضوری با او مقایسه کنید.' }),
      el('p', { text: `• فایل‌ها پس از ${faNum.format(info.retention_days)} روز از سرور حذف می‌شوند (مگر با نگهداری دائمی به تشخیص مدیر).` }),
      el('p', { text: '• پیام‌ها روی سرور نگهداری نمی‌شوند؛ پس از تحویل به همه دستگاه‌ها حذف می‌شوند.' })));
}
async function notificationsPage() {
  const perm = window.Notification?.permission || 'unsupported';
  let pushState = null;
  try { pushState = await call('push_config'); } catch { /* offline */ }
  const status = el('p', { class: 'small muted' });
  const quietOn = !!prefs.quiet;
  const hh = (m) => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  const from = el('input', { type: 'time', value: hh(prefs.quiet?.from ?? 1380), onchange: () => saveQuiet() });
  const to = el('input', { type: 'time', value: hh(prefs.quiet?.to ?? 420), onchange: () => saveQuiet() });
  const mins = (v) => { const [h, m] = v.split(':').map(Number); return h * 60 + m; };
  const saveQuiet = () => { if (prefs.quiet) { prefs.quiet = { from: mins(from.value), to: mins(to.value) }; savePrefs(); } };
  subPage('اعلان‌ها',
    !notifyCfg.enabled ? el('p', { class: 'alert warn small', text: 'اعلان‌ها توسط مدیر سامانه غیرفعال شده‌اند.' }) : null,
    el('div', { class: 'c-group' },
      toggle('اعلان سیستم', perm === 'granted' ? 'اجازه داده شده' : perm === 'denied' ? 'در تنظیمات مرورگر مسدود شده است' : perm === 'unsupported' ? 'این مرورگر پشتیبانی نمی‌کند' : 'برای نمایش اعلان وقتی برنامه در پس‌زمینه است',
        perm === 'granted', async (on, input) => { if (on && perm !== 'granted') { const r = await Notification.requestPermission(); input.checked = r === 'granted'; if (r !== 'granted') toast('اجازه داده نشد.'); } else if (!on) { input.checked = perm === 'granted'; toast('برای خاموش کردن کامل، از تنظیمات مرورگر استفاده کنید.'); } }),
      pushState?.enabled ? toggle('اعلان وقتی برنامه بسته است (Push)', pushState.subscribed ? 'فعال روی این دستگاه' : 'از طریق سرویس اعلان مرورگر (گوگل/اپل/موزیلا)', pushState.subscribed, async (on, input) => {
        status.textContent = 'در حال تنظیم…';
        try { if (on) await enablePush(); else await disablePush(); status.textContent = on ? 'اعلان پس‌زمینه فعال شد.' : 'اعلان پس‌زمینه خاموش شد.'; }
        catch (e) { input.checked = !on; status.textContent = e.message; }
      }) : null,
      toggle('اعلان داخل برنامه', 'نوار بالای صفحه برای پیام گفتگوهای دیگر', prefs.inapp, (on) => { prefs.inapp = on; savePrefs(); }),
      toggle('صدا', null, prefs.sound, (on) => { prefs.sound = on; savePrefs(); if (on) chime(); }),
      toggle('لرزش', 'روی گوشی‌هایی که پشتیبانی می‌کنند', prefs.vibrate, (on) => { prefs.vibrate = on; savePrefs(); buzz([30, 40, 30]); })),
    status,
    el('div', { class: 'c-group' },
      el('label', { class: 'c-row c-select' }, el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: 'متن اعلان' }),
        el('span', { class: 'c-prev', text: notifyCfg.preview === 'sender' ? 'متن پیام هرگز در اعلان نمایش داده نمی‌شود' : 'مدیر فقط «پیام جدید» را مجاز کرده است' })),
      el('select', { disabled: notifyCfg.preview !== 'sender' || null, onchange: (e) => { prefs.preview = e.target.value; savePrefs(); } },
        el('option', { value: 'sender', text: 'نام فرستنده', selected: previewLevel() === 'sender' || null }), el('option', { value: 'none', text: 'فقط «پیام جدید»', selected: previewLevel() === 'none' || null })))),
    el('div', { class: 'c-group' },
      toggle('ساعات سکوت', 'در این بازه اعلان‌ها بی‌صدا هستند', quietOn, (on) => { prefs.quiet = on ? { from: mins(from.value), to: mins(to.value) } : null; savePrefs(); }),
      el('div', { class: 'c-row' }, el('span', { class: 'c-name grow', text: 'از' }), from, el('span', { class: 'c-name', text: 'تا' }), to)),
    pushState?.enabled ? el('button', { class: 'btn block mt', text: 'تست اعلان وقتی برنامه بسته است (از سرور)', onclick: async (e) => {
      e.target.disabled = true; status.textContent = 'در حال ارسال اعلان آزمایشی از سرور…';
      try { const d = await pushDiagnose(); status.textContent = (d.ok ? '✓ ' : '⚠ ') + d.text; status.className = 'small ' + (d.ok ? 'ok-text' : 'alert warn'); } catch (x) { status.textContent = errText(x); }
      e.target.disabled = false;
    } }) : null,
    el('button', { class: 'btn secondary block mt', text: 'ارسال اعلان آزمایشی', onclick: async () => {
      if (window.Notification?.permission !== 'granted') return toast('ابتدا «اعلان سیستم» را روشن کنید.');
      await systemNotify('اعلان آزمایشی', { body: 'اعلان‌ها روی این دستگاه کار می‌کنند ✓', tag: 'test' }); chime(); buzz();
    } }),
    el('p', { class: 'small muted mt', text: 'بی‌صدا کردن هر گفتگو: روی آن در فهرست، انگشت را نگه دارید (یا راست‌کلیک) ← «بی‌صدا». اعلان وقتی برنامه بسته است به سرویس اعلان گوگل/اپل/موزیلا نیاز دارد؛ اگر این سرویس‌ها از شبکه شما در دسترس نباشند، اعلان فقط وقتی برنامه باز است کار می‌کند. در آیفون، ابتدا برنامه را به صفحه اصلی اضافه کنید (iOS ۱۶٫۴ به بعد).' }));
}
function appearancePage() {
  const seg = (opts, val, fn) => el('div', { class: 'seg' }, ...opts.map(([k, l, icon]) => el('button', { class: val === k ? 'on' : '', onclick: (e) => { fn(k); e.currentTarget.parentNode.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b === e.currentTarget)); } }, icon ? ico(icon) : null, el('span', { text: l }))));
  const ACC = ['', '#7c3aed', '#2563eb', '#0d9488', '#16a34a', '#db2777', '#ea580c', '#475569'];
  subPage('ظاهر و پوسته',
    el('div', { class: 'c-group c-pad' }, el('p', { class: 'c-name', text: 'حالت نمایش' }),
      seg([['light', 'روشن', 'palette'], ['dark', 'تیره', 'moon'], ['system', 'سیستمی', 'smartphone']], prefs.theme, (k) => { prefs.theme = k; savePrefs(); })),
    el('div', { class: 'c-group c-pad' }, el('p', { class: 'c-name', text: 'رنگ اصلی برنامه' }),
      el('div', { class: 'swatches' }, ...ACC.map((h) => el('button', { class: 'swatch-b' + (prefs.accent === h ? ' on' : '') + (h ? ' sw-' + h.slice(1) : ' sw-default'), 'aria-label': h ? 'رنگ ' + h : 'رنگ پیش‌فرض مدیر',
        onclick: (e) => { prefs.accent = h; savePrefs(); e.currentTarget.parentNode.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b === e.currentTarget)); } })))),
    el('div', { class: 'c-group c-pad' }, el('p', { class: 'c-name', text: 'اندازه متن' }),
      el('div', { class: 'row' }, el('span', { class: 'small', text: 'آ' }), el('input', { type: 'range', min: '13', max: '19', step: '1', value: String(prefs.font), class: 'grow', 'aria-label': 'اندازه متن',
        oninput: (e) => { prefs.font = +e.target.value; savePrefs(); } }), el('span', { class: 'big-a', text: 'آ' })),
      el('div', { class: 'c-msg in demo' }, el('div', { class: 'c-text', text: 'سلام! این نمونه اندازه متن پیام‌هاست.' }))),
    el('div', { class: 'c-group' }, toggle('پس‌زمینه طرح‌دار گفتگو', null, prefs.wallpaper, (on) => { prefs.wallpaper = on; savePrefs(); }),
      toggle('ارسال با Enter', 'خاموش: Enter خط جدید می‌سازد', prefs.enter, (on) => { prefs.enter = on; savePrefs(); })));
}
async function storagePage() {
  const s = await call('storage_info');
  let srv = null; try { srv = await call('my_storage'); } catch { /* offline */ }
  const pct = srv?.quota ? Math.min(100, Math.round(100 * srv.used / srv.quota)) : 0;
  subPage('داده و ذخیره‌سازی',
    srv ? el('div', { class: 'c-group c-pad' },
      el('p', { class: 'c-name', text: 'فضای فایل‌های شما روی سرور' }),
      srv.quota ? el('div', { class: 'meter' + (pct >= 90 ? ' full' : '') }, el('span', { class: 'fill w' + Math.round(pct / 10) })) : null,
      el('p', { class: 'small' + (pct >= 90 ? ' alert warn' : ''), text: srv.quota ? `${size(srv.used)} از ${size(srv.quota)} (${faNum.format(pct)}٪)` : `${size(srv.used)} — بدون محدودیت سهمیه` }),
      el('p', { class: 'small muted', text: `فقط فایل‌های فعلی روی سرور حساب می‌شوند؛ هر فایل ${faNum.format(srv.retention_days)} روز پس از ارسال حذف و فضایش آزاد می‌شود. پیام متنی جزو سهمیه نیست.` })) : null,
    el('div', { class: 'c-group c-pad' },
      el('p', { class: 'c-name', text: 'فضای اشغال‌شده روی این دستگاه' }),
      el('div', { class: 'meter' }, el('span', { class: 'fill', 'data-w': s.usage && s.quota ? Math.min(100, Math.max(1, Math.round(100 * s.usage / s.quota))) : 1 })),
      el('p', { class: 'small muted', text: s.usage != null ? `${size(s.usage)}${s.quota ? ' از ' + size(s.quota) + ' مجاز برای این سایت' : ''}` : 'نامعلوم' }),
      el('p', { class: 'small', text: `${faNum.format(s.messages)} پیام رمزنگاری‌شده در این دستگاه` }),
      el('p', { class: 'small ' + (s.persisted ? 'ok-text' : 'muted'), text: s.persisted ? '✓ مرورگر داده‌ها را به‌صورت ماندگار نگه می‌دارد.' : 'مرورگر ممکن است در کمبود فضا داده‌ها را پاک کند؛ نصب برنامه (PWA) این خطر را کم می‌کند.' })),
    el('div', { class: 'c-group' },
      row('trash', 'پاک کردن فایل‌های بازشده از حافظه موقت', `${faNum.format(s.cached_files)} فایل در حافظه این نشست`, async () => { const n = await call('clear_file_cache'); for (const u of urls.values()) URL.revokeObjectURL(u); urls.clear(); toast(faNum.format(n) + ' فایل از حافظه موقت پاک شد.'); storagePage(); }),
      row('refresh', 'پاک کردن کش برنامه', 'فایل‌های برنامه دوباره از سرور گرفته می‌شوند', async () => { for (const k of await caches.keys()) if (k.startsWith('pm-assets')) await caches.delete(k); toast('کش برنامه پاک شد.'); })),
    el('p', { class: 'small muted mt', text: `فایل‌های پیوست پس از ${faNum.format(info.retention_days)} روز از سرور حذف می‌شوند؛ اگر فایلی را می‌خواهید نگه دارید، آن را «ذخیره روی دستگاه» کنید. پاک کردن داده‌های مرورگر یعنی حذف پیام‌های این دستگاه.` }));
  document.querySelectorAll('.meter .fill').forEach((f) => f.style.setProperty('--w', f.dataset.w + '%'));
}
function aboutPage() {
  subPage('درباره',
    el('div', { class: 'c-hero' }, el('div', { class: 'onb-logo' }, ico('chat')), el('h3', { text: document.body.dataset.title || '' }), el('p', { class: 'small muted', text: 'نسخه ' + (info.version || '—') })),
    el('div', { class: 'c-group c-text-block' },
      el('p', { text: 'رمزنگاری پیام‌ها: پروتکل Olm (Double Ratchet، کتابخانه vodozemac). فایل‌ها: AES-256-GCM روی همین دستگاه. تماس‌ها: WebRTC با DTLS-SRTP.' }),
      el('p', { text: 'هیچ آمار یا ردیاب شخص ثالثی در برنامه وجود ندارد.' })));
}
function logout() {
  if (!confirm('از حساب خارج می‌شوید. پیام‌های رمزنگاری‌شده روی همین دستگاه باقی می‌مانند و با ورود دوباره در دسترس‌اند.')) return;
  swPost({ type: 'forget' });
  // Never let a slow push service block signing out.
  Promise.race([disablePush(), new Promise((r) => setTimeout(r, 2500))]).catch(() => {}).finally(() => document.querySelector('form[action$="/logout"]')?.submit());
}

// ------------------------------------------------------------------ PWA: service worker, install, deep links
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
async function registerSw() {
  if (!('serviceWorker' in navigator)) return;
  try {
    swReg = await navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE });
    navigator.serviceWorker.addEventListener('message', (e) => { if (e.data?.type === 'open' && e.data.c) openById(e.data.c); });
    navigator.serviceWorker.addEventListener('controllerchange', syncSwMeta);
    await navigator.serviceWorker.ready; syncSwMeta();
    // Keep an existing push subscription bound to this device (e.g. after re-login).
    const sub = await swReg.pushManager?.getSubscription();
    if (sub && window.Notification?.permission === 'granted') call('push_subscribe', { sub: sub.toJSON() }).catch(() => {});
  } catch { /* private mode / unsupported */ }
}
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); installEvt = e; installHint(); });
window.addEventListener('appinstalled', () => { installEvt = null; installHint(); toast('برنامه نصب شد.'); });
function installSlot() { const s2 = el('div', { class: 'c-install-slot' }); setTimeout(notifyHint, 0); return s2; }
async function notifyHint() {
  const slot = document.querySelector('.c-install-slot');
  if (!slot || !notifyCfg.enabled || !('Notification' in window) || Notification.permission === 'denied') return;
  let dismissed = false; try { dismissed = localStorage.getItem('pm-notify-dismissed') === '1'; } catch { /* ignore */ }
  if (dismissed) return;
  let cfg = null; try { cfg = await call('push_config'); } catch { /* offline */ }
  if (Notification.permission === 'granted' && (!cfg?.enabled || cfg.subscribed)) return;
  if (slot.querySelector('.c-notify-card')) return;
  slot.prepend(el('div', { class: 'c-install c-notify-card' }, el('span', { class: 'c-ico-box' }, ico('bell')),
    el('span', { class: 'c-body' }, el('b', { text: 'اعلان‌ها را روشن کنید' }), el('span', { class: 'c-prev', text: 'تا وقتی برنامه بسته است هم پیام و تماس را ببینید' })),
    el('button', { class: 'btn sm', text: 'روشن کردن', onclick: async (e) => {
      e.target.disabled = true;
      try {
        if (cfg?.enabled && 'PushManager' in window) await enablePush(); else await Notification.requestPermission();
        e.target.closest('.c-notify-card').remove();
        if (cfg?.enabled) { const d = await pushDiagnose(); if (!d.ok) dialog('اعلان پس‌زمینه', el('p', { text: d.text })).showModal(); else toast('اعلان‌ها روشن شد ✓'); }
      } catch (x) { toast(x.message); e.target.disabled = false; }
    } }),
    iconBtn('x', 'بستن', () => { try { localStorage.setItem('pm-notify-dismissed', '1'); } catch { /* ignore */ } slot.querySelector('.c-notify-card')?.remove(); })));
}
function installHint() {
  const slot = document.querySelector('.c-install-slot');
  if (!slot) return;
  let dismissed = false; try { dismissed = localStorage.getItem('pm-install-dismissed') === '1'; } catch { /* ignore */ }
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) && !isStandalone();
  if (dismissed || isStandalone() || (!installEvt && !ios)) { slot.replaceChildren(); return; }
  slot.replaceChildren(el('div', { class: 'c-install' }, el('span', { class: 'c-ico-box' }, ico('download')),
    el('span', { class: 'c-body' }, el('b', { text: 'نصب برنامه' }), el('span', { class: 'c-prev', text: 'سریع‌تر، تمام‌صفحه و با اعلان' })),
    el('button', { class: 'btn sm', text: 'نصب', onclick: installApp }),
    iconBtn('x', 'بستن', () => { try { localStorage.setItem('pm-install-dismissed', '1'); } catch { /* ignore */ } slot.replaceChildren(); })));
}
async function installApp() {
  if (installEvt) { installEvt.prompt(); const r = await installEvt.userChoice; if (r.outcome === 'accepted') installEvt = null; installHint(); return; }
  if (isStandalone()) return toast('برنامه نصب شده است.');
  dialog('نصب برنامه',
    el('div', { class: 'c-steps' },
      el('p', {}, el('b', { text: 'آیفون و آیپد (Safari): ' }), el('span', { text: 'دکمه «اشتراک‌گذاری» ⬆ پایین صفحه را بزنید، سپس «Add to Home Screen» و «Add».' })),
      el('p', {}, el('b', { text: 'اندروید (Chrome): ' }), el('span', { text: 'منوی ⋮ بالای صفحه ← «نصب برنامه» یا «افزودن به صفحه اصلی».' })),
      el('p', {}, el('b', { text: 'رایانه (Chrome/Edge): ' }), el('span', { text: 'نماد نصب ⊕ در نوار آدرس را بزنید.' }))),
    el('p', { class: 'small muted', text: 'آدرس این صفحه مخفی است؛ نماد برنامه همان آدرس را باز می‌کند. اگر مدیر آدرس را تغییر دهد، باید برنامه را دوباره نصب کنید.' })).showModal();
}
async function openById(cid) {
  let c = convs.find((x) => x.id === cid);
  if (!c) { await refreshList(); c = convs.find((x) => x.id === cid); }
  if (c) { if (tab !== 'chats') setTab('chats'); openConversation(c); }
}
function handleHash() {
  const h = new URLSearchParams(location.hash.slice(1));
  if (h.get('c')) openById(h.get('c'));
  else if (h.get('tab') && TABS.some(([k]) => k === h.get('tab'))) setTab(h.get('tab'));
  if (location.hash) history.replaceState(null, '', location.pathname);
}

// ------------------------------------------------------------------ conversation
let msgsEl, bannerEl, pinEl, typingEl, statusEl, composer, sendBtn, micBtn, replyBar, fileInput, camInput, chatHead, emojiPanel, findBar;
const mobile = () => window.matchMedia('(max-width: 899px)').matches;
function closeConversation(refresh = true, fromPop = false) {
  if (!fromPop && history.state?.pmChat) { history.back(); return; } // popstate closes it (keeps the back stack consistent)
  current = null; clearInterval(presenceTimer);
  root.querySelector('.c-app')?.classList.remove('show-chat');
  mainEl.replaceChildren(emptyMain());
  if (refresh) refreshList();
}
async function openConversation(c, focusMid = null) {
  current = c; reply = null; editing = null; byMid.clear();
  for (const u of urls.values()) URL.revokeObjectURL(u); urls.clear();
  clearInterval(presenceTimer);
  root.querySelector('.c-app')?.classList.add('show-chat');
  if (mobile() && !history.state?.pmChat) history.pushState({ pmChat: true }, '');
  document.querySelectorAll('.c-list .c-item.on').forEach((x) => x.classList.remove('on'));
  document.querySelector(`.c-list .c-item[data-cid="${CSS.escape(c.id)}"]`)?.classList.add('on');
  msgsEl = el('div', { class: 'c-msgs', 'aria-live': 'polite' });
  bannerEl = el('div'); pinEl = el('div'); replyBar = el('div', { class: 'c-replybar' }); findBar = el('div', { class: 'c-findbar' });
  typingEl = el('span', { class: 'c-typing' }); statusEl = el('span', { class: 'c-status' });
  composer = el('textarea', { rows: '1', placeholder: 'پیام خود را بنویسید…', 'aria-label': 'متن پیام', maxlength: '10000', enterkeyhint: prefs.enter ? 'send' : 'enter',
    onkeydown: (e) => { if (e.key === 'Enter' && !e.shiftKey && prefs.enter && !e.isComposing) { e.preventDefault(); doSend(); } else if (e.key === 'Escape') clearReply(); },
    oninput: () => { autosize(); toggleSend(); if (composer.value.trim()) call('typing', { cid: c.id }).catch(() => {}); } });
  fileInput = el('input', { type: 'file', multiple: true, hidden: true, onchange: () => { const fs = [...fileInput.files]; fileInput.value = ''; if (fs.length) sendDialog(fs); } });
  camInput = el('input', { type: 'file', accept: 'image/*,video/*', capture: 'environment', hidden: true, onchange: () => { const fs = [...camInput.files]; camInput.value = ''; if (fs.length) sendDialog(fs); } });
  sendBtn = el('button', { class: 'c-send', onclick: doSend }, ico('send'), sr('ارسال'));
  micBtn = svcOff('audio', c) ? null : recorderButton();
  emojiPanel = el('div', { class: 'c-emoji-panel', hidden: true }, ...EMOJI_PANEL.map((e) => el('button', { text: e, onclick: () => { insertAtCursor(e); } })));
  chatHead = el('div', { class: 'c-head' });
  const ban = bannedConv(c);
  const readOnly = ban ? `این ${c.channel ? 'کانال' : 'گروه'} توسط مدیر سامانه مسدود شده است${ban.until ? ' تا ' + faDay.format(dbDate(ban.until)) : ''}${ban.reason ? ' — ' + ban.reason : ''}.`
    : c.type === 'group' && c.channel && !c.is_admin ? 'فقط مدیران کانال می‌توانند پیام بفرستند.'
    : c.type === 'group' && svcOff('group_message', c) ? 'ارسال پیام در گروه‌ها و کانال‌ها توسط مدیر سامانه غیرفعال شده است.'
    : ['text', 'image', 'video', 'audio', 'file'].every((k) => svcOff(k, c)) ? 'ارسال پیام برای شما توسط مدیر سامانه غیرفعال شده است.' : null;
  if (svcOff('text', c)) { composer.disabled = true; composer.placeholder = 'ارسال پیام متنی غیرفعال است'; }
  mainEl.replaceChildren(chatHead, bannerEl, pinEl, findBar,
    el('div', { class: 'c-msgs-wrap' }, msgsEl),
    replyBar, emojiPanel,
    readOnly ? el('div', { class: 'c-compose ro' + (ban ? ' banned' : '') }, ico(ban ? 'lock' : 'channel'), el('span', { text: readOnly }), el('textarea', { hidden: true }))
      : el('div', { class: 'c-compose' },
        el('button', { class: 'ib c-attach', 'aria-label': 'پیوست فایل یا تصویر', title: 'پیوست', onclick: attachSheet }, ico('plus')),
        fileInput, camInput,
        el('div', { class: 'c-input' }, composer, el('button', { class: 'ib c-emoji-btn', 'aria-label': 'شکلک', onclick: () => { emojiPanel.hidden = !emojiPanel.hidden; } }, ico('smile'))),
        micBtn, sendBtn));
  renderHead();
  toggleSend();
  msgsEl.append(el('div', { class: 'c-e2ee' }, ico('lock'), el('span', { text: isSupport(c) ? (supportUserView(c) ? 'سؤال، مشکل یا پیشنهادتان را اینجا بنویسید. پیام‌ها رمزنگاری سرتاسری دارند و فقط تیم پشتیبانی آن‌ها را می‌خواند.' : 'گفتگوی پشتیبانی — پاسخ شما با نام «' + (info.support?.name || 'پشتیبانی') + '» به کاربر نمایش داده می‌شود.') : c.type === 'group' ? 'پیام‌های این ' + (c.channel ? 'کانال' : 'گروه') + ' رمزنگاری سرتاسری دارند.' : 'گفتگوی رمزنگاری‌شده (E2EE) — فقط شما و ' + cname() + ' می‌توانید این پیام‌ها را بخوانید.' })));
  if (c.type !== 'direct') { try { for (const m of (await call('group_info', { cid: c.id })).members) names.set(m.id, m.display_name); } catch { /* names fall back */ } }
  const list = await call('messages', { cid: c.id });
  if (current !== c) return;
  let lastDay = '';
  for (const m of list) {
    const day = faDay.format(m.ts);
    if (day !== lastDay) { msgsEl.append(el('div', { class: 'c-day', text: day === faDay.format(Date.now()) ? 'امروز' : day })); lastDay = day; }
    upsertMessage(m, false);
  }
  renderPinned();
  const target = focusMid && msgsEl.querySelector(`[data-mid="${CSS.escape(focusMid)}"]`);
  if (target) { target.scrollIntoView({ block: 'center' }); target.classList.add('flash'); } else msgsEl.scrollTop = msgsEl.scrollHeight;
  await renderBanner();
  if (!window.matchMedia('(pointer: coarse)').matches) composer.focus();
  scheduleRead();
  swPost({ type: 'read', c: c.id });
  if (c.type === 'direct') { updatePresence(); presenceTimer = setInterval(updatePresence, 20000); }
  refreshList();
}
function renderHead() {
  const c = current; if (!c) return;
  const word = c.channel ? 'مشترک' : 'عضو';
  chatHead.replaceChildren(...[
    iconBtn('back', 'بازگشت', () => closeConversation(), 'c-back'),
    el('button', { class: 'c-head-who', onclick: isSupport(c) ? supportInfo : c.type === 'group' ? groupInfo : showSafety, 'aria-label': 'اطلاعات گفتگو' }, avatar(titleOf(c), c.peer?.id || c.id, kindOf(c))),
    el('div', { class: 'c-head-name' }, el('b', {}, el('span', { text: titleOf(c) }), c.verified ? verified() : null),
      isSupport(c) ? el('button', { class: 'c-lock', onclick: supportInfo }, ico('lock', 'mini'), el('span', { text: supportUserView(c) ? ' تیم پشتیبانی — رمزنگاری سرتاسری' : ' @' + (c.support_user?.username || '') }))
        : c.type === 'group' ? el('button', { class: 'c-lock', onclick: groupInfo }, ico('lock', 'mini'), el('span', { text: ` ${faNum.format(c.member_count)} ${word} — اطلاعات ${c.channel ? 'کانال' : 'گروه'}` }))
        : el('button', { class: 'c-lock', onclick: showSafety }, statusEl, typingEl)),
    isMuted(c) ? ico('bellOff', 'mini muted-ic') : null,
    c.type !== 'direct' || svcOff('voice_call', c) ? null : iconBtn('phone', 'تماس صوتی', () => startCall(false)),
    c.type !== 'direct' || svcOff('video_call', c) ? null : iconBtn('video', 'تماس تصویری', () => startCall(true)),
    iconBtn('more', 'گزینه‌های گفتگو', chatMenu)].filter(Boolean));
  if (c.type === 'direct') { statusEl.textContent = c.seenText || '🔒 رمزنگاری سرتاسری'; }
}
async function updatePresence() {
  if (!current || current.type !== 'direct') return;
  try {
    const p = await call('presence', { cid: current.id });
    current.seenText = p.online ? 'آنلاین' : lastSeen(p.seen) || '🔒 رمزنگاری سرتاسری';
    statusEl.textContent = current.seenText; statusEl.classList.toggle('online', p.online);
  } catch { /* offline */ }
}
function chatMenu() {
  const c = current;
  sheet(titleOf(c),
    isSupport(c) ? sheetItem('info', 'درباره پشتیبانی', supportInfo) : sheetItem('info', c.type === 'group' ? 'اطلاعات ' + (c.channel ? 'کانال' : 'گروه') : 'اطلاعات و شماره امنیتی', c.type === 'group' ? groupInfo : showSafety),
    isSupport(c) ? null : sheetItem('shield', 'گزارش تخلف', () => reportDialog(c.type === 'group' ? 'conversation' : 'user', c.type === 'group' ? c.id : c.peer.id, titleOf(c))),
    sheetItem('search', 'جست‌وجو در گفتگو', openFind),
    sheetItem('image', 'رسانه‌ها، فایل‌ها و لینک‌ها', showMedia),
    isMuted(c) ? sheetItem('bell', 'روشن کردن اعلان', () => setPrefs(c, { muted_until: null }).then(renderHead)) : sheetItem('bellOff', 'بی‌صدا…', () => muteSheet(c)),
    supportUserView(c) ? null : sheetItem('archive', c.archived ? 'خارج کردن از آرشیو' : 'آرشیو', () => setPrefs(c, { archived: !c.archived }).then(() => toast(c.archived ? 'به آرشیو منتقل شد.' : 'از آرشیو خارج شد.'))),
    sheetItem('trash', 'پاک کردن تاریخچه روی این دستگاه', async () => {
      if (!confirm('همه پیام‌های این گفتگو فقط از همین دستگاه پاک شوند؟')) return;
      await call('clear_chat', { cid: c.id }); openConversation(c);
    }, 'danger'));
}
const REPORT_REASONS = [['spam', 'هرزنامه یا تبلیغ'], ['abuse', 'توهین، آزار یا تهدید'], ['illegal', 'محتوای غیرقانونی'], ['fraud', 'کلاهبرداری'], ['fake', 'جعل هویت'], ['other', 'سایر']];
function reportDialog(type, id, name) {
  const details = el('textarea', { rows: '4', maxlength: '1000', placeholder: 'توضیح (اختیاری)' });
  let reason = 'spam';
  const dlg = dialog('گزارش تخلف: ' + name,
    el('div', { class: 'c-reasons' }, ...REPORT_REASONS.map(([k, l], i) => el('label', { class: 'check' },
      el('input', { type: 'radio', name: 'reason', value: k, checked: i === 0 || null, onchange: () => { reason = k; } }), el('span', { text: l })))),
    details,
    el('p', { class: 'small muted', text: 'مدیر سامانه پیام‌ها، عکس‌ها و فایل‌های گفتگو را نمی‌بیند (رمزنگاری سرتاسری)؛ فقط همین دلیل و توضیح شما برایش ارسال می‌شود. گزارش ناشناس به طرف مقابل است.' }),
    el('button', { class: 'btn block', text: 'ارسال گزارش', onclick: async (e) => {
      e.target.disabled = true;
      try { await call('report', { type, id, reason, details: details.value }); close(dlg); toast('گزارش شما ثبت شد. ممنون.'); } catch (x) { toast(errText(x)); e.target.disabled = false; }
    } }));
  dlg.showModal();
}
function supportInfo() {
  const c = current;
  dialog(titleOf(c),
    el('div', { class: 'c-profile' }, avatar(titleOf(c), c.id, 'support', 'xl'), el('h3', {}, el('span', { text: titleOf(c) }), c.verified ? verified() : null)),
    el('p', { text: supportUserView(c) ? 'این گفتگوی رسمی پشتیبانی است و همیشه بالای فهرست می‌ماند. پیام‌های شما رمزنگاری سرتاسری دارند و فقط برای دستگاه‌های کارکنان پشتیبانی (که مدیر سامانه تعیین کرده) رمز می‌شوند.'
      : `گفتگوی پشتیبانی کاربر ${c.support_user?.display_name || ''} (@${c.support_user?.username || ''}). پاسخ‌های شما با نام «${info.support?.name || 'پشتیبانی'}» به کاربر نمایش داده می‌شود و همکاران پشتیبانی هم آن‌ها را می‌بینند.` }),
    el('p', { class: 'small muted', text: 'پشتیبان تازه، پیام‌های قبل از اضافه‌شدنش را نمی‌بیند (رمزنگاری سرتاسری).' })).showModal();
}
function muteRow(c) {
  return el('button', { class: 'c-row', onclick: () => isMuted(c) ? setPrefs(c, { muted_until: null }).then(() => toast('اعلان روشن شد.')) : muteSheet(c) },
    el('span', { class: 'c-ico-box' }, ico(isMuted(c) ? 'bellOff' : 'bell')), el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: isMuted(c) ? 'بی‌صدا — روشن کردن اعلان' : 'بی‌صدا کردن اعلان‌ها' })));
}
function openFind() {
  const q = el('input', { type: 'search', placeholder: 'جست‌وجو در این گفتگو…', 'aria-label': 'جست‌وجو در گفتگو' });
  const count = el('span', { class: 'small muted' });
  let hits = [], i = 0;
  const go = (d) => { if (!hits.length) return; i = (i + d + hits.length) % hits.length; count.textContent = `${faNum.format(i + 1)} از ${faNum.format(hits.length)}`; const n = msgsEl.querySelector(`[data-mid="${CSS.escape(hits[i].mid)}"]`); n?.scrollIntoView({ block: 'center' }); n?.classList.remove('flash'); void n?.offsetWidth; n?.classList.add('flash'); };
  let t;
  q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { hits = q.value.trim().length >= 2 ? await call('search', { q: q.value, cid: current.id }) : []; i = -1; count.textContent = hits.length ? '' : (q.value.trim().length >= 2 ? 'یافت نشد' : ''); go(1); }, 250); });
  findBar.replaceChildren(el('div', { class: 'c-find' }, ico('search'), q, count, iconBtn('chevron', 'قبلی', () => go(1)), iconBtn('back', 'بعدی', () => go(-1)), iconBtn('x', 'بستن جست‌وجو', () => findBar.replaceChildren())));
  q.focus();
}
function autosize() { composer.style.height = 'auto'; composer.style.height = Math.min(140, composer.scrollHeight) + 'px'; }
function toggleSend() { const has = !!composer.value.trim(); sendBtn.hidden = !has && !!micBtn; if (micBtn) micBtn.hidden = has; }
function insertAtCursor(t) {
  const s = composer.selectionStart ?? composer.value.length, e = composer.selectionEnd ?? s;
  composer.value = composer.value.slice(0, s) + t + composer.value.slice(e);
  composer.selectionStart = composer.selectionEnd = s + t.length; composer.focus(); toggleSend(); autosize();
}
function attachSheet() {
  emojiPanel.hidden = true;
  const tile = (icon, label, fn, cls) => el('button', { class: 'tile ' + cls, onclick: () => { document.querySelector('.c-sheet-wrap')?.remove(); fn(); } }, el('span', { class: 'tile-ic' }, ico(icon)), el('span', { text: label }));
  const img = !svcOff('image'), vid = !svcOff('video');
  const gal = [img && 'image/*', vid && 'video/*'].filter(Boolean).join(',');
  sheet(null, el('div', { class: 'tiles' },
    gal ? tile('image', 'گالری', () => { fileInput.accept = gal; fileInput.click(); fileInput.accept = ''; }, 't1') : null,
    gal ? tile('camera', 'دوربین', () => { camInput.accept = gal; camInput.click(); }, 't2') : null,
    svcOff('file') ? null : tile('file', 'فایل', () => { fileInput.accept = ''; fileInput.click(); }, 't3'),
    svcOff('audio') ? null : tile('music', 'صوت', () => { fileInput.accept = 'audio/*'; fileInput.click(); fileInput.accept = ''; }, 't4')),
    !img || !vid || svcOff('file') || svcOff('audio') ? el('p', { class: 'small alert warn', text: 'بعضی از انواع فایل توسط مدیر سامانه غیرفعال شده‌اند.' }) : null,
    el('p', { class: 'small muted', text: `🔒 فایل‌ها پیش از خروج از این دستگاه رمزنگاری می‌شوند و ${faNum.format(info.retention_days)} روز روی سرور می‌مانند. بدون سقف مصنوعی حجم.` }));
}
function scheduleRead() {
  clearTimeout(readTimer);
  readTimer = setTimeout(() => { if (current && document.visibilityState === 'visible') call('mark_read', { cid: current.id, peer: pid() }).then((n) => n && scheduleRefresh()).catch(() => {}); }, 800);
}
function showTyping() {
  typingEl.textContent = 'در حال نوشتن…'; statusEl.hidden = true;
  clearTimeout(typingTimer); typingTimer = setTimeout(() => { typingEl.textContent = ''; statusEl.hidden = false; }, 6000);
}
async function renderBanner() {
  if (!current) return;
  if (isSupport()) { composer.disabled = svcOff('text'); return; }
  if (isGroup()) {
    const blocked = current.has_state === false;
    composer.disabled = blocked || svcOff('text'); sendBtn.disabled = blocked;
    bannerEl.replaceChildren(...(blocked ? [el('div', { class: 'alert c-banner', text: 'در انتظار دریافت اطلاعات رمزنگاری‌شده گروه از مدیر گروه…' })] : []));
    return;
  }
  const st = await call('contact_state', { user_id: pid() }).catch(() => ({ pending: false }));
  const blocked = !!st.pending;
  composer.disabled = blocked || svcOff('text'); sendBtn.disabled = blocked;
  bannerEl.replaceChildren(...(blocked ? [el('div', { class: 'alert warn c-banner' },
    el('span', { text: `کلید امنیتی ${cname()} تغییر کرده است (ورود از دستگاه جدید یا بازنشانی حساب). تا تأیید شما پیامی برای او ارسال نمی‌شود. در صورت امکان شماره امنیتی را حضوری مقایسه کنید.` }),
    el('button', { class: 'btn sm', text: 'پذیرفتن کلید جدید', onclick: async () => { await call('accept_key', { user_id: pid() }); renderBanner(); } }))] : []));
}
function renderPinned() {
  const pinned = [...byMid.values()].filter((m) => m.pinned && !m.deleted).sort((a, b) => b.ts - a.ts)[0];
  pinEl.replaceChildren(...(pinned ? [el('button', { class: 'c-pinbar', onclick: () => msgsEl.querySelector(`[data-mid="${CSS.escape(pinned.mid)}"]`)?.scrollIntoView({ block: 'center' }) },
    ico('pin'), el('span', { class: 'c-body' }, el('b', { text: 'پیام سنجاق‌شده' }), el('span', { text: summary(pinned) })))] : []));
}
const summary = (m) => m.deleted ? 'پیام حذف شد' : (m.att ? KIND[m.att.kind] + ' ' : '') + (m.text || m.att?.name || '').slice(0, 80);

// ------------------------------------------------------------------ messages
const FLAGS = {
  untrusted_device: 'پیامی از دستگاهی که با کلید امنیتی فرستنده تأیید نشده بود دریافت و رد شد.',
  decrypt_failed: 'این پیام قابل رمزگشایی نبود.',
  invalid_payload: 'پیام نامعتبر دریافت و رد شد.',
  not_channel_admin: 'پیامی از عضوی که مدیر کانال نیست دریافت و رد شد.',
};
// Delivery ticks: ⏱ sending · ✓ sent (server has it) · ✓✓ delivered (a device of the recipient got it) · ✓✓ coloured = read.
const TICKS = {
  sending: ['0 0 16 16', '<circle cx="8" cy="8" r="6.2"/><path d="M8 4.8V8l2.2 1.5"/>'],
  sent: ['0 0 16 12', '<path d="M2.2 6.6 5.9 10.2 13.8 2.2"/>'],
  double: ['0 0 21 12', '<path d="M1.6 6.6 5.3 10.2 13.2 2.2"/><path class="t2" d="M8.9 9.2 9.9 10.2 17.8 2.2"/>'],
  failed: ['0 0 16 16', '<circle cx="8" cy="8" r="6.4"/><path d="M8 4.6v4.2M8 11.2v.2"/>'],
};
function tick(m) {
  const st = m.status;
  const kind = st === 'sending' || st === 'uploading' ? 'sending' : st === 'failed' ? 'failed' : st === 'sent' ? 'sent' : 'double';
  const label = { sending: 'در حال ارسال', uploading: 'در حال ارسال', sent: 'ارسال شد', delivered: 'تحویل داده شد', read: 'خوانده شد', failed: 'ارسال نشد' }[st] || '';
  // The text glyph stays in the DOM (tests and copy-paste) but is visually replaced by the drawn icon.
  const n = el('span', { class: `st ${kind}${st === 'read' ? ' read' : ''}`, title: label, 'aria-label': label, 'data-st': st, text: { sent: '✓', delivered: '✓✓', read: '✓✓' }[st] || '' });
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', TICKS[kind][0]); svg.setAttribute('aria-hidden', 'true'); svg.innerHTML = TICKS[kind][1];
  n.append(svg);
  return n;
}
function linkify(text) {
  const box = el('div', { class: 'c-text' });
  const re = /https?:\/\/[^\s<>"']+/g; let last = 0, m;
  while ((m = re.exec(text))) {
    box.append(text.slice(last, m.index));
    box.append(el('a', { href: m[0], target: '_blank', rel: 'noopener noreferrer nofollow', class: 'ltr', text: m[0] }));
    last = m.index + m[0].length;
  }
  box.append(text.slice(last));
  return box;
}
function upsertMessage(m, scroll = true) {
  byMid.set(m.mid, m);
  const old = msgsEl.querySelector(`[data-mid="${CSS.escape(m.mid)}"]`);
  let node;
  if (m.dir === 'sys') node = el('div', { class: 'c-sys', 'data-mid': m.mid, text: m.text });
  else if (m.dir === 'in' && FLAGS[m.flag]) node = el('div', { class: 'c-sys', 'data-mid': m.mid, text: '⚠ ' + FLAGS[m.flag] });
  else {
    const target = m.reply_to ? byMid.get(m.reply_to) : null;
    const reacts = Object.entries(m.reactions || {});
    const media = !m.deleted && m.att && (m.att.kind === 'image' || m.att.kind === 'video') && m.att.thumb;
    node = el('div', { class: 'c-msg ' + (m.dir === 'out' ? 'out' : 'in') + (m.deleted ? ' deleted' : '') + (media && !m.text ? ' media' : ''), 'data-mid': m.mid,
      oncontextmenu: (e) => { if (m.deleted || m.status === 'uploading' || m.status === 'sending') return; e.preventDefault(); menu(m, e.currentTarget); }, ondblclick: () => !m.deleted && setReply(m) },
      (isGroup() || (isSupport() && !supportUserView())) && m.dir === 'in' ? el('div', { class: 'c-sender g' + (hash(m.from) % 8), text: nameOf(m.from) }) : null,
      m.flag === 'not_in_roster' ? el('div', { class: 'c-flag', text: '⚠ فرستنده در فهرست رمزنگاری‌شده اعضای گروه نیست' }) : null,
      m.fwd ? el('div', { class: 'c-fwd', text: '↪ بازارسال‌شده' }) : null,
      target ? el('button', { class: 'c-quote', onclick: () => msgsEl.querySelector(`[data-mid="${CSS.escape(target.mid)}"]`)?.scrollIntoView({ block: 'center' }) },
        el('b', { text: nameOf(target.from) }), el('span', { text: summary(target) })) : null,
      m.deleted ? el('div', { class: 'c-text muted', text: '🚫 این پیام حذف شد' }) : null,
      !m.deleted && m.att ? attachmentView(m) : null,
      !m.deleted && m.text ? linkify(m.text) : null,
      m.flag === 'new_key' ? el('div', { class: 'c-flag', text: 'از کلید امنیتی جدید و تأییدنشده فرستنده' }) : null,
      reacts.length ? el('div', { class: 'c-reacts' }, ...groupReacts(reacts)) : null,
      el('div', { class: 'c-meta' },
        m.pinned ? ico('pin', 'mini') : null,
        m.edited ? el('span', { text: 'ویرایش‌شده' }) : null,
        el('span', { text: fa.format(m.ts) }),
        m.dir === 'out' ? tick(m) : null,
        m.deleted || m.status === 'uploading' || m.status === 'sending' ? null : el('button', { class: 'c-more', 'aria-label': 'گزینه‌های پیام', text: '⋯', onclick: (e) => menu(m, e.currentTarget) })),
      m.status === 'failed' ? el('div', { class: 'c-flag' }, el('span', { text: 'ارسال نشد: ' + errText(m.flag) + ' ' }),
        el('button', { class: 'btn sm secondary', text: 'تلاش دوباره', onclick: () => call('retry', { mid: m.mid, peer: pid() }).catch((e) => toast(errText(e))) })) : null);
    longPress(node, () => !m.deleted && m.status !== 'uploading' && m.status !== 'sending' && menu(m, node));
  }
  const atBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 120;
  if (old) old.replaceWith(node); else msgsEl.append(node);
  for (const x of byMid.values()) if (x.reply_to === m.mid && x.mid !== m.mid) { const n = msgsEl.querySelector(`[data-mid="${CSS.escape(x.mid)}"] .c-quote span`); if (n) n.textContent = summary(m); }
  if (scroll && !old && (atBottom || m.dir === 'out')) msgsEl.scrollTop = msgsEl.scrollHeight;
  if (old && pinEl) renderPinned();
}
function groupReacts(reacts) {
  const by = new Map();
  for (const [u, e] of reacts) { if (!by.has(e)) by.set(e, []); by.get(e).push(u); }
  return [...by].map(([e, us]) => el('span', { class: us.includes(me.id) ? 'mine' : '', title: us.map(nameOf).join('، '), text: e + (us.length > 1 ? ' ' + faNum.format(us.length) : '') }));
}

function attachmentView(m) {
  const a = m.att;
  const left = a.exp ? Math.ceil((Date.parse(a.exp.replace(' ', 'T') + 'Z') - Date.now()) / 86400000) : null;
  const expiry = a.id ? el('div', { class: 'c-exp', text: left === null ? 'نگهداری دائمی روی سرور' : left > 0 ? `تا ${faNum.format(left)} روز دیگر روی سرور` : 'از سرور حذف شده است' }) : null;
  const progress = m.status === 'uploading' ? el('progress', { max: a.size || 1, value: 0 }) : null;
  const box = el('div', { class: 'c-att' });
  const open = async () => {
    box.classList.add('busy');
    try {
      const blob = await call('file', { mid: m.mid });
      let url = urls.get(m.mid);
      if (!url) { url = URL.createObjectURL(blob); urls.set(m.mid, url); }
      return url;
    } catch (e) { toast(errText(e)); return null; } finally { box.classList.remove('busy'); }
  };
  const save = async () => { const url = await open(); if (url) el('a', { href: url, download: a.name || 'file' }).click(); };
  if (a.kind === 'image' || a.kind === 'video') {
    const thumb = a.thumb ? el('img', { src: a.thumb, alt: a.name, class: 'c-thumb' }) : el('div', { class: 'c-thumb c-ph', text: KIND[a.kind] });
    box.append(el('button', { class: 'c-media', 'aria-label': a.kind === 'video' ? 'پخش ویدیو' : 'نمایش تصویر', onclick: async () => {
      const url = await open(); if (!url) return;
      const dlg = dialog(a.name, a.kind === 'video' ? el('video', { src: url, controls: true, autoplay: true, playsinline: true, class: 'c-view' }) : el('img', { src: url, alt: a.name, class: 'c-view' }),
        el('a', { class: 'btn block mt', href: url, download: a.name, text: 'ذخیره روی دستگاه' }));
      dlg.classList.add('viewer');
      dlg.showModal();
    } }, thumb, a.kind === 'video' ? el('span', { class: 'c-play' }, ico('play')) : null,
      el('span', { class: 'c-att-info', text: `${a.q === 'hd' ? 'HD · ' : a.q === 'original' ? 'اصلی · ' : ''}${size(a.size)}${a.dur ? ' · ' + dur(a.dur) : ''}` })));
  } else if (a.kind === 'audio') {
    box.append(voicePlayer(m, open));
  } else {
    const ext = (a.name.split('.').pop() || '').slice(0, 4).toUpperCase();
    box.append(el('button', { class: 'c-file', onclick: save }, el('span', { class: 'c-file-ico' }, ico('file'), el('small', { text: ext })),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: a.name }), el('span', { class: 'c-prev', text: size(a.size) + ' · بارگیری' }))));
  }
  box.append(progress || '', expiry || '');
  return box;
}
const dur = (s) => { s = Math.round(s); return faNum.format(Math.floor(s / 60)) + ':' + String(s % 60).padStart(2, '0').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]); };
/** Voice message: play/pause, a seekable progress line, elapsed time and 1× / 1.5× / 2× speed. */
function voicePlayer(m, open) {
  const a = m.att;
  const bars = el('span', { class: 'c-wave' }, ...Array.from({ length: 28 }, (_, i) => el('i', { class: 'h' + ((hash(m.mid + i) % 5) + 1) })));
  const time = el('span', { class: 'c-vtime', text: a.dur ? dur(a.dur) : '' });
  const speed = el('button', { class: 'c-speed', text: '۱×', 'aria-label': 'سرعت پخش', onclick: () => { if (!audio) return; const r = { 1: 1.5, 1.5: 2, 2: 1 }[audio.playbackRate] || 1; audio.playbackRate = r; speed.textContent = faNum.format(r) + '×'; } });
  let audio = null;
  const btn = el('button', { class: 'c-vplay', onclick: async () => {
    if (!audio) {
      const url = await open(); if (!url) return;
      audio = el('audio', { src: url, preload: 'auto' });
      box.append(audio);
      const paint = (p) => [...bars.children].forEach((b, i) => b.classList.toggle('on', i < Math.round(p * bars.children.length)));
      audio.addEventListener('timeupdate', () => { paint(audio.duration ? audio.currentTime / audio.duration : 0); time.textContent = dur(audio.currentTime); });
      audio.addEventListener('ended', () => { btn.replaceChildren(ico('play'), sr('پخش')); time.textContent = a.dur ? dur(a.dur) : ''; paint(0); });
      audio.addEventListener('play', () => btn.replaceChildren(ico('pause'), sr('توقف')));
      audio.addEventListener('pause', () => { if (!audio.ended) btn.replaceChildren(ico('play'), sr('پخش')); });
    }
    if (audio.paused) audio.play().catch(() => {}); else audio.pause();
  } }, ico('play'), sr('پخش پیام صوتی' + (a.dur ? ' (' + dur(a.dur) + ')' : '')));
  bars.addEventListener('click', (e) => { if (!audio?.duration) return; const r = bars.getBoundingClientRect(); audio.currentTime = audio.duration * Math.min(1, Math.max(0, (r.right - e.clientX) / r.width)); });
  const box = el('div', { class: 'c-voice' }, btn, el('span', { class: 'c-body' }, bars, el('span', { class: 'row' }, time, speed)));
  return box;
}

function menu(m) {
  const own = m.from === me.id;
  const reactRow = el('div', { class: 'c-emoji' }, ...EMOJI.map((e) => el('button', { text: e, class: m.reactions?.[me.id] === e ? 'on' : '', 'aria-label': 'واکنش ' + e, onclick: async () => {
    document.querySelector('.c-sheet-wrap')?.remove(); const mine = m.reactions?.[me.id] === e;
    try { await call('react', { cid: current.id, peer: pid(), mid: m.mid, emoji: mine ? '' : e }); } catch (x) { toast(errText(x)); }
  } })));
  const w = sheet(null, reactRow,
    sheetItem('reply', 'پاسخ', () => setReply(m)),
    m.text ? sheetItem('copy', 'کپی متن', () => navigator.clipboard.writeText(m.text).then(() => toast('کپی شد'))) : null,
    own && !m.att && m.text ? sheetItem('edit', 'ویرایش', () => setEdit(m)) : null,
    sheetItem('pin', m.pinned ? 'برداشتن سنجاق' : 'سنجاق', () => call('pin', { cid: current.id, peer: pid(), mid: m.mid, pinned: !m.pinned })),
    sheetItem('forward', 'بازارسال', () => forwardDialog(m)),
    sheetItem('trash', 'حذف برای من', () => call('delete_local', { mid: m.mid }), 'danger'),
    own ? sheetItem('trash', 'حذف برای همه', () => confirm('این پیام برای همه حذف شود؟') && call('delete_all', { cid: current.id, peer: pid(), mid: m.mid }), 'danger') : null);
  w.classList.add('c-menu-wrap');
  w.querySelector('.c-sheet').classList.add('c-menu');
}
function setReply(m) { editing = null; reply = m; replyBar.replaceChildren(ico('reply'), el('span', { class: 'c-body' }, el('b', { text: 'پاسخ به ' + nameOf(m.from) }), el('span', { text: summary(m) })), iconBtn('x', 'لغو', clearReply)); composer.focus(); }
function setEdit(m) { reply = null; editing = m; composer.value = m.text; toggleSend(); autosize(); replyBar.replaceChildren(ico('edit'), el('span', { class: 'c-body' }, el('b', { text: 'ویرایش پیام' }), el('span', { text: summary(m) })), iconBtn('x', 'لغو', () => { clearReply(); composer.value = ''; toggleSend(); })); composer.focus(); }
function clearReply() { reply = null; editing = null; replyBar.replaceChildren(); }
async function forwardDialog(m) {
  const dlg = dialog('بازارسال به…', el('div', { class: 'c-dir' }, ...convs.filter((c) => c.peer).map((c) => el('button', { class: 'c-item', onclick: async () => {
    close(dlg);
    try { await call('forward', { mid: m.mid, cid: c.id, peer: c.peer.id }); toast('بازارسال شد به ' + c.peer.display_name); } catch (e) { toast(errText(e)); }
  } }, avatar(c.peer.display_name, c.peer.id), el('span', { class: 'c-name', text: c.peer.display_name })))));
  dlg.showModal();
}
async function doSend() {
  const text = composer.value;
  if (!text.trim() || !current) return;
  composer.value = ''; toggleSend(); autosize(); emojiPanel.hidden = true;
  try {
    if (editing) await call('edit', { cid: current.id, peer: pid(), mid: editing.mid, text });
    else await call('send', { cid: current.id, peer: pid(), text, reply_to: reply?.mid || null });
  } catch (e) { toast(errText(e)); }
  clearReply();
}

// ------------------------------------------------------------------ files, images, video, voice
async function bitmapThumb(src, w, h, max = 320) {
  const s = Math.min(1, max / Math.max(w, h));
  const c = el('canvas'); c.width = Math.max(1, Math.round(w * s)); c.height = Math.max(1, Math.round(h * s));
  c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
  for (const q of [0.6, 0.45, 0.3]) { const d = c.toDataURL('image/jpeg', q); if (d.length < 38000) return d; }
  return null;
}
async function imageMeta(file) {
  const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
  const meta = { kind: 'image', w: bmp.width, h: bmp.height, thumb: await bitmapThumb(bmp, bmp.width, bmp.height) };
  return { bmp, meta };
}
/** HD: re-encoded on this device (long edge ≤ 2560 px, JPEG 0.9). Also strips EXIF/location data. */
async function toHD(file, bmp) {
  const s = Math.min(1, 2560 / Math.max(bmp.width, bmp.height));
  const c = el('canvas'); c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
  c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
  const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
  return { file: new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }), w: c.width, h: c.height };
}
function videoMeta(file) {
  return new Promise((resolve) => {
    const v = el('video', { muted: true, preload: 'metadata', playsinline: true });
    const url = URL.createObjectURL(file);
    const done = (meta) => { URL.revokeObjectURL(url); resolve(meta); };
    const t = setTimeout(() => done({ kind: 'video' }), 8000);
    v.onloadedmetadata = () => { v.currentTime = Math.min(1, (v.duration || 0) / 2); };
    v.onseeked = async () => { clearTimeout(t); done({ kind: 'video', w: v.videoWidth, h: v.videoHeight, dur: v.duration, thumb: await bitmapThumb(v, v.videoWidth, v.videoHeight) }); };
    v.onerror = () => { clearTimeout(t); done({ kind: 'video' }); };
    v.src = url;
  });
}
async function sendDialog(files) {
  const kindOfFile = (f) => f.type.startsWith('image/') && f.type !== 'image/svg+xml' ? 'image' : f.type.startsWith('video/') ? 'video' : f.type.startsWith('audio/') ? 'audio' : 'file';
  const dropped = files.filter((f) => svcOff(kindOfFile(f)));
  if (dropped.length) toast(`${faNum.format(dropped.length)} فایل حذف شد: ارسال این نوع فایل توسط مدیر غیرفعال است.`);
  files = files.filter((f) => !svcOff(kindOfFile(f)));
  if (!files.length) return;
  try { // the server enforces the quota too; this only avoids encrypting files that cannot be sent
    const st = await call('my_storage');
    const need = files.reduce((n, f) => n + f.size, 0);
    if (st.quota && st.used + need > st.quota) {
      return dialog('فضای شما کافی نیست',
        el('p', { text: `این ${files.length > 1 ? 'فایل‌ها' : 'فایل'} ${size(need)} است ولی از سهمیه ${size(st.quota)} شما فقط ${size(Math.max(0, st.quota - st.used))} باقی مانده است.` }),
        el('p', { class: 'small muted', text: `فایل‌ها ${faNum.format(st.retention_days)} روز پس از ارسال از سرور حذف می‌شوند و فضایشان آزاد می‌شود؛ برای سهمیه بیشتر با مدیر سامانه تماس بگیرید. ارسال پیام متنی محدودیتی ندارد.` })).showModal();
    }
  } catch { /* offline: the server decides */ }
  const items = [];
  for (const f of files) {
    const kind = f.type.startsWith('image/') && f.type !== 'image/svg+xml' ? 'image' : f.type.startsWith('video/') ? 'video' : f.type.startsWith('audio/') ? 'audio' : 'file';
    let meta = { kind }, bmp = null;
    try { if (kind === 'image') ({ bmp, meta } = await imageMeta(f)); else if (kind === 'video') meta = await videoMeta(f); } catch { meta = { kind: 'file' }; }
    items.push({ f, meta, bmp });
  }
  const hasImg = items.some((i) => i.meta.kind === 'image');
  const quality = el('select', { 'aria-label': 'کیفیت تصویر' }, el('option', { value: 'hd', text: 'HD (حجم کمتر، حذف اطلاعات مکان)' }), el('option', { value: 'original', text: 'اصلی (بدون کاهش کیفیت؛ ممکن است اطلاعات مکان EXIF داشته باشد)' }));
  const caption = el('input', { type: 'text', placeholder: 'توضیح (اختیاری)', maxlength: '2000' });
  const dlg = dialog(`ارسال ${faNum.format(items.length)} مورد`,
    el('div', { class: 'c-sendlist' }, ...items.map((i) => el('div', { class: 'c-senditem' },
      i.meta.thumb ? el('img', { src: i.meta.thumb, alt: '' }) : el('span', { class: 'c-file-ico' }, ico(i.meta.kind === 'video' ? 'video' : i.meta.kind === 'audio' ? 'music' : 'file')),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: i.f.name }), el('span', { class: 'c-prev', text: size(i.f.size) }))))),
    hasImg ? el('label', { class: 'field' }, el('span', { text: 'کیفیت تصویر' }), quality) : null,
    items.some((i) => i.meta.kind === 'video') ? el('p', { class: 'small muted', text: 'ویدیو با کیفیت اصلی ارسال می‌شود. (انتخاب کیفیت کمتر در این نسخه وجود ندارد.)' }) : null,
    el('label', { class: 'field' }, el('span', { text: 'توضیح' }), caption),
    el('p', { class: 'small muted', text: `فایل‌ها پیش از خروج از این دستگاه رمزنگاری می‌شوند و ${faNum.format(info.retention_days)} روز روی سرور می‌مانند.` }),
    el('button', { class: 'btn block', text: 'ارسال', onclick: async () => {
      close(dlg);
      const cid = current.id, peer = pid();
      for (const [n, i] of items.entries()) {
        let file = i.f, meta = { ...i.meta, name: i.f.name, mime: i.f.type };
        if (meta.kind === 'image') {
          meta.q = quality.value;
          if (quality.value === 'hd') { const hd = await toHD(i.f, i.bmp); file = hd.file; meta = { ...meta, w: hd.w, h: hd.h, name: hd.file.name, mime: 'image/jpeg' }; }
        }
        call('send_file', { cid, peer, file, meta, caption: n === 0 ? caption.value : '', reply_to: n === 0 ? reply?.mid || null : null }).catch((e) => toast(errText(e)));
      }
      clearReply();
    } }));
  dlg.showModal();
}
function recorderButton() {
  if (!window.MediaRecorder || !navigator.mediaDevices?.getUserMedia) return null;
  let rec = null, chunks = [], started = 0, tick2 = null, cancelled = false;
  const btn = el('button', { class: 'c-send c-mic', 'aria-label': 'ضبط پیام صوتی', title: 'ضبط پیام صوتی', onclick: async () => {
    if (rec) { rec.stop(); return; }
    let stream;
    try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); } catch { toast('دسترسی به میکروفون داده نشد.'); return; }
    const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find((t) => MediaRecorder.isTypeSupported(t)) || '';
    rec = new MediaRecorder(stream, type ? { mimeType: type } : {});
    chunks = []; started = Date.now(); cancelled = false;
    const bar = el('div', { class: 'c-recbar' }, el('span', { class: 'dotrec' }), el('span', { class: 'c-rectime', text: dur(0) }), el('span', { class: 'grow small muted', text: 'در حال ضبط… برای ارسال دوباره بزنید' }),
      el('button', { class: 'btn sm secondary', text: 'لغو', onclick: () => { cancelled = true; rec?.stop(); } }));
    replyBar.before(bar);
    rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    rec.onstop = () => {
      stream.getTracks().forEach((t) => t.stop()); clearInterval(tick2); bar.remove();
      btn.classList.remove('rec');
      const secs = (Date.now() - started) / 1000; rec = null;
      if (cancelled || secs < 0.7 || !current) return;
      const mime = (chunks[0]?.type || type || 'audio/webm').split(';')[0];
      const file = new File(chunks, 'voice.' + (mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm'), { type: mime });
      call('send_file', { cid: current.id, peer: pid(), file, meta: { kind: 'audio', dur: secs, name: file.name, mime } }).catch((e) => toast(errText(e)));
    };
    rec.start();
    btn.classList.add('rec'); buzz([20]);
    tick2 = setInterval(() => { bar.querySelector('.c-rectime').textContent = dur((Date.now() - started) / 1000); }, 500);
  } }, ico('mic'));
  return btn;
}
async function showMedia() {
  const all = (await call('media', { cid: current.id })).reverse();
  const links = await call('search', { kind: 'link', cid: current.id });
  const grid = el('div');
  const tabs = [['media', 'رسانه‌ها', all.filter((m) => m.att.kind === 'image' || m.att.kind === 'video')], ['files', 'فایل‌ها', all.filter((m) => m.att.kind === 'file')],
    ['audio', 'صداها', all.filter((m) => m.att.kind === 'audio')], ['links', 'لینک‌ها', links]];
  const goTo = (mid) => { close(dlg); const n = msgsEl.querySelector(`[data-mid="${CSS.escape(mid)}"]`); n?.scrollIntoView({ block: 'center' }); n?.classList.add('flash'); };
  const show = (k) => {
    const [, , items] = tabs.find((t) => t[0] === k);
    seg.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b.dataset.k === k));
    if (!items.length) return grid.replaceChildren(el('p', { class: 'muted c-hint', text: 'موردی نیست.' }));
    if (k === 'media') return grid.replaceChildren(el('div', { class: 'c-gallery' }, ...items.map((m) => el('button', { onclick: () => goTo(m.mid) },
      m.att.thumb ? el('img', { src: m.att.thumb, alt: '' }) : el('span', { text: KIND[m.att.kind] }), m.att.kind === 'video' ? el('span', { class: 'c-play' }, ico('play')) : null))));
    if (k === 'links') return grid.replaceChildren(el('div', { class: 'c-dir' }, ...items.map((r) => el('button', { class: 'c-item', onclick: () => goTo(r.mid) },
      el('span', { class: 'c-ico-box' }, ico('link')), el('span', { class: 'c-body' }, el('span', { class: 'c-name ltr', text: (r.text.match(/https?:\/\/\S+/) || [''])[0] }), el('span', { class: 'c-prev', text: faDay.format(r.ts) }))))));
    grid.replaceChildren(el('div', { class: 'c-dir' }, ...items.map((m) => el('button', { class: 'c-item', onclick: () => goTo(m.mid) },
      el('span', { class: 'c-ico-box' }, ico(m.att.kind === 'audio' ? 'mic' : 'file')),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: m.att.name }), el('span', { class: 'c-prev', text: size(m.att.size) + ' · ' + faDay.format(m.ts) }))))));
  };
  const seg = el('div', { class: 'seg' }, ...tabs.map(([k, l, items]) => el('button', { 'data-k': k, onclick: () => show(k) }, el('span', { text: l + (items.length ? ' ' + faNum.format(items.length) : '') }))));
  const dlg = dialog('رسانه‌های گفتگو', seg, grid, el('p', { class: 'small muted', text: `فایل‌ها پس از ${faNum.format(info.retention_days)} روز از سرور حذف می‌شوند؛ نسخه‌ای که روی دستگاه ذخیره کرده‌اید باقی می‌ماند.` }));
  show('media');
  dlg.showModal();
}
async function showSafety() {
  const c = current;
  const s = await call('safety', { user_id: pid() });
  const state = el('p', { class: 'small', text: s.verified ? '✔ شما این مخاطب را تأیید کرده‌اید.' : 'هنوز تأیید نشده است.' });
  const dlg = dialog(`شماره امنیتی شما و ${cname()}`,
    el('div', { class: 'c-profile' }, avatar(cname(), c.peer?.id, 'user', 'xl'), el('h3', { text: cname() }), el('p', { class: 'muted small ltr', text: '@' + (c.peer?.username || '') }),
      el('div', { class: 'row c-prof-actions' },
        el('button', { class: 'pa', onclick: () => { close(dlg); startCall(false); } }, ico('phone'), el('span', { text: 'تماس' })),
        el('button', { class: 'pa', onclick: () => { close(dlg); startCall(true); } }, ico('video'), el('span', { text: 'تصویری' })),
        el('button', { class: 'pa', onclick: () => { close(dlg); openFind(); } }, ico('search'), el('span', { text: 'جست‌وجو' })),
        el('button', { class: 'pa', onclick: () => { close(dlg); showMedia(); } }, ico('image'), el('span', { text: 'رسانه‌ها' })))),
    muteRow(c),
    el('h3', { class: 'mt', text: '🔒 شماره امنیتی' }),
    el('p', { class: 'small muted', text: 'اگر این ۶۰ رقم روی گوشی مخاطب هم دقیقاً همین باشد، پیام‌ها مستقیماً بین شما دو نفر رمزنگاری می‌شوند و هیچ‌کس (حتی سرور) در میان نیست. مقایسه را حضوری یا تلفنی انجام دهید.' }),
    el('div', { class: 'c-safety' }, ...s.number.map((g) => el('span', { text: g }))),
    state,
    s.pending ? el('p', { class: 'alert warn small', text: 'کلید این مخاطب تغییر کرده و هنوز پذیرفته نشده است؛ این شماره مربوط به کلید قبلی است.' }) : null,
    s.verified || s.pending ? null : el('button', { class: 'btn', text: 'اعداد یکسان است — تأیید', onclick: async () => { await call('verify', { user_id: pid() }); state.textContent = '✔ شما این مخاطب را تأیید کرده‌اید.'; } }),
    el('p', { class: 'small muted mt', text: `فایل‌ها پس از ${faNum.format(info.retention_days)} روز به‌طور خودکار از سرور حذف می‌شوند.` }),
    el('button', { class: 'btn danger block mt', text: '⚑ گزارش تخلف این کاربر', onclick: () => { close(dlg); reportDialog('user', pid(), cname()); } }));
  dlg.showModal();
}

// ------------------------------------------------------------------ calls (WebRTC, in-app over the internet)
// Signalling (SDP incl. DTLS fingerprints) goes through the end-to-end encrypted channel; media is DTLS-SRTP.
let cur = null; // { id, cid, peer, name, video, outgoing, pc, stream, ui, state, started, ringTimer, tick, ring }
const CALL_STATE = { calling: 'در حال برقراری تماس…', ringing: 'در حال زنگ خوردن…', incoming: 'تماس ورودی', connecting: 'در حال اتصال…', connected: 'متصل' };
const ice = { cfg: null, at: 0 };
async function iceConfig() { if (!ice.cfg || Date.now() - ice.at > 600000) { ice.cfg = await call('call_ice'); ice.at = Date.now(); } return ice.cfg; }
function makePc(cfg) {
  const pc = new RTCPeerConnection({ iceServers: cfg.ice_servers, iceTransportPolicy: cfg.relay_only ? 'relay' : 'all' });
  pc.ontrack = (e) => {
    if (!cur?.ui) return;
    cur.ui.remote.srcObject = e.streams[0];      // picture only (muted element)
    cur.ui.audio.srcObject = e.streams[0];       // sound: one dedicated <audio> element whose output we can route
    cur.ui.audio.play?.().catch(() => {});
  };
  pc.onconnectionstatechange = () => {
    if (!cur || cur.pc !== pc) return;
    if (pc.connectionState === 'connected' && cur.state !== 'connected') onConnected();
    if (pc.connectionState === 'failed') endCall('failed', 'ice_failed', cfg.has_turn ? 'اتصال تماس برقرار نشد.' : 'اتصال تماس برقرار نشد. شبکه شما احتمالاً به سرور TURN نیاز دارد که روی این سامانه تنظیم نشده است.');
  };
  return pc;
}
const gathered = (pc) => new Promise((r) => { if (pc.iceGatheringState === 'complete') return r(); const t = setTimeout(r, 3000); pc.addEventListener('icegatheringstatechange', () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); r(); } }); });
const MIC = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
async function media(video) {
  return navigator.mediaDevices.getUserMedia({ audio: MIC, video: video ? { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } } : false });
}
// ---- Audio route: phone earpiece (گوشی) vs loudspeaker (بلندگو).
// A web page cannot call the phone's audio-routing API directly. What browsers do expose:
//  • Chrome on Android lists the routes as audio devices ("Headset earpiece", "Speakerphone"); choosing the
//    microphone device switches the whole call route, and setSinkId() can pick the output.
//  • Safari on iPhone puts WebRTC calls on the earpiece by itself and offers no switch.
// Voice calls start on the earpiece where possible; video calls on the speaker. The 🔊 button appears only
// where switching really works on this device.
const EARPIECE = /earpiece|receiver|handset|گوشی/i, SPEAKER = /speaker|بلندگو/i;
async function audioRoutes() {
  let devs = [];
  try { devs = await navigator.mediaDevices.enumerateDevices(); } catch { /* unsupported */ }
  const pick = (kind, re) => devs.find((d) => d.kind === kind && re.test(d.label) && !(re === SPEAKER && EARPIECE.test(d.label)));
  const r = { inEar: pick('audioinput', EARPIECE), inSpk: pick('audioinput', SPEAKER), outEar: pick('audiooutput', EARPIECE), outSpk: pick('audiooutput', SPEAKER) };
  r.sink = !!(r.outEar && r.outSpk && HTMLMediaElement.prototype.setSinkId);
  r.input = !!(r.inEar && r.inSpk);
  r.can = r.sink || r.input;
  return r;
}
async function setRoute(speaker) {
  const c = cur;
  if (!c?.stream) return false;
  c.routes ||= await audioRoutes();
  const r = c.routes;
  let ok = false;
  if (r.sink) { try { await c.ui.audio.setSinkId((speaker ? r.outSpk : r.outEar).deviceId); ok = true; } catch { /* not allowed */ } }
  if (r.input) {
    try {
      const old = c.stream.getAudioTracks()[0];
      const s2 = await navigator.mediaDevices.getUserMedia({ audio: { ...MIC, deviceId: { exact: (speaker ? r.inSpk : r.inEar).deviceId } } });
      const t = s2.getAudioTracks()[0];
      if (cur !== c) { t.stop(); return false; }
      t.enabled = old ? old.enabled : true;
      await c.pc?.getSenders().find((x) => x.track?.kind === 'audio')?.replaceTrack(t);
      if (old) { old.stop(); c.stream.removeTrack(old); }
      c.stream.addTrack(t);
      ok = true;
    } catch { /* device busy / unsupported */ }
  }
  if (ok) c.speaker = speaker;
  return ok;
}
async function initRoute() {
  const c = cur;
  if (!c) return;
  c.routes = await audioRoutes();
  c.speaker = !!c.video; // earpiece for voice, loudspeaker for video
  if (c.routes.can) await setRoute(c.speaker);
  if (cur === c) renderCall();
}
function ring(on) {
  if (!on) { cur?.ring?.close?.(); if (cur) cur.ring = null; return; }
  try {
    const ctx = new AudioContext(); const g = ctx.createGain(); g.gain.value = 0; g.connect(ctx.destination);
    const o = ctx.createOscillator(); o.frequency.value = 440; o.connect(g); o.start();
    let i = 0; const iv = setInterval(() => { g.gain.value = (i++ % 4 < 2) ? 0.08 : 0; if (i % 8 === 1) buzz([300, 200, 300]); }, 400);
    cur.ring = { close: () => { clearInterval(iv); ctx.close(); } };
  } catch { /* autoplay blocked: the visual ring still shows */ }
}
function callUi() {
  const remote = el('video', { class: 'cl-remote', autoplay: true, playsinline: true, muted: true });
  const audio = el('audio', { class: 'cl-audio', autoplay: true, playsinline: true });
  const local = el('video', { class: 'cl-local', autoplay: true, playsinline: true, muted: true });
  remote.muted = true; // the attribute alone does not mute a script-created element
  local.muted = true;  // never play your own microphone back
  const status = el('div', { class: 'cl-status' });
  const info2 = el('div', { class: 'cl-info' });
  const controls = el('div', { class: 'cl-controls' });
  const root2 = el('div', { class: 'cl-overlay' + (cur.video ? ' video' : ''), role: 'dialog', 'aria-label': 'تماس' },
    remote, audio, local, el('div', { class: 'cl-top' }, el('div', { class: 'cl-rings' }, avatar(cur.name, cur.peer, 'user', 'cl-av')), el('b', { class: 'cl-name', text: cur.name }), status, info2), controls);
  document.body.append(root2);
  cur.ui = { root: root2, remote, audio, local, status, info: info2, controls };
  renderCall();
}
function renderCall() {
  if (!cur) return;
  const u = cur.ui;
  u.root.classList.toggle('ringing', ['incoming', 'ringing', 'calling'].includes(cur.state));
  u.status.textContent = cur.state === 'connected' ? CALL_STATE.connected + ' · ' + dur((Date.now() - cur.started) / 1000) : CALL_STATE[cur.state] + (cur.video ? ' (تصویری)' : ' (صوتی)');
  const btn = (label, icon, fn, cls = '') => el('button', { class: 'cl-btn ' + cls, 'aria-label': label, title: label, onclick: fn }, typeof icon === 'string' && ICONS[icon] ? ico(icon) : el('span', { text: icon }));
  if (cur.state === 'incoming') {
    u.controls.replaceChildren(btn('رد تماس', 'x', () => rejectCall(), 'red'), btn('پاسخ', cur.video ? 'video' : 'phone', () => acceptCall(), 'green'));
    return;
  }
  const a = cur.stream?.getAudioTracks()[0], v = cur.stream?.getVideoTracks()[0];
  u.controls.replaceChildren(
    a ? btn(a.enabled ? 'قطع میکروفون' : 'وصل میکروفون', a.enabled ? 'mic' : '🔇', () => { a.enabled = !a.enabled; renderCall(); }, a.enabled ? '' : 'off') : '',
    v ? btn(v.enabled ? 'خاموش کردن دوربین' : 'روشن کردن دوربین', v.enabled ? 'video' : '🚫', () => { v.enabled = !v.enabled; renderCall(); }, v.enabled ? '' : 'off') : '',
    cur.routes?.can ? btn(cur.speaker ? 'بلندگو روشن — پخش از گوشی' : 'پخش از بلندگو', 'speaker', async () => {
      if (await setRoute(!cur.speaker)) renderCall(); else toast('تغییر خروجی صدا روی این دستگاه ممکن نشد.');
    }, cur.speaker ? 'off' : '') : '',
    v && cur.cams > 1 ? btn('تعویض دوربین جلو و عقب', 'refresh', switchCamera) : '',
    btn('پایان تماس', 'phone', () => hangup(), 'red hang'));
}
async function startCall(video) {
  if (cur) return toast('یک تماس در جریان است.');
  if (!current) return;
  if (!window.RTCPeerConnection || !navigator.mediaDevices?.getUserMedia) return toast('این مرورگر از تماس پشتیبانی نمی‌کند.');
  cur = { cid: current.id, peer: pid(), name: cname(), video, outgoing: true, state: 'calling' };
  callUi();
  call('call_mode', { active: true });
  try {
    const cfg = await iceConfig();
    cur.stream = await media(video);
    cur.ui.local.srcObject = cur.stream; cur.ui.root.classList.add('has-local');
    await initRoute();
    cur.cams = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput').length;
    cur.id = await call('call_start', { cid: cur.cid, video });
    cur.pc = makePc(cfg);
    cur.stream.getTracks().forEach((t) => cur.pc.addTrack(t, cur.stream));
    await cur.pc.setLocalDescription(await cur.pc.createOffer());
    await gathered(cur.pc);
    if (!cur) return;
    await call('call_signal', { cid: cur.cid, peer: cur.peer, body: { call_id: cur.id, action: 'offer', sdp: cur.pc.localDescription.sdp, video } });
    cur.state = 'ringing'; renderCall();
    cur.ringTimer = setTimeout(() => { if (cur?.state === 'ringing') { signal('cancel'); endCall('missed', 'timeout', 'پاسخی داده نشد.'); } }, ((await iceConfig()).ring_seconds || 45) * 1000);
  } catch (e) {
    endCall('failed', 'error', e?.name === 'NotAllowedError' ? 'اجازه دسترسی به میکروفون/دوربین داده نشد.' : 'تماس برقرار نشد: ' + errText(e));
  }
}
const signal = (action, extra = {}) => cur && call('call_signal', { cid: cur.cid, peer: cur.peer, body: { call_id: cur.id, action, video: !!cur.video, ...extra } }).catch(() => {});
async function onCallSignal(d) {
  if (d.from === me.id) { // a signal from another of MY devices
    if (cur && cur.id === d.call_id && cur.state === 'incoming' && (d.action === 'answer' || d.action === 'reject')) {
      endCall(null, null, d.action === 'answer' ? 'تماس روی دستگاه دیگرتان پاسخ داده شد.' : 'تماس روی دستگاه دیگرتان رد شد.');
    }
    return;
  }
  if (d.action === 'offer') {
    if (cur) { // already in a call: tell the caller we are busy
      call('call_signal', { cid: d.cid, peer: d.from, body: { call_id: d.call_id, action: 'busy', video: false } }).catch(() => {});
      return;
    }
    const c = convs.find((x) => x.id === d.cid) || (await call('conversations').then((l) => (convs = l).find((x) => x.id === d.cid)));
    cur = { id: d.call_id, cid: d.cid, peer: d.from, name: c?.peer?.display_name || 'تماس', video: !!d.video, outgoing: false, state: 'incoming', offer: d.sdp };
    callUi(); ring(true);
    call('call_mode', { active: true });
    cur.ringTimer = setTimeout(() => { if (cur?.state === 'incoming') endCall(null, null, 'تماس ازدست‌رفته از ' + cur.name); }, 50000);
    if (document.visibilityState !== 'visible') systemNotify('📞 تماس ورودی', { body: previewLevel() === 'sender' ? cur.name : 'برای پاسخ، برنامه را باز کنید', tag: 'call', requireInteraction: true, silent: false, vibrate: [400, 200, 400] });
    return;
  }
  if (!cur || d.call_id !== cur.id || d.from !== cur.peer) return; // signals of another call are ignored
  if (d.action === 'answer' && cur.outgoing && cur.state === 'ringing') {
    clearTimeout(cur.ringTimer);
    cur.state = 'connecting'; renderCall();
    try { await cur.pc.setRemoteDescription({ type: 'answer', sdp: d.sdp }); } catch { endCall('failed', 'error', 'پاسخ تماس نامعتبر بود.'); }
  } else if (d.action === 'reject') endCall(null, null, cur.name + ' تماس را رد کرد.');
  else if (d.action === 'busy') endCall('ended', 'busy', cur.name + ' در حال مکالمه دیگری است.');
  else if (d.action === 'cancel') endCall(null, null, 'تماس ازدست‌رفته از ' + cur.name);
  else if (d.action === 'hangup') endCall(null, null, cur.state === 'incoming' ? 'تماس ازدست‌رفته از ' + cur.name : 'تماس پایان یافت.');
}
async function acceptCall() {
  ring(false); clearTimeout(cur.ringTimer);
  cur.state = 'connecting'; renderCall();
  try {
    const cfg = await iceConfig();
    cur.stream = await media(cur.video);
    cur.ui.local.srcObject = cur.stream; cur.ui.root.classList.add('has-local');
    await initRoute();
    cur.cams = (await navigator.mediaDevices.enumerateDevices()).filter((x) => x.kind === 'videoinput').length;
    cur.pc = makePc(cfg);
    cur.stream.getTracks().forEach((t) => cur.pc.addTrack(t, cur.stream));
    await cur.pc.setRemoteDescription({ type: 'offer', sdp: cur.offer });
    await cur.pc.setLocalDescription(await cur.pc.createAnswer());
    await gathered(cur.pc);
    if (!cur) return;
    await call('call_status', { id: cur.id, status: 'active' });
    await signal('answer', { sdp: cur.pc.localDescription.sdp });
    renderCall();
  } catch (e) {
    signal('hangup');
    endCall('failed', 'error', e?.name === 'NotAllowedError' ? 'اجازه دسترسی به میکروفون/دوربین داده نشد.' : 'تماس برقرار نشد: ' + errText(e));
  }
}
function rejectCall() { signal('reject'); endCall('rejected', 'hangup', null); }
function hangup() { signal('hangup'); endCall('ended', 'hangup', null); }
async function onConnected() {
  cur.state = 'connected'; cur.started = Date.now();
  cur.tick = setInterval(renderCall, 1000);
  renderCall();
  try {
    const stats = await cur.pc.getStats();
    let pair = null; const cands = {};
    stats.forEach((r) => { if (r.type === 'candidate-pair' && r.state === 'succeeded' && (r.nominated || !pair)) pair = r; if (r.type === 'local-candidate') cands[r.id] = r; });
    const relay = pair && cands[pair.localCandidateId]?.candidateType === 'relay';
    cur.ui.info.textContent = '🔒 رمزنگاری DTLS-SRTP · ' + (relay ? 'از طریق سرور رله (TURN)' : 'ارتباط مستقیم');
  } catch { /* stats unavailable */ }
}
async function switchCamera() {
  const v = cur.stream.getVideoTracks()[0];
  const facing = (v.getSettings().facingMode === 'environment') ? 'user' : 'environment';
  try {
    const ns = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing } });
    const nt = ns.getVideoTracks()[0];
    await cur.pc.getSenders().find((x) => x.track?.kind === 'video')?.replaceTrack(nt);
    v.stop(); cur.stream.removeTrack(v); cur.stream.addTrack(nt); cur.ui.local.srcObject = cur.stream;
    renderCall();
  } catch { toast('تعویض دوربین ممکن نشد.'); }
}
function endCall(status, reason, message) {
  if (!cur) return;
  const c = cur; cur = null;
  c.ring?.close?.();
  clearTimeout(c.ringTimer); clearInterval(c.tick);
  if (status && c.id) call('call_status', { id: c.id, status, reason });
  c.stream?.getTracks().forEach((t) => t.stop());
  try { c.pc?.close(); } catch { /* ignore */ }
  c.ui?.root.remove();
  call('call_mode', { active: false });
  if (message) toast(message);
  if (tab === 'calls' && !subEl) renderSide();
}

// ------------------------------------------------------------------ boot
document.body.dataset.title = document.title;
// Session ended on the server (signed out elsewhere, account suspended…): back to the login page.
window.addEventListener('unhandledrejection', (e) => { if (['unauthenticated', 'http_401', 'session_expired'].includes(e.reason?.message)) { e.preventDefault(); location.reload(); } });
navigator.storage?.persist?.().catch(() => {});
document.addEventListener('visibilitychange', () => { const v = document.visibilityState === 'visible'; call('visibility', { visible: v }); if (v) { scheduleRead(); if (current) swPost({ type: 'read', c: current.id }); } });
window.addEventListener('online', () => { document.body.classList.remove('offline'); call('sync_now').catch(() => {}); });
window.addEventListener('offline', () => document.body.classList.add('offline'));
window.addEventListener('hashchange', () => { if (me) handleHash(); });
window.matchMedia('(max-width: 899px)').addEventListener?.('change', () => { if (!current) root.querySelector('.c-app')?.classList.remove('show-chat'); });
// Android back button / swipe: leave the open chat instead of the app.
window.addEventListener('popstate', () => { if (current) closeConversation(true, true); });
registerSw();
call('start').then((s) => { handleState(s); if (s.state === 'ready' && !s.needs_recovery) handleHash(); })
  .catch((e) => status('خطا در راه‌اندازی رمزنگاری: ' + e.message, el('button', { class: 'btn', text: 'بارگذاری دوباره', onclick: () => location.reload() })));
