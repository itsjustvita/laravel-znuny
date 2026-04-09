<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Resources\CustomerResource;
use ItsJustVita\LaravelZnuny\Resources\PriorityResource;
use ItsJustVita\LaravelZnuny\Resources\QueueResource;
use ItsJustVita\LaravelZnuny\Resources\StateResource;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\Resources\TypeResource;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use RuntimeException;

class ZnunyManager
{
    /** @var array<string, ZnunyClient> */
    protected array $clients = [];

    protected ?string $boundConnection = null;

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

    public function usingCredentials(
        string $baseUrl,
        string $username,
        string $password,
        bool $verifySSL = true,
        int $timeout = 30,
    ): self {
        $clone = clone $this;
        $clone->boundConnection = 'ephemeral';
        $clone->runtimeConfig = [
            'base_url'   => $baseUrl,
            'username'   => $username,
            'password'   => $password,
            'verify_ssl' => $verifySSL,
            'timeout'    => $timeout,
        ];
        $clone->clients = [];

        return $clone;
    }

    public function connectionName(): string
    {
        return $this->boundConnection ?? $this->defaultConnection();
    }

    public function client(): ZnunyClient
    {
        $connection = $this->connectionName();

        if ($this->runtimeConfig === null && isset($this->clients[$connection])) {
            return $this->clients[$connection];
        }

        $config = $this->runtimeConfig ?? $this->configFor($connection);

        $client = new ZnunyClient(
            connection: $connection,
            config: $config,
            sessionManager: $this->buildSessionManager(),
            exceptionMapper: new ExceptionMapper(),
        );

        if ($this->runtimeConfig === null) {
            $this->clients[$connection] = $client;
        }

        return $client;
    }

    public function tickets(): TicketResource
    {
        return new TicketResource($this->client());
    }

    public function queues(): QueueResource
    {
        return new QueueResource($this->client());
    }

    public function states(): StateResource
    {
        return new StateResource($this->client());
    }

    public function priorities(): PriorityResource
    {
        return new PriorityResource($this->client());
    }

    public function types(): TypeResource
    {
        return new TypeResource($this->client());
    }

    public function customers(): CustomerResource
    {
        return new CustomerResource($this->client());
    }

    public function flush(?string $resource = null): void
    {
        // Wired in Phase 7
    }

    public function flushAll(): void
    {
        // Wired in Phase 7
    }

    protected function defaultConnection(): string
    {
        return (string) $this->app['config']->get('znuny.default', 'default');
    }

    protected function configFor(string $name): array
    {
        $config = $this->app['config']->get("znuny.connections.{$name}");

        if (! is_array($config)) {
            throw new RuntimeException("Znuny connection [{$name}] is not configured.");
        }

        return $config;
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
