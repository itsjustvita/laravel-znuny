<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use Carbon\Carbon;
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

    public function withoutArticles(): self
    {
        return $this->withArticles(false);
    }

    public function withoutDynamicFields(): self
    {
        return $this->withDynamicFields(false);
    }

    public function where(string $field, mixed $value): self
    {
        $this->params[$field] = $value;

        return $this;
    }

    public function whereState(string|array $states): self
    {
        $this->params['States'] = (array) $states;

        return $this;
    }

    public function whereStateType(string|array $types): self
    {
        $this->params['StateType'] = (array) $types;

        return $this;
    }

    public function whereQueue(string|array $queues): self
    {
        $this->params['Queues'] = (array) $queues;

        return $this;
    }

    public function whereQueueId(string|int|array $ids): self
    {
        $this->params['QueueIDs'] = array_map('strval', (array) $ids);

        return $this;
    }

    public function wherePriority(string|array $priorities): self
    {
        $this->params['Priorities'] = (array) $priorities;

        return $this;
    }

    public function whereCustomerId(string $customerId): self
    {
        $this->params['CustomerID'] = $customerId;

        return $this;
    }

    public function whereCreatedAfter(Carbon $date): self
    {
        $this->params['TicketCreateTimeNewerDate'] = $date->format('Y-m-d H:i:s');

        return $this;
    }

    public function whereCreatedBefore(Carbon $date): self
    {
        $this->params['TicketCreateTimeOlderDate'] = $date->format('Y-m-d H:i:s');

        return $this;
    }

    public function orderBy(string $field, string $direction = 'Up'): self
    {
        $this->params['SortBy'] = $field;
        $this->params['OrderBy'] = strtolower($direction) === 'down' ? 'Down' : 'Up';

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
        $response = $this->client->request('TicketSearch', $this->params);
        $ids = $response['TicketID'] ?? [];

        return collect(array_map('strval', (array) $ids))->values();
    }

    /** @return Collection<int, Ticket> */
    public function get(): Collection
    {
        $ids = $this->ids();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->fetchByIds($ids->all());
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, Ticket>
     */
    protected function fetchByIds(array $ids): Collection
    {
        $chunks = array_chunk($ids, $this->poolSize());
        $results = collect();

        foreach ($chunks as $chunk) {
            $params = ['TicketID' => $chunk];

            if ($this->withArticles) {
                $params['AllArticles'] = 1;
            }

            if ($this->withDynamicFields) {
                $params['DynamicFields'] = 1;
            }

            $response = $this->client->request('TicketGet', $params);

            foreach (($response['Ticket'] ?? []) as $ticketData) {
                $results->push(Ticket::fromZnunyResponse($ticketData));
            }
        }

        return $results->values();
    }

    protected function poolSize(): int
    {
        return max(1, (int) config('znuny.http.pool_size', 25));
    }

    public function paginate(int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $ids = $this->ids();
        $total = $ids->count();

        if ($total === 0) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $pageIds = $ids->slice(($page - 1) * $perPage, $perPage)->values()->all();
        $items = $this->fetchByIds($pageIds);

        return new LengthAwarePaginator(
            items: $items->all(),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
        );
    }

    public function lazy(int $chunkSize = 100): \Generator
    {
        $ids = $this->ids();

        foreach (array_chunk($ids->all(), max(1, $chunkSize)) as $chunk) {
            foreach ($this->fetchByIds($chunk) as $ticket) {
                yield $ticket;
            }
        }
    }
}
