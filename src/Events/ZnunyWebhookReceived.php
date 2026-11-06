<?php

namespace ItsJustVita\LaravelZnuny\Events;

use ItsJustVita\LaravelZnuny\Config\ConnectionConfig;
use ItsJustVita\LaravelZnuny\Data\Ticket;

/**
 * Dispatched when Znuny calls the webhook endpoint. Contains only scalars and arrays,
 * so queued listeners can serialize it.
 */
final readonly class ZnunyWebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload  the decoded request body
     * @param  array<string, mixed>  $parameters  route parameters, e.g. ['tenant' => 'acme']
     */
    public function __construct(
        public string $event,
        public ?string $ticketId,
        public ?string $articleId,
        public array $payload,
        public array $parameters = [],
    ) {}

    public function is(string ...$events): bool
    {
        return in_array($this->event, $events, true);
    }

    /**
     * The ticket sent along by Znuny's Ticket::Generic invoker, if any.
     *
     * @param  string|null  $timezone  Znuny's OTRSTimeZone, in which the payload's dates are given;
     *                                 defaults to the one configured for the default connection
     */
    public function ticket(?string $timezone = null): ?Ticket
    {
        $data = $this->payload['Ticket'] ?? null;

        if (! is_array($data) || $data === []) {
            return null;
        }

        return Ticket::fromZnunyResponse($data, $timezone ?? self::defaultTimezone());
    }

    private static function defaultTimezone(): string
    {
        $connection = (string) config('znuny.default', 'default');

        return ConnectionConfig::timezoneFor(
            $connection,
            config("znuny.connections.{$connection}.timezone"),
            config('znuny.timezone'),
        );
    }
}
