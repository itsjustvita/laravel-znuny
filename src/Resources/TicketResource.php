<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\Builders\TicketBuilder;
use ItsJustVita\LaravelZnuny\Builders\TicketCreateBuilder;
use ItsJustVita\LaravelZnuny\Builders\TicketInputMapper;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Events\ZnunyArticleAdded;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketUpdated;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;

class TicketResource extends Resource
{
    /**
     * @param  array<string, mixed>  $ticket
     */
    public function create(array $ticket): TicketCreateBuilder
    {
        return new TicketCreateBuilder(
            client: $this->client,
            resource: $this,
            ticket: $ticket,
        );
    }

    public function find(string $ticketId, bool $withArticles = false, bool $withDynamicFields = false): Ticket
    {
        $payload = ['TicketID' => $ticketId];

        if ($withArticles) {
            $payload['AllArticles'] = 1;
        }

        if ($withDynamicFields) {
            $payload['DynamicFields'] = 1;
        }

        $response = $this->client->request('TicketGet/'.rawurlencode($ticketId), $payload);

        if (! isset($response['Ticket'][0])) {
            $ex = new TicketNotFoundException("Znuny returned no ticket data for TicketID {$ticketId}");
            $ex->setOperation('TicketGet')
                ->setConnection($this->client->connection())
                ->setRequestPayload($payload);

            throw $ex;
        }

        return Ticket::fromZnunyResponse($response['Ticket'][0]);
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    public function update(string $ticketId, array $ticket): void
    {
        $this->client->request('TicketUpdate/'.rawurlencode($ticketId), [
            'TicketID' => $ticketId,
            'Ticket' => TicketInputMapper::mapTicket($ticket),
        ]);

        event(new ZnunyTicketUpdated(
            connection: $this->client->connection(),
            ticketId: $ticketId,
            changes: $ticket,
        ));
    }

    /**
     * @param  array<string, mixed>  $article
     */
    public function addArticle(string $ticketId, array $article): void
    {
        $this->client->request('TicketUpdate/'.rawurlencode($ticketId), [
            'TicketID' => $ticketId,
            'Article' => TicketInputMapper::mapArticle($article),
        ]);

        event(new ZnunyArticleAdded(
            connection: $this->client->connection(),
            ticketId: $ticketId,
            article: $article,
        ));
    }

    public function close(string $ticketId, string $state = 'closed successful'): void
    {
        $this->update($ticketId, ['state' => $state]);
    }

    public function query(): TicketBuilder
    {
        return new TicketBuilder($this->client, $this);
    }

    public function where(string $field, mixed $value): TicketBuilder
    {
        return $this->query()->where($field, $value);
    }

    public function whereState(string|array $states): TicketBuilder
    {
        return $this->query()->whereState($states);
    }

    public function whereQueue(string|array $queues): TicketBuilder
    {
        return $this->query()->whereQueue($queues);
    }

    public function whereCustomerId(string $customerId): TicketBuilder
    {
        return $this->query()->whereCustomerId($customerId);
    }
}
