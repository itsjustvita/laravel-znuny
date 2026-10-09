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
     * Replace the values of sensitive keys (case-insensitive, at any depth) with [REDACTED].
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
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
