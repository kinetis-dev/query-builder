<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Exception;

use InvalidArgumentException;

/**
 * A row RowMapper cannot map onto its DTO, or a DTO it cannot map rows
 * onto. A message names the class, the parameter or column and the
 * expected shape, and describes a value only by its kind: a column can
 * hold a secret.
 */
final class RowMappingException extends InvalidArgumentException
{
    public static function unsupportedDefinition(string $class, string $reason): self
    {
        return new self("RowMapper cannot map rows onto {$class}: {$reason}.");
    }

    public static function missingColumn(string $class, string $parameter): self
    {
        return new self(
            "The row has no \"{$parameter}\" column for the constructor parameter of that name on {$class}, "
            . 'which has no default.',
        );
    }

    public static function invalidValue(string $class, string $parameter, string $expected, mixed $value): self
    {
        return new self(
            "Column \"{$parameter}\" cannot map onto {$class}: the parameter takes {$expected}, got "
            . get_debug_type($value) . '.',
        );
    }

    /** @param class-string $enum */
    public static function unknownEnumCase(string $class, string $parameter, string $enum, mixed $value): self
    {
        return new self(
            "Column \"{$parameter}\" cannot map onto {$class}: the " . get_debug_type($value)
            . " value names no {$enum} case.",
        );
    }
}
