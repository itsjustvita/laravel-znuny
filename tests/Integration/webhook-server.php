<?php

// Router script for `php -S`: a Laravel application with the package's webhook endpoint.
// The integration suite starts it and reads every ZnunyWebhookReceived event from the
// JSON lines file ZNUNY_WEBHOOK_CAPTURE.

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use ItsJustVita\LaravelZnuny\Events\ZnunyWebhookReceived;
use ItsJustVita\LaravelZnuny\ZnunyServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: [
    'extra' => [
        'providers' => [ZnunyServiceProvider::class],
        'env' => [
            'ZNUNY_WEBHOOKS_ENABLED=true',
            'ZNUNY_WEBHOOK_TOKEN="'.getenv('ZNUNY_WEBHOOK_TOKEN').'"',
        ],
    ],
]);

$app['events']->listen(function (ZnunyWebhookReceived $event): void {
    file_put_contents((string) getenv('ZNUNY_WEBHOOK_CAPTURE'), json_encode([
        'event' => $event->event,
        'ticketId' => $event->ticketId,
        'articleId' => $event->articleId,
        'parameters' => $event->parameters,
        'ticket' => $event->ticket()?->toArray(),
        'customerEmail' => $event->ticket()?->attribute('CustomerUser.UserEmail'),
    ])."\n", FILE_APPEND | LOCK_EX);
});

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
