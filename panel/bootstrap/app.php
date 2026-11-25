<?php

use App\Http\Middleware\ApplyDisplaySettings;
use App\Http\Middleware\DefaultReason;
use App\Http\Middleware\EnsureDatabaseUpToDate;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottlePerRoute;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [EnsureInstalled::class], append: [ApplyDisplaySettings::class, EnsureUserIsActive::class, EnsureDatabaseUpToDate::class, SecurityHeaders::class, DefaultReason::class]);
        $middleware->alias(['throttle' => ThrottlePerRoute::class]);
        $middleware->validateCsrfTokens(except: ['hook/*', 'pay/callback/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
