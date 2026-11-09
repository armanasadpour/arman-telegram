// Spike + regression test of the vodozemac WASM bridge:  node tests/crypto/olm.test.mjs
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { initSync, Account, Session, SigningKey, verify_ed25519 } from '../../app/resources/assets/pm_crypto.js';

initSync({ module: readFileSync(new URL('../../app/resources/assets/pm_crypto_bg.wasm', import.meta.url)) });
let pass = 0;
const t = (name, fn) => { fn(); pass++; console.log('  ✔', name); };
const key = () => crypto.getRandomValues(new Uint8Array(32));

const alice = new Account(), bob = new Account(), eve = new Account();
bob.generate_one_time_keys(5);
const bobOtks = JSON.parse(bob.one_time_keys());
bob.mark_keys_as_published();
const [otkId, otk] = Object.entries(bobOtks)[0];

let aSess, bSess;
t('one-time keys generated and signed by the device key', () => {
  assert.equal(Object.keys(bobOtks).length, 5);
  const sig = bob.sign(`pm-otk-v1:${otkId}:${otk}`);
  assert.ok(verify_ed25519(bob.ed25519_key(), `pm-otk-v1:${otkId}:${otk}`, sig));
  assert.ok(!verify_ed25519(bob.ed25519_key(), `pm-otk-v1:${otkId}:X${otk.slice(1)}`, sig));
});

t('Alice → Bob: pre-key message establishes a session (3DH)', () => {
  aSess = alice.create_outbound_session(bob.curve25519_key(), otk);
  const m = aSess.encrypt('سلام باب 👋');
  assert.equal(m.message_type, 0);
  const r = bob.create_inbound_session(alice.curve25519_key(), m.ciphertext);
  assert.equal(r.plaintext, 'سلام باب 👋');
  bSess = r.take_session();
  assert.equal(aSess.session_id(), bSess.session_id());
});

t('one-time key is consumed (second pre-key with same OTK fails)', () => {
  const s2 = alice.create_outbound_session(bob.curve25519_key(), otk);
  const m = s2.encrypt('again');
  assert.throws(() => bob.create_inbound_session(alice.curve25519_key(), m.ciphertext));
});

t('forged sender: pre-key with an identity key other than the certified one is rejected', () => {
  bob.generate_one_time_keys(1);
  const [, k2] = Object.entries(JSON.parse(bob.one_time_keys()))[0];
  bob.mark_keys_as_published();
  const m = eve.create_outbound_session(bob.curve25519_key(), k2).encrypt('I am Alice');
  assert.throws(() => bob.create_inbound_session(alice.curve25519_key(), m.ciphertext), /ismatched|identity/i);
});

t('Bob → Alice reply turns into normal messages (ratchet advances)', () => {
  const r = bSess.encrypt('سلام آلیس');
  assert.equal(r.message_type, 1);
  assert.equal(aSess.decrypt(r.message_type, r.ciphertext), 'سلام آلیس');
  const m2 = aSess.encrypt('پیام دوم');
  assert.equal(m2.message_type, 1);
  assert.equal(bSess.decrypt(1, m2.ciphertext), 'پیام دوم');
});

t('replay of an already-decrypted message is rejected', () => {
  const m = aSess.encrypt('once');
  assert.equal(bSess.decrypt(1, m.ciphertext), 'once');
  assert.throws(() => bSess.decrypt(1, m.ciphertext));
});

t('tampered ciphertext is rejected (MAC)', () => {
  const m = aSess.encrypt('integrity');
  const c = m.ciphertext; c[c.length - 3] ^= 1;
  assert.throws(() => bSess.decrypt(1, c));
});

t('out-of-order delivery works', () => {
  const m1 = aSess.encrypt('1'), m2 = aSess.encrypt('2'), m3 = aSess.encrypt('3');
  assert.equal(bSess.decrypt(1, m3.ciphertext), '3');
  assert.equal(bSess.decrypt(1, m1.ciphertext), '1');
  assert.equal(bSess.decrypt(1, m2.ciphertext), '2');
});

t('ciphertext does not contain the plaintext', () => {
  const m = aSess.encrypt('CANARY-plaintext-XYZ');
  assert.ok(!Buffer.from(m.ciphertext).toString('latin1').includes('CANARY'));
});

t('pickles: encrypted save/restore of account and session; wrong key fails', () => {
  const k = key();
  const p = bob.pickle(k);
  assert.ok(!p.includes(bob.curve25519_key()));
  const bob2 = Account.from_pickle(p, k);
  assert.equal(bob2.curve25519_key(), bob.curve25519_key());
  assert.throws(() => Account.from_pickle(p, key()));
  const sp = bSess.pickle(k);
  const b2 = Session.from_pickle(sp, k);
  const m = aSess.encrypt('after restore');
  assert.equal(b2.decrypt(1, m.ciphertext), 'after restore');
});

t('pre-key message matching an existing session is recognised', () => {
  const carol = new Account(); carol.generate_one_time_keys(1);
  const [, ck] = Object.entries(JSON.parse(carol.one_time_keys()))[0];
  const s = alice.create_outbound_session(carol.curve25519_key(), ck);
  const m1 = s.encrypt('a'), m2 = s.encrypt('b'); // both pre-key until a reply arrives
  const r = carol.create_inbound_session(alice.curve25519_key(), m1.ciphertext);
  const cs = r.take_session();
  assert.equal(m2.message_type, 0);
  assert.ok(cs.matches_pre_key_message(m2.ciphertext));
  assert.equal(cs.decrypt(0, m2.ciphertext), 'b');
});

t('master signing key: certificate signature verifies in JS and in PHP sodium', () => {
  const msk = new SigningKey();
  const cert = JSON.stringify({ v: 1, user: 'U1', device: 'D1', ik: alice.curve25519_key(), sk: alice.ed25519_key() });
  const sig = msk.sign(cert);
  assert.ok(verify_ed25519(msk.public_key(), cert, sig));
  const restored = SigningKey.from_bytes(msk.to_bytes());
  assert.equal(restored.public_key(), msk.public_key());
  const php = `$pk = base64_decode($argv[1]); $sig = base64_decode($argv[2]); echo sodium_crypto_sign_verify_detached($sig, $argv[3], $pk) ? 'OK' : 'BAD';`;
  assert.equal(execFileSync('php', ['-r', php, msk.public_key(), sig, cert]).toString(), 'OK');
  assert.equal(execFileSync('php', ['-r', php, msk.public_key(), sig, cert + ' ']).toString(), 'BAD');
});

console.log(`\n${pass} passed`);
