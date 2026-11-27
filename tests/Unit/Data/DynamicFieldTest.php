<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\DynamicField;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DynamicFieldTest extends TestCase
{
    public function test_it_builds_from_znuny_response_shape(): void
    {
        $field = DynamicField::fromZnunyResponse(['Name' => 'OrderId', 'Value' => 'ORD-001']);

        $this->assertSame('OrderId', $field->name);
        $this->assertSame('ORD-001', $field->value);
    }

    public function test_it_handles_null_value(): void
    {
        $field = DynamicField::fromZnunyResponse(['Name' => 'OrderId', 'Value' => null]);

        $this->assertNull($field->value);
    }

    public function test_a_date_value_is_read_in_znunys_time_zone(): void
    {
        $field = DynamicField::fromZnunyResponse(['Name' => 'DueAt', 'Value' => '2026-07-01 10:00:00'], 'Europe/Berlin');

        $this->assertSame('2026-07-01 08:00:00', $field->date()?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Berlin', $field->date()?->getTimezone()->getName());
        $this->assertSame('2026-07-01 00:00:00', DynamicField::fromZnunyResponse(['Name' => 'DueOn', 'Value' => '2026-07-01'])->date()?->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noDates(): array
    {
        return ['null' => [null], 'empty' => [''], 'text' => ['ORD-001'], 'a list' => [['2026-07-01 10:00:00']], 'a number' => [20260701], 'ISO 8601' => ['2026-07-01T10:00:00Z']];
    }

    #[DataProvider('noDates')]
    public function test_a_value_that_is_no_znuny_date_gives_no_date(mixed $value): void
    {
        $this->assertNull(DynamicField::fromZnunyResponse(['Name' => 'Field', 'Value' => $value])->date());
    }

    public function test_it_serializes_to_znuny_shape(): void
    {
        $field = new DynamicField('OrderId', 'ORD-001');

        $this->assertSame(['Name' => 'OrderId', 'Value' => 'ORD-001'], $field->toArray());
    }
}
