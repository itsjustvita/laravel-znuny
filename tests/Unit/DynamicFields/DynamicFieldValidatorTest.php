<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\DynamicFields;

use ItsJustVita\LaravelZnuny\DynamicFields\DynamicFieldValidator;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class DynamicFieldValidatorTest extends TestCase
{
    public function test_unknown_fields_are_allowed_when_no_definitions_configured(): void
    {
        $validator = new DynamicFieldValidator(definitions: []);
        $validator->validate(['Whatever' => 'value']);

        $this->expectNotToPerformAssertions();
    }

    public function test_unknown_fields_pass_when_definition_is_only_partial(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'KnownField' => ['type' => 'string'],
        ]);

        $validator->validate(['OtherField' => 'x', 'KnownField' => 'ok']);
        $this->expectNotToPerformAssertions();
    }

    public function test_string_allowed_values_enforced(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Segment' => ['type' => 'string', 'allowed' => ['business', 'private']],
        ]);

        $this->expectException(InvalidDynamicFieldException::class);
        $validator->validate(['Segment' => 'government']);
    }

    public function test_integer_min_max_enforced(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Priority' => ['type' => 'integer', 'min' => 1, 'max' => 5],
        ]);

        $this->expectException(InvalidDynamicFieldException::class);
        $validator->validate(['Priority' => 6]);
    }

    public function test_integer_type_rejects_strings(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Priority' => ['type' => 'integer'],
        ]);

        $this->expectException(InvalidDynamicFieldException::class);
        $validator->validate(['Priority' => 'high']);
    }

    public function test_valid_values_pass(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Segment' => ['type' => 'string', 'allowed' => ['business']],
            'Priority' => ['type' => 'integer', 'min' => 1, 'max' => 5],
        ]);

        $validator->validate(['Segment' => 'business', 'Priority' => 3]);
        $this->expectNotToPerformAssertions();
    }
}
