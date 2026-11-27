<?php

namespace ItsJustVita\LaravelZnuny\Support;

final class Redactor
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password', 'pw', 'userpw', 'newpw', 'sessionid', 'secret', 'token',
        'accesstoken', 'apikey', 'authorization', 'otp', 'twofactortoken',
        'x-otrs-header-password', 'x-otrs-header-sessionid',
    ];

    /**
     * Replace the values of sensitive keys (case-insensitive, at any depth) with [REDACTED],
     * and the value of a {Key, Value} pair whose key names a secret.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        // Session data arrives as a list of {Key, Value} pairs, so the secret is named by a value.
        if (is_string($data['Key'] ?? null) && array_key_exists('Value', $data)) {
            $data['Value'] = self::pairValue($data['Key'], $data['Value'], ! empty($data['Serialized']));
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function isSensitive(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }

    /**
     * Whether a name out of Znuny's data, such as a session key, names a secret: it contains
     * "password", "secret" or "token", or ends with "pw" (UserPw, but not UserLastPwChangeTime).
     */
    public static function isSecretName(string $name): bool
    {
        $name = strtolower($name);

        return str_contains($name, 'password')
            || str_contains($name, 'secret')
            || str_contains($name, 'token')
            || str_ends_with($name, 'pw');
    }

    /**
     * The data without the entries whose key names a secret, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function withoutSecrets(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::namesSecret($key)) {
                continue;
            }

            $result[$key] = is_array($value) ? self::withoutSecrets($value) : $value;
        }

        return $result;
    }

    /**
     * The value of a {Key, Value} pair for logs and events. Znuny marks values it encoded as
     * JSON with "Serialized"; a customer's session holds the configuration of the customer
     * backend that way, including the password of an LDAP directory or an external database.
     */
    private static function pairValue(string $key, mixed $value, bool $serialized): mixed
    {
        if (self::isSecretName($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            return self::maskSecrets($value);
        }

        if (! $serialized || ! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::REDACTED;
        }

        return is_array($decoded)
            ? (string) json_encode(self::maskSecrets($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function maskSecrets(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::namesSecret($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = self::maskSecrets($value);
            }
        }

        return $data;
    }

    private static function namesSecret(string $key): bool
    {
        return self::isSensitive($key) || self::isSecretName($key);
    }

    /**
     * Mask credentials that appear as key=value pairs in free text, e.g. the URL that
     * transport error messages contain.
     */
    public static function scrub(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b([A-Za-z][\w-]*)=([^&\s"\']*)/',
            fn (array $match): string => self::isSensitive($match[1]) ? $match[1].'='.self::REDACTED : $match[0],
            $text,
        );
    }

    /**
     * Redact secrets and shorten large "Content" values (base64 attachments) for log output.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function forLog(array $data): array
    {
        return self::shortenContent(self::redact($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function shortenContent(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::shortenContent($value);
            } elseif ($key === 'Content' && is_string($value) && strlen($value) > 64) {
                $data[$key] = sprintf('[%d chars]', strlen($value));
            }
        }

        return $data;
    }
}
