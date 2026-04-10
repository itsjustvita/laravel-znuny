<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyServiceProvider;

final class WebhookStubTest extends TestCase
{
    public function test_webhook_routes_are_not_registered_when_disabled(): void
    {
        config()->set('znuny.webhooks.enabled', false);

        $response = $this->postJson('/znuny/webhooks/ticket.created', []);

        $response->assertStatus(404);
    }

    public function test_webhook_endpoint_returns_501_when_enabled(): void
    {
        config()->set('znuny.webhooks.enabled', true);

        // Re-register routes for this test scope
        $this->app->getProvider(ZnunyServiceProvider::class)->registerWebhookRoutes();

        $response = $this->postJson('/znuny/webhooks/ticket.created', ['foo' => 'bar']);

        $response->assertStatus(501);
        $response->assertJson(['status' => 'not_implemented']);
    }
}
