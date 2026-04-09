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
        public bool $valid,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            id: (string) ($data['CustomerID'] ?? ''),
            company: $data['CustomerCompany'] ?? null,
            firstName: $data['UserFirstname'] ?? null,
            lastName: $data['UserLastname'] ?? null,
            email: $data['UserEmail'] ?? null,
            login: $data['UserLogin'] ?? null,
            valid: ((string) ($data['ValidID'] ?? '1')) === '1',
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
            'ValidID'         => $this->valid ? '1' : '2',
        ];
    }
}
