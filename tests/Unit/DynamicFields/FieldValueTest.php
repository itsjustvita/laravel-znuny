<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\DynamicFields;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use ItsJustVita\LaravelZnuny\DynamicFields\FieldValue;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class FieldValueTest extends TestCase
{
    private const FIELDS = ['DueOn' => ['type' => 'date'], 'DueAt' => ['type' => 'datetime'], 'Note' => ['type' => 'string']];

    public function test_a_date_time_is_sent_in_znunys_format_and_time_zone(): void
    {
        $noonInNewYork = CarbonImmutable::parse('2026-07-01 12:00:00', 'America/New_York');

        $this->assertSame('2026-07-01 18:00:00', FieldValue::forZnuny('DueAt', $noonInNewYork, 'Europe/Berlin', self::FIELDS));
        // Without a declaration a date is a point in time too.
        $this->assertSame('2026-07-01 16:00:00', FieldValue::forZnuny('Unknown', $noonInNewYork, 'UTC', []));
    }

    public function test_a_date_field_keeps_the_day_the_caller_named(): void
    {
        // Half past midnight in Tokyo is the day before in UTC; a Znuny date field takes midnight only.
        $date = new DateTimeImmutable('2026-07-01 00:30:00', new DateTimeZone('Asia/Tokyo'));

        $this->assertSame('2026-07-01 00:00:00', FieldValue::forZnuny('DueOn', $date, 'UTC', self::FIELDS));
    }

    public function test_lists_are_converted_item_by_item(): void
    {
        $this->assertSame(
            ['2026-07-01 10:00:00', '2026-07-02 10:00:00', 'text'],
            FieldValue::forZnuny('DueAt', [CarbonImmutable::parse('2026-07-01 10:00', 'UTC'), CarbonImmutable::parse('2026-07-02 10:00', 'UTC'), 'text'], 'UTC', self::FIELDS),
        );
    }

    public function test_other_values(): void
    {
        $this->assertSame(1, FieldValue::forZnuny('Flag', true, 'UTC', []));
        $this->assertSame(0, FieldValue::forZnuny('Flag', false, 'UTC', []));
        $this->assertSame('2026-07-01', FieldValue::forZnuny('DueOn', '2026-07-01', 'UTC', self::FIELDS));
        $this->assertSame(42, FieldValue::forZnuny('Count', 42, 'UTC', []));
        $this->assertNull(FieldValue::forZnuny('Note', null, 'UTC', self::FIELDS));
    }
}
