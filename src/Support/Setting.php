<?php

namespace ItsJustVita\LaravelZnuny\Support;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;

/**
 * Reads configuration values. Null and blank strings count as not set, like an empty
 * variable in .env, so that ZNUNY_TIMEOUT= means the default and not 0.
 *
 * @internal
 */
final class Setting
{
    public static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * The first candidate that is set, as an integer.
     *
     * @param  string  $owner  who has the setting, for the error message, e.g. "Znuny connection [default]"
     *
     * @throws ZnunyConfigurationException when that candidate is no number; cast to one it would be 0
     */
    public static function integer(string $owner, string $key, int $default, mixed ...$candidates): int
    {
        foreach ($candidates as $candidate) {
            if (self::isBlank($candidate)) {
                continue;
            }

            if (is_string($candidate)) {
                $candidate = trim($candidate);
            }

            if (is_int($candidate) || is_float($candidate) || (is_string($candidate) && is_numeric($candidate))) {
                return (int) $candidate;
            }

            throw new ZnunyConfigurationException(sprintf(
                '%s has invalid [%s] value [%s]. Use a whole number.',
                $owner,
                $key,
                is_scalar($candidate) ? (is_bool($candidate) ? var_export($candidate, true) : (string) $candidate) : gettype($candidate),
            ));
        }

        return $default;
    }

    /**
     * A switch: true, false and the strings PHP reads as booleans ("on", "off", "yes",
     * "no", "1", "0"). Anything else, such as a typo, leaves the default in place.
     */
    public static function boolean(bool $default, mixed $value): bool
    {
        if (self::isBlank($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
