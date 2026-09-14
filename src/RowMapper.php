<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use BackedEnum;
use Kinetis\QueryBuilder\Exception\RowMappingException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Maps result rows onto one constructor DTO. Each constructor parameter
 * reads the column of exactly its name; other columns are ignored, and a
 * missing column leaves an optional parameter to its declared default.
 *
 * for() reflects and validates the class once and keeps only that
 * immutable plan on the instance. Nothing is cached beyond it: Query
 * builds one mapper per result set, so no class metadata outlives the
 * read that needed it.
 *
 * @template T of object
 */
final class RowMapper
{
    /**
     * @param class-string<T> $class
     * @param list<array{name: string, optional: bool, nullable: bool, type: 'mixed'|'string'|'int'|'float'|'bool', enum: class-string<BackedEnum>|null}> $parameters
     */
    private function __construct(
        private readonly string $class,
        private readonly array $parameters,
    ) {}

    /**
     * @template TObject of object
     * @param class-string<TObject> $class
     * @return self<TObject>
     * @throws RowMappingException for a class or constructor parameter outside the admitted domain
     */
    public static function for(string $class): self
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw RowMappingException::unsupportedDefinition($class, 'it cannot be instantiated');
        }

        $parameters = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[] = self::plan($class, $parameter);
        }

        return new self($class, $parameters);
    }

    /**
     * Every column is checked and converted before the constructor runs;
     * an exception the constructor itself throws propagates unchanged.
     *
     * @param array<string, mixed> $row
     * @return T
     * @throws RowMappingException for a missing required column or a value its parameter does not admit
     */
    public function map(array $row): object
    {
        $arguments = [];

        foreach ($this->parameters as $parameter) {
            $name = $parameter['name'];

            if (array_key_exists($name, $row)) {
                $arguments[$name] = $this->convert($parameter, $row[$name]);
            } elseif (!$parameter['optional']) {
                throw RowMappingException::missingColumn($this->class, $name);
            }
        }

        // String keys spread as named arguments, so an omitted optional
        // parameter takes its declared default.
        return new ($this->class)(...$arguments);
    }

    /**
     * @param class-string $class
     * @return array{name: string, optional: bool, nullable: bool, type: 'mixed'|'string'|'int'|'float'|'bool', enum: class-string<BackedEnum>|null}
     */
    private static function plan(string $class, ReflectionParameter $parameter): array
    {
        $name = $parameter->getName();

        if ($parameter->isVariadic()) {
            throw RowMappingException::unsupportedDefinition($class, "constructor parameter \"{$name}\" is variadic");
        }

        if ($parameter->isPassedByReference()) {
            throw RowMappingException::unsupportedDefinition($class, "constructor parameter \"{$name}\" is passed by reference");
        }

        $type = $parameter->getType();
        // isOptional() rather than isDefaultValueAvailable(): a default
        // declared before a required parameter is not one PHP applies.
        $plan = ['name' => $name, 'optional' => $parameter->isOptional(), 'nullable' => true, 'type' => 'mixed', 'enum' => null];

        if ($type === null) {
            return $plan;
        }

        // `?T` and `T|null` both reflect as a named type; every other
        // union, and every intersection, does not.
        if (!$type instanceof ReflectionNamedType) {
            throw RowMappingException::unsupportedDefinition(
                $class,
                "constructor parameter \"{$name}\" declares the composite type {$type}",
            );
        }

        $plan['nullable'] = $type->allowsNull();
        $typeName = $type->getName();

        if (in_array($typeName, ['mixed', 'string', 'int', 'float', 'bool'], true)) {
            $plan['type'] = $typeName;

            return $plan;
        }

        if (!$type->isBuiltin() && is_subclass_of($typeName, BackedEnum::class)) {
            $plan['type'] = new ReflectionEnum($typeName)->getBackingType()?->getName() === 'int' ? 'int' : 'string';
            $plan['enum'] = $typeName;

            return $plan;
        }

        throw RowMappingException::unsupportedDefinition(
            $class,
            "constructor parameter \"{$name}\" declares {$typeName}, which is not string, int, float, bool, mixed or a backed enum",
        );
    }

    /**
     * @param array{name: string, optional: bool, nullable: bool, type: 'mixed'|'string'|'int'|'float'|'bool', enum: class-string<BackedEnum>|null} $parameter
     */
    private function convert(array $parameter, mixed $value): mixed
    {
        $type = $parameter['type'];
        $enum = $parameter['enum'];

        if ($type === 'mixed' || ($value === null && $parameter['nullable']) || ($enum !== null && $value instanceof $enum)) {
            return $value;
        }

        $converted = match ($type) {
            'string' => is_string($value) ? $value : null,
            'int' => self::int($value),
            'float' => self::float($value),
            'bool' => self::bool($value),
        };

        if ($converted === null) {
            throw RowMappingException::invalidValue($this->class, $parameter['name'], self::expected($parameter), $value);
        }

        if ($enum === null) {
            return $converted;
        }

        // A backed enum's plan type is its backing type, int or string.
        /** @var int|string $converted */
        return $enum::tryFrom($converted)
            ?? throw RowMappingException::unknownEnumCase($this->class, $parameter['name'], $enum, $value);
    }

    /**
     * The canonical spelling alone survives the round trip: a leading "-"
     * but no "+", whitespace, leading zero, fraction or exponent, and
     * nothing outside PHP's int range.
     */
    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && (string) (int) $value === $value ? (int) $value : null;
    }

    private static function float(mixed $value): ?float
    {
        $float = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;

        return $float !== null && is_finite($float) ? $float : null;
    }

    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1' => true,
            $value === 0, $value === '0' => false,
            default => null,
        };
    }

    /**
     * @param array{name: string, optional: bool, nullable: bool, type: 'mixed'|'string'|'int'|'float'|'bool', enum: class-string<BackedEnum>|null} $parameter
     */
    private static function expected(array $parameter): string
    {
        $shape = match ($parameter['type']) {
            'mixed' => 'any value',
            'string' => 'a string',
            'int' => 'an int or its canonical decimal string',
            'float' => 'a finite int, float or numeric string',
            'bool' => 'a bool, 0, 1, "0" or "1"',
        };

        if ($parameter['enum'] !== null) {
            $shape = "a {$parameter['enum']} case or its backing value as {$shape}";
        }

        return $parameter['nullable'] ? "{$shape}, or null" : $shape;
    }
}
