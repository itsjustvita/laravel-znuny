<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySearchLimitExceededException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class TicketBuilderTest extends TestCase
{
    public function test_ids_sends_ticket_search_and_returns_collection_of_ids(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3']]),
        ]);

        $ids = Znuny::tickets()->where('CustomerID', '12345')->ids();

        $this->assertInstanceOf(Collection::class, $ids);
        $this->assertSame(['1', '2', '3'], $ids->all());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && ($r['CustomerID'] ?? null) === '12345');
    }

    public function test_where_chain_translates_to_search_params(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()
            ->where('CustomerID', '12345')
            ->whereState('open')
            ->whereQueue('Support')
            ->orderByDesc('Created')
            ->limit(50)
            ->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && ($r['CustomerID'] ?? null) === '12345'
            && ($r['States'] ?? null) === ['open']
            && ($r['Queues'] ?? null) === ['Support']
            && ($r['SortBy'] ?? null) === 'Created'
            && ($r['OrderBy'] ?? null) === 'Down'
            && ($r['Limit'] ?? null) === 50);
    }

    public function test_with_a_search_limit_searches_ask_for_one_ticket_more(): void
    {
        config(['znuny.connections.default.search_limit' => 500]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2']]),
        ]);

        $this->assertSame(['1', '2'], Znuny::tickets()->where('CustomerID', '12345')->ids()->all());

        // One more than allowed shows whether Znuny has more tickets than are returned.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch') && $r['Limit'] === 501);
    }

    public function test_a_limit_that_znuny_ignores_does_not_switch_the_search_limit_off(): void
    {
        config(['znuny.connections.default.search_limit' => 3]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2']]),
        ]);

        // Znuny reads 0 and '' as "no limit given" and falls back to its own SearchLimit.
        Znuny::tickets()->whereState('open')->limit(0)->ids();
        Znuny::tickets()->where('Limit', '')->ids();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch') && $r['Limit'] !== 4);
    }

    public function test_a_result_of_exactly_500_tickets_is_logged_as_a_likely_cut_off(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::sequence()
                ->push(['TicketID' => array_map('strval', range(1, 500))])
                ->push(['TicketID' => array_map('strval', range(1, 499))])
                ->push(['TicketID' => array_map('strval', range(1, 500))]),
        ]);

        $warnings = [];
        Event::listen(function (MessageLogged $message) use (&$warnings): void {
            if ($message->level === 'warning') {
                $warnings[] = $message->message;
            }
        });

        Znuny::tickets()->whereState('open')->ids();           // 500: Znuny's default SearchLimit
        Znuny::tickets()->whereState('open')->ids();           // 499: complete
        Znuny::tickets()->whereState('open')->limit(500)->ids(); // 500, but asked for

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('search_limit', $warnings[0]);
    }

    public function test_a_search_that_matches_more_than_the_search_limit_fails_instead_of_returning_a_part(): void
    {
        config(['znuny.connections.default.search_limit' => 3]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4']]),
        ]);

        try {
            Znuny::tickets()->whereState('open')->ids();
            $this->fail('Expected a ZnunySearchLimitExceededException');
        } catch (ZnunySearchLimitExceededException $e) {
            $this->assertStringContainsString('more than 3 tickets', $e->getMessage());
            $this->assertStringContainsString('limit()', $e->getMessage());
            $this->assertSame('TicketSearch', $e->getOperation());
            $this->assertSame('default', $e->getConnection());
            $this->assertSame(4, $e->getRequestPayload()['Limit']);
        }
    }

    public function test_a_search_that_matches_exactly_the_search_limit_is_complete(): void
    {
        config(['znuny.connections.default.search_limit' => 3]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3']]),
        ]);

        $this->assertSame(['1', '2', '3'], Znuny::tickets()->whereState('open')->ids()->all());
    }

    public function test_an_explicit_limit_accepts_a_partial_result(): void
    {
        config(['znuny.connections.default.search_limit' => 3]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4', '5']]),
        ]);

        $this->assertCount(5, Znuny::tickets()->whereState('open')->limit(5)->ids());
        $this->assertCount(5, Znuny::tickets()->where('Limit', 5)->ids());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch') && $r['Limit'] === 5);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch') && $r['Limit'] === 4);
    }

    public function test_without_a_search_limit_znunys_own_limit_applies(): void
    {
        config(['znuny.connections.default.search_limit' => null]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2']]),
        ]);

        $this->assertCount(2, Znuny::tickets()->whereState('open')->ids());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch') && ! isset($r['Limit']));
    }

    public function test_pagination_and_lazy_loading_fail_on_an_incomplete_search_too(): void
    {
        config(['znuny.connections.default.search_limit' => 2]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3']]),
        ]);

        foreach ([
            fn () => Znuny::tickets()->whereState('open')->paginate(),
            fn () => iterator_to_array(Znuny::tickets()->whereState('open')->lazy()),
            fn () => Znuny::tickets()->whereState('open')->get(),
        ] as $search) {
            try {
                $search();
                $this->fail('Expected a ZnunySearchLimitExceededException');
            } catch (ZnunySearchLimitExceededException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'TicketGet'));
    }

    public function test_where_created_after_translates_to_timestamp(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->whereCreatedAfter(Carbon::parse('2026-01-01 00:00:00'))->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && ($r['TicketCreateTimeNewerDate'] ?? null) === '2026-01-01 00:00:00');
    }

    public function test_date_filters_accept_any_date_time_interface(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()
            ->whereCreatedAfter(new DateTimeImmutable('2026-01-01 08:00:00'))
            ->whereCreatedBefore(new DateTimeImmutable('2026-02-01 08:00:00'))
            ->whereChangedAfter(new DateTimeImmutable('2026-03-01 08:00:00'))
            ->whereChangedBefore(new DateTimeImmutable('2026-04-01 08:00:00'))
            ->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && $r['TicketCreateTimeNewerDate'] === '2026-01-01 08:00:00'
            && $r['TicketCreateTimeOlderDate'] === '2026-02-01 08:00:00'
            && $r['TicketChangeTimeNewerDate'] === '2026-03-01 08:00:00'
            && $r['TicketLastChangeTimeOlderDate'] === '2026-04-01 08:00:00');
    }

    public function test_changed_before_compares_the_last_change_not_any_history_entry(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->whereChangedBefore(new DateTimeImmutable('2026-04-01 08:00:00'))->ids();

        // TicketChangeTimeOlderDate matches every ticket with any history entry before the date.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && $r['TicketLastChangeTimeOlderDate'] === '2026-04-01 08:00:00'
            && ! isset($r['TicketChangeTimeOlderDate']));
    }

    public function test_last_change_filters_form_a_window_on_the_change_time(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()
            ->whereLastChangedAfter(new DateTimeImmutable('2026-03-01 08:00:00'))
            ->whereLastChangedBefore(new DateTimeImmutable('2026-04-01 08:00:00'))
            ->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && $r['TicketLastChangeTimeNewerDate'] === '2026-03-01 08:00:00'
            && $r['TicketLastChangeTimeOlderDate'] === '2026-04-01 08:00:00'
            && ! isset($r['TicketChangeTimeNewerDate']));
    }

    public function test_date_filters_are_sent_in_the_znuny_time_zone(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()
            ->whereCreatedAfter(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('Europe/Vienna')))
            ->whereChangedBefore(Carbon::parse('2026-07-01 18:00:00', 'Asia/Tokyo'))
            ->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && $r['TicketCreateTimeNewerDate'] === '2026-07-01 08:00:00'
            && $r['TicketLastChangeTimeOlderDate'] === '2026-07-01 09:00:00');
    }

    public function test_the_global_time_zone_applies_to_connections_without_their_own(): void
    {
        // A config file published before 0.2.1 has no "timezone" key at all; the package's own
        // config supplies the global one, so ZNUNY_TIMEZONE works without republishing.
        $this->assertArrayNotHasKey('timezone', config('znuny.connections.default'));
        $this->assertSame('UTC', config('znuny.timezone'));
        config(['znuny.timezone' => 'Europe/Berlin']);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        $date = new DateTimeImmutable('2026-07-01 08:00:00', new DateTimeZone('UTC'));

        Znuny::tickets()->query()->whereCreatedAfter($date)->ids();
        Znuny::build([
            'base_url' => 'https://other.test/ws',
            'username' => 'agent',
            'password' => 'secret',
            'routes' => 'operation-name',
        ])->tickets()->query()->whereCreatedAfter($date)->ids();

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://znuny.test') && str_contains($r->url(), 'TicketSearch')
            && $r['TicketCreateTimeNewerDate'] === '2026-07-01 10:00:00');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://other.test') && str_contains($r->url(), 'TicketSearch')
            && $r['TicketCreateTimeNewerDate'] === '2026-07-01 10:00:00');
    }

    public function test_the_znuny_time_zone_is_configurable_per_connection(): void
    {
        config(['znuny.connections.default.timezone' => 'Europe/Berlin']);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->whereCreatedAfter(new DateTimeImmutable('2026-07-01 08:00:00', new DateTimeZone('UTC')))->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && $r['TicketCreateTimeNewerDate'] === '2026-07-01 10:00:00');
    }

    public function test_where_state_type_maps_to_state_type_param(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->whereStateType('open')->ids();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketSearch')
            && ($r['StateType'] ?? null) === ['open']
            && ! isset($r['States']));
    }

    public function test_where_dynamic_field_builds_a_search_condition(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->whereDynamicField('OrderId', 'ORD-*', 'Like')->ids();

        Http::assertSent(fn (Request $r) => ($r['DynamicField_OrderId'] ?? null) === ['Like' => 'ORD-*']);
    }

    public function test_order_by_accepts_asc_and_desc(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->orderBy('Age', 'desc')->ids();
        Znuny::tickets()->query()->orderBy('Age', 'asc')->ids();

        Http::assertSent(fn (Request $r) => ($r['OrderBy'] ?? null) === 'Down');
        Http::assertSent(fn (Request $r) => ($r['OrderBy'] ?? null) === 'Up');
    }

    public function test_empty_search_returns_empty_collection(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response([]), // Znuny omits TicketID when there are no hits
        ]);

        $this->assertTrue(Znuny::tickets()->where('CustomerID', 'nobody')->ids()->isEmpty());
    }

    public function test_get_fetches_all_tickets_in_a_single_batch(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3']]),
            '*TicketGet*' => Http::response(['Ticket' => [
                $this->fakeTicket('1'),
                $this->fakeTicket('2'),
                $this->fakeTicket('3'),
            ]]),
        ]);

        $tickets = Znuny::tickets()->where('CustomerID', '12345')->get();

        $this->assertSame(['1', '2', '3'], $tickets->pluck('id')->all());

        $ticketGets = collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'TicketGet'));
        $this->assertCount(1, $ticketGets);
        $this->assertStringEndsWith('/TicketGet/1,2,3', $ticketGets->first()[0]->url());
    }

    public function test_get_chunks_when_result_exceeds_batch_size(): void
    {
        config()->set('znuny.http.batch_size', 2);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4', '5']]),
            '*TicketGet*' => Http::sequence()
                ->push(['Ticket' => [$this->fakeTicket('1'), $this->fakeTicket('2')]])
                ->push(['Ticket' => [$this->fakeTicket('3'), $this->fakeTicket('4')]])
                ->push(['Ticket' => [$this->fakeTicket('5')]]),
        ]);

        $tickets = Znuny::tickets()->where('CustomerID', '1')->get();

        $this->assertSame(['1', '2', '3', '4', '5'], $tickets->pluck('id')->all());
    }

    public function test_with_attachments_loads_articles_with_attachments(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1']]),
            '*TicketGet*' => Http::response(['Ticket' => [$this->fakeTicket('1')]]),
        ]);

        Znuny::tickets()->query()->withoutArticles()->withAttachments()->get();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'TicketGet')
            && ($r['AllArticles'] ?? null) === 1
            && ($r['Attachments'] ?? null) === 1);
    }

    public function test_get_returns_empty_collection_when_no_ids(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response([]),
        ]);

        $this->assertTrue(Znuny::tickets()->where('X', 'Y')->get()->isEmpty());
    }

    public function test_paginate_returns_length_aware_paginator(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']]),
            '*TicketGet*' => Http::response(['Ticket' => [
                $this->fakeTicket('1'), $this->fakeTicket('2'), $this->fakeTicket('3'),
            ]]),
        ]);

        $paginator = Znuny::tickets()->where('CustomerID', '12345')->paginate(perPage: 3, page: 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(10, $paginator->total());
        $this->assertSame(3, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertCount(3, $paginator->items());
    }

    public function test_paginate_second_page(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']]),
            '*TicketGet*' => Http::response(['Ticket' => [
                $this->fakeTicket('4'), $this->fakeTicket('5'), $this->fakeTicket('6'),
            ]]),
        ]);

        $paginator = Znuny::tickets()->where('X', 'Y')->paginate(perPage: 3, page: 2);

        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame(['4', '5', '6'], collect($paginator->items())->pluck('id')->all());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketGet/4,5,6'));
    }

    public function test_paginate_empty_result(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response([]),
        ]);

        $paginator = Znuny::tickets()->where('X', 'Y')->paginate(perPage: 10, page: 1);

        $this->assertSame(0, $paginator->total());
        $this->assertSame([], $paginator->items());
    }

    public function test_lazy_yields_tickets_in_chunks(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4', '5']]),
            '*TicketGet*' => Http::sequence()
                ->push(['Ticket' => [$this->fakeTicket('1'), $this->fakeTicket('2')]])
                ->push(['Ticket' => [$this->fakeTicket('3'), $this->fakeTicket('4')]])
                ->push(['Ticket' => [$this->fakeTicket('5')]]),
        ]);

        $collected = [];

        foreach (Znuny::tickets()->where('X', 'Y')->lazy(chunkSize: 2) as $ticket) {
            $collected[] = $ticket->id;
        }

        $this->assertSame(['1', '2', '3', '4', '5'], $collected);
    }

    private function fakeTicket(string $id): array
    {
        return [
            'TicketID' => $id,
            'TicketNumber' => str_pad($id, 10, '0', STR_PAD_LEFT),
            'Title' => 'Ticket '.$id,
            'QueueID' => '1',
            'Queue' => 'Q',
            'State' => 'new',
            'Priority' => '3 normal',
            'Created' => '2026-04-08 10:00:00',
        ];
    }
}
