<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\DynamicFields\DynamicFieldValidator;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketCreated;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
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
     * @param  array<array-key, Attachment>  $attachments
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
            'Ticket' => TicketInputMapper::mapTicket($this->ticket, $this->client->settings()->timezone),
            'Article' => TicketInputMapper::mapArticle($this->article),
        ];

        if ($this->dynamicFields !== []) {
            DynamicFieldValidator::fromConfig()->validate($this->dynamicFields);
            $payload['DynamicField'] = TicketInputMapper::mapDynamicFields(
                $this->dynamicFields,
                $this->client->settings()->timezone,
                (array) config('znuny.dynamic_fields', []),
            );
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

        try {
            // Whatever goes wrong here is dealt with below; the warning there is its record.
            $ticket = $this->client->expecting(
                fn (ZnunyException $e): bool => true,
                fn (): Ticket => $this->resource->find($ticketId, withArticles: true, withDynamicFields: true),
            );
        } catch (ZnunyException $e) {
            // The ticket exists, so failing here would make the caller create it again.
            $this->client->warn(sprintf(
                'Znuny created ticket %s, but it could not be read back (%s). save() returns it from the response of TicketCreate.',
                $ticketId,
                $e->getMessage(),
            ));

            $ticket = $this->ticketFromCreate($ticketId, $payload['Ticket'], $response);
        }

        event(new ZnunyTicketCreated(
            connection: $this->client->connection(),
            ticket: $ticket,
        ));

        return $ticket;
    }

    /**
     * The ticket as far as it is known without reading it: what Znuny returned (ID, number
     * and, with the operation's IncludeTicketData option, the stored ticket) over what was sent.
     *
     * @param  array<string, mixed>  $sent  the "Ticket" part of the request
     * @param  array<array-key, mixed>  $response
     */
    private function ticketFromCreate(string $ticketId, array $sent, array $response): Ticket
    {
        $stored = is_array($response['Ticket'] ?? null) ? $response['Ticket'] : [];

        return Ticket::fromZnunyResponse(
            array_merge(array_filter($sent, is_scalar(...)), $stored, [
                'TicketID' => $ticketId,
                'TicketNumber' => (string) ($response['TicketNumber'] ?? $stored['TicketNumber'] ?? ''),
            ]),
            $this->client->settings()->timezone,
        );
    }
}
