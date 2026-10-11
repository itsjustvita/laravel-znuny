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
use ItsJustVita\LaravelZnuny\Resources\StateResource;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\Resources\TypeResource;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;

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

    public function queues(): QueueResource
    {
        return new QueueResource($this->client(), $this->buildCacheManager());
    }

    public function states(): StateResource
    {
        return new StateResource($this->client(), $this->buildCacheManager());
    }

    public function priorities(): PriorityResource
    {
        return new PriorityResource($this->client(), $this->buildCacheManager());
    }

    public function types(): TypeResource
    {
        return new TypeResource($this->client(), $this->buildCacheManager());
    }

    public function customers(): CustomerResource
    {
        return new CustomerResource($this->client());
    }

    /**
     * Test the connection by performing a fresh SessionCreate call.
     */
    public function ping(): bool
    {
        return $this->client()->ping();
    }

    public function flush(?string $resource = null): void
    {
        $this->buildCacheManager()->flush($this->client()->identityKey(), $resource);
    }

    public function flushAll(): void
    {
        $connections = array_keys((array) $this->app['config']->get('znuny.connections', []));
        $manager = $this->buildCacheManager();

        foreach ($connections as $name) {
            try {
                $manager->flush($this->connection($name)->client()->identityKey());
            } catch (ZnunyConfigurationException) {
                // An incomplete connection cannot have cached anything.
            }
        }
    }

    protected function defaultConnection(): string
    {
        return (string) $this->app['config']->get('znuny.default', 'default');
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

    protected function configFor(string $name): array
    {
        $config = $this->app['config']->get("znuny.connections.{$name}");

        if (! is_array($config)) {
            throw new ZnunyConfigurationException("Znuny connection [{$name}] is not configured.");
        }

        return $config;
    }

    protected function buildCacheManager(): CacheManager
    {
        $store = $this->app['config']->get('znuny.cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();

        return new CacheManager(
            cache: $cache,
            prefix: (string) $this->app['config']->get('znuny.cache.prefix', 'znuny:lookup:'),
            ttls: (array) $this->app['config']->get('znuny.cache.ttl', []),
            enabled: (bool) $this->app['config']->get('znuny.cache.enabled', true),
        );
    }

    protected function buildSessionManager(): SessionManager
    {
        $store = $this->app['config']->get('znuny.session.cache_store');
        $cache = $store ? Cache::store($store) : Cache::store();

        return new SessionManager(
            store: new SessionStore(
                $cache,
                (string) $this->app['config']->get('znuny.session.cache_prefix', 'znuny:session:'),
            ),
            ttl: (int) $this->app['config']->get('znuny.session.ttl', 21600),
        );
    }
}
