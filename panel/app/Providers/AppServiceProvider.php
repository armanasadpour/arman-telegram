<?php

namespace App\Providers;

use App\Ibs\IbsConnector;
use App\Ibs\Transport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settings;
use App\Support\DatetimeColumns;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(AuditLogger::class);
        // Tests bind \App\Ibs\Transport to a fake; production builds an HttpTransport from settings.
        $this->app->singleton(IbsConnector::class, fn ($app) => new IbsConnector(
            $app->make(Settings::class),
            $app->bound(Transport::class) ? $app->make(Transport::class) : null,
        ));
    }

    public function boot(): void
    {
        // Every migrate run (install, web upgrade, tests) leaves no MySQL TIMESTAMP columns behind.
        Event::listen(MigrationsEnded::class, fn () => DatetimeColumns::convert());
        // Every permission check goes through the user's roles; the super-admin role passes all.
        Gate::before(function (User $user, string $ability) {
            if (! $user->is_active) {
                return false;
            }

            return $user->isSuperAdmin() ? true : null;
        });
        Gate::after(fn (User $user, string $ability, ?bool $result) => $result ?? $user->hasPermission($ability));

        Paginator::defaultView('partials.pagination');

        // When installed inside public_html (requests rewritten to /public), build links from
        // APP_URL so they never contain "/public". Also force https when APP_URL uses it.
        if ($this->app->environment('production') && is_file(config('panel.install_lock')) && ($url = config('app.url'))) {
            URL::forceRootUrl(rtrim($url, '/'));
            if (str_starts_with($url, 'https://')) {
                URL::forceScheme('https');
            }
        }
    }
}
