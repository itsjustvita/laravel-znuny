<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\DynamicFields;

use ItsJustVita\LaravelZnuny\DynamicFields\FieldDefinition;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class FieldDefinitionTest extends TestCase
{
    public function test_it_parses_definition_array(): void
    {
        $def = FieldDefinition::fromArray('Priority', ['type' => 'integer', 'min' => 1, 'max' => 5]);

        $this->assertSame('Priority', $def->name);
        $this->assertSame('integer', $def->type);
        $this->assertSame(1, $def->min);
        $this->assertSame(5, $def->max);
    }

    public function test_allowed_values_are_captured(): void
    {
        $def = FieldDefinition::fromArray('Segment', ['type' => 'string', 'allowed' => ['a', 'b']]);

        $this->assertSame(['a', 'b'], $def->allowed);
    }
}
