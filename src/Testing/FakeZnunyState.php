<?php

namespace ItsJustVita\LaravelZnuny\Testing;

use Closure;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use Throwable;

/**
 * Responses and recorded calls of one Znuny::fake(), shared by all its connections.
 */
final class FakeZnunyState
{
    /** @var list<array{connection: string, operation: string, payload: array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param  array<string, mixed>  $responses  by operation name; "*" answers every other operation
     */
    public function __construct(
        private readonly array $responses,
    ) {}

    /**
     * Record the call, then answer it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function respond(string $connection, string $operation, array $payload): array
    {
        $this->calls[] = ['connection' => $connection, 'operation' => $operation, 'payload' => $payload];

        $key = array_key_exists($operation, $this->responses) ? $operation : '*';

        if (! array_key_exists($key, $this->responses)) {
            throw new StrayZnunyCallException("Znuny::fake() has no response for operation [{$operation}] (connection [{$connection}]).");
        }

        $response = $this->responses[$key];

        if ($response instanceof FakeResponseSequence) {
            if ($response->isEmpty()) {
                throw new StrayZnunyCallException("The Znuny::sequence() for operation [{$operation}] has no responses left (connection [{$connection}]).");
            }

            $response = $response->next();
        }

        if ($response instanceof Closure) {
            $response = $response($payload, $connection);
        }

        if ($response instanceof Throwable) {
            if ($response instanceof ZnunyException) {
                if ($response->getOperation() === '') {
                    $response->setOperation($operation);
                }

                if ($response->getConnection() === '') {
                    $response->setConnection($connection);
                }
            }

            throw $response;
        }

        if (! is_array($response)) {
            throw new StrayZnunyCallException("The fake response for operation [{$operation}] must be an array, a closure, an exception or a Znuny::sequence().");
        }

        return $response;
    }

    /**
     * @return list<array{connection: string, operation: string, payload: array<string, mixed>}>
     */
    public function calls(?string $operation = null): array
    {
        if ($operation === null) {
            return $this->calls;
        }

        return array_values(array_filter($this->calls, fn (array $call): bool => $call['operation'] === $operation));
    }
}
