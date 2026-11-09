<?php
declare(strict_types=1);

namespace PM\Install;

use PM\Support\Random;
use PM\Support\UserError;

/**
 * Minimal cPanel API client used ONLY during installation to create the database, the database
 * user and the cron job automatically. The cPanel password is used in-request and never stored
 * or logged. Every call has a manual fallback in the installer.
 *
 * Endpoints (documented cPanel APIs):
 *   UAPI  Mysql::get_restrictions, Mysql::create_database, Mysql::create_user, Mysql::set_privileges_on_database
 *   API2  Cron::add_line
 */
final class CpanelApi
{
    /** @var list<string> */
    private array $bases;

    public function __construct(private readonly string $user, private readonly string $password, string $host)
    {
        $override = getenv('PM_CPANEL_API_BASE'); // test hook: point at a mock server
        $this->bases = $override ? [rtrim($override, '/')] : ['https://127.0.0.1:2083', 'https://' . $host . ':2083'];
    }

    public static function detectUser(string $appRoot): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name']) && $pw['name'] !== 'root') {
                return (string)$pw['name'];
            }
        }
        if (preg_match('#^/home\d*/([^/]+)/#', $appRoot . '/', $m)) {
            return $m[1];
        }
        $u = get_current_user();
        return $u === 'root' ? '' : $u;
    }

    /** @return array{host:string, port:int, name:string, user:string, pass:string} */
    public function createDatabase(): array
    {
        $r = $this->uapi('Mysql', 'get_restrictions');
        $prefix = (string)($r['prefix'] ?? '');
        $max = (int)($r['max_database_name_length'] ?? 64);
        $suffix = 'pm' . strtolower(Random::string(4, 'abcdefghijkmnpqrstuvwxyz23456789'));
        $name = substr($prefix . $suffix, 0, $max);
        $dbUser = substr($prefix . $suffix, 0, (int)($r['max_username_length'] ?? 47));
        $pass = Random::readablePassword(24);
        $this->uapi('Mysql', 'create_database', ['name' => $name]);
        $this->uapi('Mysql', 'create_user', ['name' => $dbUser, 'password' => $pass]);
        $this->uapi('Mysql', 'set_privileges_on_database', ['user' => $dbUser, 'database' => $name, 'privileges' => 'ALL PRIVILEGES']);
        return ['host' => 'localhost', 'port' => 3306, 'name' => $name, 'user' => $dbUser, 'pass' => $pass];
    }

    public function addCron(string $command): void
    {
        $res = $this->request('/json-api/cpanel?' . http_build_query([
            'cpanel_jsonapi_user' => $this->user, 'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => 'Cron', 'cpanel_jsonapi_func' => 'add_line',
            'command' => $command, 'minute' => '*/15', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
        ]));
        $ok = (int)($res['cpanelresult']['data'][0]['status'] ?? 0) === 1;
        if (!$ok) {
            throw new UserError('ساخت خودکار Cron ممکن نشد: ' . mb_substr((string)($res['cpanelresult']['error'] ?? $res['cpanelresult']['data'][0]['statusmsg'] ?? 'نامشخص'), 0, 150));
        }
    }

    private function uapi(string $module, string $func, array $args = []): array
    {
        $res = $this->request('/execute/' . $module . '/' . $func . ($args ? '?' . http_build_query($args) : ''));
        if ((int)($res['status'] ?? 0) !== 1) {
            $err = is_array($res['errors'] ?? null) ? implode(' ', $res['errors']) : 'نامشخص';
            throw new UserError('خطای cPanel در ' . $module . '::' . $func . ': ' . mb_substr($err, 0, 200));
        }
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    private function request(string $path): array
    {
        $lastError = '';
        foreach ($this->bases as $base) {
            $ch = curl_init($base . $path);
            $loopback = str_contains($base, '127.0.0.1');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($this->user . ':' . $this->password)],
                // The loopback endpoint commonly uses a self-signed certificate; it never leaves the server.
                CURLOPT_SSL_VERIFYPEER => !$loopback,
                CURLOPT_SSL_VERIFYHOST => $loopback ? 0 : 2,
                CURLOPT_PROXY => '',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);
            if ($body === false || $status === 0) {
                continue; // try next endpoint
            }
            if ($status === 401 || $status === 403) {
                throw new UserError('نام کاربری یا رمز cPanel پذیرفته نشد. (اگر ورود دومرحله‌ای cPanel فعال است، روش دستی را انتخاب کنید.)');
            }
            $json = json_decode((string)$body, true);
            if (!is_array($json)) {
                throw new UserError('پاسخ نامعتبر از cPanel (HTTP ' . $status . ').');
            }
            return $json;
        }
        throw new UserError('اتصال به cPanel از داخل سرور ممکن نشد' . ($lastError ? ' (' . mb_substr($lastError, 0, 100) . ')' : '') . '. روش دستی را انتخاب کنید.');
    }
}
