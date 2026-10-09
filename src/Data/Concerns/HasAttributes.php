<?php

namespace ItsJustVita\LaravelZnuny\Data\Concerns;

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
}
