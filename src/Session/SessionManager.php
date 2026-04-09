<?php

namespace ItsJustVita\LaravelZnuny\Session;

class SessionManager
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly int $ttl,
    ) {}

    /**
     * @param  callable(string $connection): string  $creator
     */
    public function sessionId(string $connection, callable $creator): string
    {
        $cached = $this->store->get($connection);

        if ($cached !== null) {
            return $cached;
        }

        $fresh = $creator($connection);
        $this->store->put($connection, $fresh, $this->ttl);

        return $fresh;
    }

    public function invalidate(string $connection): void
    {
        $this->store->forget($connection);
    }
}
