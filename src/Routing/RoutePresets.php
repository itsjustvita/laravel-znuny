<?php

namespace ItsJustVita\LaravelZnuny\Routing;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;

final class RoutePresets
{
    /** Routes of Znuny's stock GenericTicketConnectorREST web service. */
    public const GENERIC_TICKET_CONNECTOR = 'generic-ticket-connector';

    /** Routes named after the operations (laravel-znuny v0.1 behaviour). */
    public const OPERATION_NAME = 'operation-name';

    /** @var array<string, array<string, string>> */
    private const PRESETS = [
        self::GENERIC_TICKET_CONNECTOR => [
            'SessionCreate' => 'POST /Session',
            'SessionGet' => 'GET /Session/:SessionID',
            'TicketCreate' => 'POST /Ticket',
            'TicketGet' => 'GET /Ticket/:TicketID',
            'TicketSearch' => 'GET /Ticket',
            'TicketUpdate' => 'PATCH /Ticket/:TicketID',
            'TicketHistoryGet' => 'GET /TicketHistory/:TicketID',
        ],
        self::OPERATION_NAME => [
            'TicketGet' => 'POST /TicketGet/:TicketID',
            'TicketUpdate' => 'POST /TicketUpdate/:TicketID',
            'QueueGet' => 'POST /QueueGet/:QueueID',
        ],
    ];

    /** @return array<string, string> */
    public static function get(string $name): array
    {
        if (! isset(self::PRESETS[$name])) {
            throw new ZnunyConfigurationException(sprintf(
                'Unknown Znuny route preset [%s]. Available presets: %s.',
                $name,
                implode(', ', self::names()),
            ));
        }

        return self::PRESETS[$name];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PRESETS);
    }
}
