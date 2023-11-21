<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ResultService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ResultService::class, function ($app) {
            return new ResultService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
