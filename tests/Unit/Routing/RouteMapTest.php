<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Routing;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Routing\RouteMap;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class RouteMapTest extends TestCase
{
    public function test_null_config_uses_the_generic_ticket_connector_preset(): void
    {
        $map = RouteMap::fromConfig(null);

        // Routes of var/webservices/examples/GenericTicketConnectorREST.yml shipped with Znuny.
        $this->assertSame('generic-ticket-connector', $map->preset());
        $this->assertSame('POST /Session', (string) $map->route('SessionCreate'));
        $this->assertSame('GET /Session/:SessionID', (string) $map->route('SessionGet'));
        $this->assertSame('DELETE /Session/:SessionID', (string) $map->route('SessionDelete'));
        $this->assertSame('POST /Ticket', (string) $map->route('TicketCreate'));
        $this->assertSame('GET /Ticket/:TicketID', (string) $map->route('TicketGet'));
        $this->assertSame('POST /Ticket/Search', (string) $map->route('TicketSearch'));
        $this->assertSame('PATCH /Ticket/:TicketID', (string) $map->route('TicketUpdate'));
        $this->assertSame('GET /Ticket/History/:TicketID', (string) $map->route('TicketHistoryGet'));
    }

    public function test_classic_preset_matches_the_development_example_web_service(): void
    {
        $map = RouteMap::fromConfig('generic-ticket-connector-classic');

        // Routes of development/webservices/GenericTicketConnectorREST.yml (also used by OTRS 5/6).
        $this->assertSame('POST /Session', (string) $map->route('SessionCreate'));
        $this->assertSame('GET /Session/:SessionID', (string) $map->route('SessionGet'));
        $this->assertSame('POST /Ticket', (string) $map->route('TicketCreate'));
        $this->assertSame('GET /Ticket/:TicketID', (string) $map->route('TicketGet'));
        $this->assertSame('GET /TicketList', (string) $map->route('TicketGetList'));
        $this->assertSame('GET /Ticket', (string) $map->route('TicketSearch'));
        $this->assertSame('PATCH /Ticket/:TicketID', (string) $map->route('TicketUpdate'));
        $this->assertSame('GET /TicketHistory/:TicketID', (string) $map->route('TicketHistoryGet'));
    }

    public function test_operation_name_preset_reproduces_v01_routes(): void
    {
        $map = RouteMap::fromConfig('operation-name');

        $this->assertSame('operation-name', $map->preset());
        $this->assertSame('POST /TicketGet/:TicketID', (string) $map->route('TicketGet'));
        $this->assertSame('POST /TicketUpdate/:TicketID', (string) $map->route('TicketUpdate'));
        $this->assertSame('POST /QueueGet/:QueueID', (string) $map->route('QueueGet'));
        $this->assertSame('POST /SessionCreate', (string) $map->route('SessionCreate'));
        $this->assertSame('POST /TicketSearch', (string) $map->route('TicketSearch'));
    }

    public function test_unmapped_operations_fall_back_to_post_operation_name(): void
    {
        $map = RouteMap::fromConfig('generic-ticket-connector');

        $this->assertFalse($map->has('FAQSearch'));
        $this->assertSame('POST /FAQSearch', (string) $map->route('FAQSearch'));
    }

    public function test_extends_overrides_single_operations(): void
    {
        $map = RouteMap::fromConfig([
            'extends' => 'generic-ticket-connector',
            'TicketSearch' => 'POST /TicketSearch',
            'QueueList' => 'GET /Queue',
        ]);

        $this->assertSame('generic-ticket-connector', $map->preset());
        $this->assertTrue($map->has('QueueList'));
        $this->assertSame('POST /TicketSearch', (string) $map->route('TicketSearch'));
        $this->assertSame('GET /Queue', (string) $map->route('QueueList'));
        $this->assertSame('GET /Ticket/:TicketID', (string) $map->route('TicketGet'));
    }

    public function test_array_without_extends_contains_only_the_given_routes(): void
    {
        $map = RouteMap::fromConfig(['TicketGet' => 'GET /Tickets/:TicketID']);

        $this->assertNull($map->preset());
        $this->assertSame(['TicketGet'], array_keys($map->all()));
        $this->assertSame('POST /SessionCreate', (string) $map->route('SessionCreate'));
    }

    public function test_unknown_preset_throws_with_available_presets(): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('Unknown Znuny route preset [does-not-exist]. Available presets: generic-ticket-connector, generic-ticket-connector-classic, operation-name.');

        RouteMap::fromConfig('does-not-exist');
    }

    public function test_non_string_definitions_are_rejected(): void
    {
        $this->expectException(ZnunyConfigurationException::class);

        RouteMap::fromConfig(['TicketGet' => ['GET', '/Ticket']]);
    }
}
