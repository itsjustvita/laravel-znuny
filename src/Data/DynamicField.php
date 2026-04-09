<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class DynamicField
{
    public function __construct(
        public string $name,
        public string|int|float|bool|array|null $value,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            name: (string) ($data['Name'] ?? ''),
            value: $data['Value'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'Name'  => $this->name,
            'Value' => $this->value,
        ];
    }
}
