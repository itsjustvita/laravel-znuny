<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use ItsJustVita\LaravelZnuny\Support\TimeZoneCheck;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TimeZoneCheckTest extends TestCase
{
    /**
     * A ticket created one hour before $now, as a Znuny with the given UTC offset returns it.
     *
     * @return array{Created: string, Age: int}
     */
    private function ticket(int $now, int $znunyOffset, int $age = 3600): array
    {
        return ['Created' => gmdate('Y-m-d H:i:s', $now - $age + $znunyOffset), 'Age' => $age];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: int, 4: bool}> now, Znuny's offset, configured time zone, its offset, match
     */
    public static function cases(): array
    {
        return [
            'both UTC' => ['2026-07-01 09:00:00', 0, 'UTC', 0, true],
            'Znuny in Berlin (summer), connection UTC' => ['2026-07-01 09:00:00', 7200, 'UTC', 0, false],
            'Znuny in Berlin (summer), connection Berlin' => ['2026-07-01 09:00:00', 7200, 'Europe/Berlin', 7200, true],
            'Znuny in Berlin (winter), connection Berlin' => ['2026-01-15 09:00:00', 3600, 'Europe/Berlin', 3600, true],
            'Znuny UTC, connection Berlin' => ['2026-01-15 09:00:00', 0, 'Europe/Berlin', 3600, false],
            'west of UTC' => ['2026-07-01 09:00:00', -14400, 'America/New_York', -14400, true],
            'quarter-hour zone' => ['2026-07-01 09:00:00', 20700, 'Asia/Kathmandu', 20700, true],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_compares_znunys_offset_with_the_configured_time_zone(string $now, int $znunyOffset, string $timezone, int $configuredOffset, bool $matches): void
    {
        $now = strtotime($now.' UTC');

        $check = TimeZoneCheck::fromTicket($this->ticket($now, $znunyOffset), $timezone, $now);

        $this->assertSame($znunyOffset, $check?->znunyOffset);
        $this->assertSame($configuredOffset, $check->configuredOffset);
        $this->assertSame($matches, $check->matches());
    }

    public function test_clocks_that_differ_by_a_few_minutes_do_not_matter(): void
    {
        $now = strtotime('2026-07-01 09:00:00 UTC');
        $ticket = $this->ticket($now, 7200);

        $this->assertSame(7200, TimeZoneCheck::fromTicket($ticket, 'Europe/Berlin', $now + 300)?->znunyOffset);
        $this->assertSame(7200, TimeZoneCheck::fromTicket($ticket, 'Europe/Berlin', $now - 300)?->znunyOffset);
    }

    public function test_the_configured_offset_is_taken_at_the_time_the_ticket_was_created(): void
    {
        // Created in winter time (UTC+1), checked months later in summer time (UTC+2).
        $now = strtotime('2026-07-01 09:00:00 UTC');
        $age = $now - strtotime('2026-01-15 09:00:00 UTC');

        $check = TimeZoneCheck::fromTicket($this->ticket($now, 3600, $age), 'Europe/Berlin', $now);

        $this->assertSame(3600, $check?->configuredOffset);
        $this->assertTrue($check->matches());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableTickets(): array
    {
        return [
            'no data' => [[]],
            'no age' => [['Created' => '2026-07-01 08:00:00']],
            'no creation time' => [['Age' => 3600]],
            'age is not a number' => [['Created' => '2026-07-01 08:00:00', 'Age' => 'old']],
            'creation time is not a date' => [['Created' => 'yesterday', 'Age' => 3600]],
            'creation time is a hash' => [['Created' => ['2026-07-01 08:00:00'], 'Age' => 3600]],
        ];
    }

    #[DataProvider('unusableTickets')]
    public function test_tickets_without_creation_time_and_age_cannot_be_checked(array $ticket): void
    {
        $this->assertNull(TimeZoneCheck::fromTicket($ticket, 'UTC', strtotime('2026-07-01 09:00:00 UTC')));
    }

    public function test_offsets_are_labelled_like_utc_offsets(): void
    {
        $this->assertSame('UTC+00:00', TimeZoneCheck::label(0));
        $this->assertSame('UTC+02:00', TimeZoneCheck::label(7200));
        $this->assertSame('UTC-04:00', TimeZoneCheck::label(-14400));
        $this->assertSame('UTC+05:45', TimeZoneCheck::label(20700));
        $this->assertSame('UTC-03:30', TimeZoneCheck::label(-12600));
    }
}
