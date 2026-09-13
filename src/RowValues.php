<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use BackedEnum;
use InvalidArgumentException;
use Kinetis\Validation\Absent;
use UnitEnum;

/**
 * Turns an object's public properties into the column => value map every
 * Query write terminal takes. Stateless: it reads the object it is given
 * and keeps nothing.
 */
final class RowValues
{
    /**
     * Reads the object's initialized public properties, as seen from
     * outside it. Absent::Value is omitted, null is kept as a SQL NULL, and
     * a backed enum becomes its backing value. Every other value must
     * already be a bool, an int, a finite float or a string.
     *
     * @param array<string, string> $columns property => target column rename
     * @param list<string> $except public properties to omit
     * @return array<string, null|bool|int|float|string>
     */
    public static function fromObject(object $object, array $columns = [], array $except = []): array
    {
        // Called from this class's scope, get_object_vars() returns exactly
        // the properties readable from outside the object, and skips a
        // typed property that was never initialized.
        $properties = get_object_vars($object);
        $class = $object::class;

        foreach ([...array_keys($columns), ...$except] as $name) {
            if (!array_key_exists($name, $properties)) {
                throw new InvalidArgumentException(
                    "RowValues::fromObject() was given \"{$name}\", but {$class} has no initialized public "
                    . 'property of that name.',
                );
            }
        }

        foreach ($except as $name) {
            if (array_key_exists($name, $columns)) {
                throw new InvalidArgumentException(
                    "RowValues::fromObject() was asked to both rename and omit \"{$name}\" of {$class}.",
                );
            }
        }

        $row = [];
        $sourceOf = [];

        foreach ($properties as $property => $value) {
            if (in_array($property, $except, true)) {
                continue;
            }

            $column = $columns[$property] ?? $property;

            if (array_key_exists($column, $sourceOf)) {
                throw new InvalidArgumentException(
                    "RowValues::fromObject() maps both \"{$sourceOf[$column]}\" and \"{$property}\" of {$class} "
                    . "to the column \"{$column}\".",
                );
            }

            $sourceOf[$column] = $property;

            if ($value === Absent::Value) {
                continue;
            }

            $row[$column] = self::sqlValue($class, $property, $value);
        }

        return $row;
    }

    private static function sqlValue(string $class, string $property, mixed $value): null|bool|int|float|string
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        // The kind of value only, never the value: a property can hold a secret.
        $kind = match (true) {
            is_float($value) => 'a non-finite float',
            $value instanceof UnitEnum => 'the unit enum ' . $value::class,
            default => get_debug_type($value),
        };

        throw new InvalidArgumentException(
            "RowValues::fromObject() cannot write property \"{$property}\" of {$class}: {$kind} is not a SQL "
            . 'value. Convert it to null, a bool, an int, a finite float or a string before extracting the row.',
        );
    }
}
