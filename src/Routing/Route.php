<?php

namespace ItsJustVita\LaravelZnuny\Routing;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use Stringable;

final readonly class Route implements Stringable
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        public string $method,
        public string $path,
    ) {}

    /**
     * Parse a definition in Znuny's admin notation, e.g. "GET /Ticket/:TicketID".
     * The method is optional and defaults to POST.
     */
    public static function parse(string $definition): self
    {
        $parts = preg_split('/\s+/', trim($definition), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 1) {
            array_unshift($parts, 'POST');
        }

        if (count($parts) !== 2) {
            throw new ZnunyConfigurationException(
                "Invalid route definition [{$definition}]. Expected \"METHOD /Path/:Param\"."
            );
        }

        $method = strtoupper($parts[0]);

        if (! in_array($method, self::METHODS, true)) {
            throw new ZnunyConfigurationException(
                "Invalid HTTP method [{$parts[0]}] in route definition [{$definition}]."
            );
        }

        return new self($method, '/'.ltrim($parts[1], '/'));
    }

    /**
     * Placeholder names in the path, e.g. ["TicketID"] for "/Ticket/:TicketID".
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        preg_match_all('#:([^/]+)#', $this->path, $matches);

        return $matches[1];
    }

    /**
     * Znuny ignores request bodies on GET routes; everything else carries a JSON body.
     */
    public function sendsBody(): bool
    {
        return $this->method !== 'GET';
    }

    public function __toString(): string
    {
        return $this->method.' '.$this->path;
    }
}
