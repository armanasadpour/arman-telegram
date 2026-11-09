<?php
declare(strict_types=1);

namespace PM\Controllers;

use PM\Auth\Csrf;
use PM\Auth\OtpService;
use PM\Auth\PasswordLogin;
use PM\Auth\UserSessions;
use PM\Http\Request;
use PM\Http\Response;
use PM\Http\Router;
use PM\Infra\Sms\SmsFactory;
use PM\Support\Ip;
use PM\Support\Phone;
use PM\Support\UserError;
use PM\Users\UserService;

/** Everything under the secret APP_PATH. The login page is deliberately anonymous. */
final class AppController extends Controller
{
    private ?array $user = null;

    public function handle(Request $req): Response
    {
        if (str_starts_with($req->path, '/api/')) {
            return (new ApiController($this->app, $this->base))->handle($req);
        }
        $r = new Router();
        $r->get('/assets/{file}', fn (Request $q) => $this->asset($q, $q->param('file')));
        $r->get('/assets/fonts/{file}', fn (Request $q) => $this->asset($q, 'fonts/' . $q->param('file')));
        $r->get('/', fn (Request $q) => $this->home($q));
        $r->post('/login', fn (Request $q) => $this->guard($q) ?? $this->passwordLogin($q));
        $r->post('/otp/request', fn (Request $q) => $this->guard($q) ?? $this->otpRequest($q));
        $r->get('/otp', fn (Request $q) => $this->otpForm($q));
        $r->post('/otp/verify', fn (Request $q) => $this->guard($q) ?? $this->otpVerify($q));
        $r->get('/password', fn (Request $q) => $this->authed($q, fn () => $this->passwordForm($q), true));
        $r->post('/password', fn (Request $q) => $this->guard($q) ?? $this->authed($q, fn () => $this->passwordChange($q), true));
        $r->get('/sessions', fn (Request $q) => $this->authed($q, fn () => $this->sessionsPage($q)));
        $r->post('/sessions/revoke', fn (Request $q) => $this->guard($q) ?? $this->authed($q, fn () => $this->sessionRevoke($q)));
        $r->post('/sessions/revoke-others', fn (Request $q) => $this->guard($q) ?? $this->authed($q, fn () => $this->sessionRevokeOthers($q)));
        $r->post('/logout', fn (Request $q) => $this->guard($q) ?? $this->logout($q));
        return $r->dispatch($req);
    }

    // ------------------------------------------------------------------ helpers
    private function sessions(): UserSessions
    {
        return new UserSessions($this->app->db());
    }

    private function method(): string
    {
        return (string)$this->app->settings()->get('auth.method');
    }

    private function guard(Request $req): ?Response
    {
        return Csrf::valid($req) ? null : $this->loginPage($req, 'نشست فرم منقضی شده است؛ دوباره تلاش کنید.', 400);
    }

    private function current(Request $req): ?array
    {
        return $this->user ??= $this->sessions()->validate($req->cookie(UserSessions::COOKIE));
    }

    private function authed(Request $req, callable $fn, bool $allowMustChange = false): Response
    {
        $u = $this->current($req);
        if ($u === null) {
            return $this->redirect('/');
        }
        if (!$allowMustChange && (int)$u['must_change_password'] === 1) {
            return $this->redirect('/password');
        }
        return $fn();
    }


    private function loginPage(Request $req, ?string $error = null, int $status = 200, string $username = ''): Response
    {
        return $this->page($req, 'app/login', ['method' => $this->method(), 'error' => $error, 'username' => $username], $status);
    }

    private function startSession(Request $req, array $user): Response
    {
        $days = $this->app->settings()->int('session.days');
        $token = $this->sessions()->create((int)$user['id'], $req->ip(), $req->userAgent(), $days);
        $res = $this->redirect((int)$user['must_change_password'] === 1 ? '/password' : '/');
        $res->cookie(UserSessions::COOKIE, $token, ['expires' => time() + $days * 86400, 'samesite' => 'Lax']);
        return $res->forgetCookie(OtpService::COOKIE);
    }

    private function otp(): OtpService
    {
        $s = $this->app->settings();
        return new OtpService($this->app->db(), $s, $this->limiter(), $this->app->key(),
            SmsFactory::make($s, $this->app->root . '/storage/logs/sms-test.log'));
    }

    // ------------------------------------------------------------------ pages
    private function home(Request $req): Response
    {
        $u = $this->current($req);
        if ($u === null) {
            return $this->loginPage($req);
        }
        if ((int)$u['must_change_password'] === 1) {
            return $this->redirect('/password');
        }
        return $this->page($req, 'app/home', ['user' => $u, 'name' => (string)$this->app->settings()->get('app.internal_name')]);
    }

    private function passwordLogin(Request $req): Response
    {
        $method = $this->method();
        if (!in_array($method, ['password', 'password_otp'], true)) {
            return $this->loginPage($req);
        }
        $username = $req->input('username');
        $login = new PasswordLogin($this->app->db(), $this->app->settings(), $this->limiter(), $this->audit());
        [$result, $user] = $login->attempt($username, $req->input('password'), $req->ip());
        if ($result === PasswordLogin::RATE_LIMITED) {
            return $this->loginPage($req, 'تلاش‌های زیادی انجام شده است. چند دقیقه بعد دوباره تلاش کنید.', 429, $username);
        }
        if ($result === PasswordLogin::INVALID) {
            return $this->loginPage($req, 'نام کاربری یا رمز عبور نادرست است.', 200, $username);
        }
        if ($result === PasswordLogin::INACTIVE) {
            return $this->loginPage($req, 'حساب شما فعال نیست. با مدیر سامانه تماس بگیرید.', 200, $username);
        }
        if ($method === 'password_otp') {
            if (empty($user['phone_e164'])) {
                return $this->loginPage($req, 'برای حساب شما شماره موبایل ثبت نشده است. با مدیر سامانه تماس بگیرید.');
            }
            try {
                $challenge = $this->otp()->start((string)$user['phone_e164'], (int)$user['id'], 'second_factor', $req->ip());
            } catch (UserError $e) {
                return $this->loginPage($req, $e->getMessage(), 200, $username);
            }
            return $this->redirect('/otp')->cookie(OtpService::COOKIE, $challenge, ['samesite' => 'Strict', 'expires' => time() + 600]);
        }
        return $this->startSession($req, $user);
    }

    private function otpRequest(Request $req): Response
    {
        if ($this->method() !== 'otp') {
            return $this->loginPage($req);
        }
        $phone = Phone::normalize($req->input('phone'));
        if ($phone === null) {
            return $this->loginPage($req, 'شماره موبایل معتبر نیست.');
        }
        $users = new UserService($this->app->db(), $this->audit(), $this->sessions());
        $user = $users->findActiveByPhone($phone);
        try {
            $challenge = $this->otp()->start($phone, $user === null ? null : (int)$user['id'], 'login', $req->ip());
        } catch (UserError $e) {
            return $this->loginPage($req, $e->getMessage());
        }
        return $this->redirect('/otp')->cookie(OtpService::COOKIE, $challenge, ['samesite' => 'Strict', 'expires' => time() + 600]);
    }

    private function otpForm(Request $req, ?string $error = null): Response
    {
        $pending = $this->otp()->pending($req->cookie(OtpService::COOKIE));
        if ($pending === null) {
            return $this->redirect('/');
        }
        return $this->page($req, 'app/otp', ['error' => $error, 'phone' => Phone::mask($pending['phone_e164'])]);
    }

    private function otpVerify(Request $req): Response
    {
        $challenge = $req->cookie(OtpService::COOKIE);
        $otp = $this->otp();
        if ($otp->pending($challenge) === null) {
            return $this->loginPage($req, 'کد منقضی شده یا تعداد تلاش‌ها تمام شده است. دوباره وارد شوید.');
        }
        $userId = $otp->verify($challenge, $req->input('code'));
        if ($userId === null) {
            return $otp->pending($challenge) === null
                ? $this->loginPage($req, 'کد منقضی شده یا تعداد تلاش‌ها تمام شده است. دوباره وارد شوید.')
                : $this->otpForm($req, 'کد وارد‌شده نادرست است.');
        }
        $users = new UserService($this->app->db(), $this->audit(), $this->sessions());
        $user = $users->get($userId);
        if ($user === null || $user['status'] !== 'active') {
            return $this->loginPage($req, 'حساب شما فعال نیست. با مدیر سامانه تماس بگیرید.');
        }
        return $this->startSession($req, $user);
    }

    private function passwordForm(Request $req, ?string $error = null): Response
    {
        return $this->page($req, 'app/password', ['user' => $this->user, 'error' => $error, 'forced' => (int)$this->user['must_change_password'] === 1]);
    }

    private function passwordChange(Request $req): Response
    {
        $users = new UserService($this->app->db(), $this->audit(), $this->sessions());
        try {
            $users->changeOwnPassword($this->user, $req->input('current'), $req->input('new'), $req->input('confirm'), (int)$this->user['session_id']);
        } catch (UserError $e) {
            return $this->passwordForm($req, $e->getMessage());
        }
        return $this->redirect('/?changed=1');
    }

    private function sessionsPage(Request $req): Response
    {
        $list = $this->sessions()->listForUser((int)$this->user['id']);
        foreach ($list as &$s) {
            $s['ip_display'] = Ip::displayPrefix($s['ip_prefix']);
        }
        return $this->page($req, 'app/sessions', ['user' => $this->user, 'sessions' => $list, 'current' => $this->user['session_public_id']]);
    }

    private function sessionRevoke(Request $req): Response
    {
        $id = $req->input('session');
        if ($id === $this->user['session_public_id']) {
            return $this->redirect('/sessions');
        }
        $this->sessions()->revokeByPublicId((int)$this->user['id'], $id, 'user_revoke');
        return $this->redirect('/sessions');
    }

    private function sessionRevokeOthers(Request $req): Response
    {
        $this->sessions()->revokeAll((int)$this->user['id'], 'user_revoke', (int)$this->user['session_id']);
        return $this->redirect('/sessions');
    }

    private function logout(Request $req): Response
    {
        $u = $this->current($req);
        if ($u !== null) {
            $this->sessions()->revokeById((int)$u['session_id'], 'logout');
        }
        return $this->redirect('/')->forgetCookie(UserSessions::COOKIE)
            ->header('Clear-Site-Data', '"cache"');
    }


}
