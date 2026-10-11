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
}
