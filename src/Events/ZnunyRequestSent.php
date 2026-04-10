<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyRequestSent
{
    public function __construct(
        public string $connection,
        public string $operation,
        public array $payload,
    ) {}
}
