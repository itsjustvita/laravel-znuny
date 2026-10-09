<?php

namespace ItsJustVita\LaravelZnuny\Http;

use ItsJustVita\LaravelZnuny\Config\ConnectionConfig;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Routing\Route;

final class RequestBuilder
{
    /** @var array<string, string> */
    public const AUTH_HEADERS = [
        'SessionID' => 'X-OTRS-Header-SessionID',
        'UserLogin' => 'X-OTRS-Header-UserLogin',
        'CustomerUserLogin' => 'X-OTRS-Header-CustomerUserLogin',
        'Password' => 'X-OTRS-Header-Password',
    ];

    public function __construct(
        private readonly ConnectionConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  operation data
     * @param  array<string, string>  $auth  SessionID, or the login key and Password
     */
    public function build(string $operation, array $payload, array $auth = []): PreparedRequest
    {
        $route = $this->config->routes->route($operation);
        [$path, $used] = $this->expandPath($route, $operation, $payload);
        $url = $this->config->baseUrl.$path;
        $headers = [];

        if ($auth !== [] && $this->authInHeaders($route)) {
            foreach ($auth as $key => $value) {
                $headers[self::AUTH_HEADERS[$key] ?? 'X-OTRS-Header-'.$key] = $value;
            }

            $auth = [];
        }

        if (! $route->sendsBody()) {
            $query = QueryString::build(array_merge($auth, array_diff_key($payload, array_flip($used))), $operation);

            return new PreparedRequest('GET', $query === '' ? $url : $url.'?'.$query, $path, null, $headers);
        }

        return new PreparedRequest($route->method, $url, $path, array_merge($auth, $payload), $headers);
    }

    private function authInHeaders(Route $route): bool
    {
        return match ($this->config->authTransport) {
            ConnectionConfig::TRANSPORT_HEADERS => true,
            ConnectionConfig::TRANSPORT_PAYLOAD => false,
            default => ! $route->sendsBody(),
        };
    }

    /**
     * Fill ":Param" placeholders from the payload. Lists are joined with commas,
     * which Znuny accepts for TicketGet/TicketHistoryGet batches.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: list<string>}
     */
    private function expandPath(Route $route, string $operation, array $payload): array
    {
        $used = [];

        $path = preg_replace_callback('#:([^/]+)#', function (array $match) use ($route, $operation, $payload, &$used): string {
            $name = $match[1];
            $value = $payload[$name] ?? null;
            $values = array_filter(
                is_array($value) ? array_values($value) : [$value],
                fn (mixed $item): bool => is_scalar($item) && $item !== '',
            );

            if ($values === []) {
                throw new ZnunyConfigurationException(sprintf(
                    'Route [%s] for operation [%s] requires parameter [%s].',
                    $route,
                    $operation,
                    $name,
                ));
            }

            $used[] = $name;

            return implode(',', array_map(fn (mixed $item): string => rawurlencode(QueryString::scalar($item)), $values));
        }, $route->path);

        return [(string) $path, $used];
    }
}
