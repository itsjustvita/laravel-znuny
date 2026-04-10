<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class Priority
{
    public function __construct(
        public string $id,
        public string $name,
        public string $validId,
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        $validId = (string) ($data['ValidID'] ?? '1');

        return new self(
            id: (string) ($data['PriorityID'] ?? $data['ID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            validId: $validId,
            valid: $validId === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'PriorityID' => $this->id,
            'Name' => $this->name,
            'ValidID' => $this->validId,
        ];
    }
}
