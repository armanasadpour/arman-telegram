// End-to-end encryption engine (module Web Worker).
// All keys and all plaintext live only here, on the user's device. The server receives public keys and
// Olm ciphertext only. Cryptography is vodozemac (Olm v1 / Double Ratchet, Ed25519) + Web Crypto (AES-GCM, SHA-512).
import init, { Account, Session, SigningKey, verify_ed25519 } from './pm_crypto.js';

const API = new URL('../api/v1', self.location.href).pathname;
const OTK_TARGET = 50, OTK_MIN = 20, MAX_TEXT = 10000;
const enc = new TextEncoder(), dec = new TextDecoder();

let db, lsk, pk, account, msk, me, deviceId, sessionPublicId, cursor = 0, ncursor = 0;
let visible = true, syncing = false, stopped = false, timer = null, lockRelease = null;
const sessions = new Map();          // remote device id -> [Session] (newest first)
const pendingStatus = new Set();     // my message ids awaiting "delivered"

// ------------------------------------------------------------------ helpers
const b64 = (u8) => { let s = ''; for (let i = 0; i < u8.length; i += 8192) s += String.fromCharCode.apply(null, u8.subarray(i, i + 8192)); return btoa(s).replace(/=+$/, ''); };
const b64dec = (s) => Uint8Array.from(atob(s.padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0));
const b64url = (u8) => b64(u8).replace(/\+/g, '-').replace(/\//g, '_');
const concat = (...a) => { const o = new Uint8Array(a.reduce((n, x) => n + x.length, 0)); let i = 0; for (const x of a) { o.set(x, i); i += x.length; } return o; };
const emit = (event, data) => self.postMessage({ event, data });

async function api(path, { method = 'GET', body } = {}) {
  const r = await fetch(API + path, {
    method, credentials: 'same-origin', cache: 'no-store',
    headers: body ? { 'Content-Type': 'application/json', 'X-PM-Request': '1' } : { 'X-PM-Request': '1' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    const e = new Error(j?.error?.code || `http_${r.status}`);
    e.status = r.status; e.data = j?.error || {};
    throw e;
  }
  return j;
}

// ------------------------------------------------------------------ local encrypted storage (IndexedDB)
function openDb(name) {
  return new Promise((res, rej) => {
    const r = indexedDB.open(name, 1);
    r.onupgradeneeded = () => {
      const d = r.result;
      d.createObjectStore('kv');
      d.createObjectStore('sessions');
      d.createObjectStore('contacts');
      d.createObjectStore('messages', { keyPath: 'mid' }).createIndex('conv', ['cid', 'ts']);
    };
    r.onsuccess = () => res(r.result);
    r.onerror = () => rej(r.error);
  });
}
const req = (r) => new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
const get = (store, key) => req(db.transaction(store).objectStore(store).get(key));
/** Atomic multi-store write: ops = [[store, value, key?], ...]; value === undefined deletes. */
function write(ops) {
  return new Promise((res, rej) => {
    const t = db.transaction([...new Set(ops.map((o) => o[0]))], 'readwrite');
    for (const [store, value, key] of ops) {
      const s = t.objectStore(store);
      if (value === undefined) s.delete(key); else if (key === undefined) s.put(value); else s.put(value, key);
    }
    t.oncomplete = () => res(); t.onerror = () => rej(t.error); t.onabort = () => rej(t.error);
  });
}
async function seal(obj) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  return { iv, ct: new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, lsk, enc.encode(JSON.stringify(obj)))) };
}
async function unseal(box) {
  return JSON.parse(dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: box.iv }, lsk, box.ct)));
}

async function loadLocalKeys() {
  lsk = await get('kv', 'lsk');
  if (!lsk) {
    // Non-extractable: even code running on this page cannot export it, only use it.
    lsk = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    await write([['kv', lsk, 'lsk']]);
  }
  const wrapped = await get('kv', 'pk');
  if (wrapped) {
    pk = Uint8Array.from(await unseal(wrapped));
  } else {
    pk = crypto.getRandomValues(new Uint8Array(32));
    await write([['kv', await seal([...pk]), 'pk']]);
  }
}

// ------------------------------------------------------------------ identity and device
function signedPrekeys(json, prefix) {
  return Object.entries(JSON.parse(json)).map(([id, key]) => ({ id, key, sig: account.sign(`${prefix}:${id}:${key}`) }));
}
const certificate = (user, ik, sk) => JSON.stringify({ v: 1, type: 'pm-device-cert', user, ik, sk, protocols: ['olm-v1'] });

async function saveAccount(extraOps = []) {
  await write([['kv', account.pickle(pk), 'account'], ...extraOps]);
}

async function createIdentity(reset) {
  account = new Account();
  msk = new SigningKey();
  account.generate_one_time_keys(OTK_TARGET);
  account.generate_fallback_key();
  const ik = account.curve25519_key(), sk = account.ed25519_key();
  const r = await api('/identity', { method: 'POST', body: {
    reset, msk: msk.public_key(),
    device: { identity_key: ik, signing_key: sk, certificate_sig: msk.sign(certificate(me.id, ik, sk)),
      one_time_keys: signedPrekeys(account.one_time_keys(), 'pm-otk-v1'), fallback_key: signedPrekeys(account.fallback_key(), 'pm-fbk-v1')[0] } } });
  account.mark_keys_as_published();
  deviceId = r.device_id;
  sessions.clear();
  const old = await req(db.transaction('sessions').objectStore('sessions').getAllKeys());
  await saveAccount([['kv', await seal([...msk.to_bytes()]), 'msk'], ['kv', deviceId, 'device_id'], ['kv', me.id, 'user'],
    ...old.map((k) => ['sessions', undefined, k])]);
}

async function bindDevice() {
  const ch = await api('/device/challenge');
  await api('/device/bind', { method: 'POST', body: { device_id: deviceId, signature: account.sign(`pm-bind-v1:${ch.nonce}:${ch.session}`) } });
}

async function replenish(count) {
  if (count >= OTK_MIN) return;
  account.generate_one_time_keys(OTK_TARGET - count);
  await api('/keys/upload', { method: 'POST', body: { one_time_keys: signedPrekeys(account.one_time_keys(), 'pm-otk-v1') } });
  account.mark_keys_as_published();
  await saveAccount();
}

// ------------------------------------------------------------------ contacts (key pinning)
/**
 * Fetches a user's public keys and keeps only devices whose certificate verifies against the user's
 * master key. The first master key seen is pinned; a different one later is held as "pending" and
 * blocks sending until the user explicitly accepts it.
 */
async function contact(userId, refresh = false) {
  let c = await get('contacts', userId);
  if (c && !refresh && Date.now() - c.fetched < 300000) return c;
  const k = await api(`/users/${encodeURIComponent(userId)}/keys`);
  if (!k.msk) { if (c) return c; throw new Error('no_identity'); }
  const devices = {};
  for (const d of k.devices) {
    const cert = certificate(userId, d.identity_key, d.signing_key);
    if (d.certificate === cert && verify_ed25519(k.msk, cert, d.certificate_sig)) devices[d.id] = { ik: d.identity_key, sk: d.signing_key };
  }
  if (userId === me.id) {
    c = { user_id: userId, msk: msk.public_key(), verified: true, devices: k.msk === msk.public_key() ? devices : {}, fetched: Date.now() };
  } else if (!c) {
    c = { user_id: userId, msk: k.msk, verified: false, devices, pending_msk: null, pending_devices: null, fetched: Date.now() };
  } else if (c.msk === k.msk) {
    c = { ...c, devices, pending_msk: null, pending_devices: null, fetched: Date.now() };
  } else {
    const isNew = c.pending_msk !== k.msk;
    c = { ...c, pending_msk: k.msk, pending_devices: devices, fetched: Date.now() };
    if (isNew) emit('key_changed', { user_id: userId });
  }
  await write([['contacts', c, userId]]);
  return c;
}

async function acceptKey(userId) {
  const c = await get('contacts', userId);
  if (!c?.pending_msk) return;
  for (const d of Object.keys(c.devices || {})) sessions.delete(d);
  const n = { ...c, msk: c.pending_msk, devices: c.pending_devices || {}, verified: false, pending_msk: null, pending_devices: null };
  await write([['contacts', n, userId], ...Object.keys(c.devices || {}).map((d) => ['sessions', undefined, d])]);
}

// ------------------------------------------------------------------ sessions
async function sessionsFor(dev) {
  if (!sessions.has(dev)) {
    const p = await get('sessions', dev);
    sessions.set(dev, (p || []).map((x) => Session.from_pickle(x, pk)));
  }
  return sessions.get(dev);
}
const sessionOps = (devs) => devs.map((d) => ['sessions', (sessions.get(d) || []).slice(0, 5).map((s) => s.pickle(pk)), d]);

// ------------------------------------------------------------------ messages
const metaOf = (m) => ({ mid: m.mid, cid: m.cid, ts: m.ts, dir: m.dir, status: m.status });
async function putMessage(m, extraOps = []) {
  await write([['messages', { ...metaOf(m), box: await seal({ from: m.from, text: m.text, flag: m.flag || null }) }], ...extraOps]);
}
async function readMessage(row) {
  const c = await unseal(row.box);
  return { ...metaOf(row), from: c.from, text: c.text, flag: c.flag };
}
async function messages(cid, limit = 200) {
  const range = IDBKeyRange.bound([cid, -Infinity], [cid, Infinity]);
  const rows = [];
  await new Promise((res, rej) => {
    const r = db.transaction('messages').objectStore('messages').index('conv').openCursor(range, 'prev');
    r.onsuccess = () => { const c = r.result; if (c && rows.length < limit) { rows.push(c.value); c.continue(); } else res(); };
    r.onerror = () => rej(r.error);
  });
  return Promise.all(rows.reverse().map(readMessage));
}

async function targetsFor(peerId, refresh) {
  const peer = await contact(peerId, refresh);
  if (peer.pending_msk) throw new Error('key_changed');
  const own = await contact(me.id, refresh);
  const t = { ...peer.devices };
  for (const [d, v] of Object.entries(own.devices)) if (d !== deviceId) t[d] = v;
  if (!Object.keys(peer.devices).length) throw new Error('peer_has_no_devices');
  return t;
}

async function deliver(cid, peerId, mid, payload, attempt = 0) {
  const targets = await targetsFor(peerId, attempt > 0);
  const need = [];
  for (const d of Object.keys(targets)) if (!(await sessionsFor(d)).length) need.push(d);
  if (need.length) {
    const { keys } = await api('/keys/claim', { method: 'POST', body: { device_ids: need } });
    for (const d of need) {
      const k = keys[d];
      if (!k) continue;
      const prefix = k.type === 'fallback' ? 'pm-fbk-v1' : 'pm-otk-v1';
      if (!verify_ed25519(targets[d].sk, `${prefix}:${k.id}:${k.key}`, k.sig)) throw new Error('bad_prekey_signature');
      sessions.set(d, [account.create_outbound_session(targets[d].ik, k.key)]);
    }
  }
  const envelopes = [];
  for (const d of Object.keys(targets)) {
    const s = (await sessionsFor(d))[0];
    if (!s) continue;
    const e = s.encrypt(payload);
    envelopes.push({ device_id: d, type: e.message_type, ciphertext: b64(e.ciphertext) });
  }
  await write(sessionOps(Object.keys(targets)));
  try {
    await api('/messages', { method: 'POST', body: { conversation_id: cid, client_msg_id: mid, protocol_version: 1, envelopes } });
  } catch (e) {
    if (e.message === 'devices_mismatch' && attempt < 2) {
      for (const d of e.data.stale || []) sessions.delete(d);
      return deliver(cid, peerId, mid, payload, attempt + 1);
    }
    throw e;
  }
}

async function send(cid, peerId, text, mid = null) {
  text = String(text).slice(0, MAX_TEXT);
  if (!text.trim()) return;
  mid ??= b64url(crypto.getRandomValues(new Uint8Array(16)));
  const ts = Date.now();
  const payload = JSON.stringify({ v: 1, t: 'text', cid, mid, su: me.id, sd: deviceId, ts, body: { text } });
  const m = { mid, cid, ts, dir: 'out', status: 'sending', from: me.id, text };
  await putMessage(m);
  emit('message', m);
  try {
    await deliver(cid, peerId, mid, payload);
    m.status = 'sent';
    pendingStatus.add(mid);
  } catch (e) {
    m.status = 'failed'; m.flag = e.message;
  }
  await putMessage(m);
  emit('message', m);
}

async function retry(mid, peerId) {
  const row = await get('messages', mid);
  if (!row || row.status !== 'failed') return;
  const m = await readMessage(row);
  await write([['messages', undefined, mid]]);
  emit('removed', { mid });
  await send(m.cid, peerId, m.text);
}

/** Decrypts one envelope; stores message + updated ratchet state atomically. */
async function receive(env) {
  if (await get('messages', env.message_id)) return; // duplicate delivery
  const base = { mid: env.message_id, cid: env.conversation_id, ts: Date.parse(env.created_at.replace(' ', 'T') + 'Z') || Date.now(), dir: 'in', status: 'received', from: env.sender_user_id };
  const fail = async (flag) => { const m = { ...base, text: '', flag }; await putMessage(m); emit('message', m); };
  let c, dev, flag = null;
  try {
    c = await contact(env.sender_user_id);
    dev = c.devices?.[env.sender_device_id];
    if (!dev) {
      c = await contact(env.sender_user_id, true);
      dev = c.devices?.[env.sender_device_id];
      if (!dev && c.pending_devices?.[env.sender_device_id]) { dev = c.pending_devices[env.sender_device_id]; flag = 'new_key'; }
    }
  } catch { /* fall through */ }
  if (!dev) return fail('untrusted_device');
  const ct = b64dec(env.ciphertext);
  const list = await sessionsFor(env.sender_device_id);
  let plaintext = null, accountChanged = false;
  try {
    if (env.type === 0) {
      const s = list.find((x) => x.matches_pre_key_message(ct));
      if (s) plaintext = s.decrypt(0, ct);
      else {
        const r = account.create_inbound_session(dev.ik, ct); // fails unless sent by the certified identity key
        plaintext = r.plaintext;
        list.unshift(r.take_session());
        accountChanged = true;
      }
    } else {
      for (let i = 0; i < list.length && plaintext === null; i++) {
        try { plaintext = list[i].decrypt(1, ct); if (i) list.unshift(...list.splice(i, 1)); } catch { /* try next session */ }
      }
    }
  } catch { /* undecryptable */ }
  if (plaintext === null) return fail('decrypt_failed');
  let p;
  try { p = JSON.parse(plaintext); } catch { return fail('invalid_payload'); }
  // The server cannot move a message to another conversation or impersonate a sender: all bound inside the ciphertext.
  if (p.v !== 1 || p.t !== 'text' || p.cid !== env.conversation_id || p.su !== env.sender_user_id || p.sd !== env.sender_device_id
      || p.mid !== env.message_id || typeof p.body?.text !== 'string' || p.body.text.length > MAX_TEXT) return fail('invalid_payload');
  sessions.set(env.sender_device_id, list);
  const m = { ...base, text: p.body.text, flag };
  const ops = sessionOps([env.sender_device_id]);
  if (accountChanged) ops.push(['kv', account.pickle(pk), 'account']);
  await putMessage(m, ops);
  emit('message', m);
}

// ------------------------------------------------------------------ sync loop
async function syncOnce() {
  if (syncing || stopped) return;
  syncing = true;
  try {
    const r = await api(`/sync?cursor=${cursor}&ncursor=${ncursor}`);
    const acks = [];
    for (const env of r.envelopes) { await receive(env); acks.push(env.id); }
    if (acks.length) await api('/sync/ack', { method: 'POST', body: { ids: acks } });
    for (const n of r.notifications) {
      if ((n.type === 'contact_identity_changed' || n.type === 'contact_device_added') && n.subject_user_id) {
        try { await contact(n.subject_user_id, true); } catch { /* user may be gone */ }
      }
      if (n.type === 'admin_reset') emit('state', { state: 'revoked' });
    }
    cursor = r.cursor; ncursor = r.ncursor;
    await write([['kv', cursor, 'cursor'], ['kv', ncursor, 'ncursor']]);
    if (pendingStatus.size) {
      const { status } = await api('/messages/status', { method: 'POST', body: { ids: [...pendingStatus].slice(0, 100) } });
      for (const [mid, st] of Object.entries(status)) {
        if (st !== 'delivered') continue;
        pendingStatus.delete(mid);
        const row = await get('messages', mid);
        if (row) { row.status = 'delivered'; await write([['messages', row]]); emit('status', { mid, status: 'delivered' }); }
      }
    }
    await replenish(r.otk_count);
    if (acks.length) emit('conversations_changed', {});
  } catch (e) {
    if (e.status === 401) { emit('state', { state: 'logged_out' }); stopped = true; }
    else if (e.status === 410) { emit('state', { state: 'revoked' }); stopped = true; }
  } finally {
    syncing = false;
  }
}
function schedule() {
  clearTimeout(timer);
  if (stopped) return;
  timer = setTimeout(async () => { await syncOnce(); schedule(); }, visible ? 2500 : 15000);
}

// ------------------------------------------------------------------ safety number (Signal numeric fingerprint)
async function fingerprintPart(keyB64, id) {
  const key = b64dec(keyB64);
  let h = concat(new Uint8Array([0, 0]), key, enc.encode(id));
  for (let i = 0; i < 5200; i++) h = new Uint8Array(await crypto.subtle.digest('SHA-512', concat(h, key)));
  let s = '';
  for (let i = 0; i < 30; i += 5) s += String((h[i] * 2 ** 32 + h[i + 1] * 2 ** 24 + h[i + 2] * 2 ** 16 + h[i + 3] * 2 ** 8 + h[i + 4]) % 100000).padStart(5, '0');
  return s;
}
async function safety(userId) {
  const c = await contact(userId);
  const parts = [await fingerprintPart(msk.public_key(), me.id), await fingerprintPart(c.msk, userId)].sort();
  return { number: (parts[0] + parts[1]).match(/.{5}/g), verified: !!c.verified, pending: !!c.pending_msk };
}

// ------------------------------------------------------------------ startup
async function start() {
  if (!self.indexedDB || !self.crypto?.subtle || !self.WebAssembly || !navigator.locks) return { state: 'unsupported' };
  await init();
  const boot = await api('/bootstrap');
  me = boot.me; sessionPublicId = boot.session;
  // One tab at a time: two tabs advancing the same ratchet would corrupt it.
  const got = await new Promise((resolve) => {
    navigator.locks.request(`pm-e2ee-${me.id}`, { ifAvailable: true }, (lock) => {
      if (!lock) { resolve(false); return undefined; }
      resolve(true);
      return new Promise((r) => { lockRelease = r; });
    });
  });
  if (!got) return { state: 'locked' };
  db = await openDb(`pm-e2ee-${me.id}`);
  await loadLocalKeys();
  const saved = await get('kv', 'account');
  if (saved) {
    account = Account.from_pickle(saved, pk);
    msk = SigningKey.from_bytes(Uint8Array.from(await unseal(await get('kv', 'msk'))));
    deviceId = await get('kv', 'device_id');
    cursor = (await get('kv', 'cursor')) || 0;
    ncursor = (await get('kv', 'ncursor')) || 0;
    if (boot.identity.msk !== msk.public_key()) return { state: 'revoked', me };
    if (boot.device?.id !== deviceId) {
      try { await bindDevice(); } catch { return { state: 'revoked', me }; }
    }
  } else if (boot.identity.msk) {
    return { state: 'identity_elsewhere', me };
  } else {
    await createIdentity(false);
  }
  return ready(boot.name);
}

async function ready(name) {
  const st = await api('/bootstrap');
  await replenish(st.device?.otk_count ?? OTK_TARGET);
  await new Promise((res, rej) => {
    const r = db.transaction('messages').objectStore('messages').openCursor();
    r.onsuccess = () => { const c = r.result; if (!c) return res(); if (c.value.dir === 'out' && c.value.status === 'sent') pendingStatus.add(c.value.mid); c.continue(); };
    r.onerror = () => rej(r.error);
  });
  stopped = false;
  syncOnce().then(schedule);
  return { state: 'ready', me, device_id: deviceId, name };
}

const commands = {
  start,
  async reset_identity() {
    if (!db) { db = await openDb(`pm-e2ee-${me.id}`); await loadLocalKeys(); }
    await createIdentity(true);
    cursor = 0; ncursor = 0;
    return ready('');
  },
  async conversations() {
    const { conversations } = await api('/conversations');
    for (const c of conversations) {
      const last = (await messages(c.id, 1))[0];
      c.last = last ? { text: last.text, flag: last.flag, ts: last.ts, dir: last.dir } : null;
    }
    return conversations;
  },
  async directory() { return (await api('/directory')).users; },
  async open_direct({ user_id }) { return (await api('/conversations', { method: 'POST', body: { user_id } })).id; },
  messages: ({ cid }) => messages(cid),
  send: ({ cid, peer, text }) => send(cid, peer, text),
  retry: ({ mid, peer }) => retry(mid, peer),
  async contact_state({ user_id }) { const c = await contact(user_id); return { verified: !!c.verified, pending: !!c.pending_msk }; },
  safety: ({ user_id }) => safety(user_id),
  async verify({ user_id }) { const c = await get('contacts', user_id); if (c && !c.pending_msk) await write([['contacts', { ...c, verified: true }, user_id]]); },
  async accept_key({ user_id }) { await acceptKey(user_id); },
  visibility({ visible: v }) { visible = v; if (v) { syncOnce(); schedule(); } },
  sync_now() { return syncOnce(); },
};

self.onmessage = async ({ data }) => {
  const { id, cmd, args } = data;
  try {
    self.postMessage({ id, ok: true, result: await commands[cmd](args || {}) });
  } catch (e) {
    self.postMessage({ id, ok: false, error: e?.message || String(e) });
  }
};
