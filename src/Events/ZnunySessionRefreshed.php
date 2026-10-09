<?php

namespace ItsJustVita\LaravelZnuny\Events;

/**
 * Dispatched after an expired session was replaced by a new one.
 */
final readonly class ZnunySessionRefreshed
{
    public function __construct(
        public string $connection,
    ) {}
}
