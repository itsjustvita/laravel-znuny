<?php

namespace ItsJustVita\LaravelZnuny\DynamicFields;

use DateTimeInterface;
use ItsJustVita\LaravelZnuny\Support\ZnunyTime;

/**
 * Writes a dynamic field value the way Znuny reads it.
 *
 * @internal
 */
final class FieldValue
{
    /**
     * A date becomes "Y-m-d H:i:s" in Znuny's time zone, which is what a field of type
     * "Date / Time" takes. A field of type "Date" takes midnight only; the package cannot see
     * a field's type, so such a field is declared with 'type' => 'date' in znuny.dynamic_fields
     * and gets the day the date names. Booleans become 1 or 0, lists are converted item by item.
     *
     * @param  string  $timezone  Znuny's OTRSTimeZone
     * @param  array<array-key, mixed>  $definitions  the znuny.dynamic_fields config
     */
    public static function forZnuny(string $field, mixed $value, string $timezone, array $definitions): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $definition = $definitions[$field] ?? null;

            return is_array($definition) && ($definition['type'] ?? null) === 'date'
                ? ZnunyTime::day($value).' 00:00:00'
                : ZnunyTime::dateTime($value, $timezone);
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::forZnuny($field, $item, $timezone, $definitions), $value);
        }

        return $value;
    }
}
