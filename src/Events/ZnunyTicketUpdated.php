<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyTicketUpdated
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $dynamicFields
     */
    public function __construct(
        public string $connection,
        public string $ticketId,
        public array $changes,
        public array $dynamicFields = [],
    ) {}
}
