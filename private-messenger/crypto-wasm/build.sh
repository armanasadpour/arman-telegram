#!/bin/bash
# Builds the vodozemac WebAssembly bridge into app/resources/assets (committed, so no build is needed on the server).
# Requires: rustup target wasm32-unknown-unknown, wasm-bindgen-cli 0.2.130.
set -euo pipefail
cd "$(dirname "$0")"
cargo build --release --target wasm32-unknown-unknown
OUT=../app/resources/assets
wasm-bindgen --target web --no-typescript --out-dir "$OUT" --out-name pm_crypto target/wasm32-unknown-unknown/release/pm_crypto.wasm
ls -l "$OUT"/pm_crypto*
sha256sum "$OUT"/pm_crypto_bg.wasm "$OUT"/pm_crypto.js
