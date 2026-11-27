<?php

namespace ItsJustVita\LaravelZnuny\Http;

/**
 * @internal Not covered by the package's stability promise.
 */
final readonly class PreparedRequest
{
    /**
     * @param  string  $path  route path with filled placeholders (sensitive ones redacted) and without
     *                        query string; meant for logs, events and error messages, never for sending
     * @param  array<string, mixed>|null  $body  JSON body; null for GET requests
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $path,
        public ?array $body,
        public array $headers,
    ) {}
}
