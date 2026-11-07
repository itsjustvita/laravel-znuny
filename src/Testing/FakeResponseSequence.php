<?php

namespace ItsJustVita\LaravelZnuny\Testing;

/**
 * Fake responses that are used one per call, see Znuny::sequence().
 */
final class FakeResponseSequence
{
    /** @var list<mixed> */
    private array $responses;

    public function __construct(mixed ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function isEmpty(): bool
    {
        return $this->responses === [];
    }

    public function next(): mixed
    {
        return array_shift($this->responses);
    }
}
