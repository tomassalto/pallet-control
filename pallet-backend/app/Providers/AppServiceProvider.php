<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tira excepción ante lazy loading (N+1) en local — nunca en producción,
        // donde preferimos degradar con una query extra antes que un 500.
        Model::preventLazyLoading(! $this->app->isProduction());

        RateLimiter::for('public-order-read', function (Request $request): array {
            $resource = hash('sha256', (string) $request->route('token'));

            return [
                Limit::perMinute(60)->by("public-order-read:ip:{$request->ip()}"),
                Limit::perMinute(20)->by("public-order-read:resource:{$request->ip()}:{$resource}"),
            ];
        });

        RateLimiter::for('public-order-write', function (Request $request): array {
            $resource = hash('sha256', (string) $request->route('token'));

            return [
                Limit::perMinute(20)->by("public-order-write:ip:{$request->ip()}"),
                Limit::perMinute(5)->by("public-order-write:resource:{$request->ip()}:{$resource}"),
            ];
        });
    }
}
