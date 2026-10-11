<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Queue;
use ItsJustVita\LaravelZnuny\Exceptions\QueueNotFoundException;

/**
 * Needs the custom operations QueueList and QueueGet in the web service.
 */
class QueueResource extends Resource
{
    /** @return Collection<int, Queue> */
    public function all(): Collection
    {
        return $this->remember('queues', function () {
            $response = $this->client->call('QueueList');

            return collect($response['Queue'] ?? [])
                ->map(fn (array $row) => Queue::fromZnunyResponse($row))
                ->values();
        });
    }

    public function find(string $queueId): Queue
    {
        $payload = ['QueueID' => $queueId];
        $response = $this->client->call('QueueGet', $payload);

        if (empty($response['Queue'][0])) {
            throw (new QueueNotFoundException("Queue {$queueId} not found"))
                ->setOperation('QueueGet')
                ->setConnection($this->client->connection())
                ->setRequestPayload($payload);
        }

        return Queue::fromZnunyResponse($response['Queue'][0]);
    }

    public function findByName(string $name): Queue
    {
        $queue = $this->all()->first(fn (Queue $q) => $q->name === $name);

        if (! $queue) {
            throw (new QueueNotFoundException("Queue named '{$name}' not found"))
                ->setOperation('QueueList')
                ->setConnection($this->client->connection());
        }

        return $queue;
    }
}
