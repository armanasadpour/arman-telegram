<?php
declare(strict_types=1);

namespace PM\Controllers;

use PM\Admin\AdminService;
use PM\Audit\AuditLog;
use PM\Auth\Csrf;
use PM\Auth\RateLimiter;
use PM\Http\Request;
use PM\Http\Response;
use PM\Infra\Database;
use PM\Infra\Migrator;
use PM\Install\CpanelApi;
use PM\Install\HostCheck;
use PM\Support\Clock;
use PM\Support\Env;
use PM\Support\Passwords;
use PM\Support\Random;
use PM\Support\UserError;

/**
 * Web installer, reachable at /install ONLY while the application is not installed.
 * Access requires the one-time token written to storage/install-token.txt, which proves
 * the person has file access to the hosting account (prevents a stranger from installing first).
 */
final class InstallController extends Controller
{
    private const COOKIE = '__Host-inst';

    public function handle(Request $req): Response
    {
        if (preg_match('#^/assets/(.+)$#', $req->path, $m)) {
            return $this->asset($req, $m[1]);
        }
        if ($req->path !== '/') {
            return Response::notFound();
        }
        $tokenFile = $this->app->root . '/storage/install-token.txt';
        if (!is_file($tokenFile)) {
            if (@file_put_contents($tokenFile, Random::string(32) . "\n") === false) {
                return Response::error('پوشه storage قابل نوشتن نیست. مجوز پوشه‌ها را بررسی کنید.');
            }
            @chmod($tokenFile, 0600);
        }
        $token = trim((string)file_get_contents($tokenFile));
        $authed = hash_equals(hash('sha256', $token), (string)$req->cookie(self::COOKIE));

        if ($req->method === 'POST' && !Csrf::valid($req)) {
            return Response::error('درخواست نامعتبر است؛ صفحه را دوباره بارگذاری کنید.', 400);
        }
        if (!$authed) {
            if ($req->method === 'POST' && $req->input('token') !== '' && hash_equals($token, trim($req->input('token')))) {
                $res = Response::redirect('/install/');
                return $res->cookie(self::COOKIE, hash('sha256', $token), ['samesite' => 'Strict']);
            }
            return $this->page($req, 'install/token', ['error' => $req->method === 'POST' ? 'کد نصب نادرست است.' : null]);
        }

        $checks = HostCheck::run($this->app->root, $req);
        $blocked = HostCheck::blocking($checks);
        $form = [
            'db_mode' => $req->input('db_mode', 'auto') === 'manual' ? 'manual' : 'auto',
            'cpanel_user' => $req->input('cpanel_user', CpanelApi::detectUser($this->app->root)),
            'db_host' => $req->input('db_host', 'localhost'), 'db_port' => $req->input('db_port', '3306'),
            'db_name' => $req->input('db_name'), 'db_user' => $req->input('db_user'),
            'admin_username' => $req->input('admin_username', 'admin'), 'admin_display' => 'مدیرکل',
        ];
        if ($req->method !== 'POST' || $blocked) {
            return $this->page($req, 'install/form', compact('checks', 'blocked', 'form') + ['error' => null]);
        }
        try {
            $result = $this->install($req, $form);
        } catch (UserError $e) {
            return $this->page($req, 'install/form', compact('checks', 'blocked', 'form') + ['error' => $e->getMessage()]);
        }
        @unlink($tokenFile);
        $this->removeSingleFileInstaller($req);
        $res = $this->page($req, 'install/done', $result + ['checks' => $checks, 'host' => $req->host(), 'root' => $this->app->root, 'assetBase' => '/' . $result['admin_path']]);
        return $res->forgetCookie(self::COOKIE);
    }

    /** Deletes the single-file installer (install.php in the web root) once installation is complete. */
    private function removeSingleFileInstaller(Request $req): void
    {
        $docroot = realpath((string)($req->server['DOCUMENT_ROOT'] ?? '')) ?: '';
        if ($docroot === '') {
            return;
        }
        $f = $docroot . '/install.php';
        if (is_file($f) && str_contains((string)file_get_contents($f, false, null, 0, 4096), 'PM-SINGLE-FILE-INSTALLER')) {
            @unlink($f);
        }
        @unlink($docroot . '/.pm-install-claim');
    }

    /** @return array{app_path:string, admin_path:string, cron:string, cron_cmd:string} */
    private function install(Request $req, array $form): array
    {
        $pass = $req->input('admin_password');
        if ($pass !== $req->input('admin_password2')) {
            throw new UserError('تکرار رمز عبور مدیرکل مطابقت ندارد.');
        }
        if (($err = Passwords::policyError($pass, $form['admin_username'])) !== null) {
            throw new UserError('رمز مدیرکل: ' . $err);
        }
        $cpanel = null;
        if ($form['db_mode'] === 'auto') {
            if (trim($form['cpanel_user']) === '' || $req->input('cpanel_pass') === '') {
                throw new UserError('نام کاربری و رمز cPanel را وارد کنید (یا روش دستی را انتخاب کنید).');
            }
            $cpanel = new CpanelApi(trim($form['cpanel_user']), $req->input('cpanel_pass'), $req->host());
            $c = $cpanel->createDatabase();
            [$form['db_host'], $form['db_port'], $form['db_name'], $form['db_user'], $dbPass] = [$c['host'], (string)$c['port'], $c['name'], $c['user'], $c['pass']];
        } else {
            $dbPass = $req->input('db_pass');
        }
        try {
            $db = Database::connect($form['db_host'], (int)$form['db_port'], $form['db_name'], $form['db_user'], $dbPass);
        } catch (\PDOException $e) {
            throw new UserError('اتصال به دیتابیس ناموفق بود: ' . mb_substr($e->getMessage(), 0, 200));
        }
        $ver = (string)$db->scalar('SELECT VERSION()');
        preg_match('/^(\d+)\.(\d+)/', $ver, $m);
        $maria = stripos($ver, 'mariadb') !== false;
        $okVer = $maria ? ((int)$m[1] > 10 || ((int)$m[1] === 10 && (int)$m[2] >= 6)) : ((int)($m[1] ?? 0) >= 8);
        if (!$okVer) {
            throw new UserError('نسخه دیتابیس پشتیبانی نمی‌شود (' . $ver . '). حداقل MySQL 8.0 یا MariaDB 10.6 لازم است.');
        }
        $migrator = new Migrator($db, $this->app->root . '/database/migrations');
        $tables = array_map(static fn (array $r): string => (string)array_values($r)[0], $db->all('SHOW TABLES'));
        if ($tables !== []) {
            // Leftovers of an earlier failed attempt (only our tables, no administrator yet) are removed automatically.
            $leftover = array_diff($tables, $migrator->ownTables()) === []
                && (!in_array('admin_users', $tables, true) || (int)$db->scalar('SELECT COUNT(*) FROM admin_users') === 0);
            if (!$leftover) {
                throw new UserError('این دیتابیس خالی نیست و جدول‌های دیگری دارد. یک دیتابیس خالی جدید بسازید.');
            }
            try {
                $migrator->dropTables($tables);
            } catch (\PDOException $e) {
                throw new UserError('جدول‌های باقی‌مانده از تلاش قبلی پاک نشد (' . mb_substr($e->getMessage(), 0, 150) . '). به کاربر دیتابیس ALL PRIVILEGES بدهید.');
            }
        }
        $appKey = random_bytes(32);
        $appPath = Random::string(16);
        do {
            $adminPath = Random::string(16);
        } while ($adminPath === $appPath);
        $env = [
            'APP_KEY' => base64_encode($appKey),
            'APP_PATH' => $appPath,
            'ADMIN_PATH' => $adminPath,
            'DB_HOST' => $form['db_host'], 'DB_PORT' => (string)(int)$form['db_port'], 'DB_NAME' => $form['db_name'],
            'DB_USER' => $form['db_user'], 'DB_PASS' => $dbPass,
            'INSTALLED_AT' => gmdate('c'),
        ];
        $envFile = $this->app->root . '/config/.env';
        $tmp = $envFile . '.tmp';
        if (@file_put_contents($tmp, Env::render($env)) === false || !@chmod($tmp, 0600) || !@rename($tmp, $envFile)) {
            @unlink($tmp);
            throw new UserError('نوشتن فایل config/.env ممکن نشد. مجوز پوشه config را بررسی کنید.');
        }

        try {
            $migrator->migrate();
            $audit = new AuditLog($db);
            $admins = new AdminService($db, $audit, new RateLimiter($db), $appKey);
            $superRole = (int)$db->scalar('SELECT id FROM roles WHERE is_super = 1 ORDER BY id LIMIT 1');
            $admins->createAdmin(0, $form['admin_username'], $form['admin_display'], $pass, $superRole, $req->ip());
        } catch (\Throwable $e) {
            // Undo everything so that the next attempt starts clean, and show the real reason (installation only).
            error_log('[pm] install failed: ' . $e->getMessage());
            @unlink($envFile);
            $cleaned = true;
            try {
                $migrator->dropTables($migrator->ownTables());
            } catch (\Throwable) {
                $cleaned = false; // e.g. no DROP permission; leftovers are removed automatically on the next attempt
            }
            if ($e instanceof UserError && $cleaned) {
                throw $e;
            }
            throw new UserError('ساخت جدول‌های دیتابیس ناموفق بود' . ($cleaned ? ' و تغییرات برگردانده شد' : '')
                . '. پیام دیتابیس: ' . mb_substr($e->getMessage(), 0, 300)
                . ' — معمولاً یعنی کاربر دیتابیس همه مجوزها (ALL PRIVILEGES) را ندارد؛ در cPanel ← MySQL Databases مجوزها را کامل کنید و دوباره «نصب کن» را بزنید.');
        }
        $db->run("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('auth.method', '\"password\"', ?)", [Clock::db()]);
        $db->run("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('host.checks', ?, ?)",
            [json_encode(HostCheck::run($this->app->root, $req), JSON_UNESCAPED_UNICODE), Clock::db()]);
        $audit->record('installer', null, 'install.completed', null, null, ['db' => $ver], $req->ip());

        file_put_contents($this->app->root . '/storage/installed.lock', json_encode(['installed_at' => gmdate('c')]) . "\n");

        // Cron is optional (maintenance also runs automatically after normal requests); try to add it.
        $cronCmd = '/usr/local/bin/php ' . $this->app->root . '/app/Cli/cron.php maintenance';
        $cron = 'pseudo';
        if ($cpanel !== null) {
            try {
                $cpanel->addCron($cronCmd);
                $cron = 'auto';
            } catch (UserError $e) {
                error_log('[pm] installer cron: ' . $e->getMessage());
            }
        }
        $audit->record('installer', null, 'install.options', null, null, ['db' => $form['db_mode'], 'cron' => $cron], $req->ip());
        return ['app_path' => $appPath, 'admin_path' => $adminPath, 'cron' => $cron, 'cron_cmd' => $cronCmd];
    }
}
