<?php

namespace ItsJustVita\LaravelZnuny\WebService;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Routing\RouteMap;
use ItsJustVita\LaravelZnuny\Support\Setting;

/**
 * A Znuny web service definition that matches the routes of a connection, optionally with a
 * requester that sends ticket events to this application's webhook endpoint.
 *
 * @internal Not covered by the package's stability promise.
 */
final class WebServiceDefinition
{
    /** The operation types Znuny 6.5 to 7.3 register, by the operation names the package uses. */
    public const OPERATION_TYPES = [
        'SessionCreate' => 'Session::SessionCreate',
        'SessionGet' => 'Session::SessionGet',
        'SessionDelete' => 'Session::SessionRemove',
        'SessionRemove' => 'Session::SessionRemove',
        'TicketCreate' => 'Ticket::TicketCreate',
        'TicketGet' => 'Ticket::TicketGet',
        'TicketGetList' => 'Ticket::TicketGet',
        'TicketHistoryGet' => 'Ticket::TicketHistoryGet',
        'TicketSearch' => 'Ticket::TicketSearch',
        'TicketUpdate' => 'Ticket::TicketUpdate',
        'TimeAccountingGet' => 'Ticket::TimeAccountingGet',
        'OutOfOffice' => 'User::OutOfOffice',
    ];

    /**
     * Operations Znuny ships in web services of their own (TimeAccountingREST, OutOfOffice).
     * They need special groups, so they are only added on request.
     */
    public const OPTIONAL_OPERATIONS = ['TimeAccountingGet', 'OutOfOffice'];

    /** Always part of the provider: the operations the package itself calls. */
    private const CORE_OPERATIONS = [
        'SessionCreate', 'SessionGet', 'SessionDelete', 'TicketCreate', 'TicketGet', 'TicketSearch', 'TicketUpdate', 'TicketHistoryGet',
    ];

    public const INVOKER = 'LaravelWebhook';

    public const PLACEHOLDER = 'CHANGE-ME';

    /**
     * @param  array<string, mixed>|null  $requester
     * @param  list<string>  $also  optional operations to add
     */
    private function __construct(
        private readonly RouteMap $routes,
        private readonly ?array $requester = null,
        private readonly array $also = [],
    ) {}

    /**
     * @param  array<array-key, string>  $also  names from OPTIONAL_OPERATIONS to add to the web service
     *
     * @throws InvalidArgumentException for a name that is none of them
     */
    public static function fromRoutes(RouteMap $routes, array $also = []): self
    {
        foreach ($also as $operation) {
            if (! in_array($operation, self::OPTIONAL_OPERATIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    '[%s] cannot be added. Znuny ships these operations outside its ticket web service: %s.',
                    $operation,
                    implode(', ', self::OPTIONAL_OPERATIONS),
                ));
            }
        }

        return new self($routes, null, array_values(array_unique($also)));
    }

    /**
     * @param  list<string>  $events  ticket events, e.g. TicketCreate, ArticleCreate
     * @param  string  $baseUrl  the application's URL as Znuny reaches it
     * @param  array<string, mixed>  $auth  the webhooks.auth config
     * @param  bool  $withSecrets  write the token or password; otherwise CHANGE-ME
     * @param  bool  $synchronous  deliver in the request instead of through the Znuny daemon
     */
    public function withWebhooks(array $events, string $baseUrl, string $routePrefix, array $auth, bool $withSecrets, bool $synchronous): self
    {
        $secret = fn (string $key): string => $withSecrets ? (string) ($auth[$key] ?? '') : self::PLACEHOLDER;
        $type = strtolower((string) ($auth['type'] ?? 'token'));

        $config = [
            'AdditionalHeaders' => $type === 'token' ? [Setting::string('X-Znuny-Token', $auth['header'] ?? null) => $secret('token')] : null,
            'ContentType' => 'JSON',
            'DefaultCommand' => 'POST',
            'Host' => rtrim($baseUrl, '/'),
            'InvokerControllerMapping' => [
                self::INVOKER => ['Command' => 'POST', 'Controller' => '/'.trim($routePrefix, '/')],
            ],
            'SSLNoHostnameVerification' => '0',
            'Timeout' => '30',
        ];

        if ($type === 'basic') {
            $config['Authentication'] = [
                'AuthType' => 'BasicAuth',
                'BasicAuthUser' => (string) ($auth['username'] ?? ''),
                'BasicAuthPassword' => $secret('password'),
            ];
        }

        return new self($this->routes, also: $this->also, requester: [
            'Invoker' => [
                self::INVOKER => [
                    'Description' => 'Sends ticket events to the Laravel application',
                    'Events' => array_map(
                        fn (string $event): array => ['Asynchronous' => $synchronous ? '0' : '1', 'Event' => $event],
                        $events,
                    ),
                    'Type' => 'Ticket::Generic',
                ],
            ],
            'Transport' => ['Config' => $config, 'Type' => 'HTTP::REST'],
        ]);
    }

    /**
     * Operations the route map names explicitly that are not Znuny core operations; their
     * type cannot be known, so they are left out.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return array_values(array_diff(array_keys($this->routes->all()), array_keys(self::OPERATION_TYPES)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $names = array_unique([
            ...self::CORE_OPERATIONS,
            ...$this->also,
            ...array_intersect(array_keys($this->routes->all()), array_keys(self::OPERATION_TYPES)),
        ]);
        sort($names);

        $operations = [];
        $mapping = [];

        foreach ($names as $name) {
            $route = $this->routes->route($name);

            $operations[$name] = [
                'Description' => "{$name} (laravel-znuny)",
                'MappingInbound' => [],
                'MappingOutbound' => [],
                'Type' => self::OPERATION_TYPES[$name],
            ];
            $mapping[$name] = [
                'ParserBackend' => 'JSON',
                'RequestMethod' => [$route->method],
                'Route' => $route->path,
            ];
        }

        return [
            'Debugger' => ['DebugThreshold' => 'error', 'TestMode' => '0'],
            'Description' => 'Generated by laravel-znuny (php artisan znuny:webservice)',
            'FrameworkVersion' => '6.5.x',
            'Provider' => [
                'Operation' => $operations,
                'Transport' => [
                    'Config' => [
                        'AdditionalHeaders' => null,
                        'KeepAlive' => '1',
                        'MaxLength' => '100000000',
                        'RouteOperationMapping' => $mapping,
                    ],
                    'Type' => 'HTTP::REST',
                ],
            ],
            'RemoteSystem' => '',
            'Requester' => $this->requester ?? ['Transport' => ['Type' => '']],
        ];
    }
}
