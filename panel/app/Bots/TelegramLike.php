<?php

namespace App\Bots;

use App\Models\Bot;
use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram Bot API, also used for Bale (tapi.bale.ai mirrors the Telegram Bot API).
 * Telegram can go through a relay outside Iran: a Cloudflare Worker or a Google Apps Script web app.
 */
class TelegramLike implements Platform
{
    public function __construct(private Bot $bot) {}

    public function parse(array $p): ?Incoming
    {
        $m = $p['message'] ?? null;
        if (! isset($p['update_id']) || ! is_array($m) || ($m['chat']['type'] ?? 'private') !== 'private' || ! isset($m['text'], $m['chat']['id'])) {
            return null;
        }

        return new Incoming('u:'.$p['update_id'], (string) $m['chat']['id'], (string) $m['text'],
            $m['from']['first_name'] ?? null, $m['from']['username'] ?? null, isset($m['message_id']) ? (string) $m['message_id'] : null);
    }

    public function send(string $chatId, string $text, ?array $keyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($keyboard === []) {
            $params['reply_markup'] = ['remove_keyboard' => true];
        } elseif ($keyboard !== null) {
            $params['reply_markup'] = ['keyboard' => array_map(fn ($row) => array_map(fn ($l) => ['text' => $l], $row), $keyboard), 'resize_keyboard' => true];
        }
        $this->call('sendMessage', $params);
    }

    public function sendPhoto(string $chatId, string $png, string $caption = ''): bool
    {
        if ($this->mode() === 'gas') {
            return false;   // Apps Script relay forwards form fields only
        }
        $token = (string) $this->bot->token;
        $url = match ($this->mode()) {
            'cloudflare' => rtrim((string) $this->bot->relay_url, '/').'/bot'.$token.'/sendPhoto',
            default => $this->base().$token.'/sendPhoto',
        };
        try {
            $res = Http::timeout(30)->connectTimeout(10)->attach('photo', $png, 'qr.png', ['Content-Type' => 'image/png'])
                ->post($url, ['chat_id' => $chatId, 'caption' => mb_substr($caption, 0, 1000)]);
        } catch (ConnectionException) {
            return false;
        }

        return (bool) (json_decode((string) $res->body(), true)['ok'] ?? false);
    }

    public function getMe(): array
    {
        $r = $this->call('getMe', []);

        return ['username' => $r['username'] ?? null, 'raw' => $r];
    }

    /** True when Telegram goes through a relay: updates are then fetched by the panel (getUpdates), no webhook. */
    public function polls(): bool
    {
        return $this->mode() !== 'direct' && app(Settings::class)->get('bots.telegram_receive', 'webhook') === 'poll';
    }

    /** Telegram's view of the webhook (pending updates, last delivery error). */
    public function webhookInfo(): array
    {
        $r = $this->call('getWebhookInfo', []);

        return is_array($r) ? $r : [];
    }

    /** @return list<array> raw updates */
    public function getUpdates(int $offset, int $timeout): array
    {
        $r = $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message']], $timeout + 15);

        return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
    }

    public function setWebhook(): string
    {
        if ($this->polls()) {
            // Through a relay the panel fetches messages itself (every few seconds, by cron): no webhook may be set.
            $this->call('deleteWebhook', ['drop_pending_updates' => false]);

            return 'polling';
        }
        // Telegram delivers straight to the panel (even when sending goes through a relay).
        $url = $this->bot->hookUrl();
        $params = ['url' => $url, 'allowed_updates' => ['message'], 'drop_pending_updates' => false];
        if ($this->bot->platform === 'telegram') {
            $params['secret_token'] = $this->bot->relaySecret();   // checked by the panel
        }
        $this->call('setWebhook', $params);

        return $url;
    }

    public function deleteMessage(string $chatId, string $messageId): void
    {
        try {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => (int) $messageId]);
        } catch (Throwable) {
        }
    }

    private function mode(): string
    {
        return $this->bot->platform === 'telegram' ? $this->bot->relay_mode : 'direct';
    }

    /**
     * Relays (same as the news plugin on this host):
     *   Cloudflare Worker: transparent proxy, {worker}/bot<token>/<method> is api.telegram.org/bot<token>/<method>.
     *   Google Apps Script: POST form fields bot_token, method, args (JSON) to the /exec URL.
     *
     * @return mixed the "result" field
     */
    private function call(string $method, array $params, int $timeout = 20): mixed
    {
        $token = (string) $this->bot->token;
        if ($token === '') {
            throw new BotApiException('توکن ربات تنظیم نشده است.');
        }
        try {
            $http = Http::timeout($timeout)->connectTimeout(10)->acceptJson();
            $res = match ($this->mode()) {
                'cloudflare' => $http->asJson()->post(rtrim((string) $this->bot->relay_url, '/').'/bot'.$token.'/'.$method, (object) $params),
                // nested values (reply_markup, allowed_updates) go as JSON strings: Telegram accepts both forms, and the
                // plugin's script re-posts the args as a flat form
                'gas' => $http->asForm()->post((string) $this->bot->relay_url, ['bot_token' => $token, 'method' => $method,
                    'args' => json_encode((object) array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $params), JSON_UNESCAPED_UNICODE)]),
                default => $http->asJson()->post($this->base().$token.'/'.$method, (object) $params),
            };
        } catch (ConnectionException $e) {
            throw new BotApiException('اتصال به سرور '.$this->bot->label().' برقرار نشد: '.$this->clean($e->getMessage()));
        }
        $j = json_decode((string) $res->body(), true);
        if (! is_array($j) || ! ($j['ok'] ?? false)) {
            $desc = is_array($j) ? ($j['description'] ?? '') : '';
            if (! is_array($j) && $this->mode() !== 'direct') {
                $desc = 'پاسخ واسط JSON تلگرام نیست؛ کد Worker / Apps Script یا آدرس آن را بررسی کنید ('.mb_substr(trim(strip_tags((string) $res->body())), 0, 80).')';
            }
            throw new BotApiException('پاسخ خطا از '.$this->bot->label().' (HTTP '.$res->status().')'.($desc !== '' ? ': '.$this->clean((string) $desc) : ''));
        }

        return $j['result'] ?? null;
    }

    private function base(): string
    {
        if ($o = config('panel.bots_api_override')) {
            return rtrim($o, '/').'/'.$this->bot->platform.'/bot';
        }

        return $this->bot->platform === 'bale' ? 'https://tapi.bale.ai/bot' : 'https://api.telegram.org/bot';
    }

    private function clean(string $s): string
    {
        $t = (string) $this->bot->token;

        return mb_substr($t !== '' ? str_replace($t, '***', $s) : $s, 0, 300);
    }
}
