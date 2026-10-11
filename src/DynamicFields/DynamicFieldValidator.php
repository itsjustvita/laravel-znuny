<?php

namespace ItsJustVita\LaravelZnuny\DynamicFields;

use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;

class DynamicFieldValidator
{
    /** @var array<string, FieldDefinition> */
    private array $definitions;

    /**
     * @param  array<string, array<string, mixed>>  $definitions  raw config entries
     */
    public function __construct(array $definitions)
    {
        $parsed = [];

        foreach ($definitions as $name => $spec) {
            $parsed[$name] = FieldDefinition::fromArray($name, (array) $spec);
        }

        $this->definitions = $parsed;
    }

    public static function fromConfig(): self
    {
        return new self((array) config('znuny.dynamic_fields', []));
    }

    /**
     * @param  array<string, mixed>  $fields  name => value
     */
    public function validate(array $fields): void
    {
        foreach ($fields as $name => $value) {
            $definition = $this->definitions[$name] ?? null;

            if ($definition !== null) {
                $this->validateOne($definition, $value);
            }
        }
    }

    private function validateOne(FieldDefinition $def, mixed $value): void
    {
        switch ($def->type) {
            case 'string':
                if (! is_string($value)) {
                    throw $this->invalid($def, 'expected string, got '.gettype($value));
                }
                $this->assertAllowed($def, [$value]);

                return;

            case 'integer':
                if (! is_int($value)) {
                    throw $this->invalid($def, 'expected integer, got '.gettype($value));
                }
                $this->assertRange($def, $value);

                return;

            case 'float':
                if (! is_int($value) && ! is_float($value)) {
                    throw $this->invalid($def, 'expected float, got '.gettype($value));
                }
                $this->assertRange($def, $value);

                return;

            case 'boolean':
                if (! is_bool($value)) {
                    throw $this->invalid($def, 'expected boolean, got '.gettype($value));
                }

                return;

            case 'array':
                if (! is_array($value) || ! array_is_list($value)) {
                    throw $this->invalid($def, 'expected list, got '.gettype($value));
                }
                $this->assertAllowed($def, $value);

                return;

            default:
                throw $this->invalid($def, "unsupported field type '{$def->type}'");
        }
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function assertAllowed(FieldDefinition $def, array $values): void
    {
        if ($def->allowed === null) {
            return;
        }

        foreach ($values as $value) {
            if (! in_array($value, $def->allowed, true)) {
                $shown = is_scalar($value) ? (string) $value : gettype($value);

                throw $this->invalid($def, "value '{$shown}' not in allowed set [".implode(', ', $def->allowed).']');
            }
        }
    }

    private function assertRange(FieldDefinition $def, int|float $value): void
    {
        if ($def->min !== null && $value < $def->min) {
            throw $this->invalid($def, "value {$value} is below minimum {$def->min}");
        }

        if ($def->max !== null && $value > $def->max) {
            throw $this->invalid($def, "value {$value} is above maximum {$def->max}");
        }
    }

    private function invalid(FieldDefinition $def, string $message): InvalidDynamicFieldException
    {
        return new InvalidDynamicFieldException("DynamicField [{$def->name}]: {$message}");
    }
}
