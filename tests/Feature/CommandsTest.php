<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function test_test_connection_command_reports_success(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => ['1']]),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('operation-name')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);
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
