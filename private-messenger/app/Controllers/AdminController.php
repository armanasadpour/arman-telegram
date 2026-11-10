<?php
declare(strict_types=1);

namespace PM\Controllers;

use PM\Admin\AdminService;
use PM\Auth\Csrf;
use PM\Auth\UserSessions;
use PM\E2ee\KeyService;
use PM\Http\Request;
use PM\Http\Response;
use PM\Http\Router;
use PM\Infra\Sms\SmsException;
use PM\Infra\Sms\SmsFactory;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\Ip;
use PM\Support\Phone;
use PM\Support\Random;
use PM\Support\Totp;
use PM\Support\UserError;
use PM\Users\UserService;

/** Everything under the secret ADMIN_PATH. No route here can read message or file content. */
final class AdminController extends Controller
{
    private ?array $admin = null;

    private const MESSAGES = [
        'saved' => 'تغییرات ذخیره شد.',
        'status' => 'وضعیت حساب تغییر کرد.',
        'revoked' => 'نشست‌ها باطل شدند.',
        'unlocked' => 'قفل ورود حساب برداشته شد.',
        'retention' => 'تنظیم نگهداری فایل تغییر کرد.',
        'device_revoked' => 'دستگاه لغو شد.',
        'cleanup' => 'پاک‌سازی اجرا شد.',
        'font_saved' => 'فونت ذخیره شد. کاربران با بارگذاری دوباره صفحه آن را می‌بینند.',
        'font_deleted' => 'فایل فونت حذف شد.',
        'identity_reset' => 'هویت رمزنگاری کاربر بازنشانی شد.',
        'totp_on' => 'ورود دومرحله‌ای (TOTP) فعال شد.',
        'totp_off' => 'ورود دومرحله‌ای غیرفعال شد.',
        'pw' => 'رمز عبور تغییر کرد.',
        'sms_ok' => 'پیامک آزمایشی ارسال شد.',
        'admin_created' => 'مدیر جدید ایجاد شد.',
        'role_saved' => 'نقش ذخیره شد.',
        'role_deleted' => 'نقش حذف شد.',
        'logo_saved' => 'لوگو ذخیره شد.',
        'logo_deleted' => 'لوگو حذف شد و نماد پیش‌فرض استفاده می‌شود.',
        'path_app' => 'آدرس مخفی برنامه تغییر کرد. آدرس جدید را برای کاربران بفرستید.',
    ];

    public function handle(Request $req): Response
    {
        $r = new Router();
        $r->get('/assets/{file}', fn (Request $q) => $this->asset($q, $q->param('file')));
        $r->get('/assets/fonts/{file}', fn (Request $q) => $this->asset($q, 'fonts/' . $q->param('file')));
        $r->get('/', fn (Request $q) => $this->current($q) ? $this->dashboard($q) : $this->loginPage($q));
        $r->post('/login', fn (Request $q) => $this->guard($q) ?? $this->login($q));
        $r->post('/logout', fn (Request $q) => $this->guard($q) ?? $this->logout($q));

        $r->get('/users', $this->need('users.view', fn (Request $q) => $this->users($q)));
        $r->get('/users/new', $this->need('users.create', fn (Request $q) => $this->page($q, 'admin/user-new', $this->vars(['form' => [], 'error' => null]))));
        $r->post('/users/new', $this->need('users.create', fn (Request $q) => $this->userCreate($q), true));
        $r->get('/users/{id}', $this->need('users.view', fn (Request $q) => $this->userDetail($q)));
        $r->post('/users/{id}/edit', $this->need('users.edit', fn (Request $q) => $this->userEdit($q), true));
        $r->post('/users/{id}/status', $this->need('users.view', fn (Request $q) => $this->userStatus($q), true));
        $r->post('/users/{id}/password', $this->need('users.sessions.revoke', fn (Request $q) => $this->userPasswordReset($q), true));
        $r->post('/users/{id}/unlock', $this->need('users.edit', fn (Request $q) => $this->userUnlock($q), true));
        $r->post('/users/{id}/sessions/revoke', $this->need('users.sessions.revoke', fn (Request $q) => $this->userSessionRevoke($q), true));
        $r->post('/users/{id}/sessions/revoke-all', $this->need('users.sessions.revoke', fn (Request $q) => $this->userSessionRevokeAll($q), true));
        $r->post('/users/{id}/devices/revoke', $this->need('users.devices.revoke', fn (Request $q) => $this->userDeviceRevoke($q), true));
        $r->post('/users/{id}/identity-reset', $this->need('users.identity.reset', fn (Request $q) => $this->userIdentityReset($q), true));
        $r->post('/users/{id}/retention', $this->need('users.retention.manage', fn (Request $q) => $this->userRetention($q), true));

        $r->get('/settings', $this->need('settings.view', fn (Request $q) => $this->settingsPage($q)));
        $r->post('/settings', $this->need('settings.edit', fn (Request $q) => $this->settingsSave($q), true));
        $r->post('/settings/sms-test', $this->need('settings.edit', fn (Request $q) => $this->smsTest($q), true));

        $r->get('/admins', $this->need('admins.manage', fn (Request $q) => $this->adminsPage($q)));
        $r->post('/admins', $this->need('admins.manage', fn (Request $q) => $this->adminCreate($q), true));
        $r->post('/admins/{id}/status', $this->need('admins.manage', fn (Request $q) => $this->adminStatus($q), true));

        $r->get('/fonts', $this->need('settings.view', fn (Request $q) => $this->fontsPage($q)));
        $r->post('/fonts', $this->need('settings.edit', fn (Request $q) => $this->fontsUpload($q), true));
        $r->post('/fonts/delete', $this->need('settings.edit', fn (Request $q) => $this->fontsDelete($q), true));
        $r->get('/storage', $this->need('storage.view', fn (Request $q) => $this->storagePage($q)));
        $r->post('/storage/cleanup', $this->need('storage.cleanup.run', fn (Request $q) => $this->storageCleanup($q), true));
        $r->get('/audit', $this->need('audit.view', fn (Request $q) => $this->auditPage($q)));
        $r->get('/audit/export', $this->need('audit.view', fn (Request $q) => $this->auditExport($q)));
        $r->get('/reports', $this->need('reports.view', fn (Request $q) => $this->reportsPage($q)));
        $r->get('/reports/export', $this->need('reports.view', fn (Request $q) => $this->reportsExport($q)));
        $r->post('/roles', $this->need('admins.manage', fn (Request $q) => $this->roleSave($q, null), true));
        $r->post('/roles/{id}', $this->need('admins.manage', fn (Request $q) => $this->roleSave($q, (int)$q->param('id')), true));
        $r->post('/roles/{id}/delete', $this->need('admins.manage', fn (Request $q) => $this->roleDelete($q), true));
        $r->post('/admins/{id}/role', $this->need('admins.manage', fn (Request $q) => $this->adminRole($q), true));
        $r->get('/backup', $this->need('backup.download', fn (Request $q) => $this->backupPage($q)));
        $r->post('/backup/download', $this->need('backup.download', fn (Request $q) => $this->backupDownload($q), true));
        $r->post('/backup/settings', $this->need('backup.download', fn (Request $q) => $this->backupSettings($q), true));
        $r->post('/system/paths', $this->need('system.paths.manage', fn (Request $q) => $this->pathChange($q), true));
        $r->post('/settings/logo', $this->need('settings.edit', fn (Request $q) => $this->logoUpload($q), true));
        $r->post('/settings/logo/delete', $this->need('settings.edit', fn (Request $q) => $this->logoDelete($q), true));
        $r->get('/theme.css', $this->need(null, fn () => $this->themeCss()));
        $r->get('/system', $this->need('system.capabilities.view', fn (Request $q) => $this->systemPage($q)));

        $r->get('/account', $this->need(null, fn (Request $q) => $this->accountPage($q)));
        $r->post('/account/password', $this->need(null, fn (Request $q) => $this->accountPassword($q), true));
        $r->post('/account/totp/start', $this->need(null, fn (Request $q) => $this->totpStart($q), true));
        $r->post('/account/totp/confirm', $this->need(null, fn (Request $q) => $this->totpConfirm($q), true));
        $r->post('/account/totp/disable', $this->need(null, fn (Request $q) => $this->totpDisable($q), true));
        return $r->dispatch($req);
    }

    // ------------------------------------------------------------------ plumbing
    private function svc(): AdminService
    {
        return new AdminService($this->app->db(), $this->audit(), $this->limiter(), $this->app->key());
    }

    private function usersSvc(): UserService
    {
        return new UserService($this->app->db(), $this->audit(), new UserSessions($this->app->db()));
    }

    private function current(Request $req): ?array
    {
        return $this->admin ??= $this->svc()->validateSession($req->cookie(AdminService::COOKIE));
    }

    private function guard(Request $req): ?Response
    {
        return Csrf::valid($req) ? null : Response::error('درخواست نامعتبر است (CSRF). صفحه را دوباره بارگذاری کنید.', 400);
    }

    /** Wraps a handler with authentication, permission and (for POST) CSRF checks. */
    private function need(?string $perm, callable $fn, bool $post = false): callable
    {
        return function (Request $q) use ($perm, $fn, $post): Response {
            if ($this->current($q) === null) {
                return $this->redirect('/');
            }
            if ($post && ($g = $this->guard($q)) !== null) {
                return $g;
            }
            if ($perm !== null && !AdminService::can($this->admin, $perm)) {
                return $this->page($q, 'admin/forbidden', $this->vars([]), 403);
            }
            return $fn($q);
        };
    }

    private function vars(array $v): array
    {
        return $v + ['admin' => $this->admin, 'msg' => null, 'nav' => '', 'theme' => $this->admin !== null ? $this->themeHref() : null];
    }

    private function back(string $path, string $msg): Response
    {
        return $this->redirect($path . (str_contains($path, '?') ? '&' : '?') . 'msg=' . $msg);
    }

    private function msg(Request $q): ?string
    {
        return self::MESSAGES[$q->queryString('msg')] ?? null;
    }

    private function loginPage(Request $req, ?string $error = null, bool $totp = false, string $username = ''): Response
    {
        return $this->page($req, 'admin/login', ['error' => $error, 'totp' => $totp, 'username' => $username]);
    }

    // ------------------------------------------------------------------ auth
    private function login(Request $req): Response
    {
        $u = $req->input('username');
        [$res, $a] = $this->svc()->attemptLogin($u, $req->input('password'), $req->input('totp'), $req->ip());
        if ($res === 'rate_limited') {
            return $this->loginPage($req, 'تلاش‌های زیادی انجام شده است. چند دقیقه بعد دوباره تلاش کنید.', false, $u);
        }
        if ($res === 'totp') {
            return $this->loginPage($req, null, true, $u);
        }
        if ($res !== 'ok') {
            \PM\Admin\Stats::bump($this->app->db(), 'admin.login_failed');
            $this->audit()->record('system', null, 'admin.login_failed', null, null, ['username' => mb_substr(trim($u), 0, 64)], $req->ip());
            return $this->loginPage($req, 'اطلاعات ورود نادرست است.', $req->input('totp') !== '', $u);
        }
        $token = $this->svc()->createSession((int)$a['id'], $req->ip(), $req->userAgent());
        return $this->redirect('/')->cookie(AdminService::COOKIE, $token, ['samesite' => 'Strict']);
    }

    private function logout(Request $req): Response
    {
        if (($a = $this->current($req)) !== null) {
            $this->svc()->revokeSession((int)$a['session_id']);
            $this->audit()->record('admin', (int)$a['id'], 'admin.logout', 'admin', (int)$a['id'], [], $req->ip());
        }
        return $this->redirect('/')->forgetCookie(AdminService::COOKIE);
    }

    // ------------------------------------------------------------------ dashboard
    private function dashboard(Request $req): Response
    {
        $db = $this->app->db();
        $stats = new \PM\Admin\Stats($db);
        $s = $this->app->settings();
        $files = new \PM\Files\AttachmentService($db, $s, $this->app->root . '/storage/attachments');
        $warnings = [];
        $method = (string)$s->get('auth.method');
        $provider = (string)$s->get('sms.provider');
        if ($method !== 'password' && $provider === 'none') {
            $warnings[] = 'روش ورود به پیامک نیاز دارد اما ارسال پیامک تنظیم نشده است؛ کاربران نمی‌توانند وارد شوند.';
        }
        if ($provider === 'log') {
            $warnings[] = 'ارسال پیامک روی حالت «فقط تست» است: کدها به‌جای ارسال در فایل لاگ نوشته می‌شوند. هرگز در استفاده واقعی فعال نماند.';
        }
        $cron = $db->one("SELECT finished_at, status FROM cron_runs WHERE job = 'maintenance' ORDER BY id DESC LIMIT 1");
        if ($cron === null || strtotime($cron['finished_at'] . ' UTC') < Clock::now()->getTimestamp() - 86400) {
            $warnings[] = 'Cron نگهداری در ۲۴ ساعت گذشته اجرا نشده است. دستور Cron را در cPanel اضافه کنید (صفحه «سیستم»).';
        }
        if ((string)$s->get('calls.turn_mode') === 'none') {
            $warnings[] = 'سرور TURN تنظیم نشده است: تماس صوتی/تصویری در شبکه‌های ساده برقرار می‌شود ولی بین برخی اینترنت‌های همراه یا شبکه‌های سازمانی ممکن است وصل نشود (تنظیمات ← تماس).';
        }
        if ((int)$this->admin['totp_enabled'] === 0) {
            $warnings[] = 'ورود دومرحله‌ای (TOTP) برای حساب مدیریتی شما فعال نیست. از صفحه «حساب من» فعال کنید.';
        }
        $remind = $s->int('backup.reminder_days');
        $last = (string)$s->get('backup.last_at');
        if ($remind > 0 && AdminService::can($this->admin, 'backup.download')
            && ($last === '' || strtotime($last . ' UTC') < Clock::now()->getTimestamp() - $remind * 86400)) {
            $warnings[] = $last === '' ? 'هنوز هیچ نسخه پشتیبانی از دیتابیس گرفته نشده است (صفحه «پشتیبان‌گیری»).'
                : 'بیش از ' . \PM\Support\Fa::digits($remind) . ' روز از آخرین نسخه پشتیبان گذشته است (صفحه «پشتیبان‌گیری»).';
        }
        return $this->page($req, 'admin/dashboard', $this->vars(['o' => $stats->overview(), 'health' => $stats->health($this->app->root, (int)$s->get('storage.quota_bytes')),
            'st' => $files->stats(), 'warnings' => $warnings, 'method' => $method, 'week' => array_reverse($stats->daily(7)), 'nav' => 'dash', 'msg' => $this->msg($req)]));
    }

    // ------------------------------------------------------------------ users
    private function users(Request $req): Response
    {
        $list = $this->usersSvc()->search(trim($req->queryString('q')), $req->queryString('status'));
        return $this->page($req, 'admin/users', $this->vars(['list' => $list, 'q' => $req->queryString('q'),
            'status' => $req->queryString('status'), 'nav' => 'users', 'msg' => $this->msg($req)]));
    }

    private function userCreate(Request $req): Response
    {
        $form = ['username' => $req->input('username'), 'display_name' => $req->input('display_name'), 'phone' => $req->input('phone'),
            'activate' => $req->input('activate') === '1'];
        $activate = $form['activate'] && AdminService::can($this->admin, 'users.activate');
        try {
            [$id, $temp] = $this->usersSvc()->create((int)$this->admin['id'], $form['username'], $form['display_name'], $form['phone'], $activate, $req->ip());
        } catch (UserError $e) {
            return $this->page($req, 'admin/user-new', $this->vars(['form' => $form, 'error' => $e->getMessage(), 'nav' => 'users']));
        }
        return $this->userDetail($req, $id, $temp, 'کاربر ایجاد شد.');
    }

    private function userId(Request $req): int
    {
        return (int)$req->param('id');
    }

    private function userDetail(Request $req, ?int $id = null, ?string $tempPassword = null, ?string $msg = null): Response
    {
        $id ??= $this->userId($req);
        $u = $this->usersSvc()->get($id);
        if ($u === null) {
            return Response::notFound();
        }
        $db = $this->app->db();
        $sessions = (new UserSessions($db))->listForUser($id);
        foreach ($sessions as &$s) {
            $s['ip_display'] = Ip::displayPrefix($s['ip_prefix']);
        }
        unset($s);
        $audit = AdminService::can($this->admin, 'audit.view')
            ? $db->all("SELECT a.action, a.actor_type, a.created_at, ad.username AS actor FROM audit_logs a
                         LEFT JOIN admin_users ad ON ad.id = a.actor_id AND a.actor_type = 'admin'
                         WHERE a.target_type = 'user' AND a.target_id = ? ORDER BY a.id DESC LIMIT 20", [$id])
            : [];
        $retBy = $u['retention_changed_by'] ? $db->scalar('SELECT username FROM admin_users WHERE id = ?', [$u['retention_changed_by']]) : null;
        $devices = (new KeyService($db))->devicesOf($id);
        if ($req->method === 'GET') { // every look at an account is itself recorded
            $this->audit()->record('admin', (int)$this->admin['id'], 'user.view', 'user', $id, [], $req->ip());
        }
        return $this->page($req, 'admin/user', $this->vars(['u' => $u, 'sessions' => $sessions, 'audit' => $audit, 'temp' => $tempPassword, 'devices' => $devices,
            'ustats' => (new \PM\Admin\Stats($db))->forUser($id), 'retention_days' => $this->app->settings()->int('files.retention_days'),
            'retention_by' => $retBy, 'method' => (string)$this->app->settings()->get('auth.method'),
            'msg' => $msg ?? $this->msg($req), 'error' => null, 'nav' => 'users']));
    }

    private function withUserError(Request $req, callable $fn): Response
    {
        try {
            return $fn();
        } catch (UserError $e) {
            return $this->page($req, 'admin/error', $this->vars(['error' => $e->getMessage(), 'back' => '/users/' . $this->userId($req)]), 422);
        }
    }

    private function userEdit(Request $req): Response
    {
        return $this->withUserError($req, function () use ($req): Response {
            $this->usersSvc()->update((int)$this->admin['id'], $this->userId($req), $req->input('username'), $req->input('display_name'), $req->input('phone'), $req->ip());
            return $this->back('/users/' . $this->userId($req), 'saved');
        });
    }

    private function userStatus(Request $req): Response
    {
        $status = $req->input('status');
        $perm = ['active' => 'users.activate', 'suspended' => 'users.suspend', 'blocked' => 'users.block', 'deleted' => 'users.delete'][$status] ?? null;
        if ($perm === null || !AdminService::can($this->admin, $perm)) {
            return $this->page($req, 'admin/forbidden', $this->vars([]), 403);
        }
        if ($status === 'deleted' && $req->input('confirm') !== 'حذف') {
            return $this->page($req, 'admin/error', $this->vars(['error' => 'برای حذف، کلمه «حذف» را در کادر تأیید بنویسید.', 'back' => '/users/' . $this->userId($req)]), 422);
        }
        return $this->withUserError($req, function () use ($req, $status): Response {
            $this->usersSvc()->setStatus((int)$this->admin['id'], $this->userId($req), $status, $req->ip());
            return $status === 'deleted' ? $this->back('/users', 'status') : $this->back('/users/' . $this->userId($req), 'status');
        });
    }

    private function userPasswordReset(Request $req): Response
    {
        return $this->withUserError($req, function () use ($req): Response {
            $temp = $this->usersSvc()->resetPassword((int)$this->admin['id'], $this->userId($req), $req->ip());
            return $this->userDetail($req, null, $temp, 'رمز موقت جدید ساخته شد و همه نشست‌های کاربر باطل شد.');
        });
    }

    private function userUnlock(Request $req): Response
    {
        return $this->withUserError($req, function () use ($req): Response {
            $this->usersSvc()->unlock((int)$this->admin['id'], $this->userId($req), $req->ip());
            return $this->back('/users/' . $this->userId($req), 'unlocked');
        });
    }

    private function userSessionRevoke(Request $req): Response
    {
        $id = $this->userId($req);
        if ((new UserSessions($this->app->db()))->revokeByPublicId($id, $req->input('session'), 'admin_revoke')) {
            $this->audit()->record('admin', (int)$this->admin['id'], 'user.session.revoke', 'user', $id, [], $req->ip());
        }
        return $this->back('/users/' . $id, 'revoked');
    }

    private function userSessionRevokeAll(Request $req): Response
    {
        $id = $this->userId($req);
        $n = (new UserSessions($this->app->db()))->revokeAll($id, 'admin_revoke');
        $this->audit()->record('admin', (int)$this->admin['id'], 'user.session.revoke_all', 'user', $id, ['count' => $n], $req->ip());
        return $this->back('/users/' . $id, 'revoked');
    }

    private function userDeviceRevoke(Request $req): Response
    {
        return $this->withUserError($req, function () use ($req): Response {
            (new KeyService($this->app->db(), $this->audit()))->adminRevokeDevice((int)$this->admin['id'], $this->userId($req), $req->input('device'), $req->ip());
            return $this->back('/users/' . $this->userId($req), 'device_revoked');
        });
    }

    private function userIdentityReset(Request $req): Response
    {
        if ($req->input('confirm') !== 'بازنشانی') {
            return $this->page($req, 'admin/error', $this->vars(['error' => 'برای تأیید، کلمه «بازنشانی» را بنویسید.', 'back' => '/users/' . $this->userId($req)]), 422);
        }
        return $this->withUserError($req, function () use ($req): Response {
            $this->usersSvc()->getOrFail($this->userId($req));
            (new KeyService($this->app->db(), $this->audit()))->adminResetIdentity((int)$this->admin['id'], $this->userId($req), $req->ip());
            return $this->back('/users/' . $this->userId($req), 'identity_reset');
        });
    }

    private function userRetention(Request $req): Response
    {
        return $this->withUserError($req, function () use ($req): Response {
            $this->usersSvc()->setPermanentRetention((int)$this->admin['id'], $this->userId($req), $req->input('permanent') === '1', $req->ip(),
                $this->app->settings()->int('files.retention_days'));
            return $this->back('/users/' . $this->userId($req), 'retention');
        });
    }

    // ------------------------------------------------------------------ settings
    private function settingsPage(Request $req, ?string $error = null): Response
    {
        $s = $this->app->settings();
        return $this->page($req, 'admin/settings', $this->vars(['s' => $s, 'error' => $error, 'nav' => 'settings',
            'msg' => $this->msg($req), 'has_key' => $s->hasSecret('sms.api_key'),
            'can_edit' => AdminService::can($this->admin, 'settings.edit'), 'is_super' => (int)$this->admin['is_super'] === 1]));
    }

    private function settingsSave(Request $req): Response
    {
        $s = $this->app->settings();
        $int = static function (string $v, int $min, int $max, string $label): int {
            if (!preg_match('/^\d+$/', $v) || (int)$v < $min || (int)$v > $max) {
                throw new UserError($label . " باید عددی بین $min و $max باشد.");
            }
            return (int)$v;
        };
        try {
            $new = [
                'session.days' => $int($req->input('session_days'), 1, 365, 'مدت نشست'),
                'otp.ttl_seconds' => $int($req->input('otp_ttl'), 60, 600, 'اعتبار کد'),
                'otp.max_attempts' => $int($req->input('otp_attempts'), 3, 10, 'تعداد تلاش کد'),
                'login.per_ip_15min' => $int($req->input('login_ip'), 5, 500, 'سقف تلاش هر IP'),
                'login.user_fail_lock' => $int($req->input('login_fail'), 3, 50, 'تعداد خطای قفل حساب'),
                'login.lock_minutes' => $int($req->input('login_lock'), 1, 1440, 'مدت قفل'),
                'sms.provider' => array_key_exists($req->input('sms_provider'), SmsFactory::PROVIDERS) ? $req->input('sms_provider') : throw new UserError('ارائه‌دهنده پیامک نامعتبر است.'),
                'sms.template' => mb_substr(trim($req->input('sms_template')), 0, 100),
                'sms.param_name' => mb_substr(trim($req->input('sms_param_name')), 0, 50) ?: 'CODE',
                'sms.generic_method' => $req->input('sms_generic_method') === 'GET' ? 'GET' : 'POST',
                'sms.generic_url' => trim($req->input('sms_generic_url')),
                'sms.generic_body' => mb_substr($req->input('sms_generic_body'), 0, 1000),
                'sms.generic_auth_header' => preg_replace('/[^A-Za-z0-9-]/', '', $req->input('sms_generic_auth_header')) ?? '',
                'otp.message' => mb_substr(trim($req->input('otp_message')), 0, 160) ?: 'کد: {code}',
                'app.internal_name' => mb_substr(trim($req->input('internal_name')), 0, 60) ?: 'پیام‌رسان',
                'files.max_bytes' => $int($req->input('files_max_mb', '0'), 0, 1048576, 'سقف حجم فایل') * 1048576,
                'calls.stun' => trim(preg_replace('/[^A-Za-z0-9:.\-\s,]/', '', $req->input('calls_stun')) ?? ''),
                'calls.turn_urls' => trim(preg_replace('/[^A-Za-z0-9:.\-\s,?=&_]/', '', $req->input('calls_turn_urls')) ?? ''),
                'calls.turn_mode' => in_array($req->input('calls_turn_mode'), ['none', 'secret', 'static'], true) ? $req->input('calls_turn_mode') : 'none',
                'calls.turn_username' => mb_substr(trim($req->input('calls_turn_username')), 0, 100),
                'calls.relay_only' => $req->input('calls_relay_only') === '1',
                'storage.quota_bytes' => $int($req->input('quota_gb', '0'), 0, 100000, 'سهمیه فضا') * 1073741824,
                'app.accent' => isset(Settings::ACCENTS[$req->input('accent')]) ? $req->input('accent') : (string)$s->get('app.accent'),
                'limits.send_per_hour' => $int($req->input('limit_send', (string)$s->get('limits.send_per_hour')), 100, 100000, 'سقف ارسال'),
                'limits.search_per_hour' => $int($req->input('limit_search', (string)$s->get('limits.search_per_hour')), 10, 10000, 'سقف جست‌وجو'),
                'limits.claim_per_hour' => $int($req->input('limit_claim', (string)$s->get('limits.claim_per_hour')), 50, 100000, 'سقف دریافت کلید'),
                'notify.enabled' => $req->input('notify_enabled') === '1',
                'notify.preview' => $req->input('notify_preview') === 'sender' ? 'sender' : 'none',
            ];
            if ($new['sms.generic_url'] !== '' && !str_starts_with($new['sms.generic_url'], 'https://')) {
                throw new UserError('آدرس وب‌هوک پیامک باید با https:// شروع شود.');
            }
            if (!str_contains($new['otp.message'], '{code}')) {
                throw new UserError('متن پیامک باید {code} را داشته باشد.');
            }
            $method = $req->input('auth_method');
            if ((int)$this->admin['is_super'] === 1) {
                if (!isset(Settings::AUTH_METHODS[$method])) {
                    throw new UserError('روش ورود نامعتبر است.');
                }
                if ($method !== 'password' && $new['sms.provider'] === 'none') {
                    throw new UserError('برای ورود با پیامک ابتدا ارائه‌دهنده پیامک را تنظیم کنید.');
                }
                $new['auth.method'] = $method;
                $new['files.retention_days'] = $int($req->input('retention_days', (string)$s->get('files.retention_days')), 1, 3650, 'مدت نگهداری فایل');
            }
        } catch (UserError $e) {
            return $this->settingsPage($req, $e->getMessage());
        }
        $changed = [];
        foreach ($new as $k => $v) {
            if ($s->get($k) !== $v) {
                $s->set($k, $v, (int)$this->admin['id']);
                $changed[] = $k;
            }
        }
        foreach (['calls_turn_secret' => 'calls.turn_secret', 'calls_turn_password' => 'calls.turn_password'] as $field => $key) {
            if ($req->input($field) !== '') {
                $s->setSecret($key, $req->input($field), (int)$this->admin['id']);
                $changed[] = $key;
            }
        }
        if ($req->input('sms_api_key') !== '') {
            $s->setSecret('sms.api_key', $req->input('sms_api_key'), (int)$this->admin['id']);
            $changed[] = 'sms.api_key';
        }
        if ($changed !== []) {
            $this->audit()->record('admin', (int)$this->admin['id'], 'settings.update', null, null, ['keys' => $changed], $req->ip());
        }
        return $this->back('/settings', 'saved');
    }

    private function smsTest(Request $req): Response
    {
        $phone = Phone::normalize($req->input('phone'));
        $provider = SmsFactory::make($this->app->settings(), $this->app->root . '/storage/logs/sms-test.log');
        if ($phone === null || $provider === null) {
            return $this->settingsPage($req, $phone === null ? 'شماره آزمایشی معتبر نیست.' : 'ارائه‌دهنده پیامک تنظیم نشده است.');
        }
        try {
            $code = Random::digits(6);
            $provider->sendOtp($phone, $code, strtr((string)$this->app->settings()->get('otp.message'), ['{code}' => $code]));
        } catch (SmsException $e) {
            return $this->settingsPage($req, 'ارسال ناموفق: ' . mb_substr($e->getMessage(), 0, 200));
        }
        $this->audit()->record('admin', (int)$this->admin['id'], 'settings.sms_test', null, null, [], $req->ip());
        return $this->back('/settings', 'sms_ok');
    }

    // ------------------------------------------------------------------ admins, audit, system, account
    private function adminsPage(Request $req, ?string $error = null): Response
    {
        $svc = $this->svc();
        return $this->page($req, 'admin/admins', $this->vars(['list' => $svc->listAdmins(), 'roles' => $svc->rolesDetailed(), 'error' => $error,
            'nav' => 'admins', 'msg' => $this->msg($req), 'perms' => AdminService::PERMISSIONS, 'super_only' => AdminService::SUPER_ONLY]));
    }

    private function adminCreate(Request $req): Response
    {
        try {
            $this->svc()->createAdmin((int)$this->admin['id'], $req->input('username'), $req->input('display_name'),
                $req->input('password'), (int)$req->input('role_id'), $req->ip());
        } catch (UserError $e) {
            return $this->adminsPage($req, $e->getMessage());
        }
        return $this->back('/admins', 'admin_created');
    }

    private function adminStatus(Request $req): Response
    {
        try {
            $this->svc()->setAdminStatus((int)$this->admin['id'], (int)$req->param('id'), $req->input('status'), $req->ip());
        } catch (UserError $e) {
            return $this->adminsPage($req, $e->getMessage());
        }
        return $this->back('/admins', 'saved');
    }

    public const FONT_WEIGHTS = ['Light' => '300 — نازک', 'Regular' => '400 — معمولی', 'Medium' => '500 — متوسط', 'Bold' => '700 — ضخیم'];

    private function fontDir(): string
    {
        return $this->app->root . '/app/resources/assets/fonts';
    }

    private function fontsPage(Request $req, ?string $error = null): Response
    {
        $state = [];
        foreach (array_keys(self::FONT_WEIGHTS) as $w) {
            $f = null;
            foreach (["IRANSans-$w.woff2", $w === 'Regular' ? 'IRANSansWeb.woff2' : "IRANSansWeb_$w.woff2"] as $n) {
                if (is_file($this->fontDir() . '/' . $n)) {
                    $f = ['name' => $n, 'size' => filesize($this->fontDir() . '/' . $n)];
                    break;
                }
            }
            $state[$w] = $f;
        }
        return $this->page($req, 'admin/fonts', $this->vars(['fonts' => $state, 'error' => $error, 'nav' => 'fonts', 'msg' => $this->msg($req),
            'writable' => is_dir($this->fontDir()) && is_writable($this->fontDir()), 'can_edit' => AdminService::can($this->admin, 'settings.edit')]));
    }

    /** Accepts WOFF2 files only (checked by signature, not by name), stored under fixed names; never executable. */
    private function fontsUpload(Request $req): Response
    {
        $saved = 0;
        foreach (array_keys(self::FONT_WEIGHTS) as $w) {
            $f = $_FILES['font_' . $w] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                return $this->fontsPage($req, 'آپلود فایل ' . $w . ' ناموفق بود (کد ' . (int)$f['error'] . ').');
            }
            if ($f['size'] > 3 * 1048576 || file_get_contents($f['tmp_name'], false, null, 0, 4) !== 'wOF2') {
                return $this->fontsPage($req, 'فایل ' . $w . ' یک فونت WOFF2 معتبر نیست. فقط فایل‌های ‎.woff2‎ (حداکثر ۳ مگابایت) پذیرفته می‌شوند.');
            }
            if (!is_dir($this->fontDir()) && !@mkdir($this->fontDir(), 0755, true)) {
                return $this->fontsPage($req, 'پوشه فونت قابل ساخت نیست.');
            }
            $dest = $this->fontDir() . "/IRANSans-$w.woff2";
            if (!@move_uploaded_file($f['tmp_name'], $dest)) {
                return $this->fontsPage($req, 'ذخیره فایل ممکن نشد؛ مجوز پوشه app/resources/assets/fonts را بررسی کنید.');
            }
            @chmod($dest, 0644);
            $saved++;
        }
        if ($saved === 0) {
            return $this->fontsPage($req, 'هیچ فایلی انتخاب نشده است.');
        }
        $this->audit()->record('admin', (int)$this->admin['id'], 'settings.font_upload', null, null, ['files' => $saved], $req->ip());
        return $this->back('/fonts', 'font_saved');
    }

    private function fontsDelete(Request $req): Response
    {
        $w = $req->input('weight');
        if (isset(self::FONT_WEIGHTS[$w])) {
            foreach (["IRANSans-$w.woff2", $w === 'Regular' ? 'IRANSansWeb.woff2' : "IRANSansWeb_$w.woff2"] as $n) {
                @unlink($this->fontDir() . '/' . $n);
            }
            $this->audit()->record('admin', (int)$this->admin['id'], 'settings.font_delete', null, null, ['weight' => $w], $req->ip());
        }
        return $this->back('/fonts', 'font_deleted');
    }

    private function storagePage(Request $req, ?array $result = null): Response
    {
        $files = new \PM\Files\AttachmentService($this->app->db(), $this->app->settings(), $this->app->root . '/storage/attachments');
        $last = $this->app->db()->one("SELECT finished_at, trigger_source, stats FROM cron_runs WHERE job = 'maintenance' AND status = 'ok' ORDER BY id DESC LIMIT 1");
        return $this->page($req, 'admin/storage', $this->vars(['st' => $files->stats(), 'last' => $last, 'result' => $result, 'nav' => 'storage',
            'chunk' => \PM\Files\AttachmentService::chunkSize(), 'max' => (int)$this->app->settings()->get('files.max_bytes'), 'msg' => $this->msg($req),
            'retention' => $this->app->settings()->int('files.retention_days')]));
    }

    private function storageCleanup(Request $req): Response
    {
        $stats = \PM\Maintenance::run($this->app, 'manual');
        $this->audit()->record('admin', (int)$this->admin['id'], 'storage.cleanup', null, null, ['files' => $stats['files'] ?? null], $req->ip());
        return $this->storagePage($req, $stats ?? ['busy' => true]);
    }

    /** @return array{0:string, 1:list<mixed>, 2:array<string,string>} WHERE clause, params, normalized filters */
    private function auditFilter(Request $req): array
    {
        $f = ['action' => trim($req->queryString('action')), 'actor' => trim($req->queryString('actor')), 'target' => trim($req->queryString('target')),
              'from' => trim($req->queryString('from')), 'to' => trim($req->queryString('to'))];
        $w = ['1 = 1'];
        $p = [];
        if ($f['action'] !== '') {
            $w[] = 'a.action LIKE ?';
            $p[] = str_replace(['%', '_'], ['\%', '\_'], mb_substr($f['action'], 0, 64)) . '%';
        }
        if ($f['actor'] !== '') {
            $w[] = 'ad.username = ?';
            $p[] = mb_strtolower(mb_substr($f['actor'], 0, 64));
        }
        if ($f['target'] !== '') {
            $w[] = 'u.username = ?';
            $p[] = mb_strtolower(mb_substr($f['target'], 0, 64));
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', \PM\Support\Fa::toLatinDigits($f[$k]))) {
                $f[$k] = \PM\Support\Fa::toLatinDigits($f[$k]);
                $w[] = "a.created_at $op ?";
                $p[] = $k === 'to' ? date('Y-m-d', strtotime($f[$k] . ' +1 day')) : $f[$k];
            } else {
                $f[$k] = '';
            }
        }
        return [implode(' AND ', $w), $p, $f];
    }

    private const AUDIT_SELECT = "SELECT a.id, a.actor_type, a.action, a.target_type, a.target_id, a.details, a.ip, a.created_at,
                                         ad.username AS actor, u.username AS target_user
                                    FROM audit_logs a
                               LEFT JOIN admin_users ad ON ad.id = a.actor_id AND a.actor_type = 'admin'
                               LEFT JOIN users u ON u.id = a.target_id AND a.target_type = 'user'";

    private function auditPage(Request $req): Response
    {
        [$where, $params, $f] = $this->auditFilter($req);
        $page = max(1, (int)$req->queryString('page'));
        $rows = $this->app->db()->all(self::AUDIT_SELECT . " WHERE $where ORDER BY a.id DESC LIMIT 101 OFFSET " . (($page - 1) * 100), $params);
        $more = count($rows) > 100;
        return $this->page($req, 'admin/audit', $this->vars(['rows' => array_slice($rows, 0, 100), 'verify' => $this->audit()->verify(), 'nav' => 'audit',
            'f' => $f, 'page' => $page, 'more' => $more,
            'actions' => array_column($this->app->db()->all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action')]));
    }

    private function auditExport(Request $req): Response
    {
        [$where, $params, $f] = $this->auditFilter($req);
        $this->audit()->record('admin', (int)$this->admin['id'], 'audit.export', null, null, array_filter($f), $req->ip());
        $rows = $this->app->db()->all(self::AUDIT_SELECT . " WHERE $where ORDER BY a.id DESC LIMIT 50000", $params);
        $out = [['id', 'time_utc', 'actor_type', 'actor', 'action', 'target_type', 'target_id', 'target_user', 'details', 'ip']];
        foreach ($rows as $r) {
            $out[] = [$r['id'], $r['created_at'], $r['actor_type'], $r['actor'], $r['action'], $r['target_type'], $r['target_id'], $r['target_user'], $r['details'], Ip::fromBinary($r['ip'])];
        }
        return $this->csv('audit-' . gmdate('Ymd-His') . '.csv', $out);
    }

    /** CSV with a UTF-8 BOM (Excel opens Persian text correctly); cells that look like formulas are neutralized. */
    private function csv(string $name, array $rows): Response
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($h, array_map(static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v, $r), ',', '"', '');
        }
        rewind($h);
        $body = (string)stream_get_contents($h);
        fclose($h);
        return new Response($body, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store']);
    }

    // ------------------------------------------------------------------ reports (non-content)
    private function reportDays(Request $req): int
    {
        $d = (int)$req->queryString('days');
        return in_array($d, [7, 30, 90, 365], true) ? $d : 30;
    }

    private function reportsPage(Request $req): Response
    {
        $days = $this->reportDays($req);
        $this->audit()->record('admin', (int)$this->admin['id'], 'report.view', null, null, ['days' => $days], $req->ip());
        $stats = new \PM\Admin\Stats($this->app->db());
        $rows = $stats->daily($days);
        $tot = array_fill_keys(array_keys(\PM\Admin\Stats::METRICS), 0);
        foreach ($rows as $r) {
            foreach ($tot as $k => $_) {
                $tot[$k] += (int)$r[$k];
            }
        }
        $tot['senders'] = (int)$this->app->db()->scalar('SELECT COUNT(DISTINCT sender_user_id) FROM messages WHERE created_at >= ?', [$rows[count($rows) - 1]['day'] . ' 00:00:00']);
        return $this->page($req, 'admin/reports', $this->vars(['rows' => $rows, 'tot' => $tot, 'days' => $days, 'nav' => 'reports', 'o' => $stats->overview()]));
    }

    private function reportsExport(Request $req): Response
    {
        $days = $this->reportDays($req);
        $this->audit()->record('admin', (int)$this->admin['id'], 'report.export', null, null, ['days' => $days], $req->ip());
        $rows = (new \PM\Admin\Stats($this->app->db()))->daily($days);
        $out = [array_merge(['day_utc'], array_keys(\PM\Admin\Stats::METRICS))];
        foreach ($rows as $r) {
            $out[] = array_values($r);
        }
        return $this->csv('report-' . $days . 'd-' . gmdate('Ymd') . '.csv', $out);
    }

    // ------------------------------------------------------------------ roles
    private function roleSave(Request $req, ?int $id): Response
    {
        try {
            $this->svc()->saveRole((int)$this->admin['id'], $id, $req->input('name'), array_filter((array)($req->post['perms'] ?? []), 'is_string'), $req->ip());
        } catch (UserError $e) {
            return $this->adminsPage($req, $e->getMessage());
        }
        return $this->back('/admins', 'role_saved');
    }

    private function roleDelete(Request $req): Response
    {
        try {
            $this->svc()->deleteRole((int)$this->admin['id'], (int)$req->param('id'), $req->ip());
        } catch (UserError $e) {
            return $this->adminsPage($req, $e->getMessage());
        }
        return $this->back('/admins', 'role_deleted');
    }

    private function adminRole(Request $req): Response
    {
        try {
            $this->svc()->setAdminRole((int)$this->admin['id'], (int)$req->param('id'), (int)$req->input('role_id'), $req->ip());
        } catch (UserError $e) {
            return $this->adminsPage($req, $e->getMessage());
        }
        return $this->back('/admins', 'saved');
    }

    // ------------------------------------------------------------------ backup
    private function backupPage(Request $req, ?string $error = null): Response
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $size = (int)$db->scalar('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
        return $this->page($req, 'admin/backup', $this->vars(['error' => $error, 'nav' => 'backup', 'msg' => $this->msg($req),
            'last' => (string)$s->get('backup.last_at'), 'remind' => $s->int('backup.reminder_days'), 'gzip' => \PM\Admin\Backup::gzipAvailable(),
            'db_size' => $size, 'root' => $this->app->root, 'retention' => $s->int('files.retention_days'),
            'history' => $db->all("SELECT a.created_at, ad.username FROM audit_logs a LEFT JOIN admin_users ad ON ad.id = a.actor_id WHERE a.action = 'backup.download' ORDER BY a.id DESC LIMIT 10")]));
    }

    private function backupDownload(Request $req): Response
    {
        try {
            $this->svc()->confirmPassword($this->admin, $req->input('password'), $req->ip());
        } catch (UserError $e) {
            return $this->backupPage($req, $e->getMessage());
        }
        $gzip = \PM\Admin\Backup::gzipAvailable();
        $this->audit()->record('admin', (int)$this->admin['id'], 'backup.download', null, null, ['gzip' => $gzip], $req->ip());
        $this->app->settings()->set('backup.last_at', Clock::now()->format('Y-m-d H:i:s'), (int)$this->admin['id']);
        $backup = new \PM\Admin\Backup($this->app->db(), $this->app->root . '/database/migrations');
        $name = 'backup-' . gmdate('Ymd-His') . ($gzip ? '.sql.gz' : '.sql');
        return Response::generated(fn (callable $out) => $backup->write($out, $gzip), [
            'Content-Type' => $gzip ? 'application/gzip' : 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    private function backupSettings(Request $req): Response
    {
        $d = (int)\PM\Support\Fa::toLatinDigits($req->input('reminder_days'));
        $d = max(0, min(365, $d));
        $this->app->settings()->set('backup.reminder_days', $d, (int)$this->admin['id']);
        $this->audit()->record('admin', (int)$this->admin['id'], 'settings.update', null, null, ['keys' => ['backup.reminder_days']], $req->ip());
        return $this->back('/backup', 'saved');
    }

    // ------------------------------------------------------------------ secret paths
    private function pathChange(Request $req): Response
    {
        $which = $req->input('which') === 'admin' ? 'ADMIN_PATH' : 'APP_PATH';
        try {
            $this->svc()->confirmPassword($this->admin, $req->input('password'), $req->ip());
            if ($req->input('confirm') !== 'تغییر') {
                throw new UserError('برای تأیید، کلمه «تغییر» را بنویسید.');
            }
            $file = $this->app->root . '/config/.env';
            $env = \PM\Support\Env::parseFile($file);
            do {
                $new = Random::string(16);
            } while ($new === ($env['APP_PATH'] ?? '') || $new === ($env['ADMIN_PATH'] ?? ''));
            $old = $env[$which] ?? '';
            $env[$which] = $new;
            $tmp = $file . '.tmp';
            if (@file_put_contents($tmp, \PM\Support\Env::render($env)) === false || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new UserError('نوشتن فایل config/.env ممکن نشد. مجوز نوشتن پوشه config را بررسی کنید.');
            }
        } catch (UserError $e) {
            return $this->page($req, 'admin/error', $this->vars(['error' => $e->getMessage(), 'back' => '/system']), 422);
        }
        $this->audit()->record('admin', (int)$this->admin['id'], 'system.path_change', null, null, ['which' => strtolower(substr($which, 0, -5))], $req->ip());
        $host = 'https://' . $req->host();
        if ($which === 'ADMIN_PATH') {
            return Response::html($this->view->render('admin/path-changed', ['url' => $host . '/' . $new . '/']))->forgetCookie(AdminService::COOKIE);
        }
        return $this->back('/system', 'path_app');
    }

    // ------------------------------------------------------------------ logo
    private function logoUpload(Request $req): Response
    {
        $f = $_FILES['logo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return $this->settingsPage($req, 'فایل لوگو انتخاب یا آپلود نشد.');
        }
        $dim = @getimagesize($f['tmp_name']);
        if ($f['size'] > 1048576 || file_get_contents($f['tmp_name'], false, null, 0, 8) !== "\x89PNG\r\n\x1a\n" || !$dim || $dim[2] !== IMAGETYPE_PNG
            || $dim[0] !== $dim[1] || $dim[0] < 192 || $dim[0] > 1024) {
            return $this->settingsPage($req, 'لوگو باید فایل PNG مربعی بین ۱۹۲ تا ۱۰۲۴ پیکسل (ترجیحاً ۵۱۲×۵۱۲) و حداکثر ۱ مگابایت باشد.');
        }
        $dir = $this->app->root . '/storage/branding';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return $this->settingsPage($req, 'پوشه storage/branding قابل ساخت نیست.');
        }
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/logo.png')) {
            return $this->settingsPage($req, 'ذخیره لوگو ممکن نشد؛ مجوز پوشه storage را بررسی کنید.');
        }
        @chmod($dir . '/logo.png', 0644);
        $this->app->settings()->set('app.logo', bin2hex(random_bytes(4)), (int)$this->admin['id']);
        $this->audit()->record('admin', (int)$this->admin['id'], 'settings.logo_upload', null, null, ['size' => $dim[0]], $req->ip());
        return $this->back('/settings', 'logo_saved');
    }

    private function logoDelete(Request $req): Response
    {
        @unlink($this->app->root . '/storage/branding/logo.png');
        $this->app->settings()->set('app.logo', '', (int)$this->admin['id']);
        $this->audit()->record('admin', (int)$this->admin['id'], 'settings.logo_delete', null, null, [], $req->ip());
        return $this->back('/settings', 'logo_deleted');
    }

    private function systemPage(Request $req): Response
    {
        $checks = \PM\Install\HostCheck::run($this->app->root, $req); // live: the host may have changed since installation
        $runs = $this->app->db()->all('SELECT job, trigger_source, started_at, finished_at, status, stats FROM cron_runs ORDER BY id DESC LIMIT 20');
        $php = PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'apache') && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/local/bin/php';
        $fontDir = $this->app->root . '/app/resources/assets/fonts/';
        $fonts = [];
        foreach (['300 Light' => ['IRANSans-Light', 'IRANSansWeb_Light'], '400 Regular' => ['IRANSans-Regular', 'IRANSansWeb'],
                  '500 Medium' => ['IRANSans-Medium', 'IRANSansWeb_Medium'], '700 Bold' => ['IRANSans-Bold', 'IRANSansWeb_Bold']] as $w => $names) {
            $found = null;
            foreach ($names as $n) {
                if (is_file($fontDir . $n . '.woff2')) {
                    $found = $n . '.woff2';
                    break;
                }
            }
            $fonts[$w] = $found;
        }
        $host = 'https://' . $req->host();
        return $this->page($req, 'admin/system', $this->vars(['checks' => $checks, 'runs' => $runs, 'nav' => 'system', 'fonts' => $fonts, 'msg' => $this->msg($req),
            'paths' => AdminService::can($this->admin, 'system.paths.manage') ? ['app' => $host . '/' . $this->app->env('APP_PATH') . '/', 'admin' => $host . '/' . $this->app->env('ADMIN_PATH') . '/'] : null,
            'cron' => '*/15 * * * * ' . $php . ' ' . $this->app->root . '/app/Cli/cron.php maintenance']));
    }

    private function accountPage(Request $req, ?string $error = null, ?string $secret = null): Response
    {
        $svc = $this->svc();
        $secret ??= (int)$this->admin['totp_enabled'] === 0 ? $svc->pendingTotpSecret($this->admin) : null;
        $uri = $secret ? Totp::uri($secret, (string)$this->admin['username'], 'Admin') : null;
        return $this->page($req, 'admin/account', $this->vars(['error' => $error, 'secret' => $secret, 'uri' => $uri, 'nav' => 'account',
            'msg' => $this->msg($req)]));
    }

    private function accountPassword(Request $req): Response
    {
        try {
            $this->svc()->changePassword($this->admin, $req->input('current'), $req->input('new'), $req->input('confirm'), $req->ip());
        } catch (UserError $e) {
            return $this->accountPage($req, $e->getMessage());
        }
        return $this->back('/account', 'pw');
    }

    private function totpStart(Request $req): Response
    {
        if ((int)$this->admin['totp_enabled'] === 1) {
            return $this->redirect('/account');
        }
        return $this->accountPage($req, null, $this->svc()->startTotp($this->admin));
    }

    private function totpConfirm(Request $req): Response
    {
        try {
            $this->svc()->confirmTotp($this->admin, $req->input('code'), $req->ip());
        } catch (UserError $e) {
            return $this->accountPage($req, $e->getMessage());
        }
        return $this->back('/account', 'totp_on');
    }

    private function totpDisable(Request $req): Response
    {
        try {
            $this->svc()->disableTotp($this->admin, $req->input('password'), $req->ip());
        } catch (UserError $e) {
            return $this->accountPage($req, $e->getMessage());
        }
        return $this->back('/account', 'totp_off');
    }
}
