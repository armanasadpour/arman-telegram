//! Thin WebAssembly bridge to vodozemac for the Private Messenger web client.
//!
//! No cryptography is implemented here: every primitive and protocol step is vodozemac's
//! (Olm v1 = 3DH + Double Ratchet, Ed25519). This file only converts types for JavaScript.

use vodozemac::olm::{
    Account as VAccount, AccountPickle, OlmMessage, PreKeyMessage, Session as VSession, SessionConfig,
    SessionPickle,
};
use vodozemac::{Curve25519PublicKey, Ed25519PublicKey, Ed25519SecretKey, Ed25519Signature};
use wasm_bindgen::prelude::*;

fn js<E: std::fmt::Display>(e: E) -> JsError {
    JsError::new(&e.to_string())
}

fn pickle_key(k: &[u8]) -> Result<[u8; 32], JsError> {
    k.try_into().map_err(|_| JsError::new("pickle key must be 32 bytes"))
}

fn olm_message(message_type: usize, ciphertext: &[u8]) -> Result<OlmMessage, JsError> {
    OlmMessage::from_parts(message_type, ciphertext).map_err(js)
}

/// Olm protocol version. v1 is the stable, audited, production version (v2 is experimental upstream).
fn config() -> SessionConfig {
    SessionConfig::version_1()
}

/// A device's long-term Olm account (Curve25519 identity key, Ed25519 signing key, one-time keys).
#[wasm_bindgen]
pub struct Account {
    inner: VAccount,
}

#[wasm_bindgen]
impl Account {
    #[wasm_bindgen(constructor)]
    pub fn new() -> Account {
        Account { inner: VAccount::new() }
    }

    /// Restores an account from a pickle encrypted with a 32-byte key.
    pub fn from_pickle(pickle: &str, key: &[u8]) -> Result<Account, JsError> {
        let p = AccountPickle::from_encrypted(pickle, &pickle_key(key)?).map_err(js)?;
        Ok(Account { inner: VAccount::from_pickle(p) })
    }

    pub fn pickle(&self, key: &[u8]) -> Result<String, JsError> {
        Ok(self.inner.pickle().encrypt(&pickle_key(key)?))
    }

    pub fn curve25519_key(&self) -> String {
        self.inner.curve25519_key().to_base64()
    }

    pub fn ed25519_key(&self) -> String {
        self.inner.ed25519_key().to_base64()
    }

    /// Ed25519 signature (base64) over the UTF-8 bytes of `message`.
    pub fn sign(&self, message: &str) -> String {
        self.inner.sign(message).to_base64()
    }

    pub fn max_number_of_one_time_keys(&self) -> usize {
        self.inner.max_number_of_one_time_keys()
    }

    pub fn generate_one_time_keys(&mut self, count: usize) {
        self.inner.generate_one_time_keys(count);
    }

    /// Unpublished one-time keys as JSON: {"<key id>": "<base64 public key>", ...}
    pub fn one_time_keys(&self) -> String {
        let m: std::collections::BTreeMap<String, String> =
            self.inner.one_time_keys().into_iter().map(|(id, k)| (id.to_base64(), k.to_base64())).collect();
        serde_json::to_string(&m).unwrap_or_else(|_| "{}".into())
    }

    pub fn generate_fallback_key(&mut self) {
        self.inner.generate_fallback_key();
    }

    pub fn fallback_key(&self) -> String {
        let m: std::collections::BTreeMap<String, String> =
            self.inner.fallback_key().into_iter().map(|(id, k)| (id.to_base64(), k.to_base64())).collect();
        serde_json::to_string(&m).unwrap_or_else(|_| "{}".into())
    }

    pub fn mark_keys_as_published(&mut self) {
        self.inner.mark_keys_as_published();
    }

    pub fn create_outbound_session(&self, identity_key: &str, one_time_key: &str) -> Result<Session, JsError> {
        let ik = Curve25519PublicKey::from_base64(identity_key).map_err(js)?;
        let otk = Curve25519PublicKey::from_base64(one_time_key).map_err(js)?;
        let s = self.inner.create_outbound_session(config(), ik, otk).map_err(js)?;
        Ok(Session { inner: s })
    }

    /// Creates a session from a pre-key message. Fails if the message's identity key is not
    /// `identity_key` (the sender device's certified key) — protects against forged senders.
    pub fn create_inbound_session(&mut self, identity_key: &str, ciphertext: &[u8]) -> Result<InboundResult, JsError> {
        let ik = Curve25519PublicKey::from_base64(identity_key).map_err(js)?;
        let msg = PreKeyMessage::from_bytes(ciphertext).map_err(js)?;
        let r = self.inner.create_inbound_session(config(), ik, &msg).map_err(js)?;
        let plaintext = String::from_utf8(r.plaintext).map_err(js)?;
        Ok(InboundResult { session: Some(Session { inner: r.session }), plaintext })
    }
}

impl Default for Account {
    fn default() -> Self {
        Self::new()
    }
}

#[wasm_bindgen]
pub struct InboundResult {
    session: Option<Session>,
    plaintext: String,
}

#[wasm_bindgen]
impl InboundResult {
    #[wasm_bindgen(getter)]
    pub fn plaintext(&self) -> String {
        self.plaintext.clone()
    }

    /// Moves the session out (can be called once).
    pub fn take_session(&mut self) -> Result<Session, JsError> {
        self.session.take().ok_or_else(|| JsError::new("session already taken"))
    }
}

/// One Olm (Double Ratchet) session with one remote device.
#[wasm_bindgen]
pub struct Session {
    inner: VSession,
}

#[wasm_bindgen]
pub struct Encrypted {
    message_type: usize,
    ciphertext: Vec<u8>,
}

#[wasm_bindgen]
impl Encrypted {
    /// 0 = pre-key message, 1 = normal message
    #[wasm_bindgen(getter)]
    pub fn message_type(&self) -> usize {
        self.message_type
    }

    #[wasm_bindgen(getter)]
    pub fn ciphertext(&self) -> Vec<u8> {
        self.ciphertext.clone()
    }
}

#[wasm_bindgen]
impl Session {
    pub fn from_pickle(pickle: &str, key: &[u8]) -> Result<Session, JsError> {
        let p = SessionPickle::from_encrypted(pickle, &pickle_key(key)?).map_err(js)?;
        Ok(Session { inner: VSession::from_pickle(p) })
    }

    pub fn pickle(&self, key: &[u8]) -> Result<String, JsError> {
        Ok(self.inner.pickle().encrypt(&pickle_key(key)?))
    }

    pub fn session_id(&self) -> String {
        self.inner.session_id()
    }

    pub fn encrypt(&mut self, plaintext: &str) -> Result<Encrypted, JsError> {
        let (message_type, ciphertext) = self.inner.encrypt(plaintext).map_err(js)?.to_parts();
        Ok(Encrypted { message_type, ciphertext })
    }

    pub fn decrypt(&mut self, message_type: usize, ciphertext: &[u8]) -> Result<String, JsError> {
        let bytes = self.inner.decrypt(&olm_message(message_type, ciphertext)?).map_err(js)?;
        String::from_utf8(bytes).map_err(js)
    }

    /// True when a pre-key message belongs to this (already established) session.
    pub fn matches_pre_key_message(&self, ciphertext: &[u8]) -> bool {
        PreKeyMessage::from_bytes(ciphertext).map(|m| m.session_id() == self.inner.session_id()).unwrap_or(false)
    }
}

/// A user's Master Signing Key (Ed25519). It certifies the user's devices.
#[wasm_bindgen]
pub struct SigningKey {
    inner: Ed25519SecretKey,
}

#[wasm_bindgen]
impl SigningKey {
    #[wasm_bindgen(constructor)]
    pub fn new() -> SigningKey {
        SigningKey { inner: Ed25519SecretKey::new() }
    }

    /// Secret key bytes (only ever stored encrypted on the device).
    pub fn to_bytes(&self) -> Vec<u8> {
        self.inner.to_bytes().to_vec()
    }

    pub fn from_bytes(bytes: &[u8]) -> Result<SigningKey, JsError> {
        let b: [u8; 32] = bytes.try_into().map_err(|_| JsError::new("signing key must be 32 bytes"))?;
        Ok(SigningKey { inner: Ed25519SecretKey::from_slice(&b) })
    }

    pub fn public_key(&self) -> String {
        self.inner.public_key().to_base64()
    }

    pub fn sign(&self, message: &str) -> String {
        self.inner.sign(message.as_bytes()).to_base64()
    }
}

impl Default for SigningKey {
    fn default() -> Self {
        Self::new()
    }
}

/// Verifies an Ed25519 signature (all values base64).
#[wasm_bindgen]
pub fn verify_ed25519(public_key: &str, message: &str, signature: &str) -> bool {
    let Ok(pk) = Ed25519PublicKey::from_base64(public_key) else { return false };
    let Ok(sig) = Ed25519Signature::from_base64(signature) else { return false };
    pk.verify(message.as_bytes(), &sig).is_ok()
}
