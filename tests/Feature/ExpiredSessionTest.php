<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed;
use ItsJustVita\LaravelZnuny\Events\ZnunySessionRefreshed;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

/**
 * Znuny drops a session after two hours without a request, long before the cached session
 * ID runs out. The package then logs in again and repeats the request.
 */
final class ExpiredSessionTest extends TestCase
{
    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(function (MessageLogged $message): void {
            $this->logged[] = ['level' => $message->level, 'message' => $message->message];
        });
        Event::listen(function (ZnunyRequestFailed $event): void {
            $this->events[] = $event;
        });
        Event::listen(function (ZnunySessionRefreshed $event): void {
            $this->events[] = $event;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $searches  the answers to TicketSearch, in order
     */
    private function fakeZnuny(array $searches): void
    {
        $expired = ['Error' => ['ErrorCode' => 'TicketSearch.AuthFail', 'ErrorMessage' => 'TicketSearch: Authorization failing!']];

        Http::fake([
            '*SessionCreate' => Http::sequence()->push(['SessionID' => 'OLD'])->push(['SessionID' => 'NEW']),
            '*TicketSearch' => Http::sequence(array_map(
                fn (string|array $search): array => $search === 'expired' ? $expired : $search,
                $searches,
            )),
        ]);
    }

    /**
     * @return list<string>
     */
    private function logged(string $level): array
    {
        return array_column(array_filter($this->logged, fn (array $entry): bool => $entry['level'] === $level), 'message');
    }

    /**
     * @return list<class-string>
     */
    private function eventClasses(): array
    {
        return array_map(get_class(...), $this->events);
    }

    public function test_an_expired_session_that_is_replaced_is_no_error(): void
    {
        $this->fakeZnuny(['expired', ['TicketID' => ['7']]]);

        $this->assertSame(['TicketID' => ['7']], Znuny::call('TicketSearch'));

        $this->assertSame([], $this->logged('error'));
        $this->assertContains('Znuny session expired, logging in again', $this->logged('info'));
        $this->assertSame([ZnunySessionRefreshed::class], $this->eventClasses());
    }

    public function test_a_session_that_is_rejected_again_right_after_logging_in_is_an_error(): void
    {
        $this->fakeZnuny(['expired', 'expired']);

        try {
            Znuny::call('TicketSearch');
            $this->fail('The second rejection must reach the caller.');
        } catch (ZnunySessionExpiredException) {
        }

        $this->assertSame(['Znuny error'], $this->logged('error'));
        $this->assertSame([ZnunySessionRefreshed::class, ZnunyRequestFailed::class], $this->eventClasses());
    }

    public function test_an_expired_session_is_an_error_when_it_is_not_replaced(): void
    {
        config(['znuny.session.auto_retry_on_expired' => false]);
        $this->fakeZnuny(['expired']);

        try {
            Znuny::call('TicketSearch');
            $this->fail('Without the retry the expired session must reach the caller.');
        } catch (ZnunySessionExpiredException) {
        }

        $this->assertSame(['Znuny error'], $this->logged('error'));
        $this->assertSame([ZnunyRequestFailed::class], $this->eventClasses());
    }
}
