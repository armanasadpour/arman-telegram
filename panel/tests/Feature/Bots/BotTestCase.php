<?php

namespace Tests\Feature\Bots;

use App\Models\Bot;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Finance\ResellerTestCase;

abstract class BotTestCase extends ResellerTestCase
{
    protected int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'panel_bot']]),
            'tapi.bale.ai/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'panel_bale_bot']]),
            'botapi.rubika.ir/*' => Http::response(['status' => 'OK', 'data' => ['message_id' => '1', 'bot' => ['username' => 'panel_rbot']]]),
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    protected function freshHttpFake(): void
    {
        $this->app->instance(Factory::class, new Factory);
        Http::clearResolvedInstances();
    }

    protected function bot(string $platform = 'telegram', array $attrs = []): Bot
    {
        $b = Bot::for($platform);
        $b->forceFill(['token' => $platform === 'rubika' ? 'RUBIKATOKEN123' : '123456:SECRET-TOKEN_abc', 'is_active' => true] + $attrs)->save();

        return $b->fresh();
    }

    /** Send a Telegram-style private text message to the webhook. */
    protected function tg(string $text, string $chat = '555', ?Bot $bot = null, ?int $updateId = null)
    {
        $bot ??= Bot::for('telegram');
        $payload = ['update_id' => $updateId ?? ++$this->updateId, 'message' => ['message_id' => $this->updateId, 'chat' => ['id' => (int) $chat, 'type' => 'private'],
            'from' => ['id' => (int) $chat, 'first_name' => 'Ali', 'username' => 'ali'], 'text' => $text]];
        $headers = $bot->platform === 'telegram' ? [$bot->relay_mode === 'direct' ? 'X-Telegram-Bot-Api-Secret-Token' : 'X-Relay-Secret' => $bot->relaySecret()] : [];

        return $this->withHeaders($headers)->postJson('/hook/'.$bot->platform.'/'.$bot->hook_secret, $payload);
    }

    /** @return list<array> sendMessage params, oldest first */
    protected function sent(string $host = 'api.telegram.org'): array
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), $host) && str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    protected function lastText(string $host = 'api.telegram.org'): string
    {
        $s = $this->sent($host);

        return (string) (end($s)['text'] ?? '');
    }

    protected function lastKeyboard(): array
    {
        $s = $this->sent();
        $kb = end($s)['reply_markup']['keyboard'] ?? [];

        return array_merge(...array_map(fn ($row) => array_column($row, 'text'), $kb ?: [[]]));
    }

    protected function urls(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->url())->values()->all();
    }

    protected function request(string $suffix): ?Request
    {
        $r = Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), $suffix));

        return $r[0] ?? null;
    }
}
