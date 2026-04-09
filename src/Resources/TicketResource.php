<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;

class TicketResource extends Resource
{
    public function find(string $ticketId, bool $withArticles = false, bool $withDynamicFields = false): Ticket
    {
        $payload = ['TicketID' => $ticketId];

        if ($withArticles) {
            $payload['AllArticles'] = 1;
        }

        if ($withDynamicFields) {
            $payload['DynamicFields'] = 1;
        }

        $response = $this->client->request('TicketGet/' . rawurlencode($ticketId), $payload);

        if (! isset($response['Ticket'][0])) {
            $ex = new TicketNotFoundException("Znuny returned no ticket data for TicketID {$ticketId}");
            $ex->setOperation('TicketGet')
               ->setConnection($this->client->connection())
               ->setRequestPayload($payload);

            throw $ex;
        }

        return Ticket::fromZnunyResponse($response['Ticket'][0]);
    }
}
