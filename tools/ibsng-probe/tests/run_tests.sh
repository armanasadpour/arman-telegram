#!/usr/bin/env bash
# Runs codec unit tests and end-to-end probe runs against local mock servers
# (IBSng XML-RPC and RouterOS REST over self-signed TLS).
set -u
cd "$(dirname "$0")"
PORT=18235
MTPORT=18443
TMP=$(mktemp -d)
MOCK=""; MTMOCK=""
trap 'kill $MOCK $MTMOCK 2>/dev/null; rm -rf "$TMP"' EXIT
fail=0

waitport() { for i in $(seq 1 50); do (echo > /dev/tcp/127.0.0.1/$1) 2>/dev/null && return 0; sleep 0.1; done; return 1; }

echo "== lint"
for f in ../index.php ../src/*.php ../config.sample.php CodecTest.php; do php -l "$f" >/dev/null || fail=1; done

echo "== codec unit tests"
php CodecTest.php || fail=1

python3 mock_ibsng_server.py $PORT >/dev/null 2>&1 &
MOCK=$!
waitport $PORT

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

echo "== IBSng end-to-end against mock"
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

echo "== MikroTik REST against mock (self-signed TLS)"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$TMP/k.pem" -out "$TMP/c.pem" -days 1 -subj "/CN=router" >/dev/null 2>&1
REALPIN="sha256//$(openssl x509 -in "$TMP/c.pem" -pubkey -noout | openssl pkey -pubin -outform der 2>/dev/null | openssl dgst -sha256 -binary | base64)"
python3 mock_routeros_rest.py $MTPORT "$TMP/c.pem" "$TMP/k.pem" >/dev/null 2>&1 &
MTMOCK=$!
waitport $MTPORT

mkmt() { # $1=file $2=pin $3=router password
cat > "$1" <<EOF
<?php return ['host'=>'127.0.0.1','port'=>$PORT,'admin_username'=>'system','admin_password'=>'secret-pass',
'access_token'=>'x','timeout'=>5,'connect_timeout'=>2,
'mikrotik'=>['enabled'=>true,'host'=>'127.0.0.1','port'=>$MTPORT,'username'=>'panel-ro','password'=>'$3','cert_pin'=>'$2']];
EOF
}

mkmt "$TMP/mt0.php" "" mt-secret-pass
php ../index.php --config="$TMP/mt0.php" --json > "$TMP/mt0.json"
grep -qF "\"cert_pin\": \"$REALPIN\"" "$TMP/mt0.json" && echo "ok   TOFU pin equals openssl-computed SPKI pin" || { echo "FAIL pin mismatch"; grep cert_pin "$TMP/mt0.json"; fail=1; }
grep -q '"mt.resource"' "$TMP/mt0.json" && { echo "FAIL credentials used without pin"; fail=1; } || echo "ok   no authenticated call before pin is set"

mkmt "$TMP/mt1.php" "$REALPIN" mt-secret-pass
php ../index.php --config="$TMP/mt1.php" --json > "$TMP/mt1.json"
miss=0
for id in mt.resource mt.license mt.services mt.radius mt.ppp_aaa mt.ovpn mt.l2tp mt.wg mt.wg_peers mt.ppp_active; do
  grep -q "\"$id\"" "$TMP/mt1.json" || { echo "FAIL missing $id"; miss=1; fail=1; }
done
[ $miss = 0 ] && echo "ok   all MikroTik checks executed"
python3 -I "$(pwd)/mt_assert.py" "$TMP/mt1.json" || fail=1
for s in mt-secret-pass radius-shared-secret l2tp-psk-secret cHJpdmF0ZS1rZXktc2hvdWxk cHNrLXNob3VsZC1uZXZlci1sZWFr; do
  grep -q "$s" "$TMP/mt1.json" && { echo "FAIL secret leaked: $s"; fail=1; }
done
echo "ok   router secrets and keys redacted"

mkmt "$TMP/mt2.php" "sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" mt-secret-pass
php ../index.php --config="$TMP/mt2.php" > "$TMP/out"
grep -q "does NOT match cert_pin" "$TMP/out" && echo "ok   wrong pin rejected" || { echo "FAIL wrong pin not rejected"; cat "$TMP/out"; fail=1; }
mkmt "$TMP/mt3.php" "$REALPIN" wrong
php ../index.php --config="$TMP/mt3.php" > "$TMP/out"
grep -q "401 Unauthorized" "$TMP/out" && echo "ok   wrong router password reported" || { echo "FAIL 401 not reported"; fail=1; }

echo
[ $fail = 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"
exit $fail
