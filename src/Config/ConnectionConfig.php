<?php

namespace ItsJustVita\LaravelZnuny\Config;

use DateTimeZone;
use Exception;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Http\RequestBuilder;
use ItsJustVita\LaravelZnuny\Routing\RouteMap;
use ItsJustVita\LaravelZnuny\Support\Setting;

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
     * The Guzzle request options a connection may set. Others could switch off certificate
     * verification or the timeouts, or change what the client relies on.
     */
    public const HTTP_OPTIONS = ['proxy', 'cert', 'ssl_key', 'headers', 'auth', 'force_ip_resolve', 'version'];

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
     * @param  array<string, mixed>  $options  Guzzle request options, see HTTP_OPTIONS
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
        public array $options = [],
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

        $owner = "Znuny connection [{$name}]";

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
            timeout: Setting::integer($owner, 'timeout', 30, $config['timeout'] ?? null),
            connectTimeout: Setting::integer($owner, 'connect_timeout', 3, $config['connect_timeout'] ?? null),
            retries: max(0, Setting::integer($owner, 'retries', 3, $config['retries'] ?? null, $http['retries'] ?? null)),
            retryDelay: max(10, Setting::integer($owner, 'retry_delay', 100, $config['retry_delay'] ?? null, $http['retry_delay'] ?? null)),
            batchSize: max(1, Setting::integer($owner, 'batch_size', 50, $config['batch_size'] ?? null, $http['batch_size'] ?? null)),
            timezone: self::timezoneFor($name, $config['timezone'] ?? null, $timezone),
            searchLimit: self::searchLimit($name, $config, $http),
            options: self::options($name, $http['options'] ?? null, $config['options'] ?? null),
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
     * The most tickets a search without limit() may match: the connection's value, then the
     * global one, then 500. Null is a value of its own and switches the limit off, so that no
     * limit is sent and Znuny cuts the result off at its own SearchLimit. A blank string, as
     * an empty ZNUNY_SEARCH_LIMIT= gives, counts as not set: an empty variable must not
     * switch off the check that a search is complete.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $http
     * @return int<1, max>|null
     */
    private static function searchLimit(string $connection, array $config, array $http): ?int
    {
        $value = 500;

        foreach ([$config, $http] as $source) {
            if (! array_key_exists('search_limit', $source)) {
                continue;
            }

            if ($source['search_limit'] === null) {
                return null;
            }

            if (! Setting::isBlank($source['search_limit'])) {
                $value = $source['search_limit'];

                break;
            }
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
     * The request options of the connection: the global ones, replaced key by key by its own.
     *
     * @return array<string, mixed>
     */
    private static function options(string $connection, mixed $global, mixed $own): array
    {
        $options = [];

        foreach ([$global, $own] as $source) {
            if (Setting::isBlank($source) || $source === []) {
                continue;
            }

            if (! is_array($source) || array_is_list($source)) {
                throw new ZnunyConfigurationException(
                    "Znuny connection [{$connection}] has invalid [options]: they must be an array of named options, e.g. ['proxy' => 'http://proxy.internal:3128']."
                );
            }

            foreach ($source as $key => $value) {
                $key = (string) $key;

                if (in_array($key, ['verify', 'timeout', 'connect_timeout'], true)) {
                    throw new ZnunyConfigurationException(sprintf(
                        'Znuny connection [%s] cannot set [%s] in [options]. Use [%s] instead.',
                        $connection,
                        $key,
                        $key === 'verify' ? 'verify_ssl' : $key,
                    ));
                }

                if (! in_array($key, self::HTTP_OPTIONS, true)) {
                    throw new ZnunyConfigurationException(sprintf(
                        'Znuny connection [%s] has an unsupported entry in [options]: [%s]. Accepted: %s.',
                        $connection,
                        $key,
                        implode(', ', self::HTTP_OPTIONS),
                    ));
                }

                if ($key === 'headers') {
                    if ($value === null) {
                        continue;
                    }

                    self::checkHeaders($connection, $value);
                }

                if ($key === 'cert' || $key === 'ssl_key') {
                    self::checkFile($connection, $key, $value);
                }

                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Headers must map names to values, and must not be ones the package sets itself. Znuny
     * copies the X-OTRS-Header-* authentication headers into the request, over its body, so
     * a configured one would replace the authentication the package sends in the body of
     * POST, PATCH and DELETE requests. The content type belongs to the request body.
     */
    private static function checkHeaders(string $connection, mixed $headers): void
    {
        if (! is_array($headers)) {
            throw new ZnunyConfigurationException(
                "Znuny connection [{$connection}]: [headers] in [options] must be an array of header names and values."
            );
        }

        $own = [...array_map(strtolower(...), array_values(RequestBuilder::AUTH_HEADERS)), 'content-type'];

        foreach (array_keys($headers) as $header) {
            if (! is_string($header)) {
                throw new ZnunyConfigurationException(
                    "Znuny connection [{$connection}]: [headers] in [options] must map header names to values, e.g. ['X-Api' => '1']."
                );
            }

            if (in_array(strtolower($header), $own, true)) {
                throw new ZnunyConfigurationException(
                    "Znuny connection [{$connection}] cannot set the header [{$header}] in [options]: the package sets it itself."
                );
            }
        }
    }

    /**
     * A client certificate or key: the path of a file, or the path and its passphrase. A
     * missing file is reported here, when the connection is built, and not by the first request.
     */
    private static function checkFile(string $connection, string $option, mixed $value): void
    {
        $file = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($file) || ! is_file($file)) {
            throw new ZnunyConfigurationException(sprintf(
                'Znuny connection [%s]: the [%s] file [%s] in [options] does not exist.',
                $connection,
                $option,
                is_string($file) ? $file : get_debug_type($file),
            ));
        }
    }

    /**
     * true, false or the path of a CA bundle to verify against. Other values are an
     * error, so that a typo cannot switch certificate verification off.
     */
    private static function verifySsl(string $connection, mixed $value): bool|string
    {
        if (Setting::isBlank($value)) {
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
        // Blank counts as not set, like an empty variable in .env.
        $value = Setting::isBlank($value) ? $allowed[0] : strtolower(trim((string) $value));

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
