<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\ZnunyClient;

class TicketCreateBuilder
{
    /** @var array<string, mixed> */
    protected array $article = [];

    /** @var array<string, mixed> */
    protected array $dynamicFields = [];

    /**
     * @param  array<string, mixed>  $ticket
     */
    public function __construct(
        protected readonly ZnunyClient $client,
        protected readonly TicketResource $resource,
        protected array $ticket,
    ) {}

    /**
     * @param  array<string, mixed>  $article
     */
    public function withArticle(array $article): self
    {
        $this->article = $article;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function withDynamicFields(array $fields): self
    {
        $this->dynamicFields = $fields;

        return $this;
    }

    public function save(): Ticket
    {
        if ($this->article === []) {
            throw new InvalidArgumentException(
                'TicketCreate requires an Article. Call withArticle() before save().'
            );
        }

        $payload = [
            'Ticket'  => TicketInputMapper::mapTicket($this->ticket),
            'Article' => TicketInputMapper::mapArticle($this->article),
        ];

        if ($this->dynamicFields !== []) {
            $payload['DynamicField'] = TicketInputMapper::mapDynamicFields($this->dynamicFields);
        }

        $response = $this->client->request('TicketCreate', $payload);

        $ticketId = (string) ($response['TicketID'] ?? '');
        if ($ticketId === '') {
            throw new InvalidArgumentException('TicketCreate succeeded but no TicketID was returned.');
        }

        return $this->resource->find($ticketId, withArticles: true, withDynamicFields: true);
    }
}
