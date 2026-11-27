<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use ItsJustVita\LaravelZnuny\Support\ZnunyTime;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class ZnunyTimeTest extends TestCase
{
    public function test_a_date_time_is_written_in_the_given_time_zone(): void
    {
        $noonInBerlin = new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('Europe/Berlin'));

        $this->assertSame('2026-07-01 10:00:00', ZnunyTime::dateTime($noonInBerlin, 'UTC'));
        $this->assertSame('2026-07-01 19:00:00', ZnunyTime::dateTime($noonInBerlin, 'Asia/Tokyo'));
    }

    public function test_a_day_is_the_day_the_caller_named(): void
    {
        // Half past midnight in Tokyo is still the day before in UTC; the caller means the first of July.
        $date = new DateTimeImmutable('2026-07-01 00:30:00', new DateTimeZone('Asia/Tokyo'));

        $this->assertSame('2026-07-01', ZnunyTime::day($date));
    }
}
