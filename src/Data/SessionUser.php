<?php

namespace ItsJustVita\LaravelZnuny\Data;

use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;
use ItsJustVita\LaravelZnuny\Support\Redactor;

/**
 * The user a session belongs to, as SessionGet describes it: the account of the connection.
 */
final readonly class SessionUser
{
    use HasAttributes;

    /**
     * @param  string  $id  the agent's numeric ID; for a customer user the login
     * @param  string  $type  "agent" or "customer"
     * @param  array<string, mixed>  $attributes  the session's values by key, without secrets
     */
    public function __construct(
        public string $id,
        public string $login,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $fullName,
        public ?string $email,
        public string $type,
        public array $attributes = [],
    ) {}

    /**
     * Znuny returns the session as a list of {Key, Value} pairs and marks values it had to
     * encode as JSON with "Serialized". Values whose key names a secret, such as a customer's
     * password hash or a two-factor key, are left out, at any depth.
     *
     * @param  array<array-key, mixed>  $response  the response of SessionGet
     */
    public static function fromZnunyResponse(array $response): self
    {
        $data = [];

        foreach (is_array($response['SessionData'] ?? null) ? $response['SessionData'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['Key'] ?? null) || Redactor::isSecretName($row['Key'])) {
                continue;
            }

            $value = $row['Value'] ?? null;

            if (! empty($row['Serialized']) && is_string($value)) {
                $value = json_decode($value, true);

                // What claims to be JSON and is not cannot be searched for secrets.
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
            }

            // A customer's session holds the customer backend's configuration, passwords included.
            $data[$row['Key']] = is_array($value) ? Redactor::withoutSecrets($value) : $value;
        }

        return new self(
            id: (string) (self::optionalString($data['UserID'] ?? null) ?? ''),
            login: (string) (self::optionalString($data['UserLogin'] ?? null) ?? ''),
            firstName: self::optionalString($data['UserFirstname'] ?? null),
            lastName: self::optionalString($data['UserLastname'] ?? null),
            fullName: self::optionalString($data['UserFullname'] ?? null),
            email: self::optionalString($data['UserEmail'] ?? null),
            type: ($data['UserType'] ?? null) === 'Customer' ? 'customer' : 'agent',
            attributes: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'UserID' => $this->id,
            'UserLogin' => $this->login,
            'UserFirstname' => $this->firstName,
            'UserLastname' => $this->lastName,
            'UserFullname' => $this->fullName,
            'UserEmail' => $this->email,
            'UserType' => $this->type === 'customer' ? 'Customer' : 'User',
        ];
    }
}
