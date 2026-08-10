<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
    }
}
