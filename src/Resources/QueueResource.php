<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Queue;
use ItsJustVita\LaravelZnuny\Exceptions\QueueNotFoundException;

class QueueResource extends Resource
{
    /** @return Collection<int, Queue> */
    public function all(): Collection
    {
        $response = $this->client->request('QueueList', []);

        return collect($response['Queue'] ?? [])
            ->map(fn (array $row) => Queue::fromZnunyResponse($row))
            ->values();
    }

    public function find(string $queueId): Queue
    {
        $response = $this->client->request(
            'QueueGet/'.rawurlencode($queueId),
            ['QueueID' => $queueId],
        );

        if (empty($response['Queue'][0])) {
            $ex = new QueueNotFoundException("Queue {$queueId} not found");
            $ex->setOperation('QueueGet')
               ->setConnection($this->client->connection())
               ->setRequestPayload(['QueueID' => $queueId]);

            throw $ex;
        }

        return Queue::fromZnunyResponse($response['Queue'][0]);
    }

    public function findByName(string $name): Queue
    {
        $queue = $this->all()->first(fn (Queue $q) => $q->name === $name);

        if (! $queue) {
            $ex = new QueueNotFoundException("Queue named '{$name}' not found");
            $ex->setOperation('QueueList')
               ->setConnection($this->client->connection());

            throw $ex;
        }

        return $queue;
    }

    public function resolve(string $type, bool $businessCustomer): string
    {
        $segment = $businessCustomer ? 'business' : 'private';

        $configured = config("znuny.queues.resolution.{$segment}.{$type}");

        return $configured !== null
            ? (string) $configured
            : (string) config('znuny.queues.default');
    }
}
