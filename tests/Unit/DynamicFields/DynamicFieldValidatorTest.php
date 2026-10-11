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

    public function test_float_accepts_integers_and_floats_within_range(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Amount' => ['type' => 'float', 'min' => 0, 'max' => 99.5],
        ]);

        $validator->validate(['Amount' => 10]);
        $validator->validate(['Amount' => 99.5]);
        $this->expectNotToPerformAssertions();
    }

    public function test_float_rejects_strings_and_out_of_range_values(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Amount' => ['type' => 'float', 'max' => 1.5],
        ]);

        try {
            $validator->validate(['Amount' => '1.0']);
            $this->fail('Expected InvalidDynamicFieldException for a string');
        } catch (InvalidDynamicFieldException) {
        }

        $this->expectException(InvalidDynamicFieldException::class);
        $validator->validate(['Amount' => 2.0]);
    }

    public function test_array_requires_a_list_with_allowed_values(): void
    {
        $validator = new DynamicFieldValidator(definitions: [
            'Tags' => ['type' => 'array', 'allowed' => ['vip', 'urgent']],
        ]);

        $validator->validate(['Tags' => ['vip', 'urgent']]);

        try {
            $validator->validate(['Tags' => ['vip', 'spam']]);
            $this->fail('Expected InvalidDynamicFieldException for a value outside the allowed set');
        } catch (InvalidDynamicFieldException $e) {
            $this->assertStringContainsString("value 'spam' not in allowed set", $e->getMessage());
        }

        $this->expectException(InvalidDynamicFieldException::class);
        $validator->validate(['Tags' => ['a' => 'vip']]);
    }

    public function test_from_config_reads_the_package_config(): void
    {
        config()->set('znuny.dynamic_fields', ['Rating' => ['type' => 'integer', 'max' => 5]]);

        $this->expectException(InvalidDynamicFieldException::class);

        DynamicFieldValidator::fromConfig()->validate(['Rating' => 6]);
    }
}
