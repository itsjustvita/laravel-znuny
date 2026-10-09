<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\DynamicFields\DynamicFieldValidator;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketCreated;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\ZnunyClient;

class TicketCreateBuilder
{
    /** @var array<string, mixed> */
    protected array $article = [];

    /** @var array<string, mixed> */
    protected array $dynamicFields = [];

    /** @var list<Attachment> */
    protected array $attachments = [];

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

    /**
     * @param  list<Attachment>  $attachments
     */
    public function withAttachments(array $attachments): self
    {
        $this->attachments = array_values($attachments);

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
            'Ticket' => TicketInputMapper::mapTicket($this->ticket),
            'Article' => TicketInputMapper::mapArticle($this->article),
        ];

        if ($this->dynamicFields !== []) {
            DynamicFieldValidator::fromConfig()->validate($this->dynamicFields);
            $payload['DynamicField'] = TicketInputMapper::mapDynamicFields($this->dynamicFields);
        }

        if ($this->attachments !== []) {
            $payload['Attachment'] = TicketInputMapper::mapAttachments($this->attachments);
        }

        $response = $this->client->call('TicketCreate', $payload);
        $ticketId = (string) ($response['TicketID'] ?? '');

        if ($ticketId === '') {
            throw (new ZnunyServerException('TicketCreate succeeded but Znuny returned no TicketID.'))
                ->setOperation('TicketCreate')
                ->setConnection($this->client->connection())
                ->setResponseBody($response);
        }

        $ticket = $this->resource->find($ticketId, withArticles: true, withDynamicFields: true);

        event(new ZnunyTicketCreated(
            connection: $this->client->connection(),
            ticket: $ticket,
        ));

        return $ticket;
    }
}
