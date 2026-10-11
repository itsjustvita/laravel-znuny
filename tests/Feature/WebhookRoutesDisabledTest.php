<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class WebhookRoutesDisabledTest extends TestCase
{
    public function test_webhook_routes_are_not_registered_when_disabled(): void
    {
        $this->postJson('/znuny/webhooks/TicketCreate', [])->assertNotFound();
    }
}
