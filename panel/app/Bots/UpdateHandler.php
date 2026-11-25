<?php

namespace App\Bots;

use App\Models\Bot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One incoming update (from a webhook or from polling): parsed, de-duplicated, handled once. */
class UpdateHandler
{
    public function __construct(private Platforms $platforms, private Engine $engine) {}

    /** @return string handled | duplicate | ignored */
    public function handle(Bot $bot, array $payload): string
    {
        $in = $this->platforms->for($bot)->parse($payload);
        if (! $in) {
            return 'ignored';
        }
        try {
            DB::table('bot_updates')->insert(['bot_id' => $bot->id, 'update_key' => mb_substr($in->updateKey, 0, 100), 'created_at' => now()]);
        } catch (QueryException) {
            return 'duplicate';   // already handled
        }
        $bot->forceFill(['last_update_at' => now()])->save();
        try {
            $this->engine->handle($bot, $in);
        } catch (Throwable $e) {
            $msg = mb_substr(str_replace((string) $bot->token, '***', $e->getMessage()), 0, 500);
            Log::error('bot handling failed', ['platform' => $bot->platform, 'error' => $msg]);
            $bot->forceFill(['last_error_at' => now(), 'last_error' => $msg])->save();
        }

        return 'handled';
    }
}
