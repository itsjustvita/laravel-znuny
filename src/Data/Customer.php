<?php

namespace ItsJustVita\LaravelZnuny\Data;

final readonly class Customer
{
    public function __construct(
        public string $id,
        public ?string $company,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $login,
        public string $validId,
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        $validId = (string) ($data['ValidID'] ?? '1');

        return new self(
            id: (string) ($data['CustomerID'] ?? ''),
            company: $data['CustomerCompany'] ?? null,
            firstName: $data['UserFirstname'] ?? null,
            lastName: $data['UserLastname'] ?? null,
            email: $data['UserEmail'] ?? null,
            login: $data['UserLogin'] ?? null,
            validId: $validId,
            valid: $validId === '1',
        );
    }

    public function toArray(): array
    {
        return [
            'CustomerID'      => $this->id,
            'CustomerCompany' => $this->company,
            'UserFirstname'   => $this->firstName,
            'UserLastname'    => $this->lastName,
            'UserEmail'       => $this->email,
            'UserLogin'       => $this->login,
            'ValidID'         => $this->validId,
        ];
    }
}
