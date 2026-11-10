<?php
// Installer failure path: missing DB privileges must show the real reason, leave nothing behind, and succeed after the fix.
require __DIR__ . '/e2e-lib.php';
$b = new Browser('https://pm.test');
$b->post('/install/', ['token' => trim(file_get_contents('/srv/pmhost/private-messenger/storage/install-token.txt'))]);
$f = ['db_mode' => 'manual', 'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'pm_fail', 'db_user' => 'pmlimited', 'db_pass' => 'Limited-pass1',
      'admin_username' => 'admin', 'admin_password' => 'Admin strong pass 1', 'admin_password2' => 'Admin strong pass 1'];
$r = $b->post('/install/', $f, '/install/');
$msg = trim(preg_replace("/\\s+/", " ", strip_tags($r["body"])));
check('failure shows the real database error (not «خطای داخلی»)', str_contains($r['body'], 'پیام دیتابیس') && !str_contains($r['body'], 'خطای داخلی'), $msg);
echo "    shown: ", mb_substr($msg, 0, 160), "\n";
check('nothing left behind: no tables, no .env, not installed', trim(shell_exec("mysql -N -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='pm_fail'\"")) === '0'
   && !is_file('/srv/pmhost/private-messenger/config/.env') && !is_file('/srv/pmhost/private-messenger/storage/installed.lock'));
shell_exec("mysql -e \"GRANT ALL PRIVILEGES ON pm_fail.* TO 'pmlimited'@'127.0.0.1'\"");
$r = $b->post('/install/', $f, '/install/');
check('after fixing permissions, the same form installs successfully', str_contains($r['body'], 'نصب کامل شد'), substr(strip_tags($r['body']), 0, 200));
finish();
