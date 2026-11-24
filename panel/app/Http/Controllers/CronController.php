<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the scheduler over HTTP for hosts that cannot run `php artisan schedule:run` from cron.
 * Protected by a long random key; constant-time comparison; never exposes output.
 */
class CronController extends Controller
{
    public function __invoke(string $key): Response
    {
        $expected = (string) config('panel.cron_key');
        if ($expected === '' || ! hash_equals($expected, $key)) {
            abort(404);
        }
        @set_time_limit(60);
        Artisan::call('schedule:run');

        return response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
