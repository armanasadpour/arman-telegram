<?php

namespace App\Bots;

use App\Models\Bot;
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

    public function getMe(): array
    {
        $r = $this->call('getMe', []);

        return ['username' => $r['username'] ?? null, 'raw' => $r];
    }

    public function setWebhook(): string
    {
        $url = match ($this->mode()) {
            'cloudflare' => rtrim((string) $this->bot->relay_url, '/').'/hook',
            'gas' => (string) $this->bot->relay_url.'?s='.$this->bot->relaySecret(),
            default => $this->bot->hookUrl(),
        };
        $params = ['url' => $url, 'allowed_updates' => ['message']];
        if ($this->bot->platform === 'telegram' && $this->mode() !== 'gas') {
            $params['secret_token'] = $this->bot->relaySecret();   // checked by the worker, or by the panel when direct
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

    /** @return mixed the "result" field */
    private function call(string $method, array $params): mixed
    {
        $token = (string) $this->bot->token;
        if ($token === '') {
            throw new BotApiException('توکن ربات تنظیم نشده است.');
        }
        try {
            $http = Http::timeout(15)->connectTimeout(8)->acceptJson()->asJson();
            $res = match ($this->mode()) {
                'cloudflare' => $http->withHeaders(['X-Relay-Secret' => $this->bot->relaySecret()])
                    ->post(rtrim((string) $this->bot->relay_url, '/').'/api/bot'.$token.'/'.$method, $params),
                'gas' => $http->post((string) $this->bot->relay_url, ['s' => $this->bot->relaySecret(), 'token' => $token, 'method' => $method, 'params' => $params]),
                default => $http->post($this->base().$token.'/'.$method, $params),
            };
        } catch (ConnectionException $e) {
            throw new BotApiException('اتصال به سرور '.$this->bot->label().' برقرار نشد: '.$this->clean($e->getMessage()));
        }
        $j = $res->json();
        if (! is_array($j) || ! ($j['ok'] ?? false)) {
            $desc = is_array($j) ? ($j['description'] ?? '') : '';
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
