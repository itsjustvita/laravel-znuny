<?php

namespace ItsJustVita\LaravelZnuny\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Writes dates the way Znuny reads them.
 *
 * @internal
 */
final class ZnunyTime
{
    /**
     * A point in time as "Y-m-d H:i:s" in the given time zone, which is Znuny's OTRSTimeZone.
     */
    public static function dateTime(DateTimeInterface $date, string $timezone): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d H:i:s');
    }

    /**
     * A calendar day as "Y-m-d": the day the date names in its own time zone. A day is not
     * a point in time, so it is not converted.
     */
    public static function day(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }
}
