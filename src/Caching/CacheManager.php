<?php

namespace ItsJustVita\LaravelZnuny\Caching;

use Illuminate\Contracts\Cache\Repository;

class CacheManager
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
        private readonly array $ttls,
        private readonly bool $enabled,
    ) {}

    public function remember(string $connection, string $resource, callable $resolver): mixed
    {
        if (! $this->enabled) {
            return $resolver();
        }

        $key = $this->key($connection, $resource);

        return $this->cache->remember($key, $this->ttlFor($resource), $resolver);
    }

    public function flush(string $connection, ?string $resource = null): void
    {
        if ($resource !== null) {
            $this->cache->forget($this->key($connection, $resource));

            return;
        }

        foreach (array_keys($this->ttls) as $r) {
            $this->cache->forget($this->key($connection, $r));
        }
    }

    private function key(string $connection, string $resource): string
    {
        return $this->prefix.$connection.':'.$resource;
    }

    private function ttlFor(string $resource): int
    {
        return (int) ($this->ttls[$resource] ?? 3600);
    }
}
