<?php

namespace ItsJustVita\LaravelZnuny\Events;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;

final readonly class ZnunyRequestFailed
{
    public function __construct(
        public string $connection,
        public string $operation,
        public ZnunyException $exception,
        public array $payload,
    ) {}
}
