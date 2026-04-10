<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class State
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $typeName,
        public string $validId,
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        $validId = (string) ($data['ValidID'] ?? '1');

        return new self(
            id: (string) ($data['StateID'] ?? $data['ID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            typeName: $data['TypeName'] ?? null,
            validId: $validId,
            valid: $validId === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'StateID' => $this->id,
            'Name' => $this->name,
            'TypeName' => $this->typeName,
            'ValidID' => $this->validId,
        ];
    }
}
