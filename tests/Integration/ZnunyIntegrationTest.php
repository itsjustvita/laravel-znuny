<?php

namespace ItsJustVita\LaravelZnuny\Tests\Integration;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
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
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRouteNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySearchLimitExceededException;
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
            'timezone' => $this->timezone(),
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

    /**
     * An agent in the group admin, for operations that need one.
     */
    private function admin(array $config = [], string $urlVariable = 'ZNUNY_INTEGRATION_URL'): ZnunyManager
    {
        $password = getenv('ZNUNY_INTEGRATION_ADMIN_PASSWORD');

        if (! $password) {
            $this->markTestSkipped('Set ZNUNY_INTEGRATION_ADMIN_USERNAME and ZNUNY_INTEGRATION_ADMIN_PASSWORD to test operations that need an administrator.');
        }

        return $this->znuny([
            'username' => getenv('ZNUNY_INTEGRATION_ADMIN_USERNAME') ?: 'api-admin',
            'password' => $password,
        ] + $config, $urlVariable);
    }

    /**
     * The data of a session created just now, by key. Znuny reads a user's data into the
     * session at login, so only a new session shows what has changed since.
     *
     * @return array<string, mixed>
     */
    private function freshSessionData(string $login, string $password, string $userType = 'agent'): array
    {
        $znuny = $this->znuny(['username' => $login, 'password' => $password, 'user_type' => $userType, 'auth' => 'credentials']);
        $sessionId = $znuny->call('SessionCreate')['SessionID'];

        return array_column($znuny->call('SessionGet', ['SessionID' => $sessionId])['SessionData'], 'Value', 'Key');
    }

    /**
     * The OTRSTimeZone of the Znuny under test.
     */
    private function timezone(): string
    {
        return getenv('ZNUNY_INTEGRATION_TIMEZONE') ?: 'UTC';
    }

    private function customerUser(): string
    {
        return getenv('ZNUNY_INTEGRATION_CUSTOMER_USER') ?: 'jane';
    }

    private function newTicket(ZnunyManager $znuny, string $title, string $senderType = 'agent', array $ticket = [], array $article = []): TicketCreateBuilder
    {
        return $znuny->tickets()->create(array_merge([
            'title' => $title,
            'queue' => 'Raw',
            'state' => 'new',
            'priority' => '3 normal',
            'customerUser' => $this->customerUser(),
        ], $ticket))->withArticle(array_merge([
            'subject' => $title,
            'body' => 'Created by the laravel-znuny integration suite.',
            'contentType' => 'text/plain; charset=utf8',
            'communicationChannel' => 'Internal',
            'senderType' => $senderType,
            'isVisibleForCustomer' => true,
        ], $article));
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
            'communicationChannel' => 'Internal',
            'senderType' => 'agent',
            'isVisibleForCustomer' => false,
        ], [Attachment::fromContent('note.csv', "a,b\n1,2\n", 'text/csv')]);

        $this->assertNotNull($articleId);
        $note = $znuny->tickets()->find($ticket->id, withAttachments: true)->articles->firstWhere('id', $articleId);
        // Znuny rejects articles without a content type; the package sends plain text by default.
        $this->assertStringStartsWith('text/plain', (string) $note?->contentType);
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

        // Several conditions on one field: Znuny combines them with AND.
        $this->assertSame([$ticket->id], $znuny->tickets()->whereDynamicField('OrderId', $orderId.'*', 'Like')->whereDynamicField('OrderId', $orderId.'-2')->ids()->all());
        $this->assertSame([], $znuny->tickets()->whereDynamicField('OrderId', 'no-such-order')->whereDynamicField('OrderId', $orderId.'*', 'Like')->ids()->all());
    }

    public function test_dates_in_dynamic_fields_round_trip(): void
    {
        // DueOn is a Znuny field of type "Date", which takes a day; DueAt is "Date / Time".
        config(['znuny.dynamic_fields' => ['DueOn' => ['type' => 'date'], 'DueAt' => ['type' => 'datetime']]]);

        $znuny = $this->znuny();
        $title = 'Dynamic field dates '.uniqid();
        $dueAt = CarbonImmutable::parse('2033-04-05 12:30:00', 'America/New_York');
        $dueOn = CarbonImmutable::parse('2033-04-05 00:30:00', 'Asia/Tokyo');

        $ticket = $this->newTicket($znuny, $title)->withDynamicFields(['DueAt' => $dueAt, 'DueOn' => $dueOn])->save();

        $this->assertTrue($dueAt->equalTo($ticket->dynamicField('DueAt')?->date()));
        $this->assertSame('2033-04-05 00:00:00', $ticket->dynamicField('DueOn')?->value);
        $this->assertSame('2033-04-05', $ticket->dynamicField('DueOn')?->date()?->format('Y-m-d'));

        $search = fn () => $znuny->tickets()->whereTitle($title);

        $this->assertSame([$ticket->id], $search()
            ->whereDynamicField('DueAt', $dueAt->subMinute(), 'GreaterThan')
            ->whereDynamicField('DueAt', $dueAt->addMinute(), 'SmallerThan')
            ->ids()->all());
        $this->assertSame([], $search()->whereDynamicField('DueAt', $dueAt->addMinute(), 'GreaterThan')->ids()->all());
        $this->assertSame([$ticket->id], $search()->whereDynamicField('DueOn', $dueOn)->ids()->all());
        $this->assertSame([], $search()->whereDynamicField('DueOn', $dueOn->addDay())->ids()->all());

        $znuny->tickets()->update($ticket->id, dynamicFields: ['DueAt' => $dueAt->addDay(), 'DueOn' => $dueOn->addDay()]);
        $updated = $znuny->tickets()->find($ticket->id, withDynamicFields: true);

        $this->assertTrue($dueAt->addDay()->equalTo($updated->dynamicField('DueAt')?->date()));
        $this->assertSame('2033-04-06', $updated->dynamicField('DueOn')?->date()?->format('Y-m-d'));

        // An empty string clears a date field.
        $znuny->tickets()->update($ticket->id, dynamicFields: ['DueAt' => '', 'DueOn' => '']);
        $cleared = $znuny->tickets()->find($ticket->id, withDynamicFields: true);

        $this->assertNull($cleared->dynamicField('DueAt')?->date());
        $this->assertNull($cleared->dynamicField('DueOn')?->date());
    }

    #[DataProvider('webServices')]
    public function test_the_session_user_is_the_user_of_the_connection(string $urlVariable, string $routes): void
    {
        $user = $this->znuny([], $urlVariable, $routes)->session()->user();

        $this->assertSame(getenv('ZNUNY_INTEGRATION_USERNAME'), $user->login);
        $this->assertSame('agent', $user->type);
        $this->assertMatchesRegularExpression('/^\d+$/', $user->id);
        $this->assertNotNull($user->email);
        $this->assertNotNull($user->fullName);

        // The same through a connection that sends its credentials with every request.
        $this->assertSame($user->id, $this->znuny(['auth' => 'credentials'], $urlVariable, $routes)->session()->user()->id);
    }

    public function test_the_session_user_of_a_customer_connection(): void
    {
        $user = $this->customer()->session()->user();

        $this->assertSame($this->customerUser(), $user->login);
        $this->assertSame($this->customerUser(), $user->id);
        $this->assertSame('customer', $user->type);
        $this->assertArrayNotHasKey('UserPassword', $user->attributes);
        $this->assertNotNull($user->attribute('UserCustomerID'));
    }

    /**
     * @return array<string, array{0: string, 1: string}> URL variable and route preset
     */
    public static function webServicesWithSessionRemove(): array
    {
        // The classic example has no SessionRemove operation.
        return array_diff_key(self::webServices(), ['classic example' => true]);
    }

    #[DataProvider('webServicesWithSessionRemove')]
    public function test_logout_ends_the_session(string $urlVariable, string $routes): void
    {
        $znuny = $this->znuny([], $urlVariable, $routes);
        $old = $znuny->client()->sessionId();

        $znuny->session()->logout();

        try {
            $this->znuny(['auth' => 'credentials'], $urlVariable, $routes)->call('SessionGet', ['SessionID' => $old]);
            $this->fail('Znuny must not know the session after logout().');
        } catch (ZnunyException $e) {
            $this->assertSame('SessionGet.SessionInvalid', $e->getErrorCode());
        }

        $this->assertNotSame($old, $znuny->client()->sessionId());

        // A second logout right away, and one for a session Znuny no longer knows, are quiet.
        $znuny->session()->logout();
        $znuny->session()->logout();
        $this->addToAssertionCount(1);
    }

    #[DataProvider('timeAccountingWebServices')]
    public function test_time_accounting_entries(string $urlVariable, array $config): void
    {
        $login = (string) getenv('ZNUNY_INTEGRATION_USERNAME');
        $ticket = $this->newTicket($this->znuny(), 'Time accounting '.uniqid(), article: ['timeUnit' => 15])->save();

        $entries = $this->znuny($config, $urlVariable)
            ->timeAccounting()
            ->entries($login, now()->subHour(), now()->addHour());

        $entry = $entries->firstWhere('ticketNumber', $ticket->ticketNumber);

        $this->assertNotNull($entry);
        $this->assertSame(15.0, $entry->timeUnit);
        $this->assertSame($ticket->title, $entry->ticketTitle);
        $this->assertSame('Raw', $entry->queue);
        $this->assertLessThan(300, abs($entry->created->diffInSeconds(now())));

        $this->assertCount(0, $this->znuny($config, $urlVariable)
            ->timeAccounting()
            ->entries($login, now()->subDays(3), now()->subDays(2)));

        $this->expectException(ZnunyAccessDeniedException::class);

        $this->admin($config, $urlVariable)
            ->timeAccounting()
            ->entries($login, now()->subHour(), now()->addHour());
    }

    #[DataProvider('outOfOfficeWebServices')]
    public function test_out_of_office_is_set_and_cleared(string $urlVariable, array $config): void
    {
        $login = (string) getenv('ZNUNY_INTEGRATION_USERNAME');
        $password = (string) getenv('ZNUNY_INTEGRATION_PASSWORD');
        $users = $this->admin($config, $urlVariable)->users();

        $users->setOutOfOffice($login, CarbonImmutable::parse('2032-03-04'), CarbonImmutable::parse('2032-03-11'));

        $data = $this->freshSessionData($login, $password);
        $this->assertEquals(1, $data['OutOfOffice']);
        $this->assertEquals([2032, 3, 4], [$data['OutOfOfficeStartYear'], $data['OutOfOfficeStartMonth'], $data['OutOfOfficeStartDay']]);
        $this->assertEquals([2032, 3, 11], [$data['OutOfOfficeEndYear'], $data['OutOfOfficeEndMonth'], $data['OutOfOfficeEndDay']]);

        $users->clearOutOfOffice($login);

        $this->assertEquals(0, $this->freshSessionData($login, $password)['OutOfOffice']);

        // By e-mail address, and an entry that matches nobody: Znuny reports success for both.
        $users->updateOutOfOffice([
            ['email' => $this->freshSessionData($login, $password)['UserEmail'], 'enabled' => true],
            ['login' => 'no-such-agent-'.uniqid(), 'enabled' => true],
        ]);

        $this->assertEquals(1, $this->freshSessionData($login, $password)['OutOfOffice']);

        $users->clearOutOfOffice($login);

        $this->expectException(ZnunyAccessDeniedException::class);

        $this->znuny($config, $urlVariable)->users()->clearOutOfOffice($login);
    }

    public function test_out_of_office_entries_with_special_characters_stay_separate(): void
    {
        $login = (string) getenv('ZNUNY_INTEGRATION_USERNAME');
        $password = (string) getenv('ZNUNY_INTEGRATION_PASSWORD');

        // Znuny's own web service reads the entries from CSV. The second entry only arrives
        // intact if the comma and the quotes of the first stayed inside its field.
        $users = $this->admin(['auth' => 'credentials'], 'ZNUNY_INTEGRATION_OUT_OF_OFFICE_URL')->users();

        $users->updateOutOfOffice([
            ['search' => 'Nobody, "at all"', 'enabled' => true],
            ['login' => $login, 'from' => CarbonImmutable::parse('2034-05-06'), 'until' => CarbonImmutable::parse('2034-05-13')],
        ]);

        $data = $this->freshSessionData($login, $password);

        $this->assertEquals(1, $data['OutOfOffice']);
        $this->assertEquals([2034, 5, 6], [$data['OutOfOfficeStartYear'], $data['OutOfOfficeStartMonth'], $data['OutOfOfficeStartDay']]);
        $this->assertEquals([2034, 5, 13], [$data['OutOfOfficeEndYear'], $data['OutOfOfficeEndMonth'], $data['OutOfOfficeEndDay']]);

        $users->clearOutOfOffice($login);

        $this->assertEquals(0, $this->freshSessionData($login, $password)['OutOfOffice']);
    }

    /*
     * What Znuny itself does with the operations the package wraps. These tests call Znuny
     * directly, so a change in Znuny shows up here and not as a puzzling failure elsewhere.
     */

    public function test_znuny_returns_the_user_of_a_session(): void
    {
        $login = (string) getenv('ZNUNY_INTEGRATION_USERNAME');
        $agent = $this->freshSessionData($login, (string) getenv('ZNUNY_INTEGRATION_PASSWORD'));

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $agent['UserID']);
        $this->assertSame($login, $agent['UserLogin']);
        $this->assertSame('User', $agent['UserType']);

        foreach (['UserFirstname', 'UserLastname', 'UserFullname', 'UserEmail'] as $key) {
            $this->assertNotSame('', (string) ($agent[$key] ?? ''), $key);
        }

        $this->assertArrayNotHasKey('UserPw', $agent);
        $this->assertArrayNotHasKey('UserChallengeToken', $agent);

        $password = getenv('ZNUNY_INTEGRATION_CUSTOMER_PASSWORD');

        if ($password) {
            $customer = $this->freshSessionData($this->customerUser(), $password, 'customer');

            $this->assertSame($this->customerUser(), $customer['UserLogin']);
            $this->assertSame($this->customerUser(), $customer['UserID']);
            $this->assertSame('Customer', $customer['UserType']);
        }
    }

    public function test_znuny_books_time_units_for_the_time_accounting_web_service(): void
    {
        // Znuny's TimeAccountingREST web service has this one route and no session route.
        $service = $this->znuny(['auth' => 'credentials'], 'ZNUNY_INTEGRATION_TIME_ACCOUNTING_URL');
        $ticket = $this->newTicket($this->znuny(), 'Time accounting '.uniqid(), article: ['timeUnit' => 15])->save();

        $window = fn (int $from, int $until): array => [
            'TimeAccountingUserLogin' => getenv('ZNUNY_INTEGRATION_USERNAME'),
            'TimeAccountingStart' => CarbonImmutable::now($this->timezone())->addHours($from)->format('Y-m-d H:i:s'),
            'TimeAccountingEnd' => CarbonImmutable::now($this->timezone())->addHours($until)->format('Y-m-d H:i:s'),
        ];

        $row = collect($service->call('TimeAccountingGet', $window(-1, 1))['TimeAccountingResult'] ?? [])
            ->firstWhere('TicketNumber', $ticket->ticketNumber);

        $this->assertIsArray($row);
        $this->assertEquals(15, $row['TimeUnit']);
        $this->assertSame($ticket->title, $row['TicketTitle']);
        $this->assertSame('Raw', $row['Queue']);
        $this->assertArrayHasKey('TicketCustomerID', $row);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['Created']);

        // Without entries Znuny leaves the key out.
        $this->assertArrayNotHasKey('TimeAccountingResult', $service->call('TimeAccountingGet', $window(-48, -24)));

        try {
            $this->admin(['auth' => 'credentials'], 'ZNUNY_INTEGRATION_TIME_ACCOUNTING_URL')->call('TimeAccountingGet', $window(-1, 1));
            $this->fail('An agent outside the group timeaccounting_webservice must be refused.');
        } catch (ZnunyException $e) {
            $this->assertSame('TimeAccountingGet.NoPermission', $e->getErrorCode());
        }
    }

    /**
     * Znuny ships the operation in a web service of its own, which has no session route and
     * builds the entries from the CSV string. The generated web service has the session
     * routes and no mapping.
     *
     * @return array<string, array{0: string, 1: array<string, string>}> URL variable and connection settings
     */
    public static function outOfOfficeWebServices(): array
    {
        return [
            "Znuny's OutOfOffice web service, with credentials" => ['ZNUNY_INTEGRATION_OUT_OF_OFFICE_URL', ['auth' => 'credentials']],
            'a generated web service, with a session' => ['ZNUNY_INTEGRATION_ALL_OPERATIONS_URL', []],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}> URL variable and connection settings
     */
    public static function timeAccountingWebServices(): array
    {
        return [
            "Znuny's TimeAccountingREST web service, with credentials" => ['ZNUNY_INTEGRATION_TIME_ACCOUNTING_URL', ['auth' => 'credentials']],
            'a generated web service, with a session' => ['ZNUNY_INTEGRATION_ALL_OPERATIONS_URL', []],
        ];
    }

    #[DataProvider('outOfOfficeWebServices')]
    public function test_znuny_sets_out_of_office_from_entries_and_csv(string $urlVariable, array $config): void
    {
        $login = (string) getenv('ZNUNY_INTEGRATION_USERNAME');
        $password = (string) getenv('ZNUNY_INTEGRATION_PASSWORD');
        $columns = ['UserLogin', 'UserSearch', 'UserEmail', 'StartDate', 'EndDate', 'OutOfOffice'];

        // The mapping reads the CSV string and fails without it; without mapping the operation reads the entries.
        $payload = fn (array $entry): array => [
            'OutOfOfficeEntries' => [$entry],
            'OutOfOfficeEntriesCSVString' => implode(',', $columns)."\n"
                .implode(',', array_map(fn (string $column): string => '"'.($entry[$column] ?? '').'"', $columns))."\n",
        ];

        $admin = $this->admin($config, $urlVariable);
        $admin->call('OutOfOffice', $payload(['UserLogin' => $login, 'StartDate' => '2031-02-03', 'EndDate' => '2031-02-10', 'OutOfOffice' => 1]));

        // No operation reads the setting of another user; that user's own session data shows it.
        $data = $this->freshSessionData($login, $password);

        $this->assertEquals(1, $data['OutOfOffice']);
        $this->assertEquals([2031, 2, 3], [$data['OutOfOfficeStartYear'], $data['OutOfOfficeStartMonth'], $data['OutOfOfficeStartDay']]);
        $this->assertEquals([2031, 2, 10], [$data['OutOfOfficeEndYear'], $data['OutOfOfficeEndMonth'], $data['OutOfOfficeEndDay']]);

        $admin->call('OutOfOffice', $payload(['UserLogin' => $login, 'OutOfOffice' => 0]));

        $this->assertEquals(0, $this->freshSessionData($login, $password)['OutOfOffice']);

        try {
            $this->znuny($config, $urlVariable)->call('OutOfOffice', $payload(['UserLogin' => $login, 'OutOfOffice' => 0]));
            $this->fail('An agent outside the group admin must be refused.');
        } catch (ZnunyException $e) {
            $this->assertSame('OutOfOffice.AuthFail', $e->getErrorCode());
            $this->assertStringContainsString('group admin', $e->getMessage());
        }
    }

    public function test_znuny_accepts_dates_in_dynamic_fields_in_its_own_format_only(): void
    {
        $znuny = $this->znuny();
        $title = 'Dynamic field dates '.uniqid();

        $create = fn (string $field, string $value): array => $znuny->call('TicketCreate', [
            'Ticket' => ['Title' => $title, 'Queue' => 'Raw', 'State' => 'new', 'Priority' => '3 normal', 'CustomerUser' => $this->customerUser()],
            'Article' => ['Subject' => $title, 'Body' => 'Created by the laravel-znuny integration suite.', 'ContentType' => 'text/plain; charset=utf8'],
            'DynamicField' => [['Name' => $field, 'Value' => $value]],
        ]);

        $dueAt = (string) $create('DueAt', '2031-02-03 10:00:00')['TicketID'];
        $dueOn = (string) $create('DueOn', '2031-02-03 00:00:00')['TicketID'];

        foreach ([['DueAt', '2031-02-03T10:00:00.000000Z'], ['DueOn', '2031-02-03 10:00:00']] as [$field, $value]) {
            try {
                $create($field, $value);
                $this->fail("Znuny must refuse [{$value}] for {$field}.");
            } catch (ZnunyException $e) {
                $this->assertSame('TicketCreate.InvalidParameter', $e->getErrorCode());
            }
        }

        $search = fn (array $conditions): array => array_map('strval', (array) ($znuny->call('TicketSearch', ['Title' => $title] + $conditions)['TicketID'] ?? []));

        $this->assertSame([$dueAt], $search(['DynamicField_DueAt' => ['GreaterThan' => '2031-02-03 09:00:00', 'SmallerThan' => '2031-02-03 11:00:00']]));
        $this->assertSame([$dueOn], $search(['DynamicField_DueOn' => ['Equals' => '2031-02-03 00:00:00']]));
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

    public function test_change_filters_compare_the_last_change_and_the_history(): void
    {
        $znuny = $this->znuny();
        $title = 'Last change '.uniqid();
        $ticket = $this->createTicket($znuny, $title);

        // A moment on Znuny's own clock, so the clock of this machine does not matter.
        $between = $ticket->created->copy()->addSecond();
        sleep(3);
        $znuny->tickets()->update($ticket->id, ['state' => 'open']);
        $search = fn () => $znuny->tickets()->where('Title', $title);

        $this->assertSame([], $search()->whereChangedBefore($between)->ids()->all());
        $this->assertSame([], $search()->whereLastChangedBefore($between)->ids()->all());
        $this->assertSame([$ticket->id], $search()->whereLastChangedAfter($between)->ids()->all());
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

    #[DataProvider('webServices')]
    public function test_named_filters_find_tickets(string $urlVariable, string $routes): void
    {
        $znuny = $this->znuny([], $urlVariable, $routes);
        $title = 'Filters '.uniqid();
        $word = 'Quokka'.uniqid();
        $ticket = $this->newTicket($znuny, $title, article: ['body' => "A {$word} sat on the printer."])->save();

        $this->assertSame([$ticket->id], $znuny->tickets()->whereTicketNumber($ticket->ticketNumber)->ids()->all());
        $this->assertSame([$ticket->id], $znuny->tickets()->whereTitle($title)->ids()->all());
        $this->assertSame([$ticket->id], $znuny->tickets()->whereCustomerUser($this->customerUser())->whereTitle($title)->ids()->all());
        $this->assertSame([$ticket->id], $znuny->tickets()->whereFullText($word)->ids()->all());
        $this->assertSame(1, $znuny->tickets()->whereLock('unlock')->whereTitle($title)->count());

        $znuny->tickets()->update($ticket->id, ['lock' => 'lock']);

        $this->assertSame(1, $znuny->tickets()->whereLock('lock')->whereTitle($title)->count());
        $this->assertSame(0, $znuny->tickets()->whereLock('unlock')->whereTitle($title)->count());
    }

    public function test_ticket_actions(): void
    {
        $znuny = $this->znuny();
        $ticket = $this->createTicket($znuny, 'Actions '.uniqid());
        $find = fn () => $znuny->tickets()->find($ticket->id);

        $znuny->tickets()->lock($ticket->id);
        $this->assertSame('lock', $find()->lock);

        $znuny->tickets()->unlock($ticket->id);
        $this->assertSame('unlock', $find()->lock);

        $znuny->tickets()->assign($ticket->id, 'root@localhost');
        $this->assertSame('root@localhost', $find()->owner);

        $znuny->tickets()->move($ticket->id, 'Misc');
        $this->assertSame('Misc', $find()->queue);

        // Minute 0 on purpose, and a time zone that is neither UTC nor Znuny's.
        $until = CarbonImmutable::now('Asia/Tokyo')->addDays(3)->setTime(14, 0);
        $znuny->tickets()->setPending($ticket->id, $until);

        $pending = $find();
        $this->assertSame('pending reminder', $pending->state);
        $this->assertSame($until->getTimestamp(), $pending->pendingUntil?->getTimestamp());

        $znuny->tickets()->update($ticket->id, ['state' => 'open']);
        $this->assertNull($find()->pendingUntil);
    }

    public function test_find_by_number(): void
    {
        $znuny = $this->znuny();
        $ticket = $this->createTicket($znuny, 'By number '.uniqid());

        $this->assertSame($ticket->id, $znuny->tickets()->findByNumber($ticket->ticketNumber)->id);

        // Znuny's search would match these; the exact lookup must not.
        foreach (['0', '*', substr($ticket->ticketNumber, 0, -1).'_', ''] as $number) {
            try {
                $znuny->tickets()->findByNumber($number);
                $this->fail("findByNumber('{$number}') returned a ticket");
            } catch (TicketNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_find_many_skips_unknown_ids(): void
    {
        $znuny = $this->znuny();
        $a = $this->createTicket($znuny, 'Skip A '.uniqid());
        $b = $this->createTicket($znuny, 'Skip B '.uniqid());
        $c = $this->createTicket($znuny, 'Skip C '.uniqid());

        // Znuny rejects the whole batch; the package halves it until the unknown ticket is isolated.
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $znuny->tickets()->findMany([$a->id, $b->id, '999999999', $c->id])->pluck('id')->all(),
        );
    }

    public function test_a_search_is_complete_or_fails(): void
    {
        $title = 'Search limit '.uniqid();
        $tickets = [];

        foreach (range(1, 3) as $number) {
            $tickets[] = $this->createTicket($this->znuny(), $title)->id;
        }

        $search = fn (?int $searchLimit) => $this->znuny(['search_limit' => $searchLimit])->tickets()->where('Title', $title);

        $this->assertEqualsCanonicalizing($tickets, $search(3)->ids()->all());
        $this->assertEqualsCanonicalizing($tickets, $search(null)->ids()->all());
        $this->assertCount(2, $search(2)->limit(2)->ids());

        $this->expectException(ZnunySearchLimitExceededException::class);

        $search(2)->ids();
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
        // Znuny up to 6.5.11 and 7.1 authenticates SessionRemove with credentials, newer versions do not.
        $znuny->call('SessionDelete', [
            'SessionID' => $znuny->client()->sessionId(),
            'UserLogin' => getenv('ZNUNY_INTEGRATION_USERNAME'),
            'Password' => getenv('ZNUNY_INTEGRATION_PASSWORD'),
        ]);
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

        $live = ['base_url' => getenv('ZNUNY_INTEGRATION_URL'), 'timezone' => $this->timezone()] + $credentials;
        config(['znuny.connections.live' => $live]);
        $this->createTicket($this->znuny(), 'Artisan '.uniqid());

        $this->artisan('znuny:test-connection', ['connection' => 'live'])
            ->expectsOutputToContain('Time zone')
            ->doesntExpectOutputToContain('not verified')
            ->expectsOutputToContain('Znuny connection [live] OK')
            ->assertSuccessful();

        // A time zone that is hours away from Znuny's.
        $offset = (new DateTimeZone($this->timezone()))->getOffset(new DateTimeImmutable);
        $elsewhere = $offset > 5 * 3600 ? 'America/New_York' : 'Asia/Tokyo';
        config(['znuny.connections.elsewhere' => ['timezone' => $elsewhere] + $live]);

        $this->artisan('znuny:test-connection', ['connection' => 'elsewhere'])
            ->expectsOutputToContain('ZNUNY_TIMEZONE')
            ->assertFailed();

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
            'ZNUNY_TIMEZONE' => $this->timezone(),
        ]);
        $server->start();

        // Fails when the server is gone, e.g. because another process already uses the port.
        $running = function () use ($server, $listen): void {
            if (! $server->isRunning()) {
                $this->fail("The webhook server on {$listen} exited: ".trim($server->getErrorOutput()));
            }
        };

        try {
            usleep(300_000);

            $this->waitFor(function () use ($running, $port): bool {
                $running();
                $socket = @fsockopen('127.0.0.1', $port);

                return $socket !== false && fclose($socket);
            }, "the webhook server on port {$port}");

            $ticket = $this->createTicket($this->znuny(), 'Webhook '.uniqid());

            $received = $this->waitFor(function () use ($running, $capture, $ticket): ?array {
                $running();

                // A line that is still being written does not decode yet.
                return collect(file($capture, FILE_IGNORE_NEW_LINES) ?: [])
                    ->map(fn (string $line): mixed => json_decode($line, true))
                    ->first(fn (mixed $event): bool => is_array($event) && ($event['ticketId'] ?? null) === $ticket->id);
            }, "the TicketCreate webhook of ticket {$ticket->id}");
        } finally {
            $server->stop();
            @unlink($capture);
        }

        // The generated requester posts every event to /znuny/webhooks; the name comes from the payload.
        $this->assertSame('TicketCreate', $received['event']);
        $this->assertSame([], $received['parameters']);
        $this->assertSame($ticket->title, $received['ticket']['Title']);
        $this->assertSame($this->customerUser(), $received['ticket']['CustomerUser']);
        $this->assertSame($ticket->created->getTimestamp(), $received['createdTimestamp']);
        $this->assertStringContainsString('@', (string) $received['customerEmail']);
    }
}
