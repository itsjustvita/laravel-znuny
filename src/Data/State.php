<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class State
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $typeName,
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            id: (string) ($data['StateID'] ?? $data['ID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            typeName: $data['TypeName'] ?? null,
            valid: ((string) ($data['ValidID'] ?? '1')) === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'StateID'  => $this->id,
            'Name'     => $this->name,
            'TypeName' => $this->typeName,
            'ValidID'  => $this->valid ? '1' : '2',
        ];
    }
}
