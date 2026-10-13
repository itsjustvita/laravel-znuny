<?php

namespace ItsJustVita\LaravelZnuny\Data\Concerns;

use Carbon\Carbon;
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
    protected static function optionalDate(mixed $value, string $timezone): ?Carbon
    {
        $value = self::optionalString($value);

        return $value === null ? null : Carbon::parse($value, $timezone);
    }
}
