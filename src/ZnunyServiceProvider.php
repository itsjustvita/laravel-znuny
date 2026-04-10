<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ItsJustVita\LaravelZnuny\Commands\CacheClearCommand;
use ItsJustVita\LaravelZnuny\Commands\GenerateDynamicFieldsCommand;
use ItsJustVita\LaravelZnuny\Commands\TestConnectionCommand;
use ItsJustVita\LaravelZnuny\Webhooks\WebhookController;

class ZnunyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/znuny.php', 'znuny');

        $this->app->singleton(ZnunyManager::class, fn ($app) => new ZnunyManager($app));
        $this->app->alias(ZnunyManager::class, 'znuny');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/znuny.php' => config_path('znuny.php'),
            ], 'znuny-config');

            $this->commands([
                TestConnectionCommand::class,
                CacheClearCommand::class,
                GenerateDynamicFieldsCommand::class,
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

        Route::prefix($prefix)
            ->middleware($middleware)
            ->post('/{event}', [WebhookController::class, 'handle']);
    }
}
