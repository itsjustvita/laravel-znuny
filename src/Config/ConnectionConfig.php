<?php

namespace ItsJustVita\LaravelZnuny\Config;

use DateTimeZone;
use Exception;
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

    /**
     * Zone names Znuny offers for OTRSTimeZone that PHP reads as fixed offsets without
     * daylight saving time, mapped to the regions the time zone database links them to.
     */
    private const LEGACY_TIMEZONES = [
        'CET' => 'Europe/Brussels',
        'MET' => 'Europe/Brussels',
        'EET' => 'Europe/Athens',
        'WET' => 'Europe/Lisbon',
        'EST' => 'America/Panama',
        'MST' => 'America/Phoenix',
        'HST' => 'Pacific/Honolulu',
    ];

    /**
     * @param  int<1, max>  $batchSize
     * @param  int<1, max>|null  $searchLimit
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $username,
        public string $password,
        public RouteMap $routes,
        public string $auth,
        public string $userType,
        public string $authTransport,
        public bool|string $verifySsl,
        public int $timeout,
        public int $connectTimeout,
        public int $retries,
        public int $retryDelay,
        public int $batchSize,
        public string $timezone = 'UTC',
        public ?int $searchLimit = 500,
    ) {}

    /**
     * @param  array<string, mixed>  $config  one entry of znuny.connections (or a runtime config)
     * @param  array<string, mixed>  $http  the global znuny.http config used as fallback
     * @param  string|null  $timezone  the global znuny.timezone used as fallback
     */
    public static function fromArray(string $name, array $config, array $http = [], ?string $timezone = null): self
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
            verifySsl: self::verifySsl($name, $config['verify_ssl'] ?? null),
            timeout: (int) ($config['timeout'] ?? 30),
            connectTimeout: (int) ($config['connect_timeout'] ?? 3),
            retries: max(0, (int) ($config['retries'] ?? $http['retries'] ?? 3)),
            retryDelay: max(10, (int) ($config['retry_delay'] ?? $http['retry_delay'] ?? 100)),
            batchSize: max(1, (int) ($config['batch_size'] ?? $http['batch_size'] ?? 50)),
            timezone: self::timezoneFor($name, $config['timezone'] ?? null, $timezone),
            searchLimit: self::searchLimit($name, match (true) {
                array_key_exists('search_limit', $config) => $config['search_limit'],
                array_key_exists('search_limit', $http) => $http['search_limit'],
                default => 500,
            }),
        );
    }

    public function usesSession(): bool
    {
        return $this->auth === self::AUTH_SESSION;
    }

    /**
     * Cache key for this connection's session and lookups. It covers the target and the
     * credentials, so connections that share a name (or a login with another password)
     * never share cached data. $secret (the app key) keeps the credentials from being
     * guessed from the key.
     */
    public function identityKey(string $secret = ''): string
    {
        $identity = implode("\0", [$this->baseUrl, $this->userType, $this->username, $this->password]);

        return $this->name.':'.substr(hash_hmac('sha256', $identity, $secret), 0, 32);
    }

    /**
     * The request field Znuny expects for the login of this user type.
     */
    public function loginKey(): string
    {
        return $this->userType === self::USER_CUSTOMER ? 'CustomerUserLogin' : 'UserLogin';
    }

    /**
     * Znuny's OTRSTimeZone: the time zone of the dates Znuny reads and returns. The
     * connection's own value wins over the global one; without either it is UTC, Znuny's default.
     */
    public static function timezoneFor(string $connection, mixed $own, mixed $global = null): string
    {
        $value = 'UTC';

        foreach ([$own, $global] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));

            if ($candidate !== '') {
                $value = $candidate;

                break;
            }
        }

        $value = self::LEGACY_TIMEZONES[strtoupper($value)] ?? $value;

        try {
            // Abbreviations (CEST) and offsets (+02:00) have no location and ignore daylight saving time.
            $valid = (new DateTimeZone($value))->getLocation() !== false;
        } catch (Exception) {
            $valid = false;
        }

        if (! $valid) {
            throw new ZnunyConfigurationException(sprintf(
                "Znuny connection [%s] has invalid [timezone] value [%s]. Use the IANA name of Znuny's OTRSTimeZone, e.g. UTC or Europe/Berlin; abbreviations and offsets are not supported.",
                $connection,
                $value,
            ));
        }

        return $value;
    }

    /**
     * The most tickets a search without limit() may match. Without it (null or blank) no
     * limit is sent and Znuny cuts the result off at its own SearchLimit.
     *
     * @return int<1, max>|null
     */
    private static function searchLimit(string $connection, mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        // Up to eight digits, like Znuny's own SearchLimit setting.
        $limit = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99_999_999]])
            : false;

        if ($limit === false) {
            throw new ZnunyConfigurationException(sprintf(
                'Znuny connection [%s] has invalid [search_limit] value [%s]. Use a number between 1 and 99999999, or null to switch it off.',
                $connection,
                is_scalar($value) ? var_export($value, true) : gettype($value),
            ));
        }

        return max(1, $limit);
    }

    /**
     * true, false or the path of a CA bundle to verify against. Other values are an
     * error, so that a typo cannot switch certificate verification off.
     */
    private static function verifySsl(string $connection, mixed $value): bool|string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        if (is_scalar($value)) {
            $switch = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($switch !== null) {
                return $switch;
            }

            if (is_string($value) && file_exists($value)) {
                return $value;
            }
        }

        throw new ZnunyConfigurationException(sprintf(
            'Znuny connection [%s] has invalid [verify_ssl] value [%s]. Use true, false or the absolute path of an existing CA bundle.',
            $connection,
            is_scalar($value) ? (string) $value : gettype($value),
        ));
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
