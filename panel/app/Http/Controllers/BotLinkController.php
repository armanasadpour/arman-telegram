<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotLinkCode;
use App\Models\BotUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Any panel user (reseller or staff) links their messenger chats with a one-time code. */
class BotLinkController extends Controller
{
    public function show(Request $request): View
    {
        return view('bots.link', [
            'bots' => Bot::query()->where('is_active', true)->get()->filter->isConfigured(),
            'linked' => BotUser::query()->with('bot')->where('user_id', $request->user()->id)->get(),
            'code' => session('link_code'),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        BotLinkCode::query()->where('user_id', $request->user()->id)->whereNull('used_at')->delete();
        BotLinkCode::query()->create(['user_id' => $request->user()->id, 'code_hash' => BotLinkCode::hash($code), 'expires_at' => now()->addMinutes(15)]);

        return back()->with('link_code', $code);
    }

    public function unlink(Request $request, BotUser $botUser): RedirectResponse
    {
        abort_unless($botUser->user_id === $request->user()->id, 404);
        $botUser->forceFill(['user_id' => null])->save();

        return back()->with('success', 'اتصال قطع شد.');
    }
}
