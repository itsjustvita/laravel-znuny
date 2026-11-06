<?php

namespace ItsJustVita\LaravelZnuny\Data;

use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class Queue
{
    use HasAttributes;

    /**
     * @param  array<string, mixed>  $attributes  raw Znuny fields
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $groupId,
        public string $validId,
        public bool $valid,
        public ?string $comment,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
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
            attributes: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'QueueID' => $this->id,
            'Name' => $this->name,
            'GroupID' => $this->groupId,
            'ValidID' => $this->validId,
            'Comment' => $this->comment,
        ];
    }
}
