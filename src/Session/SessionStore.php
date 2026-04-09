<?php

namespace ItsJustVita\LaravelZnuny\Session;

use Illuminate\Contracts\Cache\Repository;

class SessionStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
    ) {}

    public function get(string $connection): ?string
    {
        return $this->cache->get($this->key($connection));
    }

    public function put(string $connection, string $sessionId, int $ttl): void
    {
        $this->cache->put($this->key($connection), $sessionId, $ttl);
    }

    public function forget(string $connection): void
    {
        $this->cache->forget($this->key($connection));
    }

    private function key(string $connection): string
    {
        return $this->prefix.$connection;
    }
}
