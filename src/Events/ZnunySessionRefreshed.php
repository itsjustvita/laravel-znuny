<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunySessionRefreshed
{
    public function __construct(
        public string $connection,
        public ?string $oldSessionId,
        public string $newSessionId,
    ) {}
}
