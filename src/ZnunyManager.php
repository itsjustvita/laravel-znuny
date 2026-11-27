<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use ItsJustVita\LaravelZnuny\Caching\CacheManager;
use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Resources\CustomerResource;
use ItsJustVita\LaravelZnuny\Resources\PriorityResource;
use ItsJustVita\LaravelZnuny\Resources\QueueResource;
use ItsJustVita\LaravelZnuny\Resources\SessionResource;
use ItsJustVita\LaravelZnuny\Resources\StateResource;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\Resources\TimeAccountingResource;
use ItsJustVita\LaravelZnuny\Resources\TypeResource;
use ItsJustVita\LaravelZnuny\Resources\UserResource;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use ItsJustVita\LaravelZnuny\Support\Setting;

class ZnunyManager
{
    /** @var array<string, ZnunyClient> */
    protected array $clients = [];

    protected ?string $boundConnection = null;

    /** @var array<string, mixed>|null */
    protected ?array $runtimeConfig = null;

    public function __construct(
        protected readonly Application $app,
    ) {}

    public function connection(?string $name = null): self
    {
        $clone = clone $this;
        $clone->boundConnection = $name ?? $this->defaultConnection();
        $clone->runtimeConfig = null;
        $clone->clients = [];

        return $clone;
    }

    /**
     * Use a connection that only exists at runtime. Accepts the keys of a configured
     * connection plus an optional "name" (used for logs, events and the session cache).
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config): self
    {
        $name = (string) ($config['name'] ?? '');
        unset($config['name']);

        $clone = clone $this;
        $clone->boundConnection = $name !== '' ? $name : $this->runtimeName($config);
        $clone->runtimeConfig = $config;
        $clone->clients = [];

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $options  further connection keys, e.g. ['routes' => 'operation-name']
     */
    public function usingCredentials(
        string $baseUrl,
        string $username,
        string $password,
        bool $verifySSL = true,
        int $timeout = 30,
        array $options = [],
    ): self {
        return $this->build(array_merge([
            'base_url' => $baseUrl,
            'username' => $username,
            'password' => $password,
            'verify_ssl' => $verifySSL,
            'timeout' => $timeout,
        ], $options));
    }

    public function client(): ZnunyClient
    {
        $connection = $this->connectionName();

        return $this->clients[$connection] ??= new ZnunyClient(
            connection: $connection,
            config: $this->runtimeConfig ?? $this->configFor($connection),
            sessionManager: $this->buildSessionManager(),
            exceptionMapper: new ExceptionMapper,
        );
    }

    /**
     * Call any Znuny operation on this connection.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function call(string $operation, array $payload = []): array
    {
        return $this->client()->call($operation, $payload);
    }

    public function connectionName(): string
    {
        return $this->boundConnection ?? $this->defaultConnection();
    }

    public function tickets(): TicketResource
    {
        return new TicketResource($this->client());
    }

    public function session(): SessionResource
    {
        return new SessionResource($this->client());
    }

    public function timeAccounting(): TimeAccountingResource
    {
        return new TimeAccountingResource($this->client());
    }

    public function users(): UserResource
    {
        return new UserResource($this->client());
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0: needs QueueList and QueueGet, which Znuny does not ship.
     */
    public function queues(): QueueResource
    {
        $this->deprecated(__FUNCTION__, "it needs operations Znuny does not ship. Use Znuny::call('QueueList') instead.");

        return new QueueResource($this->client(), $this->buildCacheManager());
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0: needs StateList, which Znuny does not ship.
     */
    public function states(): StateResource
    {
        $this->deprecated(__FUNCTION__, "it needs operations Znuny does not ship. Use Znuny::call('StateList') instead.");

        return new StateResource($this->client(), $this->buildCacheManager());
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0: needs PriorityList, which Znuny does not ship.
     */
    public function priorities(): PriorityResource
    {
        $this->deprecated(__FUNCTION__, "it needs operations Znuny does not ship. Use Znuny::call('PriorityList') instead.");

        return new PriorityResource($this->client(), $this->buildCacheManager());
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0: needs TypeList, which Znuny does not ship.
     */
    public function types(): TypeResource
    {
        $this->deprecated(__FUNCTION__, "it needs operations Znuny does not ship. Use Znuny::call('TypeList') instead.");

        return new TypeResource($this->client(), $this->buildCacheManager());
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0: needs CustomerUserGet, which Znuny does not ship.
     */
    public function customers(): CustomerResource
    {
        $this->deprecated(__FUNCTION__, "it needs operations Znuny does not ship. Use Znuny::call('CustomerUserGet') instead, and Znuny::tickets()->whereCustomerId() for a customer's tickets.");

        return new CustomerResource($this->client());
    }

    /**
     * Test the connection by performing a fresh SessionCreate call.
     */
    public function ping(): bool
    {
        return $this->client()->ping();
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0 together with the lookups whose cache it clears.
     */
    public function flush(?string $resource = null): void
    {
        $this->deprecated(__FUNCTION__, 'it clears the cache of the lookups, which need operations Znuny does not ship.');

        $this->buildCacheManager()->flush($this->client()->identityKey(), $resource);
    }

    /**
     * @deprecated since 0.4.0, removed in 1.0 together with the lookups whose cache it clears.
     */
    public function flushAll(): void
    {
        $this->deprecated(__FUNCTION__, 'it clears the cache of the lookups, which need operations Znuny does not ship.');

        $connections = array_keys((array) $this->config('znuny.connections', []));
        $manager = $this->buildCacheManager();

        foreach ($connections as $name) {
            try {
                $manager->flush($this->connection($name)->client()->identityKey());
            } catch (ZnunyConfigurationException) {
                // An incomplete connection cannot have cached anything.
            }
        }
    }

    /**
     * The package covers the operations Znuny ships. What needs other operations works until 1.0.
     */
    private function deprecated(string $method, string $why): void
    {
        trigger_error("Znuny::{$method}() is deprecated and will be removed in 1.0: {$why}", E_USER_DEPRECATED);
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get($key, $default);
    }

    protected function defaultConnection(): string
    {
        return (string) $this->config('znuny.default', 'default');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function runtimeName(array $config): string
    {
        return 'ephemeral_'.md5(implode('|', [
            (string) ($config['base_url'] ?? ''),
            strtolower((string) ($config['user_type'] ?? 'agent')),
            (string) ($config['username'] ?? ''),
            strtolower((string) ($config['auth'] ?? 'session')),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function configFor(string $name): array
    {
        $config = $this->config("znuny.connections.{$name}");

        if (! is_array($config)) {
            throw new ZnunyConfigurationException("Znuny connection [{$name}] is not configured.");
        }

        return $config;
    }

    protected function buildCacheManager(): CacheManager
    {
        $store = $this->config('znuny.cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();

        return new CacheManager(
            cache: $cache,
            prefix: (string) $this->config('znuny.cache.prefix', 'znuny:lookup:'),
            ttls: (array) $this->config('znuny.cache.ttl', []),
            enabled: Setting::boolean(true, $this->config('znuny.cache.enabled')),
        );
    }

    protected function buildSessionManager(): SessionManager
    {
        $store = $this->config('znuny.session.cache_store');
        $cache = $store ? Cache::store($store) : Cache::store();
        $prefix = (string) $this->config('znuny.session.cache_prefix', 'znuny:session:');

        // Znuny binds a session to the IP address that created it (SessionCheckRemoteIP). Hosts
        // that share a cache store but reach Znuny from different addresses would invalidate
        // each other's session with every request, so each host keeps its own. Only an
        // explicit "false", "off", "no" or 0 switches that off.
        if (Setting::boolean(true, $this->config('znuny.session.per_host'))) {
            $prefix .= substr(hash('sha256', (string) gethostname()), 0, 12).':';
        }

        return new SessionManager(
            store: new SessionStore($cache, $prefix),
            ttl: Setting::integer('The Znuny configuration', 'session.ttl', 21600, $this->config('znuny.session.ttl')),
        );
    }
}
