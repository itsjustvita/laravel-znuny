<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\TimeAccountingEntry;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class TimeAccountingResourceTest extends TestCase
{
    public function test_entries_asks_znuny_in_its_time_zone(): void
    {
        config(['znuny.connections.default.timezone' => 'Europe/Berlin']);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/TimeAccountingGet' => Http::response(['TimeAccountingResult' => [
                ['TicketNumber' => '1001', 'TicketTitle' => 'First', 'TicketCustomerID' => 'acme', 'Queue' => 'Raw', 'TimeUnit' => '15', 'Created' => '2026-07-01 10:00:00'],
                ['TicketNumber' => '1002', 'TicketTitle' => 'Second', 'TicketCustomerID' => 'acme', 'Queue' => 'Misc', 'TimeUnit' => '0.5', 'Created' => '2026-07-01 11:00:00'],
            ]]),
        ]);

        $entries = Znuny::timeAccounting()->entries(
            'jane.agent',
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-02 00:00:00', 'UTC'),
        );

        $this->assertContainsOnlyInstancesOf(TimeAccountingEntry::class, $entries);
        $this->assertSame(['1001', '1002'], $entries->pluck('ticketNumber')->all());
        $this->assertSame(15.5, $entries->sum('timeUnit'));
        $this->assertSame('2026-07-01 08:00:00', $entries->first()?->created?->utc()->format('Y-m-d H:i:s'));

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/TimeAccountingGet')
            && $r['TimeAccountingUserLogin'] === 'jane.agent'
            && $r['TimeAccountingStart'] === '2026-07-01 02:00:00'
            && $r['TimeAccountingEnd'] === '2026-07-02 02:00:00');
    }

    public function test_no_entries_is_an_empty_collection(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            // Znuny leaves the key out when nothing was booked.
            '*/TimeAccountingGet' => Http::response('{}'),
        ]);

        $this->assertCount(0, Znuny::timeAccounting()->entries('jane.agent', now()->subDay(), now()));
    }

    public function test_a_user_outside_the_time_accounting_group_is_denied(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/TimeAccountingGet' => Http::response(['Error' => ['ErrorCode' => 'TimeAccountingGet.NoPermission', 'ErrorMessage' => 'TimeAccountingGet: No permission!']]),
        ]);

        $this->expectException(ZnunyAccessDeniedException::class);

        Znuny::timeAccounting()->entries('jane.agent', now()->subDay(), now());
    }

    public function test_time_accounting_get_is_repeated_after_a_server_error(): void
    {
        config(['znuny.connections.default.retries' => 1, 'znuny.connections.default.retry_delay' => 10]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/TimeAccountingGet' => Http::sequence()->push('Bad Gateway', 502)->push('{}'),
        ]);

        // It only reads, so it is safe to repeat although its route is a POST.
        $this->assertCount(0, Znuny::timeAccounting()->entries('jane.agent', now()->subDay(), now()));
    }
}
