<?php

namespace ItsJustVita\LaravelZnuny\Data\Concerns;

use Carbon\CarbonImmutable;
use Error;
use Illuminate\Support\Arr;

/**
 * @internal Not covered by the package's stability promise.
 */
trait HasAttributes
{
    /**
     * Read a raw field as returned by Znuny. Supports dot notation for nested data.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->attributes, $key, $default);
    }

    /**
     * An undefined property is an error, not a silent null, so a typo cannot pass for an
     * empty value. A property that was renamed names its successor.
     */
    public function __get(string $name): mixed
    {
        $renamed = static::renamedProperties()[$name] ?? null;

        throw new Error(sprintf(
            'Undefined property: %s::$%s%s',
            static::class,
            $name,
            $renamed !== null ? ' (renamed to $'.$renamed.')' : '',
        ));
    }

    /**
     * An undefined property is not set, so isset() and ?? on it stay quiet. A renamed one
     * counts as set: then ??, data_get() and the collection helpers built on it (pluck(),
     * sortBy(), where()) reach __get() and fail with the new name, instead of going on with null.
     */
    public function __isset(string $name): bool
    {
        return isset(static::renamedProperties()[$name]);
    }

    /**
     * Properties an earlier release had under another name: old name => new name.
     *
     * @return array<string, string>
     */
    protected static function renamedProperties(): array
    {
        return [];
    }

    protected static function optionalString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * Znuny returns dates as "Y-m-d H:i:s" in its OTRSTimeZone, not in the app's time zone.
     */
    protected static function optionalDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        $value = self::optionalString($value);

        return $value === null ? null : CarbonImmutable::parse($value, $timezone);
    }
}
