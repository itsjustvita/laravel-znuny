<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestSent;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketCreated;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Testing\FakeZnunyManager;
use ItsJustVita\LaravelZnuny\Testing\StrayZnunyCallException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use PHPUnit\Framework\ExpectationFailedException;
use Znuny;

final class ZnunyFakeTest extends TestCase
{
    public function test_resources_and_dtos_run_against_the_fake(): void
    {
        Znuny::fake(['TicketGet' => Znuny::fakeTickets(['TicketID' => '42', 'Title' => 'Printer on fire'])]);

        $ticket = Znuny::tickets()->find('42');

        $this->assertSame('Printer on fire', $ticket->title);
        $this->assertSame('42', $ticket->id);
        Znuny::assertCalled('TicketGet', fn (array $payload) => $payload['TicketID'] === '42');
        Znuny::assertCalledTimes('TicketGet', 1);
    }

    public function test_fake_tickets_are_complete_and_numbered_by_position(): void
    {
        Znuny::fake([
            'TicketSearch' => ['TicketID' => ['1', '2']],
            'TicketGet' => Znuny::fakeTickets(['Title' => 'First'], ['Title' => 'Second']),
        ]);

        $tickets = Znuny::tickets()->query()->get();

        $this->assertSame(['1', '2'], $tickets->pluck('id')->all());
        $this->assertSame(['First', 'Second'], $tickets->pluck('title')->all());
        $this->assertNotSame('', $tickets->first()?->ticketNumber);
        $this->assertNotSame('', $tickets->first()?->state);
        $this->assertNotNull($tickets->first()?->created);
    }

    public function test_ticket_creation_returns_the_created_ticket(): void
    {
        Event::fake([ZnunyTicketCreated::class]);
        Znuny::fake([
            'TicketCreate' => ['TicketID' => '43', 'TicketNumber' => '2026110643', 'ArticleID' => '7'],
            'TicketGet' => Znuny::fakeTickets(['TicketID' => '43', 'Queue' => 'Support']),
        ]);

        $ticket = Znuny::tickets()->create(['title' => 'Printer on fire', 'queue' => 'Support', 'customerUser' => 'jane'])
            ->withArticle(['subject' => 'Help', 'body' => 'Please'])
            ->save();

        $this->assertSame('43', $ticket->id);
        Znuny::assertCalled('TicketCreate', fn (array $payload) => $payload['Ticket']['Queue'] === 'Support'
            && $payload['Article']['ContentType'] === 'text/plain; charset=utf8');
        Event::assertDispatched(ZnunyTicketCreated::class);
    }

    public function test_closures_exceptions_and_sequences(): void
    {
        Znuny::fake([
            'TicketHistoryGet' => fn (array $payload, string $connection) => ['TicketHistory' => [['TicketID' => $payload['TicketID'], 'History' => [
                ['HistoryType' => 'NewTicket', 'Name' => "on {$connection}", 'CreateTime' => '2026-07-01 08:00:00'],
            ]]]],
            'TicketUpdate' => new ZnunyAccessDeniedException('TicketUpdate: no permission'),
            'TicketSearch' => Znuny::sequence(['TicketID' => ['1']], ['TicketID' => []]),
        ]);

        $this->assertSame('on default', Znuny::tickets()->history('9')->first()?->name);

        try {
            Znuny::tickets()->close('9');
            $this->fail('Expected a ZnunyAccessDeniedException');
        } catch (ZnunyAccessDeniedException $e) {
            $this->assertSame('TicketUpdate', $e->getOperation());
            $this->assertSame('default', $e->getConnection());
        }

        $this->assertSame(['1'], Znuny::tickets()->query()->ids()->all());
        $this->assertSame([], Znuny::tickets()->query()->ids()->all());

        $this->expectException(StrayZnunyCallException::class);
        $this->expectExceptionMessage('TicketSearch');

        Znuny::tickets()->query()->ids();
    }

    public function test_an_operation_without_a_response_fails_loudly(): void
    {
        Znuny::fake(['TicketGet' => Znuny::fakeTickets([])]);

        $this->expectException(StrayZnunyCallException::class);
        $this->expectExceptionMessage('Znuny::fake() has no response for operation [TicketHistoryGet] (connection [default]).');

        Znuny::tickets()->history('1');
    }

    public function test_a_wildcard_answers_every_other_operation(): void
    {
        Znuny::fake([
            'TicketSearch' => ['TicketID' => ['5']],
            '*' => ['TicketID' => '5'],
        ]);

        $this->assertSame(['5'], Znuny::tickets()->query()->ids()->all());
        Znuny::tickets()->lock('5');

        Znuny::assertCalled('TicketUpdate', fn (array $payload) => $payload['Ticket'] === ['Lock' => 'lock']);
    }

    public function test_assertions_fail_when_they_should(): void
    {
        Znuny::fake(['*' => []]);
        Znuny::call('TicketSearch', ['Title' => 'x']);

        $failures = 0;

        foreach ([
            fn () => Znuny::assertNotCalled('TicketSearch'),
            fn () => Znuny::assertCalled('TicketGet'),
            fn () => Znuny::assertCalled('TicketSearch', fn (array $payload) => $payload['Title'] === 'y'),
            fn () => Znuny::assertCalledTimes('TicketSearch', 2),
            fn () => Znuny::assertNothingCalled(),
        ] as $assertion) {
            try {
                $assertion();
            } catch (ExpectationFailedException) {
                $failures++;
            }
        }

        $this->assertSame(5, $failures);
    }

    public function test_calls_are_recorded_per_connection(): void
    {
        config(['znuny.connections.tenant-a' => ['base_url' => 'https://tenant-a.test/ws', 'username' => 'a', 'password' => 'a']]);
        Znuny::fake(['TicketSearch' => ['TicketID' => []]]);

        Znuny::connection('tenant-a')->call('TicketSearch');
        Znuny::build(['name' => 'runtime', 'base_url' => 'https://runtime.test/ws', 'username' => 'r', 'password' => 'r'])->call('TicketSearch');
        Znuny::call('TicketSearch');

        $this->assertSame(['tenant-a', 'runtime', 'default'], array_column(Znuny::recorded('TicketSearch'), 'connection'));
        Znuny::assertCalled('TicketSearch', fn (array $payload, string $connection) => $connection === 'runtime');
    }

    public function test_the_fake_works_without_a_configured_connection(): void
    {
        config(['znuny.connections.default' => ['base_url' => null, 'username' => null, 'password' => null]]);
        Znuny::fake(['TicketGet' => Znuny::fakeTickets(['TicketID' => '1'])]);

        $this->assertSame('1', Znuny::tickets()->find('1')->id);
        $this->assertTrue(Znuny::ping());
    }

    public function test_injected_managers_get_the_fake(): void
    {
        Znuny::fake(['TicketGet' => Znuny::fakeTickets(['TicketID' => '1'])]);

        $manager = $this->app->make(ZnunyManager::class);

        $this->assertInstanceOf(FakeZnunyManager::class, $manager);
        $this->assertSame('1', $manager->tickets()->find('1')->id);
        Znuny::assertCalledTimes('TicketGet', 1);
    }

    public function test_the_configured_time_zone_applies(): void
    {
        config(['znuny.timezone' => 'Europe/Berlin']);
        Znuny::fake(['TicketGet' => Znuny::fakeTickets(['TicketID' => '1', 'Created' => '2026-07-01 08:00:00'])]);

        $this->assertSame(strtotime('2026-07-01 06:00:00 UTC'), Znuny::tickets()->find('1')->created?->getTimestamp());
    }

    public function test_nothing_is_sent_over_http(): void
    {
        Event::fake([ZnunyRequestSent::class]);
        Http::fake();
        Znuny::fake(['*' => ['TicketID' => ['1']]]);

        Znuny::call('TicketSearch');
        Znuny::ping();
        Znuny::client()->sessionId();

        Http::assertNothingSent();
        Event::assertNotDispatched(ZnunyRequestSent::class);
    }
}
