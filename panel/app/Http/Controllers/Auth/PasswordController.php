<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function edit(): View
    {
        return view('profile.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers(), 'different:current_password'],
        ], [], ['current_password' => 'رمز فعلی', 'password' => 'رمز جدید']);

        $user = $request->user();
        $user->password = $data['password'];
        $user->password_changed_at = now();
        $user->save();

        // Sign out every other session of this user.
        Auth::logoutOtherDevices($data['password']);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();

        $this->audit->log('auth.password_changed', $user, 'تغییر رمز عبور توسط خود کاربر؛ سایر نشست‌ها خارج شدند');

        return back()->with('success', 'رمز عبور تغییر کرد و از سایر دستگاه‌ها خارج شدید.');
    }

    /** Re-authentication screen for sensitive operations (route "password.confirm"). */
    public function showConfirm(): View
    {
        return view('auth.confirm-password');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [], ['password' => 'رمز عبور']);
        $request->session()->passwordConfirmed();
        $this->audit->log('auth.password_confirmed', $request->user(), 'تأیید مجدد رمز برای عملیات حساس');

        return redirect()->intended(route('dashboard'));
    }
}
