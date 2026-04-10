<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class Type
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
            id: (string) ($data['TypeID'] ?? $data['ID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            validId: $validId,
            valid: $validId === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'TypeID' => $this->id,
            'Name' => $this->name,
            'ValidID' => $this->validId,
        ];
    }
}
