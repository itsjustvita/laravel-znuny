<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class Queue
{
    public function __construct(
        public string $id,
        public string $name,
        public string $groupId,
        public string $validId,
        public bool $valid,
        public ?string $comment,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        $validId = (string) ($data['ValidID'] ?? '1');

        return new self(
            id: (string) ($data['QueueID'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            groupId: (string) ($data['GroupID'] ?? ''),
            validId: $validId,
            valid: $validId === '1',
            comment: $data['Comment'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'QueueID' => $this->id,
            'Name'    => $this->name,
            'GroupID' => $this->groupId,
            'ValidID' => $this->validId,
            'Comment' => $this->comment,
        ];
    }
}
