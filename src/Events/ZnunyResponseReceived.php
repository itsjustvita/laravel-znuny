<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyResponseReceived
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>  $response
     */
    public function __construct(
        public string $connection,
        public string $operation,
        public float $responseTimeMs,
        public array $payload,
        public array $response,
        public int $status = 200,
    ) {}
}
