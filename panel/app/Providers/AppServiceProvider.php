<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
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
