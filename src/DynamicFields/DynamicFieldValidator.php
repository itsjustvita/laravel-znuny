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

    /**
     * @param  array<string, mixed>  $fields  name => value
     */
    public function validate(array $fields): void
    {
        foreach ($fields as $name => $value) {
            $definition = $this->definitions[$name] ?? null;
            if ($definition === null) {
                continue;
            }

            $this->validateOne($definition, $value);
        }
    }

    private function validateOne(FieldDefinition $def, mixed $value): void
    {
        switch ($def->type) {
            case 'string':
                if (! is_string($value)) {
                    throw $this->invalid($def, 'expected string, got '.gettype($value));
                }
                if ($def->allowed !== null && ! in_array($value, $def->allowed, true)) {
                    throw $this->invalid($def, "value '{$value}' not in allowed set [".implode(', ', $def->allowed).']');
                }

                return;

            case 'integer':
                if (! is_int($value)) {
                    throw $this->invalid($def, 'expected integer, got '.gettype($value));
                }
                if ($def->min !== null && $value < $def->min) {
                    throw $this->invalid($def, "value {$value} is below minimum {$def->min}");
                }
                if ($def->max !== null && $value > $def->max) {
                    throw $this->invalid($def, "value {$value} is above maximum {$def->max}");
                }

                return;

            case 'boolean':
                if (! is_bool($value)) {
                    throw $this->invalid($def, 'expected boolean, got '.gettype($value));
                }

                return;

            default:
                throw $this->invalid($def, "unsupported field type '{$def->type}'");
        }
    }

    private function invalid(FieldDefinition $def, string $message): InvalidDynamicFieldException
    {
        return new InvalidDynamicFieldException("DynamicField [{$def->name}]: {$message}");
    }
}
