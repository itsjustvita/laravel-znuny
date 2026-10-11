<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyTicketUpdated
{
    public function __construct(
        public string $connection,
        public string $ticketId,
        public array $changes,
        public array $dynamicFields = [],
    ) {}
}
