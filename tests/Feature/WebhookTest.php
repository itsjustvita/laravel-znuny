<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ItsJustVita\LaravelZnuny\Events\ZnunyWebhookReceived;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\Webhooks\WebhookController;

final class WebhookTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $warnings = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('znuny.webhooks.enabled', true);
        $app['config']->set('znuny.webhooks.auth.token', 'secret-token');
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/webhook-ticket-generic.json'), true);
    }

    private function listenForWarnings(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            if ($message->level === 'warning') {
                $this->warnings[] = $message;
            }
        });
    }

    public function test_a_valid_bearer_token_dispatches_the_event_and_returns_an_empty_object(): void
    {
        Event::fake([ZnunyWebhookReceived::class]);

        $response = $this->postJson('/znuny/webhooks', $this->payload(), ['Authorization' => 'Bearer secret-token']);

        $response->assertOk();
        $this->assertSame('{}', $response->getContent());

        Event::assertDispatched(ZnunyWebhookReceived::class, function (ZnunyWebhookReceived $event): bool {
            $ticket = $event->ticket();

            return $event->event === 'TicketCreate'
                && $event->ticketId === '42'
                && $event->articleId === null
                && $event->is('ArticleCreate', 'TicketCreate')
                && $ticket?->title === 'Printer on fire'
                && $ticket->articles->count() === 1
                && $ticket->dynamicFields->first()?->name === 'OrderId';
        });
    }

    public function test_the_configured_token_header_is_accepted(): void
    {
        Event::fake([ZnunyWebhookReceived::class]);

        $this->postJson('/znuny/webhooks', $this->payload(), ['X-Znuny-Token' => 'secret-token'])->assertOk();

        Event::assertDispatched(ZnunyWebhookReceived::class);
    }

    public function test_missing_or_wrong_tokens_are_rejected(): void
    {
        Event::fake([ZnunyWebhookReceived::class]);

        $this->postJson('/znuny/webhooks', $this->payload())->assertUnauthorized();
        $this->postJson('/znuny/webhooks', $this->payload(), ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        Event::assertNotDispatched(ZnunyWebhookReceived::class);
    }

    public function test_an_unconfigured_token_rejects_every_request_and_logs_a_warning(): void
    {
        config()->set('znuny.webhooks.auth.token', null);
        $this->listenForWarnings();

        $this->postJson('/znuny/webhooks', [], ['Authorization' => 'Bearer anything'])->assertUnauthorized();

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('ZNUNY_WEBHOOK_TOKEN is not set', $this->warnings[0]->message);
    }

    public function test_basic_auth(): void
    {
        config()->set('znuny.webhooks.auth', ['type' => 'basic', 'username' => 'znuny', 'password' => 'pw']);

        $this->postJson('/znuny/webhooks', [], ['Authorization' => 'Basic '.base64_encode('znuny:pw')])->assertOk();
        $this->postJson('/znuny/webhooks', [], ['Authorization' => 'Basic '.base64_encode('znuny:nope')])->assertUnauthorized();
    }

    public function test_auth_type_none_accepts_requests(): void
    {
        config()->set('znuny.webhooks.auth.type', 'none');

        $this->postJson('/znuny/webhooks', [])->assertOk();
    }

    public function test_an_unknown_auth_type_fails_closed(): void
    {
        config()->set('znuny.webhooks.auth.type', 'magic');
        $this->listenForWarnings();

        $this->postJson('/znuny/webhooks', [], ['Authorization' => 'Bearer secret-token'])->assertUnauthorized();

        $this->assertCount(1, $this->warnings);
    }

    public function test_the_route_event_wins_over_the_payload(): void
    {
        Event::fake([ZnunyWebhookReceived::class]);

        $this->postJson('/znuny/webhooks/TicketStateUpdate', $this->payload(), ['X-Znuny-Token' => 'secret-token'])->assertOk();

        Event::assertDispatched(ZnunyWebhookReceived::class, fn (ZnunyWebhookReceived $e) => $e->event === 'TicketStateUpdate'
            && $e->parameters === ['event' => 'TicketStateUpdate']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->call('POST', '/znuny/webhooks', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret-token',
            'CONTENT_TYPE' => 'application/json',
        ], 'not json')->assertStatus(400);
    }

    public function test_the_middleware_protects_custom_application_routes(): void
    {
        Event::fake([ZnunyWebhookReceived::class]);
        Route::post('tenants/{tenant}/znuny', WebhookController::class)->middleware('znuny.webhook');

        $this->postJson('/tenants/acme/znuny', ['Event' => ['Event' => 'TicketCreate', 'TicketID' => 1]], ['X-Znuny-Token' => 'secret-token'])
            ->assertOk();
        $this->postJson('/tenants/acme/znuny', [])->assertUnauthorized();

        Event::assertDispatched(ZnunyWebhookReceived::class, fn (ZnunyWebhookReceived $e) => $e->parameters === ['tenant' => 'acme']
            && $e->ticketId === '1');
    }
}
