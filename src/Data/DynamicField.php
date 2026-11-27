<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\CarbonImmutable;
use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class DynamicField
{
    use HasAttributes;

    /**
     * @param  array<string, mixed>  $attributes  raw Znuny fields
     * @param  string  $timezone  Znuny's OTRSTimeZone, in which it returns the dates of date fields
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public array $attributes = [],
        public string $timezone = 'UTC',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $timezone  Znuny's OTRSTimeZone
     */
    public static function fromZnunyResponse(array $data, string $timezone = 'UTC'): self
    {
        return new self(
            name: (string) ($data['Name'] ?? ''),
            value: $data['Value'] ?? null,
            attributes: $data,
            timezone: $timezone,
        );
    }

    /**
     * The value of a "Date" or "Date / Time" field as a date. Znuny returns both as
     * "Y-m-d H:i:s" in its own time zone. Null for an empty value and for any value that is
     * not a date in Znuny's format.
     */
    public function date(): ?CarbonImmutable
    {
        if (! is_string($this->value) || preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $this->value) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($this->value, $this->timezone);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'Name' => $this->name,
            'Value' => $this->value,
        ];
    }
}
