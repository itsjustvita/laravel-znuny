<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyRequestSent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $connection,
        public string $operation,
        public array $payload,
        public string $method = '',
        public string $path = '',
    ) {}
}
