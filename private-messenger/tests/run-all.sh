#!/bin/bash
# Runs every test suite against fresh deployments (development machine only; see tests/Integration/test-env-up.sh).
set -uo pipefail
cd "$(dirname "$0")/.."
R=0; H=/srv/pmhost; V=${1:-dev}
tests/Integration/test-env-up.sh >/dev/null 2>&1
tools/build-release.sh "$V" >/dev/null 2>&1 || { echo "build failed"; exit 1; }
fresh() { rm -rf $H && mkdir -p $H/public_html && (cd $H && unzip -q "$OLDPWD/dist/private-messenger-$V.zip" && cp private-messenger/public/index.php private-messenger/public/.htaccess public_html/) && chown -R www-data: $H
          # Like many cPanel hosts: latin1 default charset, plus leftovers of a failed earlier attempt.
          mysql -e "DROP DATABASE IF EXISTS pm_e2e; CREATE DATABASE pm_e2e CHARACTER SET latin1; CREATE TABLE pm_e2e.users (id INT); CREATE TABLE pm_e2e.roles (id INT); CREATE TABLE pm_e2e.schema_migrations (name VARCHAR(10));"
          : > /var/log/apache2/pm-error.log; : > /var/log/apache2/pm-access.log
          curl -sk --noproxy '*' -o /dev/null https://pm.test/install/; }
echo "== unit + service";   mysql -e "DROP DATABASE IF EXISTS pm_unit; CREATE DATABASE pm_unit CHARACTER SET utf8mb4;"
TEST_DB_NAME=pm_unit TEST_DB_USER=pmtest TEST_DB_PASS=pmtest-pass php tests/run.php | tail -1 || R=1
echo "== crypto (wasm)";    node tests/crypto/olm.test.mjs | tail -1 || R=1
echo "== e2e (http)";       fresh; php tests/Integration/e2e.php https://pm.test $H/private-messenger pm_e2e pme2e E2e-db-pass | tail -1 || R=1
echo "== e2ee (browser)";   fresh; node tests/Integration/e2ee-browser.mjs https://pm.test $H/private-messenger pm_e2e pme2e E2e-db-pass | tail -1 || R=1
echo "== phase 4 (browser)"; fresh; node tests/Integration/phase4-browser.mjs https://pm.test $H/private-messenger pm_e2e pme2e E2e-db-pass | tail -1 || R=1
echo "== installer failure path"; fresh
mysql -e "DROP DATABASE IF EXISTS pm_fail; CREATE DATABASE pm_fail CHARACTER SET latin1; DROP USER IF EXISTS 'pmlimited'@'127.0.0.1'; CREATE USER 'pmlimited'@'127.0.0.1' IDENTIFIED BY 'Limited-pass1'; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP ON pm_fail.* TO 'pmlimited'@'127.0.0.1';"
php tests/Integration/install-failure.php | tail -1 || R=1
echo "== one-file installer"
rm -rf $H /tmp/pm-mock-crontab.txt && mkdir -p $H/public_html && printf '# php -- BEGIN cPanel-generated handler, do not edit\n# php -- END cPanel-generated handler, do not edit\n' > $H/public_html/.htaccess && cp dist/install.php $H/public_html/ && chown -R www-data: $H
mysql -N -e "SELECT CONCAT('DROP DATABASE \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name LIKE 'pmuser\_%'" | mysql
mysql -N -e "SELECT CONCAT('DROP USER \'',user,'\'@\'',host,'\';') FROM mysql.user WHERE user LIKE 'pmuser\_%'" | mysql
php tests/Integration/single-file-install.php https://pm.test $H/public_html $H/private-messenger dist/install.php | tail -1 || R=1
exit $R
