<?php

namespace ItsJustVita\LaravelZnuny\Tests\Integration;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRouteNotFoundException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use PHPUnit\Framework\Attributes\DataProvider;
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
        ], $config);

        return Znuny::build(['name' => 'integration-'.md5(serialize($config))] + $config);
    }

    private function customerUser(): string
    {
        return getenv('ZNUNY_INTEGRATION_CUSTOMER_USER') ?: 'jane';
    }

    private function createTicket(ZnunyManager $znuny, string $title, array $attachments = []): Ticket
    {
        return $znuny->tickets()->create([
            'title' => $title,
            'queue' => 'Raw',
            'state' => 'new',
            'priority' => '3 normal',
            'customerUser' => $this->customerUser(),
        ])->withArticle([
            'subject' => $title,
            'body' => 'Created by the laravel-znuny integration suite.',
            'contentType' => 'text/plain; charset=utf8',
            'communicationChannel' => 'Internal',
            'senderType' => 'agent',
            'isVisibleForCustomer' => true,
        ])->withAttachments($attachments)->save();
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
        ]);

        $this->assertNotNull($articleId);
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

    public function test_customer_users_find_their_own_tickets(): void
    {
        $password = getenv('ZNUNY_INTEGRATION_CUSTOMER_PASSWORD');

        if (! $password) {
            $this->markTestSkipped('Set ZNUNY_INTEGRATION_CUSTOMER_PASSWORD to test customer users.');
        }

        $ticket = $this->createTicket($this->znuny(), 'Customer '.uniqid());

        $ids = $this->znuny([
            'username' => $this->customerUser(),
            'password' => $password,
            'user_type' => 'customer',
        ])->tickets()->query()->ids()->all();

        $this->assertContains($ticket->id, $ids);
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
}
