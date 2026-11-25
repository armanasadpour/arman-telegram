<?php

namespace App\Http\Controllers\Bots;

use App\Bots\Engine;
use App\Bots\Platforms;
use App\Bots\UpdateHandler;
use App\Http\Controllers\Controller;
use App\Models\Bot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /hook/{platform}/{secret}. The random path secret identifies the bot; Telegram also proves itself
 * with its secret_token header (direct) or the relay's X-Relay-Secret header (Cloudflare / Apps Script).
 * Every update is processed once (bot_updates is unique per bot). Errors are logged, never echoed.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $platform, string $secret, Platforms $platforms, Engine $engine): JsonResponse
    {
        $bot = Bot::query()->where('platform', $platform)->first();
        if (! $bot || ! hash_equals((string) $bot->hook_secret, $secret) || ! $bot->is_active || ! $bot->isConfigured()) {
            return response()->json(['ok' => false], 404);
        }
        if ($bot->platform === 'telegram') {
            // Telegram's own header (direct webhook); X-Relay-Secret from relays deployed with older panel versions.
            $given = (string) ($request->header('X-Telegram-Bot-Api-Secret-Token') ?? $request->header('X-Relay-Secret'));
            if (! hash_equals($bot->relaySecret(), $given)) {
                return response()->json(['ok' => false], 403);
            }
        }
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['ok' => true]);
        }
        $r = app(UpdateHandler::class)->handle($bot, $payload);

        return response()->json($r === 'duplicate' ? ['ok' => true, 'duplicate' => true] : ['ok' => true]);
    }
}
