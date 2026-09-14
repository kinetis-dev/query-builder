<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use DomainException;
use Iterator;
use Kinetis\QueryBuilder\Exception\RowMappingException;
use Kinetis\QueryBuilder\RowMapper;
use Kinetis\QueryBuilder\Tests\Fixtures\ArticleStatus;
use Kinetis\QueryBuilder\Tests\Fixtures\InvariantRow;
use Kinetis\QueryBuilder\Tests\Fixtures\NoConstructorRow;
use Kinetis\QueryBuilder\Tests\Fixtures\Priority;
use Kinetis\QueryBuilder\Tests\Fixtures\RequiredRow;
use Kinetis\QueryBuilder\Tests\Fixtures\TypedRow;
use Kinetis\QueryBuilder\Tests\Fixtures\UnitVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunctionAbstract;

final class RowMapperTest extends TestCase
{
    #[DataProvider('admittedValues')]
    public function test_an_admitted_value_maps_to_the_declared_type(string $column, mixed $value, mixed $expected): void
    {
        $row = RowMapper::for(TypedRow::class)->map([$column => $value]);

        self::assertSame($expected, $row->{$column});
    }

    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function admittedValues(): iterable
    {
        yield 'a string' => ['string', 'text', 'text'];
        yield 'an int' => ['int', -7, -7];
        yield 'a negative decimal string' => ['int', '-42', -42];
        yield 'the largest int as a string' => ['int', (string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'an int into float' => ['float', 3, 3.0];
        yield 'a float' => ['float', 1.5, 1.5];
        yield 'a decimal string into float' => ['float', '1.50', 1.5];
        yield 'a bool' => ['bool', true, true];
        yield 'integer 0 into bool' => ['bool', 0, false];
        yield 'string "1" into bool' => ['bool', '1', true];
        yield 'a backed enum case' => ['status', ArticleStatus::Published, ArticleStatus::Published];
        yield 'a string backing value' => ['status', 'published', ArticleStatus::Published];
        yield 'an int backing value' => ['priority', 2, Priority::High];
        yield 'an int backing value as a string' => ['priority', '2', Priority::High];
        yield 'null into a nullable parameter' => ['nullableInt', null, null];
        yield 'a decimal string into a nullable int' => ['nullableInt', '5', 5];
        yield 'null into mixed' => ['mixed', null, null];
        yield 'an array into mixed' => ['mixed', ['kept' => true], ['kept' => true]];
        yield 'null into an untyped parameter' => ['untyped', null, null];
    }

    #[DataProvider('refusedValues')]
    public function test_a_value_outside_the_declared_type_is_refused(string $column, mixed $value, string $expected): void
    {
        try {
            RowMapper::for(TypedRow::class)->map([$column => $value]);
            self::fail('map() was expected to throw.');
        } catch (RowMappingException $e) {
            self::assertSame(
                "Column \"{$column}\" cannot map onto " . TypedRow::class . ": the parameter takes {$expected}, got "
                . get_debug_type($value) . '.',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function refusedValues(): iterable
    {
        $int = 'an int or its canonical decimal string';
        $float = 'a finite int, float or numeric string';
        $bool = 'a bool, 0, 1, "0" or "1"';

        yield 'an int into string' => ['string', 42, 'a string'];
        yield 'null into a non-nullable parameter' => ['string', null, 'a string'];
        yield 'a leading zero' => ['int', '042', $int];
        yield 'a leading plus' => ['int', '+42', $int];
        yield 'surrounding whitespace' => ['int', ' 42', $int];
        yield 'a decimal form' => ['int', '42.0', $int];
        yield 'an exponent' => ['int', '4e2', $int];
        yield 'a string beyond the int range' => ['int', '9223372036854775808', $int];
        yield 'a float into int' => ['int', 42.0, $int];
        yield 'a bool into int' => ['int', true, $int];
        yield 'a non-numeric string into float' => ['float', 'abc', $float];
        yield 'an infinite float' => ['float', INF, $float];
        yield 'a numeric string overflowing float' => ['float', '1e999', $float];
        yield 'integer 2 into bool' => ['bool', 2, $bool];
        yield 'the text "true" into bool' => ['bool', 'true', $bool];
        yield 'an int into a string-backed enum' => ['status', 1, 'a ' . ArticleStatus::class . ' case or its backing value as a string'];
        yield 'another enum\'s case' => ['status', Priority::High, 'a ' . ArticleStatus::class . ' case or its backing value as a string'];
        yield 'a word into an int-backed enum' => ['priority', 'high', 'a ' . Priority::class . " case or its backing value as {$int}"];
        yield 'a leading zero into a nullable int' => ['nullableInt', '05', "{$int}, or null"];
    }

    #[DataProvider('unknownBackingValues')]
    public function test_a_backing_value_naming_no_case_is_refused(string $column, mixed $value, string $enum): void
    {
        try {
            RowMapper::for(TypedRow::class)->map([$column => $value]);
            self::fail('map() was expected to throw.');
        } catch (RowMappingException $e) {
            self::assertSame(
                "Column \"{$column}\" cannot map onto " . TypedRow::class . ': the ' . get_debug_type($value)
                . " value names no {$enum} case.",
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, mixed, class-string}>
     */
    public static function unknownBackingValues(): iterable
    {
        yield 'a string backing value' => ['status', 'archived', ArticleStatus::class];
        yield 'an int backing value' => ['priority', '9', Priority::class];
    }

    /** A message describes a value by its kind alone: a column can hold a secret. */
    public function test_a_refusal_never_contains_the_value(): void
    {
        foreach ([['int' => 'secret-token'], ['status' => 'secret-token']] as $row) {
            try {
                RowMapper::for(TypedRow::class)->map($row);
                self::fail('map() was expected to throw.');
            } catch (RowMappingException $e) {
                self::assertStringNotContainsString('secret-token', $e->getMessage());
            }
        }
    }

    public function test_names_match_exactly_extra_columns_are_ignored_and_missing_ones_take_defaults(): void
    {
        $row = RowMapper::for(TypedRow::class)->map(['String' => 'wrong case', 'int' => 5, 'unrelated' => 'x']);

        self::assertSame('default', $row->string);
        self::assertSame(5, $row->int);
        self::assertSame(ArticleStatus::Draft, $row->status);
    }

    public function test_a_missing_column_without_a_default_is_refused(): void
    {
        $this->expectException(RowMappingException::class);
        $this->expectExceptionMessage(
            'The row has no "name" column for the constructor parameter of that name on ' . RequiredRow::class
            . ', which has no default.',
        );

        RowMapper::for(RequiredRow::class)->map(['id' => 1]);
    }

    /**
     * Each row would reach the constructor as a PHP error, not a
     * RowMappingException, if the checks ran there instead of before it.
     */
    public function test_a_refused_row_never_reaches_the_constructor(): void
    {
        $mapper = RowMapper::for(InvariantRow::class);

        foreach ([[], ['quantity' => null], ['quantity' => 'many']] as $row) {
            try {
                $mapper->map($row);
                self::fail('map() was expected to throw.');
            } catch (RowMappingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_exception_from_the_constructor_propagates_unchanged(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An order line needs a quantity of at least 1.');

        RowMapper::for(InvariantRow::class)->map(['quantity' => '0']);
    }

    public function test_a_class_without_a_constructor_is_constructed_with_no_arguments(): void
    {
        $row = RowMapper::for(NoConstructorRow::class)->map(['label' => 'from the row']);

        self::assertSame('unset', $row->label);
    }

    #[DataProvider('unsupportedDefinitions')]
    public function test_a_definition_outside_the_admitted_domain_is_refused_by_for(string $class, string $reason): void
    {
        try {
            RowMapper::for($class);
            self::fail('for() was expected to throw.');
        } catch (RowMappingException $e) {
            self::assertStringStartsWith("RowMapper cannot map rows onto {$class}: {$reason}", $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function unsupportedDefinitions(): iterable
    {
        $unsupported = 'which is not string, int, float, bool, mixed or a backed enum.';

        yield 'an interface' => [Countable::class, 'it cannot be instantiated.'];
        yield 'an abstract class' => [ReflectionFunctionAbstract::class, 'it cannot be instantiated.'];
        yield 'an enum' => [UnitVisibility::class, 'it cannot be instantiated.'];
        yield 'a variadic parameter' => [
            (new class {
                public function __construct(int ...$ids) {}
            })::class,
            'constructor parameter "ids" is variadic.',
        ];
        yield 'a by-reference parameter' => [
            (new class {
                public function __construct(?int &$count = null) {}
            })::class,
            'constructor parameter "count" is passed by reference.',
        ];
        yield 'an intersection type' => [
            (new class (new ArrayIterator([])) {
                public function __construct(public Countable&Iterator $items) {}
            })::class,
            'constructor parameter "items" declares the composite type ',
        ];
        yield 'a union type' => [
            (new class (1) {
                public function __construct(public int|string|null $id) {}
            })::class,
            'constructor parameter "id" declares the composite type ',
        ];
        yield 'an array' => [
            (new class ([]) {
                public function __construct(public array $tags) {}
            })::class,
            "constructor parameter \"tags\" declares array, {$unsupported}",
        ];
        yield 'a class that is not an enum' => [
            (new class (null) {
                public function __construct(public ?DateTimeImmutable $at) {}
            })::class,
            "constructor parameter \"at\" declares DateTimeImmutable, {$unsupported}",
        ];
        yield 'a unit enum' => [
            (new class (UnitVisibility::Public) {
                public function __construct(public UnitVisibility $visibility) {}
            })::class,
            'constructor parameter "visibility" declares ' . UnitVisibility::class . ", {$unsupported}",
        ];
    }
}
