<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyResponseReceived
{
    public function __construct(
        public string $connection,
        public string $operation,
        public float $responseTimeMs,
        public array $payload,
        public array $response,
        public int $status = 200,
    ) {}
}
