<?php

namespace ItsJustVita\LaravelZnuny\Tests\Integration;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Builders\TicketCreateBuilder;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Events\ZnunySessionRefreshed;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidPriorityException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidQueueException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidStateException;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRouteNotFoundException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Znuny;

/**
 * Runs against a real Znuny with the GenericTicketConnectorREST example web services.
 * See tests/Integration/README.md. Skipped unless ZNUNY_INTEGRATION_URL is set.
 */
final class ZnunyIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('ZNUNY_INTEGRATION_URL')) {
            $this->markTestSkipped('Set ZNUNY_INTEGRATION_URL, ZNUNY_INTEGRATION_USERNAME and ZNUNY_INTEGRATION_PASSWORD to run the integration suite.');
        }

        Http::allowStrayRequests();
    }

    /**
     * @return array<string, array{0: string, 1: string}> URL variable and route preset
     */
    public static function webServices(): array
    {
        return [
            'shipped example, default preset' => ['ZNUNY_INTEGRATION_URL', 'generic-ticket-connector'],
            'classic example' => ['ZNUNY_INTEGRATION_CLASSIC_URL', 'generic-ticket-connector-classic'],
            'operation-name routes' => ['ZNUNY_INTEGRATION_OPERATION_NAME_URL', 'operation-name'],
        ];
    }

    private function znuny(array $config = [], string $urlVariable = 'ZNUNY_INTEGRATION_URL', string $routes = 'generic-ticket-connector'): ZnunyManager
    {
        $url = getenv($urlVariable);

        if (! $url) {
            $this->markTestSkipped("Set {$urlVariable} to test the {$routes} routes.");
        }

        $config = array_merge([
            'base_url' => $url,
            'username' => getenv('ZNUNY_INTEGRATION_USERNAME'),
            'password' => getenv('ZNUNY_INTEGRATION_PASSWORD'),
            'routes' => $routes,
            'retries' => 0,
            'timezone' => getenv('ZNUNY_INTEGRATION_TIMEZONE') ?: 'UTC',
        ], $config);

        return Znuny::build(['name' => 'integration-'.md5(serialize($config))] + $config);
    }

    private function customer(): ZnunyManager
    {
        $password = getenv('ZNUNY_INTEGRATION_CUSTOMER_PASSWORD');

        if (! $password) {
            $this->markTestSkipped('Set ZNUNY_INTEGRATION_CUSTOMER_PASSWORD to test customer users.');
        }

        return $this->znuny([
            'username' => $this->customerUser(),
            'password' => $password,
            'user_type' => 'customer',
        ]);
    }

    private function customerUser(): string
    {
        return getenv('ZNUNY_INTEGRATION_CUSTOMER_USER') ?: 'jane';
    }

    private function newTicket(ZnunyManager $znuny, string $title, string $senderType = 'agent', array $ticket = []): TicketCreateBuilder
    {
        return $znuny->tickets()->create(array_merge([
            'title' => $title,
            'queue' => 'Raw',
            'state' => 'new',
            'priority' => '3 normal',
            'customerUser' => $this->customerUser(),
        ], $ticket))->withArticle([
            'subject' => $title,
            'body' => 'Created by the laravel-znuny integration suite.',
            'contentType' => 'text/plain; charset=utf8',
            'communicationChannel' => 'Internal',
            'senderType' => $senderType,
            'isVisibleForCustomer' => true,
        ]);
    }

    private function createTicket(ZnunyManager $znuny, string $title, array $attachments = []): Ticket
    {
        return $this->newTicket($znuny, $title)->withAttachments($attachments)->save();
    }

    private function dynamicField(Ticket $ticket, string $name): mixed
    {
        return $ticket->dynamicFields->firstWhere('name', $name)?->value;
    }

    /**
     * Poll until the callback returns something other than null or false.
     */
    private function waitFor(Closure $callback, string $what, int $seconds = 30): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            $result = $callback();

            if ($result !== null && $result !== false) {
                return $result;
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        $this->fail("Timed out after {$seconds}s waiting for {$what}.");
    }

    #[DataProvider('webServices')]
    public function test_create_read_attachment_update_article_and_history(string $urlVariable, string $routes): void
    {
        $znuny = $this->znuny([], $urlVariable, $routes);
        $ticket = $this->createTicket($znuny, 'Integration '.uniqid(), [
            Attachment::fromContent('hello.txt', 'Hello Znuny', 'text/plain'),
        ]);

        $loaded = $znuny->tickets()->find($ticket->id, withAttachments: true);
        $attachment = $loaded->articles->first()?->attachments->firstWhere('filename', 'hello.txt');
        $this->assertNotNull($attachment);
        $this->assertSame('Hello Znuny', $attachment->content);

        $znuny->tickets()->update($ticket->id, ['state' => 'open']);
        $articleId = $znuny->tickets()->addArticle($ticket->id, [
            'subject' => 'Note',
            'body' => 'Internal note',
            'contentType' => 'text/plain; charset=utf8',
            'communicationChannel' => 'Internal',
            'senderType' => 'agent',
            'isVisibleForCustomer' => false,
        ], [Attachment::fromContent('note.csv', "a,b\n1,2\n", 'text/csv')]);

        $this->assertNotNull($articleId);
        $note = $znuny->tickets()->find($ticket->id, withAttachments: true)->articles->firstWhere('id', $articleId);
        $this->assertSame("a,b\n1,2\n", $note?->attachments->firstWhere('filename', 'note.csv')?->content);
        $this->assertSame('open', $znuny->tickets()->find($ticket->id)->state);
        $this->assertContains('StateUpdate', $znuny->tickets()->history($ticket->id)->pluck('historyType')->all());
    }

    #[DataProvider('webServices')]
    public function test_search_with_list_filters_and_batched_find_many(string $urlVariable, string $routes): void
    {
        $znuny = $this->znuny([], $urlVariable, $routes);
        $a = $this->createTicket($znuny, 'Batch A '.uniqid());
        $b = $this->createTicket($znuny, 'Batch B '.uniqid());

        $ids = $znuny->tickets()->whereState(['new', 'open'])->whereQueue('Raw')->ids()->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertSame([$b->id, $a->id], $znuny->tickets()->findMany([$b->id, $a->id])->pluck('id')->all());
    }

    #[DataProvider('webServices')]
    public function test_credentials_mode_with_header_and_payload_transport(string $urlVariable, string $routes): void
    {
        $ticket = $this->createTicket($this->znuny([], $urlVariable, $routes), 'Credentials '.uniqid());

        foreach (['headers', 'payload'] as $transport) {
            $znuny = $this->znuny(['auth' => 'credentials', 'auth_transport' => $transport], $urlVariable, $routes);

            $this->assertSame($ticket->id, $znuny->tickets()->find($ticket->id)->id, "find, transport {$transport}");
            $this->assertContains($ticket->id, $znuny->tickets()->whereQueue('Raw')->ids()->all(), "search, transport {$transport}");
        }
    }

    #[DataProvider('webServices')]
    public function test_dynamic_fields_on_create_update_and_search(string $urlVariable, string $routes): void
    {
        $znuny = $this->znuny([], $urlVariable, $routes);
        $orderId = 'ORD-'.uniqid();

        $ticket = $this->newTicket($znuny, 'Dynamic fields '.uniqid())
            ->withDynamicFields(['OrderId' => $orderId, 'Tags' => ['alpha', 'beta']])
            ->save();

        $this->assertSame($orderId, $this->dynamicField($ticket, 'OrderId'));
        $this->assertSame(['alpha', 'beta'], $this->dynamicField($ticket, 'Tags'));

        $znuny->tickets()->update($ticket->id, dynamicFields: ['OrderId' => $orderId.'-2', 'Tags' => ['gamma']]);
        $updated = $znuny->tickets()->find($ticket->id, withDynamicFields: true);

        $this->assertSame($orderId.'-2', $this->dynamicField($updated, 'OrderId'));
        $this->assertSame(['gamma'], $this->dynamicField($updated, 'Tags'));

        if ($routes === 'generic-ticket-connector-classic') {
            // GET /Ticket cannot carry the nested search condition; the package says so before sending.
            $this->expectException(ZnunyConfigurationException::class);
            $this->expectExceptionMessage('nested structure');
        }

        $this->assertSame([$ticket->id], $znuny->tickets()->query()->whereDynamicField('OrderId', $orderId.'-2')->ids()->all());
        $this->assertSame([$ticket->id], $znuny->tickets()->query()->whereDynamicField('OrderId', $orderId.'*', 'Like')->ids()->all());
    }

    public function test_dates_are_exchanged_in_the_znuny_time_zone(): void
    {
        $znuny = $this->znuny();
        $title = 'Time zone '.uniqid();
        $previous = date_default_timezone_get();

        // An application time zone ahead of Znuny's (UTC unless ZNUNY_INTEGRATION_TIMEZONE says otherwise).
        date_default_timezone_set('Asia/Tokyo');

        try {
            $ticket = $this->createTicket($znuny, $title);
            $search = fn () => $znuny->tickets()->where('Title', $title);

            $this->assertLessThan(300, abs($ticket->created->diffInSeconds(now())));
            $this->assertSame([$ticket->id], $search()->whereCreatedAfter(now()->subMinutes(5))->ids()->all());
            $this->assertSame([$ticket->id], $search()->whereChangedAfter(now()->subMinutes(5))->ids()->all());
            $this->assertSame([], $search()->whereCreatedBefore(now()->subMinutes(5))->ids()->all());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_changed_before_means_last_changed_before(): void
    {
        $znuny = $this->znuny();
        $title = 'Last change '.uniqid();
        $ticket = $this->createTicket($znuny, $title);
        sleep(2);
        $between = now();
        sleep(2);
        $znuny->tickets()->update($ticket->id, ['state' => 'open']);
        $search = fn () => $znuny->tickets()->where('Title', $title);

        $this->assertSame([], $search()->whereChangedBefore($between)->ids()->all());
        $this->assertSame([$ticket->id], $search()->whereChangedAfter($between)->ids()->all());
        $this->assertSame([$ticket->id], $search()->whereCreatedBefore($between)->ids()->all());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: class-string}>
     */
    public static function invalidInput(): array
    {
        return [
            'queue' => [['queue' => 'No such queue'], [], InvalidQueueException::class],
            'state' => [['state' => 'no such state'], [], InvalidStateException::class],
            'priority' => [['priority' => 'no such priority'], [], InvalidPriorityException::class],
            'dynamic field value' => [[], ['Tags' => ['no-such-tag']], InvalidDynamicFieldException::class],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_raises_typed_validation_exceptions(array $ticket, array $dynamicFields, string $exception): void
    {
        $this->expectException($exception);

        $this->newTicket($this->znuny(), 'Invalid '.uniqid(), ticket: $ticket)->withDynamicFields($dynamicFields)->save();
    }

    public function test_find_many_skips_unknown_ids(): void
    {
        $znuny = $this->znuny();
        $ticket = $this->createTicket($znuny, 'Skip '.uniqid());

        $this->assertSame([$ticket->id], $znuny->tickets()->findMany([$ticket->id, '999999999'])->pluck('id')->all());
    }

    public function test_an_unknown_ticket_is_access_denied_or_not_found(): void
    {
        try {
            $this->znuny()->tickets()->find('999999999');
            $this->fail('Expected an exception for an unknown ticket');
        } catch (ZnunyAccessDeniedException|TicketNotFoundException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_an_expired_session_is_replaced_and_the_request_retried(): void
    {
        Event::fake([ZnunySessionRefreshed::class]);
        $znuny = $this->znuny();
        $ticket = $this->createTicket($znuny, 'Session '.uniqid());

        // A GET route (session ID in a header), then a POST route (session ID in the body).
        $this->endSession($znuny);
        $this->assertSame($ticket->id, $znuny->tickets()->find($ticket->id)->id);

        $this->endSession($znuny);
        $this->assertContains($ticket->id, $znuny->tickets()->whereQueue('Raw')->ids()->all());

        Event::assertDispatchedTimes(ZnunySessionRefreshed::class, 2);
    }

    /**
     * Remove the cached session in Znuny, as if it had expired there.
     */
    private function endSession(ZnunyManager $znuny): void
    {
        $sessionId = Cache::get('znuny:session:'.$znuny->client()->identityKey());
        $this->assertIsString($sessionId);

        $znuny->call('SessionDelete', ['SessionID' => $sessionId]);
    }

    public function test_customer_users_create_tickets_and_find_their_own(): void
    {
        $customer = $this->customer();
        $own = $this->newTicket($customer, 'Customer '.uniqid(), senderType: 'customer')->save();
        $fromAgent = $this->createTicket($this->znuny(), 'For customer '.uniqid());

        $this->assertSame($this->customerUser(), $own->customerUser);

        $ids = $customer->tickets()->query()->ids()->all();
        $this->assertContains($own->id, $ids);
        $this->assertContains($fromAgent->id, $ids);
        $this->assertSame($own->id, $this->znuny()->tickets()->find($own->id)->id);
    }

    public function test_a_wrong_password_raises_an_authentication_exception(): void
    {
        $this->expectException(ZnunyAuthenticationException::class);

        $this->znuny(['auth' => 'credentials', 'password' => 'wrong-password'])->tickets()->query()->limit(1)->ids();
    }

    public function test_operations_missing_from_the_web_service_raise_route_not_found(): void
    {
        $this->expectException(ZnunyRouteNotFoundException::class);

        $this->znuny()->call('QueueList');
    }

    public function test_artisan_commands_check_a_live_connection(): void
    {
        $credentials = [
            'username' => getenv('ZNUNY_INTEGRATION_USERNAME'),
            'password' => getenv('ZNUNY_INTEGRATION_PASSWORD'),
        ];

        config(['znuny.connections.live' => ['base_url' => getenv('ZNUNY_INTEGRATION_URL')] + $credentials]);

        $this->artisan('znuny:test-connection', ['connection' => 'live'])
            ->expectsOutputToContain('Znuny connection [live] OK')
            ->assertSuccessful();

        $this->artisan('znuny:routes', ['connection' => 'live'])
            ->expectsOutputToContain('Connection [live] uses preset generic-ticket-connector.')
            ->assertSuccessful();

        if (! getenv('ZNUNY_INTEGRATION_CLASSIC_URL')) {
            return;
        }

        // The default routes against the classic web service: there is no POST /Ticket/Search.
        config(['znuny.connections.mismatch' => ['base_url' => getenv('ZNUNY_INTEGRATION_CLASSIC_URL')] + $credentials]);

        $this->artisan('znuny:test-connection', ['connection' => 'mismatch'])
            ->expectsOutputToContain('php artisan znuny:routes mismatch')
            ->assertFailed();
    }

    public function test_webhooks_from_znuny_reach_the_laravel_endpoint(): void
    {
        $listen = getenv('ZNUNY_INTEGRATION_WEBHOOK_LISTEN');

        if (! $listen) {
            $this->markTestSkipped('Set ZNUNY_INTEGRATION_WEBHOOK_LISTEN (e.g. 0.0.0.0:8099) to receive the webhooks of the LaravelWebhook web service.');
        }

        $port = (int) substr((string) strrchr($listen, ':'), 1);
        $capture = (string) tempnam(sys_get_temp_dir(), 'znuny-webhook-');
        $server = new Process([PHP_BINARY, '-S', $listen, __DIR__.'/webhook-server.php'], env: [
            'ZNUNY_WEBHOOK_TOKEN' => 'integration-token',
            'ZNUNY_WEBHOOK_CAPTURE' => $capture,
        ]);
        $server->start();

        try {
            $this->waitFor(fn (): bool => @fsockopen('127.0.0.1', $port) !== false, "the webhook server on port {$port}");

            $ticket = $this->createTicket($this->znuny(), 'Webhook '.uniqid());

            $received = $this->waitFor(fn (): ?array => collect(file($capture, FILE_IGNORE_NEW_LINES) ?: [])
                ->map(fn (string $line): array => json_decode($line, true))
                ->firstWhere('ticketId', $ticket->id), "the TicketCreate webhook of ticket {$ticket->id}");
        } finally {
            $server->stop();
            @unlink($capture);
        }

        $this->assertSame('TicketCreate', $received['event']);
        $this->assertSame(['event' => 'TicketCreate'], $received['parameters']);
        $this->assertSame($ticket->title, $received['ticket']['Title']);
        $this->assertSame($this->customerUser(), $received['ticket']['CustomerUser']);
        $this->assertStringContainsString('@', (string) $received['customerEmail']);
    }
}
