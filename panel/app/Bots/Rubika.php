<?php

namespace App\Bots;

use App\Models\Bot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rubika Bot API v3: POST https://botapi.rubika.ir/v3/{token}/{method}, JSON in and {"status":"OK","data":…} out.
 * Menus use the chat keypad (a press arrives as a normal text message), so no inline-keypad support is needed.
 * The docs could not be read from the development environment; the connection test on the host records
 * the real getMe response, and unknown webhook payloads are logged (without the token) for review.
 */
class Rubika implements Platform
{
    public function __construct(private Bot $bot) {}

    public function parse(array $p): ?Incoming
    {
        $u = $p['update'] ?? null;
        if (is_array($u) && ($u['type'] ?? null) === 'NewMessage' && isset($u['chat_id'], $u['new_message']['text'])) {
            $m = $u['new_message'];
            if (($m['sender_type'] ?? 'User') !== 'User') {
                return null;
            }

            return new Incoming('m:'.$u['chat_id'].':'.($m['message_id'] ?? md5(json_encode($m))), (string) $u['chat_id'], (string) $m['text'],
                null, null, isset($m['message_id']) ? (string) $m['message_id'] : null);
        }
        Log::info('rubika: unhandled webhook payload', ['keys' => array_keys($p), 'type' => $u['type'] ?? null]);

        return null;
    }

    public function send(string $chatId, string $text, ?array $keyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($keyboard === []) {
            $params['chat_keypad_type'] = 'Remove';
        } elseif ($keyboard !== null) {
            $n = 0;
            $params['chat_keypad_type'] = 'New';
            $params['chat_keypad'] = [
                'rows' => array_map(fn ($row) => ['buttons' => array_map(function ($l) use (&$n) {
                    return ['id' => 'b'.(++$n), 'type' => 'Simple', 'button_text' => $l];
                }, $row)], $keyboard),
                'resize_keyboard' => true,
                'on_time_keyboard' => false,
            ];
        }
        $this->call('sendMessage', $params);
    }

    public function sendPhoto(string $chatId, string $png, string $caption = ''): bool
    {
        return false;   // Rubika file upload is a separate flow: the config text is sent instead
    }

    public function getMe(): array
    {
        $d = $this->call('getMe', []);

        return ['username' => $d['bot']['username'] ?? null, 'raw' => $d];
    }

    public function setWebhook(): string
    {
        $url = $this->bot->hookUrl();
        $this->call('updateBotEndpoints', ['url' => $url, 'type' => 'ReceiveUpdate']);

        return $url;
    }

    public function deleteMessage(string $chatId, string $messageId): void
    {
        try {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (Throwable) {
        }
    }

    private function call(string $method, array $params): mixed
    {
        $token = (string) $this->bot->token;
        if ($token === '') {
            throw new BotApiException('توکن ربات تنظیم نشده است.');
        }
        try {
            $res = Http::timeout(15)->connectTimeout(8)->acceptJson()->asJson()->post((config('panel.bots_api_override') ? rtrim(config('panel.bots_api_override'), '/').'/rubika' : 'https://botapi.rubika.ir').'/v3/'.$token.'/'.$method, $params);
        } catch (ConnectionException $e) {
            throw new BotApiException('اتصال به سرور روبیکا برقرار نشد: '.mb_substr(str_replace($token, '***', $e->getMessage()), 0, 300));
        }
        $j = $res->json();
        if (! is_array($j) || ($j['status'] ?? null) !== 'OK') {
            $desc = is_array($j) ? (string) ($j['status_det'] ?? $j['status'] ?? '') : '';
            throw new BotApiException('پاسخ خطا از روبیکا (HTTP '.$res->status().')'.($desc !== '' ? ': '.mb_substr(str_replace($token, '***', $desc), 0, 200) : ''));
        }

        return $j['data'] ?? null;
    }
}
