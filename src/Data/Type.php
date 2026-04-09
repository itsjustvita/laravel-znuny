<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class Type
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            id: (string) ($data['TypeID'] ?? $data['ID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            valid: ((string) ($data['ValidID'] ?? '1')) === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'TypeID'  => $this->id,
            'Name'    => $this->name,
            'ValidID' => $this->valid ? '1' : '2',
        ];
    }
}
