<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\CarbonImmutable;
use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

/**
 * Time units an agent booked on a ticket, as TimeAccountingGet returns them.
 */
final readonly class TimeAccountingEntry
{
    use HasAttributes;

    /**
     * @param  string|null  $queue  the queue the ticket was in when the time was booked
     * @param  array<string, mixed>  $attributes  raw Znuny fields
     */
    public function __construct(
        public string $ticketNumber,
        public string $ticketTitle,
        public ?string $queue,
        public ?string $customerId,
        public float $timeUnit,
        public ?CarbonImmutable $created,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $timezone  Znuny's OTRSTimeZone, in which it returns dates
     */
    public static function fromZnunyResponse(array $data, string $timezone = 'UTC'): self
    {
        $timeUnit = $data['TimeUnit'] ?? null;

        return new self(
            ticketNumber: (string) (self::optionalString($data['TicketNumber'] ?? null) ?? ''),
            ticketTitle: (string) (self::optionalString($data['TicketTitle'] ?? null) ?? ''),
            queue: self::optionalString($data['Queue'] ?? null),
            customerId: self::optionalString($data['TicketCustomerID'] ?? null),
            timeUnit: is_numeric($timeUnit) ? (float) $timeUnit : 0.0,
            created: self::optionalDate($data['Created'] ?? null, $timezone),
            attributes: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'TicketNumber' => $this->ticketNumber,
            'TicketTitle' => $this->ticketTitle,
            'TicketCustomerID' => $this->customerId,
            'Queue' => $this->queue,
            'TimeUnit' => $this->timeUnit,
            'Created' => $this->created?->format('Y-m-d H:i:s'),
        ];
    }
}
