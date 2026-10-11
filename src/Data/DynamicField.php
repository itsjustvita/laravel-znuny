<?php

namespace ItsJustVita\LaravelZnuny\Data;

use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class DynamicField
{
    use HasAttributes;

    public function __construct(
        public string $name,
        public mixed $value,
        public array $attributes = [],
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            name: (string) ($data['Name'] ?? ''),
            value: $data['Value'] ?? null,
            attributes: $data,
        );
    }

    public function toArray(): array
    {
        return [
            'Name' => $this->name,
            'Value' => $this->value,
        ];
    }
}
