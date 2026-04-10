<?php

namespace ItsJustVita\LaravelZnuny\DynamicFields;

final readonly class FieldDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public ?array $allowed = null,
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            type: (string) ($data['type'] ?? 'string'),
            allowed: isset($data['allowed']) ? array_values((array) $data['allowed']) : null,
            min: isset($data['min']) ? (int) $data['min'] : null,
            max: isset($data['max']) ? (int) $data['max'] : null,
        );
    }
}
