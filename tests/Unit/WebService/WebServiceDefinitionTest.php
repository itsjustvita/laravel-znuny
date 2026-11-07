<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\WebService;

use ItsJustVita\LaravelZnuny\Routing\RouteMap;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\WebService\WebServiceDefinition;

final class WebServiceDefinitionTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array{0: string, 1: string}> operation => [method, route]
     */
    private function routes(array $definition): array
    {
        return array_map(
            fn (array $route): array => [$route['RequestMethod'][0], $route['Route']],
            $definition['Provider']['Transport']['Config']['RouteOperationMapping'],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, string> operation => type
     */
    private function types(array $definition): array
    {
        return array_map(fn (array $operation): string => $operation['Type'], $definition['Provider']['Operation']);
    }

    public function test_the_default_preset_matches_the_shipped_example(): void
    {
        // Operations, types, methods and routes of var/webservices/examples/GenericTicketConnectorREST.yml (rel-7_3_8).
        $definition = WebServiceDefinition::fromRoutes(RouteMap::fromConfig('generic-ticket-connector'))->toArray();

        $this->assertSame([
            'SessionCreate' => 'Session::SessionCreate',
            'SessionDelete' => 'Session::SessionRemove',
            'SessionGet' => 'Session::SessionGet',
            'TicketCreate' => 'Ticket::TicketCreate',
            'TicketGet' => 'Ticket::TicketGet',
            'TicketHistoryGet' => 'Ticket::TicketHistoryGet',
            'TicketSearch' => 'Ticket::TicketSearch',
            'TicketUpdate' => 'Ticket::TicketUpdate',
        ], $this->types($definition));
        $this->assertSame([
            'SessionCreate' => ['POST', '/Session'],
            'SessionDelete' => ['DELETE', '/Session/:SessionID'],
            'SessionGet' => ['GET', '/Session/:SessionID'],
            'TicketCreate' => ['POST', '/Ticket'],
            'TicketGet' => ['GET', '/Ticket/:TicketID'],
            'TicketHistoryGet' => ['GET', '/Ticket/History/:TicketID'],
            'TicketSearch' => ['POST', '/Ticket/Search'],
            'TicketUpdate' => ['PATCH', '/Ticket/:TicketID'],
        ], $this->routes($definition));
        $this->assertSame('JSON', $definition['Provider']['Transport']['Config']['RouteOperationMapping']['TicketGet']['ParserBackend']);
        $this->assertSame('HTTP::REST', $definition['Provider']['Transport']['Type']);
        $this->assertSame(['Transport' => ['Type' => '']], $definition['Requester']);
    }

    public function test_the_operation_name_preset_gets_every_core_operation(): void
    {
        $definition = WebServiceDefinition::fromRoutes(RouteMap::fromConfig('operation-name'));
        $routes = $this->routes($definition->toArray());

        $this->assertSame(['POST', '/TicketSearch'], $routes['TicketSearch']);
        $this->assertSame(['POST', '/TicketGet/:TicketID'], $routes['TicketGet']);
        $this->assertSame(['POST', '/SessionCreate'], $routes['SessionCreate']);
        $this->assertSame(['POST', '/TicketHistoryGet'], $routes['TicketHistoryGet']);
        $this->assertArrayNotHasKey('QueueGet', $routes);
        $this->assertSame(['QueueGet'], $definition->skipped());
    }

    public function test_the_classic_preset_keeps_its_ticket_list_route(): void
    {
        $definition = WebServiceDefinition::fromRoutes(RouteMap::fromConfig('generic-ticket-connector-classic'))->toArray();

        $this->assertSame('Ticket::TicketGet', $this->types($definition)['TicketGetList']);
        $this->assertSame(['GET', '/TicketList'], $this->routes($definition)['TicketGetList']);
        $this->assertSame(['GET', '/Ticket'], $this->routes($definition)['TicketSearch']);
    }

    public function test_unknown_operations_are_skipped(): void
    {
        $definition = WebServiceDefinition::fromRoutes(RouteMap::fromConfig([
            'extends' => 'generic-ticket-connector',
            'FAQSearch' => 'POST /FAQ/Search',
        ]));

        $this->assertSame(['FAQSearch'], $definition->skipped());
        $this->assertArrayNotHasKey('FAQSearch', $this->types($definition->toArray()));
    }

    public function test_webhooks_with_a_token(): void
    {
        $requester = WebServiceDefinition::fromRoutes(RouteMap::fromConfig(null))
            ->withWebhooks(['TicketCreate', 'ArticleCreate'], 'https://app.example.com/', '/znuny/webhooks/', ['type' => 'token', 'token' => 'secret', 'header' => 'X-Znuny-Token'], withSecrets: true, synchronous: false)
            ->toArray()['Requester'];

        $this->assertSame([
            'Description' => 'Sends ticket events to the Laravel application',
            'Events' => [['Asynchronous' => '1', 'Event' => 'TicketCreate'], ['Asynchronous' => '1', 'Event' => 'ArticleCreate']],
            'Type' => 'Ticket::Generic',
        ], $requester['Invoker']['LaravelWebhook']);
        $this->assertSame('HTTP::REST', $requester['Transport']['Type']);
        $this->assertSame('https://app.example.com', $requester['Transport']['Config']['Host']);
        $this->assertSame(['Command' => 'POST', 'Controller' => '/znuny/webhooks'], $requester['Transport']['Config']['InvokerControllerMapping']['LaravelWebhook']);
        $this->assertSame(['X-Znuny-Token' => 'secret'], $requester['Transport']['Config']['AdditionalHeaders']);
        $this->assertArrayNotHasKey('Authentication', $requester['Transport']['Config']);
    }

    public function test_synchronous_webhooks(): void
    {
        $events = WebServiceDefinition::fromRoutes(RouteMap::fromConfig(null))
            ->withWebhooks(['TicketCreate'], 'http://host.docker.internal:8099', 'znuny/webhooks', ['type' => 'token', 'token' => 't'], withSecrets: true, synchronous: true)
            ->toArray()['Requester']['Invoker']['LaravelWebhook']['Events'];

        $this->assertSame([['Asynchronous' => '0', 'Event' => 'TicketCreate']], $events);
    }

    public function test_webhooks_with_basic_authentication(): void
    {
        $config = WebServiceDefinition::fromRoutes(RouteMap::fromConfig(null))
            ->withWebhooks(['TicketCreate'], 'https://app.example.com', 'znuny/webhooks', ['type' => 'basic', 'username' => 'znuny', 'password' => 'pw'], withSecrets: true, synchronous: false)
            ->toArray()['Requester']['Transport']['Config'];

        $this->assertSame(['AuthType' => 'BasicAuth', 'BasicAuthUser' => 'znuny', 'BasicAuthPassword' => 'pw'], $config['Authentication']);
        $this->assertNull($config['AdditionalHeaders']);
    }

    public function test_secrets_are_left_out_unless_asked_for(): void
    {
        $definition = WebServiceDefinition::fromRoutes(RouteMap::fromConfig(null));

        $token = $definition->withWebhooks(['TicketCreate'], 'https://app.example.com', 'znuny/webhooks', ['type' => 'token', 'token' => 'secret'], withSecrets: false, synchronous: false)
            ->toArray()['Requester']['Transport']['Config'];
        $basic = $definition->withWebhooks(['TicketCreate'], 'https://app.example.com', 'znuny/webhooks', ['type' => 'basic', 'username' => 'znuny', 'password' => 'pw'], withSecrets: false, synchronous: false)
            ->toArray()['Requester']['Transport']['Config'];

        $this->assertSame(['X-Znuny-Token' => 'CHANGE-ME'], $token['AdditionalHeaders']);
        $this->assertSame(['AuthType' => 'BasicAuth', 'BasicAuthUser' => 'znuny', 'BasicAuthPassword' => 'CHANGE-ME'], $basic['Authentication']);
    }
}
