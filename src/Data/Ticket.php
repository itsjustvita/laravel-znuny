<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;

final readonly class Ticket
{
    public function __construct(
        public string $id,
        public string $ticketNumber,
        public string $title,
        public string $queueId,
        public string $queue,
        public string $state,
        public string $priority,
        public ?string $type,
        public ?string $customerId,
        public ?string $customerUser,
        public Carbon $created,
        public ?Carbon $changed,
        /** @var Collection<int, Article> */
        public Collection $articles,
        /** @var Collection<int, DynamicField> */
        public Collection $dynamicFields,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        $articles = collect($data['Article'] ?? [])
            ->map(fn (array $article) => Article::fromZnunyResponse($article))
            ->values();

        $dynamicFields = collect($data['DynamicField'] ?? [])
            ->map(fn (array $field) => DynamicField::fromZnunyResponse($field))
            ->values();

        return new self(
            id: (string) ($data['TicketID'] ?? ''),
            ticketNumber: (string) ($data['TicketNumber'] ?? ''),
            title: (string) ($data['Title'] ?? ''),
            queueId: (string) ($data['QueueID'] ?? ''),
            queue: (string) ($data['Queue'] ?? ''),
            state: (string) ($data['State'] ?? ''),
            priority: (string) ($data['Priority'] ?? ''),
            type: $data['Type'] ?? null,
            customerId: $data['CustomerID'] ?? null,
            customerUser: $data['CustomerUserID'] ?? null,
            created: Carbon::parse($data['Created'] ?? 'now'),
            changed: isset($data['Changed']) ? Carbon::parse($data['Changed']) : null,
            articles: $articles,
            dynamicFields: $dynamicFields,
        );
    }

    public function toArray(): array
    {
        return [
            'TicketID'       => $this->id,
            'TicketNumber'   => $this->ticketNumber,
            'Title'          => $this->title,
            'QueueID'        => $this->queueId,
            'Queue'          => $this->queue,
            'State'          => $this->state,
            'Priority'       => $this->priority,
            'Type'           => $this->type,
            'CustomerID'     => $this->customerId,
            'CustomerUserID' => $this->customerUser,
            'Created'        => $this->created->format('Y-m-d H:i:s'),
            'Changed'        => $this->changed?->format('Y-m-d H:i:s'),
            'Article'        => $this->articles->map(fn (Article $a) => $a->toArray())->all(),
            'DynamicField'   => $this->dynamicFields->map(fn (DynamicField $f) => $f->toArray())->all(),
        ];
    }
}
