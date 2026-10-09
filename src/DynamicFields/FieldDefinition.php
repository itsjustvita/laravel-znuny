<?php

namespace ItsJustVita\LaravelZnuny\DynamicFields;

final readonly class FieldDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public ?array $allowed = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {}

    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            type: (string) ($data['type'] ?? 'string'),
            allowed: isset($data['allowed']) ? array_values((array) $data['allowed']) : null,
            min: isset($data['min']) && is_numeric($data['min']) ? $data['min'] + 0 : null,
            max: isset($data['max']) && is_numeric($data['max']) ? $data['max'] + 0 : null,
        );
    }
}
