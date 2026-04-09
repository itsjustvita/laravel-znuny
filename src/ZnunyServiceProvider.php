<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Support\ServiceProvider;

class ZnunyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/znuny.php', 'znuny');

        $this->app->singleton(ZnunyManager::class, fn ($app) => new ZnunyManager($app));
        $this->app->alias(ZnunyManager::class, 'znuny');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/znuny.php' => config_path('znuny.php'),
            ], 'znuny-config');
        }
    }
}
