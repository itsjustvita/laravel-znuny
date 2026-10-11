<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use DateTimeInterface;
use Generator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\ZnunyClient;

class TicketBuilder
{
    /** @var array<string, mixed> */
    protected array $params = [];

    protected ?int $limit = null;

    protected bool $withArticles = true;

    protected bool $withDynamicFields = true;

    protected bool $withAttachments = false;

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
     * Any TicketSearch parameter, e.g. where('OwnerIDs', [3, 4]).
     */
    public function where(string $field, mixed $value): self
    {
        $this->params[$field] = $value;

        return $this;
    }

    public function whereState(string|array $states): self
    {
        return $this->where('States', (array) $states);
    }

    public function whereStateType(string|array $types): self
    {
        return $this->where('StateType', (array) $types);
    }

    public function whereQueue(string|array $queues): self
    {
        return $this->where('Queues', (array) $queues);
    }

    public function whereQueueId(string|int|array $ids): self
    {
        return $this->where('QueueIDs', array_map('strval', (array) $ids));
    }

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
        return $this->where('TicketCreateTimeNewerDate', $date->format('Y-m-d H:i:s'));
    }

    public function whereCreatedBefore(DateTimeInterface $date): self
    {
        return $this->where('TicketCreateTimeOlderDate', $date->format('Y-m-d H:i:s'));
    }

    public function whereChangedAfter(DateTimeInterface $date): self
    {
        return $this->where('TicketChangeTimeNewerDate', $date->format('Y-m-d H:i:s'));
    }

    public function whereChangedBefore(DateTimeInterface $date): self
    {
        return $this->where('TicketChangeTimeOlderDate', $date->format('Y-m-d H:i:s'));
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

    /** @return Collection<int, string> */
    public function ids(): Collection
    {
        $ids = $this->client->call('TicketSearch', $this->params)['TicketID'] ?? [];

        return collect(array_map('strval', (array) $ids))->values();
    }

    /** @return Collection<int, Ticket> */
    public function get(): Collection
    {
        $ids = $this->ids();

        return $ids->isEmpty() ? collect() : $this->fetch($ids->all());
    }

    /**
     * Fetches all matching IDs, then loads only the tickets of the requested page.
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
     * @param  array<int, string>  $ids
     * @return Collection<int, Ticket>
     */
    protected function fetch(array $ids): Collection
    {
        return $this->resource->findMany($ids, $this->withArticles, $this->withDynamicFields, $this->withAttachments);
    }
}
