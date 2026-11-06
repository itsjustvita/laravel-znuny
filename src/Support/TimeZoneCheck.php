<?php

namespace ItsJustVita\LaravelZnuny\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Compares the time zone of a connection with the one Znuny actually uses.
 *
 * A TicketGet row carries "Created" (wall-clock time in Znuny's OTRSTimeZone) and "Age"
 * (the real number of seconds since then). Together they reveal Znuny's UTC offset.
 */
final readonly class TimeZoneCheck
{
    /**
     * @param  int  $znunyOffset  seconds east of UTC that Znuny's dates use
     * @param  int  $configuredOffset  offset of the connection's time zone at the same moment
     */
    public function __construct(
        public int $znunyOffset,
        public int $configuredOffset,
    ) {}

    /**
     * @param  array<array-key, mixed>  $ticket  a TicketGet row
     * @param  string  $timezone  the connection's time zone
     * @param  int  $now  the current Unix time
     * @return self|null null when the row has no usable "Created" and "Age"
     */
    public static function fromTicket(array $ticket, string $timezone, int $now): ?self
    {
        $created = $ticket['Created'] ?? null;
        $age = $ticket['Age'] ?? null;

        if (! is_string($created) || ! is_numeric($age)) {
            return null;
        }

        $wallClock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $created, new DateTimeZone('UTC'));

        if ($wallClock === false) {
            return null;
        }

        $createdAt = $now - (int) $age;

        // UTC offsets are multiples of 15 minutes, so rounding absorbs clocks that differ by a few minutes.
        $znunyOffset = (int) round(($wallClock->getTimestamp() - $createdAt) / 900) * 900;

        return new self(
            $znunyOffset,
            (new DateTimeZone($timezone))->getOffset(new DateTimeImmutable('@'.$createdAt)),
        );
    }

    public function matches(): bool
    {
        return $this->znunyOffset === $this->configuredOffset;
    }

    public static function label(int $offset): string
    {
        return sprintf('UTC%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
    }
}
