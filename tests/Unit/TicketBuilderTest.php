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
}
