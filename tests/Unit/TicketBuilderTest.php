<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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

        $ids = Znuny::tickets()
            ->where('CustomerID', '12345')
            ->ids();

        $this->assertInstanceOf(Collection::class, $ids);
        $this->assertSame(['1', '2', '3'], $ids->all());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketSearch')
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

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'TicketSearch')) {
                return false;
            }
            return ($r['CustomerID'] ?? null) === '12345'
                && ($r['States'] ?? null) === ['open']
                && ($r['Queues'] ?? null) === ['Support']
                && ($r['SortBy'] ?? null) === 'Created'
                && ($r['OrderBy'] ?? null) === 'Down'
                && ($r['Limit'] ?? null) === 50;
        });
    }

    public function test_where_created_after_translates_to_timestamp(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()
            ->query()
            ->whereCreatedAfter(\Carbon\Carbon::parse('2026-01-01 00:00:00'))
            ->ids();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketSearch')
            && isset($r['TicketCreateTimeNewerDate']));
    }

    public function test_where_state_type_maps_to_state_type_param(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->whereStateType('open')->ids();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketSearch')
            && ($r['StateType'] ?? null) === ['open']
            && ! isset($r['States']));
    }

    public function test_empty_search_returns_empty_collection(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response([]), // Znuny omits TicketID when zero hits
        ]);

        $ids = Znuny::tickets()->where('CustomerID', 'nobody')->ids();

        $this->assertTrue($ids->isEmpty());
    }

    public function test_get_fetches_all_tickets_in_a_single_batch(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3']]),
            '*TicketGet*'    => Http::response(['Ticket' => [
                $this->fakeTicket('1'),
                $this->fakeTicket('2'),
                $this->fakeTicket('3'),
            ]]),
        ]);

        $tickets = Znuny::tickets()->where('CustomerID', '12345')->get();

        $this->assertCount(3, $tickets);
        $this->assertSame(['1', '2', '3'], $tickets->pluck('id')->all());
    }

    public function test_get_chunks_when_result_exceeds_pool_size(): void
    {
        config()->set('znuny.http.pool_size', 2);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1', '2', '3', '4', '5']]),
            '*TicketGet*'    => Http::sequence()
                ->push(['Ticket' => [$this->fakeTicket('1'), $this->fakeTicket('2')]])
                ->push(['Ticket' => [$this->fakeTicket('3'), $this->fakeTicket('4')]])
                ->push(['Ticket' => [$this->fakeTicket('5')]]),
        ]);

        $tickets = Znuny::tickets()->where('CustomerID', '1')->get();

        $this->assertCount(5, $tickets);
        $this->assertSame(['1', '2', '3', '4', '5'], $tickets->pluck('id')->all());
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
            '*TicketSearch*' => Http::response(['TicketID' => ['1','2','3','4','5','6','7','8','9','10']]),
            '*TicketGet*'    => Http::response(['Ticket' => [
                $this->fakeTicket('1'), $this->fakeTicket('2'), $this->fakeTicket('3'),
            ]]),
        ]);

        $paginator = Znuny::tickets()
            ->where('CustomerID', '12345')
            ->paginate(perPage: 3, page: 1);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $paginator);
        $this->assertSame(10, $paginator->total());
        $this->assertSame(3, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertCount(3, $paginator->items());
    }

    public function test_paginate_second_page(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['1','2','3','4','5','6','7','8','9','10']]),
            '*TicketGet*'    => Http::response(['Ticket' => [
                $this->fakeTicket('4'), $this->fakeTicket('5'), $this->fakeTicket('6'),
            ]]),
        ]);

        $paginator = Znuny::tickets()->where('X', 'Y')->paginate(perPage: 3, page: 2);

        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame(['4', '5', '6'], collect($paginator->items())->pluck('id')->all());
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

    private function fakeTicket(string $id): array
    {
        return [
            'TicketID'     => $id,
            'TicketNumber' => str_pad($id, 10, '0', STR_PAD_LEFT),
            'Title'        => 'Ticket ' . $id,
            'QueueID'      => '1',
            'Queue'        => 'Q',
            'State'        => 'new',
            'Priority'     => '3 normal',
            'Created'      => '2026-04-08 10:00:00',
        ];
    }
}
