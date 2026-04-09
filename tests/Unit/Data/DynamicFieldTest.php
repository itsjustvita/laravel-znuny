<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\DynamicField;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

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

    public function test_it_serializes_to_znuny_shape(): void
    {
        $field = new DynamicField('OrderId', 'ORD-001');

        $this->assertSame(['Name' => 'OrderId', 'Value' => 'ORD-001'], $field->toArray());
    }
}
