<?php

namespace App\Http\Controllers\Admin;

use App\Bots\BotApiException;
use App\Bots\Platforms;
use App\Bots\RelayCode;
use App\Bots\TelegramLike;
use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Services\AuditLogger;
use App\Services\Settings;
use App\Support\Paste;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class BotsController extends Controller
{
    public const HOSTS = ['telegram' => 'https://api.telegram.org', 'bale' => 'https://tapi.bale.ai', 'rubika' => 'https://botapi.rubika.ir'];

    public function __construct(private Platforms $platforms, private AuditLogger $audit, private Settings $settings) {}

    public function index(): View
    {
        $this->authorize('bots.view');
        $bots = collect(array_keys(Bot::PLATFORMS))->mapWithKeys(fn ($p) => [$p => Bot::for($p)]);

        return view('bots.index', [
            'bots' => $bots,
            'counts' => BotUser::query()->selectRaw('bot_id, count(*) c, sum(case when user_id is not null then 1 else 0 end) linked')->groupBy('bot_id')->get()->keyBy('bot_id'),
            'outbox' => ['pending' => BotOutbox::query()->where('status', 'pending')->count(), 'failed' => BotOutbox::query()->where('status', 'failed')->count()],
            'welcome' => $this->settings->get('bots.welcome_text'),
            'support' => $this->settings->get('bots.support_text'),
            'probe' => session('probe'),
        ]);
    }

    /** Sensitive (token): requires recent password confirmation via the route group. */
    public function update(Request $request, string $platform): RedirectResponse
    {
        $this->authorize('bots.sensitive');
        $bot = $this->bot($platform);
        // Pasting on phones sometimes adds the text twice or brings hidden characters: keep the clean value.
        $request->merge([
            'token' => Paste::clean((string) $request->input('token', '')),
            'relay_url' => Paste::url((string) $request->input('relay_url', '')),
        ]);
        $d = $request->validate([
            'token' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9:_\-]+$/'],
            'is_active' => ['nullable', 'boolean'],
            'relay_mode' => ['nullable', 'in:direct,cloudflare,gas'],
            'receive' => ['nullable', 'in:webhook,poll'],
            'relay_url' => ['nullable', 'url:https', 'max:500'],
        ], ['token.regex' => 'توکن فقط حروف انگلیسی، عدد و «:_-» دارد.'], ['token' => 'توکن', 'relay_url' => 'آدرس واسط']);
        $before = ['active' => $bot->is_active, 'relay_mode' => $bot->relay_mode, 'relay_url' => $bot->relay_url];
        if (($d['token'] ?? '') !== '') {
            $bot->token = $d['token'];
            $bot->bot_username = null;
            $bot->webhook_set_at = null;
        }
        $bot->is_active = (bool) ($d['is_active'] ?? false);
        if ($platform === 'telegram') {
            $bot->relay_mode = $d['relay_mode'] ?? 'direct';
            $this->settings->set('bots.telegram_receive', $d['receive'] ?? 'webhook');
            $bot->relay_url = $bot->relay_mode === 'direct' ? null : ($d['relay_url'] ?? null);
            if ($bot->relay_mode !== 'direct' && ! $bot->relay_url) {
                return back()->withInput()->withErrors(['relay_url' => 'برای حالت واسط، آدرس واسط لازم است.']);
            }
        }
        $bot->save();
        $after = ['active' => $bot->is_active, 'relay_mode' => $bot->relay_mode, 'relay_url' => $bot->relay_url];
        $diff = AuditLogger::diff($before, $after);
        if (($d['token'] ?? '') !== '') {
            $diff['token'] = ['***', '***'];
        }
        $this->audit->log('bots.update', $bot, 'تنظیمات ربات '.$bot->label(), $diff);

        return back()->with('success', 'تنظیمات ربات '.$bot->label().' ذخیره شد.');
    }

    public function texts(Request $request): RedirectResponse
    {
        $this->authorize('bots.update');
        $d = $request->validate(['welcome' => ['nullable', 'string', 'max:1000'], 'support' => ['nullable', 'string', 'max:1000']]);
        $this->settings->set('bots.welcome_text', $d['welcome'] ?? null);
        $this->settings->set('bots.support_text', $d['support'] ?? null);
        $this->audit->log('bots.texts', null, 'متن‌های ربات');

        return back()->with('success', 'متن‌های ربات ذخیره شد.');
    }

    /** getMe + register the webhook (or the relay) with the platform. */
    public function connect(string $platform): RedirectResponse
    {
        $this->authorize('bots.update');
        $bot = $this->bot($platform);
        try {
            $api = $this->platforms->for($bot);
            $me = $api->getMe();
            $url = $api->setWebhook();
        } catch (BotApiException $e) {
            $bot->forceFill(['last_error_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return back()->with('error', $e->getMessage())->with('probe', [$platform => ['raw' => null]]);
        }
        $bot->forceFill(['bot_username' => $me['username'], 'webhook_set_at' => now(), 'last_error' => null, 'last_error_at' => null])->save();
        $warn = null;
        if ($url !== 'polling' && $api instanceof TelegramLike) {
            try {
                $info = $api->webhookInfo();
                if (! empty($info['last_error_message'])) {
                    $warn = 'تلگرام نتوانسته پیام را به هاست برساند: '.mb_substr((string) $info['last_error_message'], 0, 150).'. اگر ادامه داشت، «دریافت پیام» را روی «دوره‌ای» بگذارید.';
                }
            } catch (BotApiException) {
            }
        }
        $this->audit->log('bots.connect', $bot, 'اتصال وبهوک ربات '.$bot->label());

        return back()->with('success', 'ربات '.$bot->label().($me['username'] ? ' (@'.$me['username'].')' : '').' وصل شد. '
            .($url === 'polling' ? 'پیام‌ها از مسیر واسط هر چند ثانیه توسط پنل دریافت می‌شود (Cron هر دقیقه لازم است).' : 'آدرس دریافت پیام‌ها ثبت شد.'))
            ->with('probe', [$platform => ['raw' => $me['raw'], 'url' => preg_replace('#/hook/([a-z]+)/[A-Za-z0-9]+#', '/hook/$1/…', $url)]])
            ->with('warning', $warn);
    }

    /** Can this host reach each platform's API at all? (no token is sent) */
    public function reach(): RedirectResponse
    {
        $this->authorize('bots.view');
        $out = [];
        foreach (self::HOSTS as $p => $url) {
            $t = microtime(true);
            try {
                $r = Http::timeout(8)->connectTimeout(6)->get($url);
                $out[$p] = ['ok' => true, 'detail' => 'HTTP '.$r->status(), 'ms' => (int) ((microtime(true) - $t) * 1000)];
            } catch (Throwable $e) {
                $out[$p] = ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 160), 'ms' => (int) ((microtime(true) - $t) * 1000)];
            }
        }
        $tg = Bot::for('telegram');
        if ($tg->relay_mode !== 'direct' && $tg->relay_url) {
            $t = microtime(true);
            try {
                $r = Http::timeout(10)->connectTimeout(6)->get($tg->relay_url);
                $out['relay'] = ['ok' => true, 'detail' => 'HTTP '.$r->status(), 'ms' => (int) ((microtime(true) - $t) * 1000)];
            } catch (Throwable $e) {
                $out['relay'] = ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 160), 'ms' => (int) ((microtime(true) - $t) * 1000)];
            }
        }

        return back()->with('reach', $out);
    }

    public function relay(string $kind): View
    {
        $this->authorize('bots.sensitive');
        $bot = Bot::for('telegram');
        abort_unless(in_array($kind, ['cloudflare', 'gas'], true), 404);

        return view('bots.relay', ['bot' => $bot, 'kind' => $kind, 'code' => $kind === 'cloudflare' ? RelayCode::cloudflare($bot) : RelayCode::appsScript($bot)]);
    }

    public function sendTest(Request $request, string $platform): RedirectResponse
    {
        $this->authorize('bots.update');
        $bot = $this->bot($platform);
        $n = 0;
        foreach (BotUser::query()->where('bot_id', $bot->id)->where('user_id', $request->user()->id)->get() as $bu) {
            BotOutbox::query()->create(['bot_id' => $bot->id, 'chat_id' => $bu->chat_id, 'text' => '🔔 پیام آزمایشی پنل — '.jdate(now())]);
            $n++;
        }

        return back()->with($n ? 'success' : 'error', $n ? 'پیام آزمایشی در صف ارسال قرار گرفت (تا یک دقیقه دیگر با Cron ارسال می‌شود).' : 'ابتدا از صفحه «اتصال به ربات» حساب خودتان را به این ربات وصل کنید.');
    }

    public function rotateSecret(string $platform): RedirectResponse
    {
        $this->authorize('bots.sensitive');
        $bot = $this->bot($platform);
        $bot->forceFill(['hook_secret' => Str::random(40), 'webhook_set_at' => null])->save();
        $this->audit->log('bots.rotate', $bot, 'تعویض آدرس مخفی ربات '.$bot->label());

        return back()->with('success', 'آدرس مخفی عوض شد. دکمه «اتصال» را دوباره بزنید'.($platform === 'telegram' && $bot->relay_mode !== 'direct' ? ' و کد واسط را هم به‌روز کنید' : '').'.');
    }

    private function bot(string $platform): Bot
    {
        abort_unless(isset(Bot::PLATFORMS[$platform]), 404);

        return Bot::for($platform);
    }
}
