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

    public function __construct(
        protected readonly ZnunyClient $client,
        protected readonly TicketResource $resource,
    ) {}

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

    /**
     * Implemented in Task 5.2.
     * @return Collection<int, Ticket>
     */
    public function get(): Collection
    {
        throw new \BadMethodCallException('get() is implemented in Task 5.2');
    }

    /** Implemented in Task 5.3. */
    public function paginate(int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        throw new \BadMethodCallException('paginate() is implemented in Task 5.3');
    }

    /** Implemented in Task 5.4. */
    public function lazy(int $chunkSize = 100): \Generator
    {
        throw new \BadMethodCallException('lazy() is implemented in Task 5.4');
    }
}
