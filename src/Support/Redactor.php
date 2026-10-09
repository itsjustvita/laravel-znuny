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
