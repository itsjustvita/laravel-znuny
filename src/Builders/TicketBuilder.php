<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySearchLimitExceededException;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\ZnunyClient;

class TicketBuilder
{
    /** Znuny's default for GenericInterface::Operation::TicketSearch###SearchLimit. */
    private const ZNUNY_SEARCH_LIMIT = 500;

    /** @var array<string, mixed> */
    protected array $params = [];

    protected ?int $limit = null;

    protected bool $withArticles = true;

    protected bool $withDynamicFields = true;

    protected bool $withAttachments = false;

    /** @var array<string, mixed> raw TicketGet parameters for get(), paginate() and lazy() */
    protected array $options = [];

    public function __construct(
        protected readonly ZnunyClient $client,
        protected readonly TicketResource $resource,
    ) {}

    public function withArticles(bool $value = true): self
    {
        $this->withArticles = $value;

        return $this;
    }

    public function withDynamicFields(bool $value = true): self
    {
        $this->withDynamicFields = $value;

        return $this;
    }

    public function withAttachments(bool $value = true): self
    {
        $this->withAttachments = $value;

        return $this;
    }

    public function withoutArticles(): self
    {
        return $this->withArticles(false);
    }

    public function withoutDynamicFields(): self
    {
        return $this->withDynamicFields(false);
    }

    /**
     * Raw TicketGet parameters for get(), paginate() and lazy(), e.g. ['ArticleLimit' => 5].
     * Later calls override earlier keys.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Any TicketSearch parameter, e.g. where('OwnerIDs', [3, 4]).
     */
    public function where(string $field, mixed $value): self
    {
        $this->params[$field] = $value;

        return $this;
    }

    /**
     * @param  string|list<string>  $states
     */
    public function whereState(string|array $states): self
    {
        return $this->where('States', (array) $states);
    }

    /**
     * @param  string|list<string>  $types
     */
    public function whereStateType(string|array $types): self
    {
        return $this->where('StateType', (array) $types);
    }

    /**
     * @param  string|list<string>  $queues
     */
    public function whereQueue(string|array $queues): self
    {
        return $this->where('Queues', (array) $queues);
    }

    /**
     * @param  string|int|list<string|int>  $ids
     */
    public function whereQueueId(string|int|array $ids): self
    {
        return $this->where('QueueIDs', self::idList($ids));
    }

    /**
     * Znuny's ticket number search; "*" is a wildcard.
     *
     * @param  string|list<string>  $numbers
     */
    public function whereTicketNumber(string|array $numbers): self
    {
        return $this->where('TicketNumber', (array) $numbers);
    }

    /**
     * Znuny's title search; "*" is a wildcard.
     *
     * @param  string|list<string>  $titles
     */
    public function whereTitle(string|array $titles): self
    {
        return $this->where('Title', (array) $titles);
    }

    /**
     * @param  string|list<string>  $types
     */
    public function whereType(string|array $types): self
    {
        return $this->where('Types', (array) $types);
    }

    /**
     * @param  string|int|list<string|int>  $ids
     */
    public function whereTypeId(string|int|array $ids): self
    {
        return $this->where('TypeIDs', self::idList($ids));
    }

    /**
     * @param  string|int|list<string|int>  $ids
     */
    public function whereStateId(string|int|array $ids): self
    {
        return $this->where('StateIDs', self::idList($ids));
    }

    /**
     * @param  string|int|list<string|int>  $ids
     */
    public function wherePriorityId(string|int|array $ids): self
    {
        return $this->where('PriorityIDs', self::idList($ids));
    }

    /**
     * @param  string|int|list<string|int>  $ids
     */
    public function whereOwnerId(string|int|array $ids): self
    {
        return $this->where('OwnerIDs', self::idList($ids));
    }

    /**
     * @param  string|int|list<string|int>  $ids
     */
    public function whereResponsibleId(string|int|array $ids): self
    {
        return $this->where('ResponsibleIDs', self::idList($ids));
    }

    /**
     * @param  string|list<string>  $logins
     */
    public function whereCustomerUser(string|array $logins): self
    {
        return $this->where('CustomerUserLogin', (array) $logins);
    }

    /**
     * @param  string|list<string>  $locks  "lock" or "unlock"
     */
    public function whereLock(string|array $locks): self
    {
        return $this->where('Locks', (array) $locks);
    }

    /**
     * @param  string|list<string>  $services
     */
    public function whereService(string|array $services): self
    {
        return $this->where('Services', (array) $services);
    }

    /**
     * @param  string|list<string>  $slas
     */
    public function whereSla(string|array $slas): self
    {
        return $this->where('SLAs', (array) $slas);
    }

    /**
     * Searches subject, body, From, To and Cc of the articles.
     */
    public function whereFullText(string $text): self
    {
        return $this->where('Fulltext', $text);
    }

    /**
     * Include archived tickets (only relevant when Znuny's archive system is on).
     */
    public function includeArchived(): self
    {
        return $this->where('SearchInArchive', 'AllTickets');
    }

    public function onlyArchived(): self
    {
        return $this->where('SearchInArchive', 'ArchivedTickets');
    }

    /**
     * @param  string|list<string>  $priorities
     */
    public function wherePriority(string|array $priorities): self
    {
        return $this->where('Priorities', (array) $priorities);
    }

    public function whereCustomerId(string $customerId): self
    {
        return $this->where('CustomerID', $customerId);
    }

    public function whereCreatedAfter(DateTimeInterface $date): self
    {
        return $this->where('TicketCreateTimeNewerDate', $this->znunyTime($date));
    }

    public function whereCreatedBefore(DateTimeInterface $date): self
    {
        return $this->where('TicketCreateTimeOlderDate', $this->znunyTime($date));
    }

    public function whereClosedAfter(DateTimeInterface $date): self
    {
        return $this->where('TicketCloseTimeNewerDate', $this->znunyTime($date));
    }

    public function whereClosedBefore(DateTimeInterface $date): self
    {
        return $this->where('TicketCloseTimeOlderDate', $this->znunyTime($date));
    }

    public function wherePendingAfter(DateTimeInterface $date): self
    {
        return $this->where('TicketPendingTimeNewerDate', $this->znunyTime($date));
    }

    public function wherePendingBefore(DateTimeInterface $date): self
    {
        return $this->where('TicketPendingTimeOlderDate', $this->znunyTime($date));
    }

    /**
     * Tickets with any change (history entry, e.g. a new article) after the date.
     */
    public function whereChangedAfter(DateTimeInterface $date): self
    {
        return $this->where('TicketChangeTimeNewerDate', $this->znunyTime($date));
    }

    /**
     * Same as whereLastChangedBefore().
     */
    public function whereChangedBefore(DateTimeInterface $date): self
    {
        return $this->whereLastChangedBefore($date);
    }

    /**
     * Tickets whose change time ($ticket->changed) is after the date.
     */
    public function whereLastChangedAfter(DateTimeInterface $date): self
    {
        return $this->where('TicketLastChangeTimeNewerDate', $this->znunyTime($date));
    }

    /**
     * Tickets whose change time ($ticket->changed) is before the date.
     */
    public function whereLastChangedBefore(DateTimeInterface $date): self
    {
        return $this->where('TicketLastChangeTimeOlderDate', $this->znunyTime($date));
    }

    /**
     * A dynamic field condition, e.g. whereDynamicField('OrderId', 'ORD-*', 'Like').
     * Operators: Equals, Like, GreaterThan, GreaterThanEquals, SmallerThan, SmallerThanEquals.
     * Needs a TicketSearch route with a request body, e.g. 'POST /TicketSearch'.
     */
    public function whereDynamicField(string $name, mixed $value, string $operator = 'Equals'): self
    {
        return $this->where('DynamicField_'.$name, [$operator => $value]);
    }

    /**
     * @param  string  $direction  "Up"/"asc" or "Down"/"desc"
     */
    public function orderBy(string $field, string $direction = 'Up'): self
    {
        $this->params['SortBy'] = $field;
        $this->params['OrderBy'] = in_array(strtolower($direction), ['down', 'desc'], true) ? 'Down' : 'Up';

        return $this;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'Down');
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        $this->params['Limit'] = $limit;

        return $this;
    }

    /**
     * The IDs of the matching tickets. Znuny cuts a search without a limit off at its SearchLimit
     * (500 by default) and does not say so. With a search_limit on the connection, a search
     * without limit() is complete or fails.
     *
     * @return Collection<int, string>
     *
     * @throws ZnunySearchLimitExceededException when more tickets match than the connection's search_limit
     */
    public function ids(): Collection
    {
        $params = $this->params;
        // Znuny treats 0 and '' like a missing limit.
        $limited = ! empty($params['Limit']);
        $searchLimit = $limited ? null : $this->client->settings()->searchLimit;

        if ($searchLimit !== null) {
            // One more than allowed shows whether Znuny has more tickets than are returned.
            $params['Limit'] = $searchLimit + 1;
        }

        $ids = array_map('strval', (array) ($this->client->call('TicketSearch', $params)['TicketID'] ?? []));

        if (! $limited && $searchLimit === null && count($ids) === self::ZNUNY_SEARCH_LIMIT) {
            $this->client->warn(sprintf(
                'TicketSearch returned exactly %d tickets, which is where Znuny cuts a search off by default. '
                .'Set "search_limit" (ZNUNY_SEARCH_LIMIT) to get an exception instead of a partial result, or call limit().',
                self::ZNUNY_SEARCH_LIMIT,
            ));
        }

        if ($searchLimit !== null && count($ids) > $searchLimit) {
            throw (new ZnunySearchLimitExceededException(sprintf(
                'The search matches more than %d tickets. Narrow it down, call limit() to accept only the first tickets, '
                .'or raise the "search_limit" of the connection.',
                $searchLimit,
            )))
                ->setOperation('TicketSearch')
                ->setConnection($this->client->connection())
                ->setRequestPayload($params);
        }

        return collect($ids)->values();
    }

    /**
     * The number of matching tickets; the search limit applies as for ids().
     */
    public function count(): int
    {
        return $this->ids()->count();
    }

    /** @return Collection<int, Ticket> */
    public function get(): Collection
    {
        $ids = $this->ids();

        return $ids->isEmpty() ? collect() : $this->fetch($ids->all());
    }

    /**
     * Fetches all matching IDs, then loads only the tickets of the requested page.
     *
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $ids = $this->ids();
        $total = $ids->count();

        if ($total === 0) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $pageIds = $ids->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return new LengthAwarePaginator(
            items: $this->fetch($pageIds)->all(),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
        );
    }

    /** @return Generator<int, Ticket> */
    public function lazy(int $chunkSize = 100): Generator
    {
        foreach (array_chunk($this->ids()->all(), max(1, $chunkSize)) as $chunk) {
            foreach ($this->fetch($chunk) as $ticket) {
                yield $ticket;
            }
        }
    }

    /**
     * Znuny reads search dates in its OTRSTimeZone.
     */
    protected function znunyTime(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone($this->client->settings()->timezone))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, Ticket>
     */
    protected function fetch(array $ids): Collection
    {
        return $this->resource->findMany($ids, $this->withArticles, $this->withDynamicFields, $this->withAttachments, $this->options);
    }

    /**
     * IDs as strings, the way Znuny returns them.
     *
     * @param  string|int|list<string|int>  $ids
     * @return list<string>
     */
    private static function idList(string|int|array $ids): array
    {
        return array_map('strval', (array) $ids);
    }
}
