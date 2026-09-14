<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\RowValues;
use Kinetis\QueryBuilder\Tests\Fixtures\ArticleStatus;
use Kinetis\QueryBuilder\Tests\Fixtures\ArticleWrite;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\UnitVisibility;
use Kinetis\QueryBuilder\Tests\Fixtures\ValueHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RowValuesTest extends TestCase
{
    /**
     * A backed enum becomes its value, and only initialized public
     * properties — readonly and asymmetric-visibility ones included — are
     * read.
     */
    public function test_reads_initialized_public_properties(): void
    {
        $row = RowValues::fromObject(new ArticleWrite('Hello', ArticleStatus::Published, authorId: 7));

        self::assertSame(
            ['slug' => 'hello-world', 'title' => 'Hello', 'status' => 'published', 'summary' => null, 'authorId' => 7],
            $row,
        );
    }

    public function test_null_is_kept_as_a_written_null(): void
    {
        $row = RowValues::fromObject(new ArticleWrite('Hello', ArticleStatus::Draft, summary: null));

        self::assertArrayHasKey('summary', $row);
        self::assertNull($row['summary']);
        self::assertArrayHasKey('authorId', $row);
        self::assertNull($row['authorId']);
    }

    public function test_rename_changes_only_the_key_and_except_removes(): void
    {
        $row = RowValues::fromObject(
            new ArticleWrite('Hello', ArticleStatus::Draft, summary: 'Short', authorId: 7),
            columns: ['authorId' => 'author_id'],
            except: ['slug'],
        );

        self::assertSame(
            ['title' => 'Hello', 'status' => 'draft', 'summary' => 'Short', 'author_id' => 7],
            $row,
        );
    }

    /**
     * @param array<string, string> $columns
     * @param list<string> $except
     */
    #[DataProvider('invalidMappings')]
    public function test_a_mapping_that_would_hide_a_mistake_is_refused(array $columns, array $except, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RowValues::fromObject(new ArticleWrite('Hello', ArticleStatus::Draft), $columns, $except);
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>, string}>
     */
    public static function invalidMappings(): iterable
    {
        $class = ArticleWrite::class;

        yield 'an unknown rename' => [
            ['authorID' => 'author_id'],
            [],
            "RowValues::fromObject() was given \"authorID\", but {$class} has no initialized public property of that name.",
        ];
        yield 'an unknown exclusion' => [[], ['body'], 'was given "body"'];
        yield 'a private property' => [[], ['editToken'], 'was given "editToken"'];
        yield 'a protected property' => [['reviewNote' => 'note'], [], 'was given "reviewNote"'];
        yield 'an uninitialized property' => [[], ['neverInitialized'], 'was given "neverInitialized"'];
        yield 'renamed and omitted' => [
            ['slug' => 'url_slug'],
            ['slug'],
            "RowValues::fromObject() was asked to both rename and omit \"slug\" of {$class}.",
        ];
        yield 'two properties to one column' => [
            ['title' => 'slug'],
            [],
            "RowValues::fromObject() maps both \"slug\" and \"title\" of {$class} to the column \"slug\".",
        ];
    }

    /**
     * @param callable(): mixed $value
     */
    #[DataProvider('unwritableValues')]
    public function test_an_unwritable_value_names_the_property_and_never_the_value(callable $value, string $kind, string $secret): void
    {
        try {
            RowValues::fromObject(new ValueHolder($value()));
            self::fail('fromObject() was expected to throw.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'RowValues::fromObject() cannot write property "value" of ' . ValueHolder::class . ": {$kind} is not a SQL "
                . 'value. Convert it to null, a bool, an int, a finite float or a string before extracting the row.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{callable(): mixed, string, string}>
     */
    public static function unwritableValues(): iterable
    {
        yield 'a unit enum' => [static fn () => UnitVisibility::Public, 'the unit enum ' . UnitVisibility::class, 'Public'];
        yield 'infinity' => [static fn () => INF, 'a non-finite float', 'INF'];
        yield 'not a number' => [static fn () => NAN, 'a non-finite float', 'NAN'];
        yield 'a date' => [static fn () => new DateTimeImmutable('2031-05-17'), DateTimeImmutable::class, '2031'];
        yield 'an array' => [static fn () => ['secret-token'], 'array', 'secret-token'];
        yield 'a nested object' => [static fn () => new ValueHolder('nested-secret'), ValueHolder::class, 'nested-secret'];
        yield 'a resource' => [static fn () => fopen('php://memory', 'r'), 'resource (stream)', 'php://memory'];
    }

    public function test_scalar_values_pass_through_unchanged(): void
    {
        $object = new stdClass();
        $object->flag = false;
        $object->count = 0;
        $object->ratio = 0.25;
        $object->name = '';

        self::assertSame(['flag' => false, 'count' => 0, 'ratio' => 0.25, 'name' => ''], RowValues::fromObject($object));
    }

    /** An object with nothing to write yields [], and the write terminals keep refusing empty input. */
    public function test_an_object_without_public_properties_yields_an_empty_row_the_writes_still_refuse(): void
    {
        $values = RowValues::fromObject(new stdClass());
        self::assertSame([], $values);

        $spy = new SpyMysqlLink();

        try {
            new Query($spy)->table('articles')->insert($values);
            self::fail('insert() was expected to throw.');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('insert() needs at least one column', $e->getMessage());
        }

        try {
            new Query($spy)->table('articles')->where('id', '=', 1)->update($values);
            self::fail('update() was expected to throw.');
        } catch (InvalidArgumentException | QueryBuilderException $e) {
            self::assertStringStartsWith('update() needs at least one column', $e->getMessage());
        }

        self::assertCount(0, $spy->calls);
    }
}
