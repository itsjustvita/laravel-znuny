<?php

namespace ItsJustVita\LaravelZnuny\Http;

use ItsJustVita\LaravelZnuny\Config\ConnectionConfig;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Routing\Route;
use ItsJustVita\LaravelZnuny\Support\Redactor;

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
        [$path, $loggedPath, $used] = $this->expandPath($route, $operation, $payload);
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

            return new PreparedRequest('GET', $query === '' ? $url : $url.'?'.$query, $loggedPath, null, $headers);
        }

        return new PreparedRequest($route->method, $url, $loggedPath, array_merge($auth, $payload), $headers);
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
     * Also returns the path for logs, events and errors, in which sensitive placeholders
     * (e.g. ":SessionID") are redacted.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function expandPath(Route $route, string $operation, array $payload): array
    {
        $used = [];
        $logged = [];

        $path = preg_replace_callback('#:([^/]+)#', function (array $match) use ($route, $operation, $payload, &$used, &$logged): string {
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

            // "." and ".." would become dot segments that HTTP servers normalise away,
            // sending the request to another route or web service.
            foreach ($values as $item) {
                if (in_array(QueryString::scalar($item), ['.', '..'], true)) {
                    throw new ZnunyConfigurationException(sprintf(
                        'Parameter [%s] for operation [%s] must not be "." or "..".',
                        $name,
                        $operation,
                    ));
                }
            }

            $used[] = $name;
            $encoded = implode(',', array_map(fn (mixed $item): string => rawurlencode(QueryString::scalar($item)), $values));
            $logged[$name] = Redactor::isSensitive($name) ? Redactor::REDACTED : $encoded;

            return $encoded;
        }, $route->path);

        $loggedPath = preg_replace_callback('#:([^/]+)#', fn (array $match): string => $logged[$match[1]], $route->path);

        return [(string) $path, (string) $loggedPath, $used];
    }
}
