<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class Ticket
{
    use HasAttributes;

    /**
     * @param  Collection<int, Article>  $articles
     * @param  Collection<int, DynamicField>  $dynamicFields
     * @param  array<string, mixed>  $attributes  raw Znuny fields without articles
     */
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
        public ?string $customerUserID,
        public ?string $customerUser,
        public ?Carbon $created,
        public ?Carbon $changed,
        public Collection $articles,
        public Collection $dynamicFields,
        public ?string $stateId = null,
        public ?string $stateType = null,
        public ?string $priorityId = null,
        public ?string $typeId = null,
        public ?string $lock = null,
        public ?string $owner = null,
        public ?string $ownerId = null,
        public ?string $responsible = null,
        public ?string $responsibleId = null,
        public ?string $service = null,
        public ?string $serviceId = null,
        public ?string $sla = null,
        public ?string $slaId = null,
        public array $attributes = [],
    ) {}

    /**
     * Accepts TicketGet rows and TicketDeepGet data (webhooks): articles as "Article" list,
     * "Articles" list or a single "Article" hash; dynamic fields as "DynamicField" list or
     * flat "DynamicField_<Name>" keys; "CustomerUser" as login or customer data hash.
     *
     * @param  string  $timezone  Znuny's OTRSTimeZone, in which it returns dates
     */
    public static function fromZnunyResponse(array $data, string $timezone = 'UTC'): self
    {
        $articles = collect(self::articleRows($data))
            ->map(fn (array $article): Article => Article::fromZnunyResponse($article, $timezone))
            ->values();

        $dynamicFields = collect(self::dynamicFieldRows($data))
            ->map(fn (array $field): DynamicField => DynamicField::fromZnunyResponse($field))
            ->values();

        $customerUser = $data['CustomerUser'] ?? null;

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
            customerUserID: $data['CustomerUserID'] ?? null,
            customerUser: is_string($customerUser) ? $customerUser : ($data['CustomerUserID'] ?? null),
            created: self::optionalDate($data['Created'] ?? null, $timezone),
            changed: self::optionalDate($data['Changed'] ?? null, $timezone),
            articles: $articles,
            dynamicFields: $dynamicFields,
            stateId: self::optionalString($data['StateID'] ?? null),
            stateType: self::optionalString($data['StateType'] ?? null),
            priorityId: self::optionalString($data['PriorityID'] ?? null),
            typeId: self::optionalString($data['TypeID'] ?? null),
            lock: self::optionalString($data['Lock'] ?? null),
            owner: self::optionalString($data['Owner'] ?? null),
            ownerId: self::optionalString($data['OwnerID'] ?? null),
            responsible: self::optionalString($data['Responsible'] ?? null),
            responsibleId: self::optionalString($data['ResponsibleID'] ?? null),
            service: self::optionalString($data['Service'] ?? null),
            serviceId: self::optionalString($data['ServiceID'] ?? null),
            sla: self::optionalString($data['SLA'] ?? null),
            slaId: self::optionalString($data['SLAID'] ?? null),
            attributes: array_diff_key($data, ['Article' => true, 'Articles' => true]),
        );
    }

    public function toArray(): array
    {
        return [
            'TicketID' => $this->id,
            'TicketNumber' => $this->ticketNumber,
            'Title' => $this->title,
            'QueueID' => $this->queueId,
            'Queue' => $this->queue,
            'State' => $this->state,
            'StateID' => $this->stateId,
            'StateType' => $this->stateType,
            'Priority' => $this->priority,
            'PriorityID' => $this->priorityId,
            'Type' => $this->type,
            'TypeID' => $this->typeId,
            'Lock' => $this->lock,
            'Owner' => $this->owner,
            'OwnerID' => $this->ownerId,
            'Responsible' => $this->responsible,
            'ResponsibleID' => $this->responsibleId,
            'Service' => $this->service,
            'ServiceID' => $this->serviceId,
            'SLA' => $this->sla,
            'SLAID' => $this->slaId,
            'CustomerID' => $this->customerId,
            'CustomerUserID' => $this->customerUserID,
            'CustomerUser' => $this->customerUser,
            'Created' => $this->created?->format('Y-m-d H:i:s'),
            'Changed' => $this->changed?->format('Y-m-d H:i:s'),
            'Article' => $this->articles->map(fn (Article $article): array => $article->toArray())->all(),
            'DynamicField' => $this->dynamicFields->map(fn (DynamicField $field): array => $field->toArray())->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function articleRows(array $data): array
    {
        foreach (['Article', 'Articles'] as $key) {
            $rows = $data[$key] ?? null;

            if (is_array($rows) && $rows !== [] && array_is_list($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        $single = $data['Article'] ?? null;

        return is_array($single) && $single !== [] && ! array_is_list($single) ? [$single] : [];
    }

    /** @return list<array{Name: string, Value: mixed}> */
    private static function dynamicFieldRows(array $data): array
    {
        $rows = $data['DynamicField'] ?? null;

        if (is_array($rows) && $rows !== [] && array_is_list($rows)) {
            return array_values(array_filter($rows, 'is_array'));
        }

        $fields = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'DynamicField_')) {
                $fields[] = ['Name' => substr($key, strlen('DynamicField_')), 'Value' => $value];
            }
        }

        return $fields;
    }
}
