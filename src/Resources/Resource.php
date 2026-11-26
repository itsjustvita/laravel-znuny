<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\Caching\CacheManager;
use ItsJustVita\LaravelZnuny\ZnunyClient;

abstract class Resource
{
    public function __construct(
        protected readonly ZnunyClient $client,
        protected readonly ?CacheManager $cache = null,
    ) {}

    protected function remember(string $resource, callable $resolver): mixed
    {
        if (! $this->cache) {
            return $resolver();
        }

        return $this->cache->remember($this->client->identityKey(), $resource, $resolver);
    }

    /**
     * The rows of a list operation, cached as plain arrays. Objects must not be cached: by
     * default a Laravel 13 application reads none back (cache.serializable_classes).
     *
     * @param  string  $key  the response key that holds the rows
     * @return list<array<array-key, mixed>>
     */
    protected function rows(string $resource, string $operation, string $key): array
    {
        $resolver = function () use ($operation, $key): array {
            $rows = $this->client->call($operation)[$key] ?? [];

            return array_values(array_filter(is_array($rows) ? $rows : [], is_array(...)));
        };

        $rows = $this->remember($resource, $resolver);

        if (! is_array($rows)) {
            // An entry written by 0.3.1 or earlier, which cached collections of objects.
            $this->cache?->flush($this->client->identityKey(), $resource);
            $rows = $this->remember($resource, $resolver);
        }

        return array_values(array_filter($rows, is_array(...)));
    }
}
