<?php

namespace ItsJustVita\LaravelZnuny\Events;

use ItsJustVita\LaravelZnuny\Data\Ticket;

final readonly class ZnunyTicketCreated
{
    public function __construct(
        public string $connection,
        public Ticket $ticket,
    ) {}
}
