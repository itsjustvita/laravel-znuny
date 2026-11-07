<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use BadMethodCallException;
use DateTimeInterface;
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

/**
 * @mixin TicketBuilder
 */
class TicketResource extends Resource
{
    /** A rejected batch of up to this many tickets is fetched ticket by ticket. */
    private const SINGLE_FETCH_THRESHOLD = 8;

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

    /**
     * @param  array<string, mixed>  $options  raw TicketGet parameters, e.g. ['Extended' => 1]; they override the flags
     */
    public function find(string $ticketId, bool $withArticles = false, bool $withDynamicFields = false, bool $withAttachments = false, array $options = []): Ticket
    {
        $payload = ['TicketID' => $ticketId] + array_merge(self::fetchOptions($withArticles, $withDynamicFields, $withAttachments), $options);
        $data = $this->client->call('TicketGet', $payload)['Ticket'][0] ?? null;

        if (! is_array($data)) {
            throw (new TicketNotFoundException("Znuny returned no ticket data for TicketID {$ticketId}"))
                ->setOperation('TicketGet')
                ->setConnection($this->client->connection())
                ->setRequestPayload($payload);
        }

        return Ticket::fromZnunyResponse($data, $this->client->settings()->timezone);
    }

    /**
     * Fetch several tickets with batched TicketGet calls. The result keeps the order of
     * $ticketIds; tickets Znuny does not return or denies access to are skipped.
     *
     * @param  array<int, string|int>  $ticketIds
     * @param  array<string, mixed>  $options  raw TicketGet parameters; they override the flags
     * @return Collection<int, Ticket>
     */
    public function findMany(array $ticketIds, bool $withArticles = false, bool $withDynamicFields = false, bool $withAttachments = false, array $options = []): Collection
    {
        $ids = array_values(array_unique(array_map('strval', $ticketIds)));
        $options = array_merge(self::fetchOptions($withArticles, $withDynamicFields, $withAttachments), $options);
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
     * Find a ticket by its number, the way customers and e-mail subjects refer to it.
     *
     * @param  array<string, mixed>  $options  raw TicketGet parameters
     *
     * @throws TicketNotFoundException when no ticket with this number exists or the user cannot read it
     */
    public function findByNumber(string $ticketNumber, bool $withArticles = false, bool $withDynamicFields = false, bool $withAttachments = false, array $options = []): Ticket
    {
        $payload = ['TicketNumber' => $ticketNumber, 'Limit' => 1];
        $notFound = fn (): TicketNotFoundException => (new TicketNotFoundException("Znuny has no ticket with the number [{$ticketNumber}] that this user can read."))
            ->setOperation('TicketSearch')
            ->setConnection($this->client->connection())
            ->setRequestPayload($payload);

        // Znuny's search treats "*" and the database's "%" as wildcards and ignores an empty
        // number. A number that customers entered must never return somebody else's ticket.
        if ($ticketNumber === '' || strpbrk($ticketNumber, '*%') !== false) {
            throw $notFound();
        }

        $ids = (array) ($this->client->call('TicketSearch', $payload)['TicketID'] ?? []);

        if ($ids === []) {
            throw $notFound();
        }

        $ticket = $this->find((string) reset($ids), $withArticles, $withDynamicFields, $withAttachments, $options);

        if ($ticket->ticketNumber !== $ticketNumber) {
            throw $notFound();
        }

        return $ticket;
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
            $payload['Ticket'] = TicketInputMapper::mapTicket($ticket, $this->client->settings()->timezone);
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

    public function lock(string $ticketId): void
    {
        $this->update($ticketId, ['lock' => 'lock']);
    }

    public function unlock(string $ticketId): void
    {
        $this->update($ticketId, ['lock' => 'unlock']);
    }

    /**
     * @param  string|int  $owner  the agent's login, or an int for the agent's ID
     */
    public function assign(string $ticketId, string|int $owner): void
    {
        $this->update($ticketId, is_int($owner) ? ['ownerId' => $owner] : ['owner' => $owner]);
    }

    /**
     * @param  string|int  $queue  the queue's name, or an int for the queue's ID
     */
    public function move(string $ticketId, string|int $queue): void
    {
        $this->update($ticketId, is_int($queue) ? ['queueId' => $queue] : ['queue' => $queue]);
    }

    /**
     * Set a pending state and its time. Znuny accepts a pending time only together with a
     * pending state; it is read to the minute in Znuny's time zone.
     */
    public function setPending(string $ticketId, DateTimeInterface $until, string $state = 'pending reminder'): void
    {
        $this->update($ticketId, ['state' => $state, 'pendingTime' => $until]);
    }

    /**
     * @return Collection<int, HistoryEntry>
     */
    public function history(string $ticketId): Collection
    {
        $entries = $this->client->call('TicketHistoryGet', ['TicketID' => $ticketId])['TicketHistory'][0]['History'] ?? [];

        return collect(is_array($entries) ? $entries : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): HistoryEntry => HistoryEntry::fromZnunyResponse($entry, $this->client->settings()->timezone))
            ->values();
    }

    public function query(): TicketBuilder
    {
        return new TicketBuilder($this->client, $this);
    }

    /**
     * Search methods can be called on the resource directly: Znuny::tickets()->whereTitle(...).
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $builder = $this->query();

        if (! is_callable([$builder, $method])) {
            throw new BadMethodCallException(sprintf('Method %s::%s() does not exist.', static::class, $method));
        }

        return $builder->{$method}(...$arguments);
    }

    public function where(string $field, mixed $value): TicketBuilder
    {
        return $this->query()->where($field, $value);
    }

    /**
     * @param  string|list<string>  $states
     */
    public function whereState(string|array $states): TicketBuilder
    {
        return $this->query()->whereState($states);
    }

    /**
     * @param  string|list<string>  $queues
     */
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
     * accessible. A rejected batch is halved, which isolates a few missing tickets quickly;
     * small batches are fetched ticket by ticket, which bounds the cost when many are missing.
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

            $size = count($ids) <= self::SINGLE_FETCH_THRESHOLD ? 1 : max(1, intdiv(count($ids) + 1, 2));

            return array_merge(...array_map(
                fn (array $chunk): array => $this->fetchChunk($chunk, $options),
                array_chunk($ids, $size),
            ));
        }

        $tickets = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['TicketID'])) {
                $tickets[] = Ticket::fromZnunyResponse($row, $this->client->settings()->timezone);
            }
        }

        return $tickets;
    }
}
