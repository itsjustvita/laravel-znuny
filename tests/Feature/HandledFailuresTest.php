<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestSent;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

/**
 * An error entry in the log and a ZnunyRequestFailed event mean: a failure reached the
 * application. A failure the package deals with itself is information.
 */
final class HandledFailuresTest extends TestCase
{
    private const DENIED = ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'TicketGet: User does not have access to the ticket!']];

    private const HANDLED = 'Znuny failure handled by the package';

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    /** @var list<ZnunyRequestFailed> */
    private array $failed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(function (MessageLogged $message): void {
            $this->logged[] = ['level' => $message->level, 'message' => $message->message];
        });
        Event::listen(function (ZnunyRequestFailed $event): void {
            $this->failed[] = $event;
        });
    }

    /**
     * @return list<string>
     */
    private function logged(string $level): array
    {
        return array_column(array_filter($this->logged, fn (array $entry): bool => $entry['level'] === $level), 'message');
    }

    /**
     * Znuny rejects a whole batch when one of its tickets is unreadable; here that is ticket 2.
     *
     * @param  mixed  $batch  the answer to the batch of all three
     */
    private function fakeThreeTickets(mixed $batch): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => []]),
            '*TicketGet/1,2,3' => $batch,
            '*TicketGet/1' => Http::response(['Ticket' => [['TicketID' => '1']]]),
            '*TicketGet/2' => Http::response(self::DENIED),
            '*TicketGet/3' => Http::response(['Ticket' => [['TicketID' => '3']]]),
        ]);
    }

    public function test_a_rejected_batch_that_is_split_is_no_error(): void
    {
        $this->fakeThreeTickets(Http::response(self::DENIED));

        $tickets = Znuny::tickets()->findMany(['1', '2', '3']);

        $this->assertSame(['1', '3'], $tickets->pluck('id')->all());
        $this->assertSame([], $this->logged('error'));
        $this->assertSame([], $this->failed);
        // The batch and the single ticket 2.
        $this->assertSame(2, count(array_keys($this->logged('info'), self::HANDLED, true)));
    }

    public function test_a_failure_the_scope_does_not_handle_is_still_an_error(): void
    {
        config(['znuny.connections.default.retries' => 0]);
        $this->fakeThreeTickets(Http::response('Bad Gateway', 502));

        try {
            Znuny::tickets()->findMany(['1', '2', '3']);
            $this->fail('A server error must reach the caller.');
        } catch (ZnunyServerException) {
        }

        $this->assertSame(['Znuny error'], $this->logged('error'));
        $this->assertCount(1, $this->failed);
        $this->assertNotContains(self::HANDLED, $this->logged('info'));
    }

    public function test_a_call_made_by_a_listener_does_not_switch_the_handling_off(): void
    {
        $this->fakeThreeTickets(Http::response(self::DENIED));

        $nested = 0;
        Event::listen(function (ZnunyRequestSent $event) use (&$nested): void {
            if ($event->operation === 'TicketGet' && $nested++ === 0) {
                Znuny::call('TicketSearch');
            }
        });

        $this->assertCount(2, Znuny::tickets()->findMany(['1', '2', '3']));

        $this->assertGreaterThanOrEqual(1, $nested);
        $this->assertSame([], $this->logged('error'));
        $this->assertSame([], $this->failed);
    }

    public function test_a_listeners_own_failure_is_an_error_although_the_package_is_handling_another(): void
    {
        $this->fakeThreeTickets(Http::response(self::DENIED));

        // Application code that runs while the package fetches a batch: its failures are its own.
        $caught = 0;
        Event::listen(function (ZnunyRequestSent $event) use (&$caught): void {
            static $done = false;

            if ($event->operation === 'TicketGet' && ! $done) {
                $done = true;

                try {
                    Znuny::tickets()->find('2');
                } catch (ZnunyAccessDeniedException) {
                    $caught++;
                }
            }
        });

        $this->assertCount(2, Znuny::tickets()->findMany(['1', '2', '3']));

        $this->assertSame(1, $caught);
        $this->assertSame(['Znuny error'], $this->logged('error'));
        $this->assertCount(1, $this->failed);
    }

    public function test_a_created_ticket_that_cannot_be_read_back_logs_a_warning_only(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate' => Http::response(['TicketID' => '42', 'TicketNumber' => '2026112710000042', 'ArticleID' => '7']),
            '*TicketGet*' => Http::response(self::DENIED),
        ]);

        $ticket = Znuny::tickets()->create(['title' => 't', 'queue' => 'Raw', 'state' => 'new', 'priority' => '3 normal', 'customerUser' => 'jane'])
            ->withArticle(['subject' => 's', 'body' => 'b'])
            ->save();

        $this->assertSame('42', $ticket->id);
        $this->assertCount(1, $this->logged('warning'));
        $this->assertSame([], $this->logged('error'));
        $this->assertSame([], $this->failed);
    }

    public function test_logout_of_an_unknown_session_is_no_error(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*SessionDelete' => Http::response(['Error' => ['ErrorCode' => 'SessionRemove.Fail', 'ErrorMessage' => 'SessionRemove: Could not remove session.']]),
        ]);

        Znuny::client()->sessionId();
        Znuny::session()->logout();

        $this->assertSame([], $this->logged('error'));
        $this->assertSame([], $this->failed);
        $this->assertContains(self::HANDLED, $this->logged('info'));
    }

    public function test_a_dropped_session_in_credentials_mode_is_no_error(): void
    {
        config(['znuny.connections.default.auth' => 'credentials']);

        Http::fake([
            '*SessionCreate' => Http::sequence()->push(['SessionID' => 'OLD'])->push(['SessionID' => 'NEW']),
            '*SessionGet' => Http::sequence()
                ->push(['Error' => ['ErrorCode' => 'SessionGet.SessionInvalid', 'ErrorMessage' => 'SessionGet: SessionID is Invalid!']])
                ->push(['SessionData' => [['Key' => 'UserLogin', 'Value' => 'testuser']]]),
        ]);

        $this->assertSame('testuser', Znuny::session()->user()->login);

        $this->assertSame([], $this->logged('error'));
        $this->assertSame([], $this->failed);
        $this->assertContains(self::HANDLED, $this->logged('info'));
    }

    public function test_session_user_fails_when_znuny_sends_no_session_data(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*SessionGet' => Http::response('{}'),
        ]);

        try {
            Znuny::session()->user();
            $this->fail('A response without session data is no user.');
        } catch (ZnunyServerException $e) {
            $this->assertSame('Znuny answered SessionGet without SessionData.', $e->getMessage());
            $this->assertSame('SessionGet', $e->getOperation());
            $this->assertSame('default', $e->getConnection());
        }
    }
}
