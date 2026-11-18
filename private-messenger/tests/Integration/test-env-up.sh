#!/bin/bash
# Brings up the local integration-test environment (development machine only):
# MariaDB, Apache+HTTPS vhost "pm.test" (see docs in tests), mock cPanel API on :2099.
grep -q " pm.test" /etc/hosts || echo "127.0.0.1 pm.test" >> /etc/hosts
grep -q " push.test" /etc/hosts || echo "127.0.0.1 push.test" >> /etc/hosts
if ! mysqladmin ping >/dev/null 2>&1; then
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
  (mysqld_safe --user=mysql >/dev/null 2>&1 &)
  for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
pgrep -x apache2 >/dev/null || apachectl start 2>&1 | grep -v AH00558
# After a container restart an apache2 process can be left that no longer listens: restart it.
if ! curl -sk --noproxy '*' -o /dev/null --max-time 3 https://pm.test/; then
  apachectl -k stop >/dev/null 2>&1; pkill -x apache2; rm -f /var/run/apache2/apache2.pid; sleep 1; apachectl start 2>&1 | grep -v AH00558
fi
# Local TURN/STUN server for the call tests (apt-get install coturn): secret «pm-turn-test».
if command -v turnserver >/dev/null && ! pgrep -x turnserver >/dev/null; then
  turnserver -n --listening-ip=127.0.0.1 --relay-ip=127.0.0.1 --listening-port=3478 --use-auth-secret --static-auth-secret=pm-turn-test \
    --realm=pm.test --allow-loopback-peers --no-tls --no-dtls --no-cli --log-file=/tmp/turnserver.log --pidfile=/tmp/turnserver.pid >/dev/null 2>&1 &
fi
pgrep -f "127.0.0.1:2099" >/dev/null || (php -S 127.0.0.1:2099 "$(dirname "$0")/mock-cpanel.php" > /tmp/mock-cpanel.log 2>&1 &)
sleep 1
mysqladmin ping && pgrep -x apache2 >/dev/null && echo "apache ok" && pgrep -f 127.0.0.1:2099 >/dev/null && echo "mock cpanel ok"
