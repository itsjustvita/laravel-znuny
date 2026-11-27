<?php

namespace ItsJustVita\LaravelZnuny\Data\Concerns;

use Carbon\CarbonImmutable;
use Error;
use Illuminate\Support\Arr;

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
     * Without this, isset() and ?? on an undefined property would call __get() and fail.
     */
    public function __isset(string $name): bool
    {
        return false;
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
