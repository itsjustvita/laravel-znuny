<?php

namespace ItsJustVita\LaravelZnuny\Events;

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
     */
    public function ticket(): ?Ticket
    {
        $data = $this->payload['Ticket'] ?? null;

        return is_array($data) && $data !== [] ? Ticket::fromZnunyResponse($data) : null;
    }
}
