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

            $this->commands([
                \ItsJustVita\LaravelZnuny\Commands\TestConnectionCommand::class,
                \ItsJustVita\LaravelZnuny\Commands\CacheClearCommand::class,
                \ItsJustVita\LaravelZnuny\Commands\GenerateDynamicFieldsCommand::class,
            ]);
        }

        if ((bool) config('znuny.webhooks.enabled', false)) {
            $this->registerWebhookRoutes();
        }
    }

    public function registerWebhookRoutes(): void
    {
        $prefix = (string) config('znuny.webhooks.route_prefix', 'znuny/webhooks');
        $middleware = (array) config('znuny.webhooks.middleware', ['api']);

        \Illuminate\Support\Facades\Route::prefix($prefix)
            ->middleware($middleware)
            ->post('/{event}', [\ItsJustVita\LaravelZnuny\Webhooks\WebhookController::class, 'handle']);
    }
}
