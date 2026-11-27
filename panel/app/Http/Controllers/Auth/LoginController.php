<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Captcha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Real bcrypt hash of a random string: used to equalise timing for unknown usernames. */
    private const DUMMY_HASH = '$2y$12$bzSprICNi5gTN0hqxuoDke3rRFMwDfsKa4nficwWjS0NkgB2Vhyjy';

    public function __construct(private AuditLogger $audit) {}

    public function show(): View
    {
        return view('auth.login', ['captcha' => Captcha::enabled()]);
    }

    /** Captcha image; every request issues a new code for this session. */
    public function captcha(Request $request): Response
    {
        abort_unless(Captcha::enabled(), 404);
        $code = Captcha::issue($request->session());
        $png = Captcha::png($code);
        abort_if($png === null, 503, 'افزونه GD در PHP هاست فعال نیست.');

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store, no-cache, must-revalidate', 'Pragma' => 'no-cache']);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:200'],
            'captcha' => [Captcha::enabled() ? 'required' : 'nullable', 'string', 'max:10'],
        ], ['captcha.required' => 'کد امنیتی داخل تصویر را وارد کنید.'], ['username' => 'نام کاربری', 'password' => 'رمز عبور', 'captcha' => 'کد امنیتی']);

        if (Captcha::enabled() && ! Captcha::check($request->session(), $data['captcha'] ?? null)) {
            throw ValidationException::withMessages(['captcha' => 'کد امنیتی درست نیست؛ کد تصویر جدید را وارد کنید.']);
        }

        $key = 'login:'.mb_strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'username' => 'تلاش‌های ناموفق زیاد بود. '.fa((string) ceil($seconds / 60)).' دقیقه دیگر دوباره امتحان کنید.',
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('username', $data['username'])->first();
        // Always run a hash check to keep timing similar for unknown usernames.
        $ok = $user
            ? Hash::check($data['password'], $user->password)
            : Hash::check($data['password'], self::DUMMY_HASH);

        if (! $ok || ! $user) {
            RateLimiter::hit($key, 15 * 60);
            $this->audit->log('auth.login_failed', $user, 'ورود ناموفق برای «'.$data['username'].'»', actorLabel: $user?->username ?? 'unknown');
            throw ValidationException::withMessages(['username' => 'نام کاربری یا رمز عبور نادرست است.']);
        }
        if (! $user->is_active) {
            RateLimiter::hit($key, 15 * 60);
            $this->audit->log('auth.login_blocked', $user, 'تلاش ورود حساب غیرفعال', actor: $user);
            throw ValidationException::withMessages(['username' => 'این حساب غیرفعال است.']);
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        if (Hash::needsRehash($user->password)) {
            $user->password = $data['password'];
        }
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->log('auth.login', $user, 'ورود موفق', actor: $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            $this->audit->log('auth.logout', $user, 'خروج', actor: $user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
