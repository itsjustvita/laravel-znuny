<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class Priority
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            id: (string) ($data['PriorityID'] ?? $data['ID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            valid: ((string) ($data['ValidID'] ?? '1')) === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'PriorityID' => $this->id,
            'Name'       => $this->name,
            'ValidID'    => $this->valid ? '1' : '2',
        ];
    }
}
