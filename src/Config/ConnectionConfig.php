<?php

namespace ItsJustVita\LaravelZnuny\Config;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Routing\RouteMap;

final readonly class ConnectionConfig
{
    public const AUTH_SESSION = 'session';

    public const AUTH_CREDENTIALS = 'credentials';

    public const USER_AGENT = 'agent';

    public const USER_CUSTOMER = 'customer';

    public const TRANSPORT_AUTO = 'auto';

    public const TRANSPORT_PAYLOAD = 'payload';

    public const TRANSPORT_HEADERS = 'headers';

    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $username,
        public string $password,
        public RouteMap $routes,
        public string $auth,
        public string $userType,
        public string $authTransport,
        public bool $verifySsl,
        public int $timeout,
        public int $connectTimeout,
        public int $retries,
        public int $retryDelay,
        public int $batchSize,
    ) {}

    /**
     * @param  array<string, mixed>  $config  one entry of znuny.connections (or a runtime config)
     * @param  array<string, mixed>  $http  the global znuny.http config used as fallback
     */
    public static function fromArray(string $name, array $config, array $http = []): self
    {
        $required = [
            'base_url' => trim((string) ($config['base_url'] ?? '')),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];

        foreach ($required as $key => $value) {
            if ($value === '') {
                throw new ZnunyConfigurationException("Znuny connection [{$name}] is missing [{$key}].");
            }
        }

        return new self(
            name: $name,
            baseUrl: rtrim($required['base_url'], '/'),
            username: $required['username'],
            password: $required['password'],
            routes: RouteMap::fromConfig($config['routes'] ?? null),
            auth: self::oneOf($name, 'auth', $config['auth'] ?? null, [self::AUTH_SESSION, self::AUTH_CREDENTIALS]),
            userType: self::oneOf($name, 'user_type', $config['user_type'] ?? null, [self::USER_AGENT, self::USER_CUSTOMER]),
            authTransport: self::oneOf($name, 'auth_transport', $config['auth_transport'] ?? null, [self::TRANSPORT_AUTO, self::TRANSPORT_PAYLOAD, self::TRANSPORT_HEADERS]),
            verifySsl: filter_var($config['verify_ssl'] ?? true, FILTER_VALIDATE_BOOL),
            timeout: (int) ($config['timeout'] ?? 30),
            connectTimeout: (int) ($config['connect_timeout'] ?? 3),
            retries: max(0, (int) ($config['retries'] ?? $http['retries'] ?? 3)),
            retryDelay: max(10, (int) ($config['retry_delay'] ?? $http['retry_delay'] ?? 100)),
            batchSize: max(1, (int) ($config['batch_size'] ?? $http['batch_size'] ?? 50)),
        );
    }

    public function usesSession(): bool
    {
        return $this->auth === self::AUTH_SESSION;
    }

    /**
     * The request field Znuny expects for the login of this user type.
     */
    public function loginKey(): string
    {
        return $this->userType === self::USER_CUSTOMER ? 'CustomerUserLogin' : 'UserLogin';
    }

    /**
     * @param  list<string>  $allowed  the first entry is the default
     */
    private static function oneOf(string $connection, string $key, mixed $value, array $allowed): string
    {
        $value = strtolower(trim((string) ($value ?? $allowed[0])));

        if (! in_array($value, $allowed, true)) {
            throw new ZnunyConfigurationException(sprintf(
                'Znuny connection [%s] has invalid [%s] value [%s]. Allowed: %s.',
                $connection,
                $key,
                $value,
                implode(', ', $allowed),
            ));
        }

        return $value;
    }
}
