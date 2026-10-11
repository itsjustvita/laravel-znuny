<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Builders\TicketBuilder;
use ItsJustVita\LaravelZnuny\Builders\TicketCreateBuilder;
use ItsJustVita\LaravelZnuny\Builders\TicketInputMapper;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\HistoryEntry;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\DynamicFields\DynamicFieldValidator;
use ItsJustVita\LaravelZnuny\Events\ZnunyArticleAdded;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketUpdated;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;

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

    public function find(string $ticketId, bool $withArticles = false, bool $withDynamicFields = false, bool $withAttachments = false): Ticket
    {
        $payload = ['TicketID' => $ticketId] + self::fetchOptions($withArticles, $withDynamicFields, $withAttachments);
        $data = $this->client->call('TicketGet', $payload)['Ticket'][0] ?? null;

        if (! is_array($data)) {
            throw (new TicketNotFoundException("Znuny returned no ticket data for TicketID {$ticketId}"))
                ->setOperation('TicketGet')
                ->setConnection($this->client->connection())
                ->setRequestPayload($payload);
        }

        return Ticket::fromZnunyResponse($data);
    }

    /**
     * Fetch several tickets with batched TicketGet calls. The result keeps the order of
     * $ticketIds; tickets Znuny does not return or denies access to are skipped.
     *
     * @param  array<int, string|int>  $ticketIds
     * @return Collection<int, Ticket>
     */
    public function findMany(array $ticketIds, bool $withArticles = false, bool $withDynamicFields = false, bool $withAttachments = false): Collection
    {
        $ids = array_values(array_unique(array_map('strval', $ticketIds)));
        $options = self::fetchOptions($withArticles, $withDynamicFields, $withAttachments);
        $found = [];

        foreach (array_chunk($ids, $this->client->settings()->batchSize) as $chunk) {
            foreach ($this->fetchChunk($chunk, $options) as $ticket) {
                $found[$ticket->id] = $ticket;
            }
        }

        return collect($ids)
            ->filter(fn (string $id): bool => isset($found[$id]))
            ->map(fn (string $id): Ticket => $found[$id])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $ticket  ticket attributes (camelCase or Znuny keys)
     * @param  array<string, mixed>  $dynamicFields  name => value
     */
    public function update(string $ticketId, array $ticket = [], array $dynamicFields = []): void
    {
        if ($ticket === [] && $dynamicFields === []) {
            throw new InvalidArgumentException('Nothing to update: pass ticket attributes and/or dynamic fields.');
        }

        $payload = ['TicketID' => $ticketId];

        if ($ticket !== []) {
            $payload['Ticket'] = TicketInputMapper::mapTicket($ticket);
        }

        if ($dynamicFields !== []) {
            DynamicFieldValidator::fromConfig()->validate($dynamicFields);
            $payload['DynamicField'] = TicketInputMapper::mapDynamicFields($dynamicFields);
        }

        $this->client->call('TicketUpdate', $payload);

        event(new ZnunyTicketUpdated(
            connection: $this->client->connection(),
            ticketId: $ticketId,
            changes: $ticket,
            dynamicFields: $dynamicFields,
        ));
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  list<Attachment>  $attachments
     * @return string|null the ArticleID of the new article
     */
    public function addArticle(string $ticketId, array $article, array $attachments = []): ?string
    {
        $payload = [
            'TicketID' => $ticketId,
            'Article' => TicketInputMapper::mapArticle($article),
        ];

        if ($attachments !== []) {
            $payload['Attachment'] = TicketInputMapper::mapAttachments($attachments);
        }

        $response = $this->client->call('TicketUpdate', $payload);
        $articleId = (string) ($response['ArticleID'] ?? '');
        $articleId = $articleId !== '' ? $articleId : null;

        event(new ZnunyArticleAdded(
            connection: $this->client->connection(),
            ticketId: $ticketId,
            article: $article,
            articleId: $articleId,
        ));

        return $articleId;
    }

    public function close(string $ticketId, string $state = 'closed successful'): void
    {
        $this->update($ticketId, ['state' => $state]);
    }

    /**
     * @return Collection<int, HistoryEntry>
     */
    public function history(string $ticketId): Collection
    {
        $entries = $this->client->call('TicketHistoryGet', ['TicketID' => $ticketId])['TicketHistory'][0]['History'] ?? [];

        return collect(is_array($entries) ? $entries : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): HistoryEntry => HistoryEntry::fromZnunyResponse($entry))
            ->values();
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

    /**
     * TicketGet flags for the requested related data.
     *
     * @return array<string, int>
     */
    public static function fetchOptions(bool $withArticles, bool $withDynamicFields, bool $withAttachments): array
    {
        $options = [];

        if ($withArticles || $withAttachments) {
            $options['AllArticles'] = 1;
        }

        if ($withAttachments) {
            $options['Attachments'] = 1;
        }

        if ($withDynamicFields) {
            $options['DynamicFields'] = 1;
        }

        return $options;
    }

    /**
     * Znuny rejects a whole batch with AccessDenied when one ticket is missing or not
     * accessible, so a rejected batch is retried ticket by ticket.
     *
     * @param  list<string>  $ids
     * @param  array<string, int>  $options
     * @return list<Ticket>
     */
    protected function fetchChunk(array $ids, array $options): array
    {
        try {
            $rows = $this->client->call('TicketGet', ['TicketID' => $ids] + $options)['Ticket'] ?? [];
        } catch (ZnunyAccessDeniedException|TicketNotFoundException) {
            if (count($ids) === 1) {
                return [];
            }

            return array_merge(...array_map(fn (string $id): array => $this->fetchChunk([$id], $options), $ids));
        }

        $tickets = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['TicketID'])) {
                $tickets[] = Ticket::fromZnunyResponse($row);
            }
        }

        return $tickets;
    }
}
