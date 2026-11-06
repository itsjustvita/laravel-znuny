<?php

namespace ItsJustVita\LaravelZnuny\Data;

use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class DynamicField
{
    use HasAttributes;

    /**
     * @param  array<string, mixed>  $attributes  raw Znuny fields
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            name: (string) ($data['Name'] ?? ''),
            value: $data['Value'] ?? null,
            attributes: $data,
        );
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
