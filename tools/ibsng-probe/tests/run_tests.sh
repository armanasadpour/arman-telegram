#!/usr/bin/env bash
# Runs codec unit tests and end-to-end probe runs against the local mock server.
set -u
cd "$(dirname "$0")"
PORT=18235
TMP=$(mktemp -d)
trap 'kill $MOCK 2>/dev/null; rm -rf "$TMP"' EXIT
fail=0

echo "== lint"
for f in ../index.php ../src/*.php ../config.sample.php CodecTest.php; do php -l "$f" >/dev/null || fail=1; done

echo "== codec unit tests"
php CodecTest.php || fail=1

python3 mock_ibsng_server.py $PORT >/dev/null 2>&1 &
MOCK=$!
for i in $(seq 1 50); do (echo > /dev/tcp/127.0.0.1/$PORT) 2>/dev/null && break; sleep 0.1; done

mkcfg() { # $1=file $2=password $3=port $4=allow_write
cat > "$1" <<EOF
<?php return ['host'=>'127.0.0.1','port'=>$3,'admin_username'=>'system','admin_password'=>'$2',
'access_token'=>'x','timeout'=>5,'connect_timeout'=>2,'sample_username'=>'',
'allow_write_test'=>$4,'write_test_group'=>'test-group'];
EOF
}

expect() { # $1=name $2=expected-exit $3...=cmd
  local name=$1 want=$2; shift 2
  "$@" > "$TMP/out" 2>&1; local got=$?
  if [ "$got" = "$want" ]; then echo "ok   $name"; else echo "FAIL $name (exit $got, want $want)"; cat "$TMP/out"; fail=1; fi
}

echo "== end-to-end against mock"
mkcfg "$TMP/ok.php" secret-pass $PORT true
expect "read-only probe passes" 0 php ../index.php --config="$TMP/ok.php"
expect "write test passes and cleans up" 0 php ../index.php --config="$TMP/ok.php" --write
php ../index.php --config="$TMP/ok.php" --write --json > "$TMP/r.json"
grep -q '"write.verify_delete"' "$TMP/r.json" && echo "ok   cleanup verified in report" || { echo "FAIL cleanup not verified"; fail=1; }
grep -q 'secret-pass' "$TMP/r.json" && { echo "FAIL admin password leaked into report"; fail=1; } || echo "ok   password not in report"
grep -q '"normal_password": "\*\*\*"' "$TMP/r.json" && echo "ok   user password redacted" || { echo "FAIL user password not redacted"; fail=1; }

mkcfg "$TMP/badpass.php" wrong $PORT false
expect "wrong password -> fail" 1 php ../index.php --config="$TMP/badpass.php"
grep -q "Invalid Username or Password" "$TMP/out" && echo "ok   fault text surfaced" || { echo "FAIL fault text missing"; fail=1; }

mkcfg "$TMP/closed.php" secret-pass 1 false
expect "closed port -> fail fast" 1 php ../index.php --config="$TMP/closed.php"

mkcfg "$TMP/nowrite.php" secret-pass $PORT false
php ../index.php --config="$TMP/nowrite.php" --write > "$TMP/out"
grep -q "write.disabled" "$TMP/out" && echo "ok   write blocked when allow_write_test=false" || { echo "FAIL write not blocked"; fail=1; }

echo
[ $fail = 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"
exit $fail
