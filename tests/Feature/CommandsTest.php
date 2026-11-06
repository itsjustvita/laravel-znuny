<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class CommandsTest extends TestCase
{
    /**
     * Fake a Znuny with a ticket from an hour ago and tickets from more than four and more
     * than eight months ago.
     *
     * @param  Closure(int): int  $offset  the UTC offset of Znuny's dates at a moment
     * @param  list<int>|null  $newestAges  the "Age" values consecutive reads of the newest ticket return
     */
    private function fakeZnuny(Closure $offset, string $now = '2026-11-15 09:00:00', int $tickets = 3, ?array $newestAges = null): void
    {
        $this->travelTo($now);
        Sleep::fake();

        $now = now()->getTimestamp();
        $createdAt = array_slice([7 => $now - 3600, 5 => $now - 130 * 86400, 3 => $now - 250 * 86400], 0, $tickets, true);

        Http::fake(function (Request $request) use ($offset, $now, $createdAt, &$newestAges) {
            $url = $request->url();

            if (str_ends_with($url, '/SessionCreate')) {
                return Http::response(['SessionID' => 'S1']);
            }

            if (str_ends_with($url, '/TicketSearch')) {
                $before = $now - 60 * ($request['TicketCreateTimeOlderMinutes'] ?? 0);
                $id = array_key_first(array_filter($createdAt, fn (int $created): bool => $created <= $before));

                return Http::response(['TicketID' => $id === null ? [] : [(string) $id]]);
            }

            foreach ($createdAt as $id => $created) {
                if (str_ends_with($url, "/TicketGet/{$id}")) {
                    return Http::response(['Ticket' => [[
                        'TicketID' => (string) $id,
                        'Created' => gmdate('Y-m-d H:i:s', $created + $offset($created)),
                        'Age' => $id === 7 && $newestAges ? array_shift($newestAges) : $now - $created,
                    ]]]);
                }
            }

            return Http::response("HTTP::REST Error while determine Operation for request URI '{$url}'.", 500);
        });
    }

    /**
     * @return Closure(int): int
     */
    private function offsetOf(string $timezone): Closure
    {
        return fn (int $moment): int => (new DateTimeZone($timezone))->getOffset(new DateTimeImmutable('@'.$moment));
    }

    public function test_test_connection_command_reports_success(): void
    {
        $this->fakeZnuny($this->offsetOf('UTC'));

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('operation-name')
            ->expectsOutputToContain('TicketGet')
            ->expectsOutputToContain('Time zone')
            ->doesntExpectOutputToContain('not verified')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/TicketSearch')
            && $request['Limit'] === 1 && ! isset($request['TicketCreateTimeOlderMinutes']));
        Sleep::assertNeverSlept();
    }

    public function test_test_connection_fails_when_znuny_uses_another_time_zone(): void
    {
        $this->fakeZnuny($this->offsetOf('Europe/Berlin'));

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny returns dates in UTC+01:00, but the time zone [UTC] of this connection is UTC+00:00')
            ->expectsOutputToContain('ZNUNY_TIMEZONE')
            ->assertExitCode(1);
    }

    public function test_test_connection_accepts_the_configured_znuny_time_zone(): void
    {
        config()->set('znuny.timezone', 'Europe/Berlin');
        $this->fakeZnuny($this->offsetOf('Europe/Berlin'));

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Europe/Berlin')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);

        // The newest ticket, and one each from at least four and eight months ago.
        foreach ([7, 5, 3] as $id) {
            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/TicketGet/{$id}"));
        }
    }

    public function test_test_connection_notices_a_zone_that_only_matches_for_part_of_the_year(): void
    {
        // In November UTC and Europe/London have the same offset; the ticket from July tells them apart.
        config()->set('znuny.timezone', 'Europe/London');
        $this->fakeZnuny($this->offsetOf('UTC'));

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny returns dates in UTC+00:00, but the time zone [Europe/London] of this connection is UTC+01:00')
            ->assertExitCode(1);
    }

    public function test_test_connection_ignores_a_newest_ticket_that_lies_in_znunys_future(): void
    {
        // The hour that repeats when daylight saving time ends, or a time zone that was just changed:
        // Znuny reports the absolute distance as "Age", which then shrinks from read to read.
        $this->fakeZnuny($this->offsetOf('Europe/Berlin'), tickets: 1, newestAges: [3480, 3478]);
        config()->set('znuny.timezone', 'UTC');

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('not verified')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);

        Sleep::assertSleptTimes(1);
    }

    public function test_test_connection_still_fails_when_the_age_of_the_newest_ticket_grows(): void
    {
        $this->fakeZnuny($this->offsetOf('Europe/Berlin'), tickets: 1, newestAges: [3600, 3602]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny returns dates in UTC+01:00')
            ->assertExitCode(1);
    }

    public function test_test_connection_cannot_check_the_time_zone_without_a_readable_ticket(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => []]),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('not verified (needs a ticket this user can read)')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'TicketGet'));
        // Without any ticket there is no point in searching for older ones.
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/TicketSearch')));
    }

    public function test_test_connection_cannot_check_the_time_zone_without_age_and_creation_time(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => ['7']]),
            '*TicketGet/7' => Http::response(['Ticket' => [['TicketID' => '7', 'Title' => 'Mapped away']]]),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('not verified (TicketGet returned no Created and Age)')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);
    }

    public function test_test_connection_reports_a_missing_ticket_get_route(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => ['7']]),
            '*TicketGet/7' => Http::response("HTTP::REST Error while determine Operation for request URI '/TicketGet/7'.", 500),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('POST /TicketGet/7')
            ->assertExitCode(1);
    }

    public function test_test_connection_command_reports_failure(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'Wrong password']]),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny connection [default] FAILED')
            ->assertExitCode(1);
    }

    public function test_cache_clear_command_flushes_default_connection(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*' => Http::response(['Queue' => [
                ['QueueID' => '1', 'Name' => 'Support', 'GroupID' => '1', 'ValidID' => '1'],
            ]]),
        ]);

        \Znuny::queues()->all();
        \Znuny::queues()->all();
        $this->artisan('znuny:cache-clear')->assertExitCode(0);
        \Znuny::queues()->all();

        // The second call is served from the cache, the third one after clearing it is not.
        $this->assertCount(2, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'QueueList')));
    }

    public function test_cache_clear_all_flag_flushes_every_connection(): void
    {
        config()->set('znuny.connections.tenant-a', [
            'base_url' => 'https://tenant-a.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'u', 'password' => 'p', 'verify_ssl' => false, 'timeout' => 5,
        ]);

        $this->artisan('znuny:cache-clear --all')->assertExitCode(0);
    }

    public function test_generate_fields_writes_helper_class(): void
    {
        $target = storage_path('app/znuny-test/DynamicFields.php');
        @unlink($target);
        @mkdir(dirname($target), 0777, true);

        config()->set('znuny.dynamic_fields', [
            'CustomerSegment' => ['type' => 'string', 'allowed' => ['business', 'private']],
            'OrderId' => ['type' => 'string'],
            'Priority' => ['type' => 'integer'],
        ]);

        $this->artisan('znuny:generate-fields', ['--output' => $target])
            ->assertExitCode(0);

        $this->assertFileExists($target);
        $contents = file_get_contents($target);
        $this->assertStringContainsString('public function customerSegment(', $contents);
        $this->assertStringContainsString('public function orderId(', $contents);
        $this->assertStringContainsString('public function priority(', $contents);
        $this->assertStringContainsString('namespace App\\Znuny;', $contents);
    }

    public function test_generate_fields_supports_float_and_array_types(): void
    {
        $target = storage_path('app/znuny-test/DynamicFieldsTyped.php');
        @unlink($target);

        config()->set('znuny.dynamic_fields', [
            'Amount' => ['type' => 'float'],
            'Tags' => ['type' => 'array'],
        ]);

        $this->artisan('znuny:generate-fields', ['--output' => $target])->assertExitCode(0);

        $contents = file_get_contents($target);
        $this->assertStringContainsString('public function amount(float $value)', $contents);
        $this->assertStringContainsString('public function tags(array $value)', $contents);
    }

    public function test_test_connection_skips_session_create_in_credentials_mode(): void
    {
        config()->set('znuny.connections.default.auth', 'credentials');
        Http::fake(['*TicketSearch' => Http::response(['TicketID' => []])]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'SessionCreate'));
    }

    public function test_test_connection_reports_routing_problems(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response("HTTP::REST Error while determine Operation for request URI '/TicketSearch'.", 500),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('POST /TicketSearch')
            ->assertExitCode(1);
    }

    public function test_test_connection_reports_unknown_connections(): void
    {
        $this->artisan('znuny:test-connection', ['connection' => 'nope'])
            ->expectsOutputToContain('Znuny connection [nope] is not configured')
            ->assertExitCode(1);
    }

    public function test_routes_command_prints_the_route_map(): void
    {
        $this->artisan('znuny:routes')
            ->expectsOutputToContain('operation-name')
            ->expectsTable(['Operation', 'Method', 'Path'], [
                ['TicketGet', 'POST', '/TicketGet/:TicketID'],
                ['TicketUpdate', 'POST', '/TicketUpdate/:TicketID'],
                ['QueueGet', 'POST', '/QueueGet/:QueueID'],
            ])
            ->assertExitCode(0);
    }

    public function test_routes_command_for_the_stock_preset(): void
    {
        config()->set('znuny.connections.stock', [
            'base_url' => 'https://stock.test/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST',
            'username' => 'u',
            'password' => 'p',
        ]);

        $this->artisan('znuny:routes', ['connection' => 'stock'])
            ->expectsTable(['Operation', 'Method', 'Path'], [
                ['SessionCreate', 'POST', '/Session'],
                ['SessionGet', 'GET', '/Session/:SessionID'],
                ['SessionDelete', 'DELETE', '/Session/:SessionID'],
                ['TicketCreate', 'POST', '/Ticket'],
                ['TicketGet', 'GET', '/Ticket/:TicketID'],
                ['TicketSearch', 'POST', '/Ticket/Search'],
                ['TicketUpdate', 'PATCH', '/Ticket/:TicketID'],
                ['TicketHistoryGet', 'GET', '/Ticket/History/:TicketID'],
            ])
            ->assertExitCode(0);
    }

    public function test_routes_command_does_not_need_credentials(): void
    {
        config()->set('znuny.connections.partial', ['routes' => 'generic-ticket-connector-classic']);

        $this->artisan('znuny:routes', ['connection' => 'partial'])
            ->expectsOutputToContain('generic-ticket-connector-classic')
            ->assertExitCode(0);
    }

    public function test_routes_command_reports_unknown_connections(): void
    {
        $this->artisan('znuny:routes', ['connection' => 'nope'])
            ->expectsOutputToContain('Znuny connection [nope] is not configured')
            ->assertExitCode(1);
    }
}
