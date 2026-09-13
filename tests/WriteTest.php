<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use InvalidArgumentException;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyPostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\QueuedRowsMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** insert()'s batch form, insertUsing(), insertOrIgnore(), upsert(), increment() and decrement(). */
final class WriteTest extends TestCase
{
    public function test_a_batch_insert_is_one_statement_with_one_tuple_per_row(): void
    {
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('tags')->insert([['name' => 'PHP', 'slug' => 'php'], ['name' => 'SQL', 'slug' => 'sql']]);

        self::assertCount(1, $spy->calls);
        self::assertSame('INSERT INTO `tags` (`name`, `slug`) VALUES (?, ?), (?, ?)', $spy->calls[0]->sql);
        self::assertSame(['PHP', 'php', 'SQL', 'sql'], $spy->calls[0]->params);
    }

    /**
     * @param list<mixed> $rows
     */
    #[DataProvider('mismatchedBatches')]
    public function test_a_batch_whose_rows_disagree_is_refused(array $rows, string $message): void
    {
        $spy = new SpyMysqlLink();

        try {
            new Query($spy)->table('tags')->insert($rows);
            self::fail('insert() was expected to throw.');
        } catch (InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertCount(0, $spy->calls);
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function mismatchedBatches(): iterable
    {
        $order = 'insert() row 1 does not name the same columns in the same order as row 0. Every row of a batch '
            . 'shares one column list.';

        yield 'same columns, other order' => [[['name' => 'a', 'slug' => 'a'], ['slug' => 'b', 'name' => 'b']], $order];
        yield 'a missing column' => [[['name' => 'a', 'slug' => 'a'], ['name' => 'b']], $order];
        yield 'an extra column' => [[['name' => 'a'], ['name' => 'b', 'slug' => 'b']], $order];
        yield 'a row that is not a map' => [[['name' => 'a'], 'b'], 'insert() row 1 must be a non-empty column => value map.'];
        yield 'an empty row' => [[['name' => 'a'], []], 'insert() row 1 must be a non-empty column => value map.'];
    }

    public function test_the_placeholder_ceiling_admits_65535_and_refuses_one_more(): void
    {
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('numbers')->insert(array_map(static fn (int $n): array => ['n' => $n], range(1, 65535)));

        self::assertCount(65535, $spy->calls[0]->params);

        try {
            new Query($spy)->table('pairs')->insert(array_fill(0, 32768, ['a' => 1, 'b' => 2]));
            self::fail('insert() was expected to throw.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'insert() would bind 65536 values in one statement; MySQL, MariaDB and PostgreSQL accept at most 65535. '
                . 'Insert fewer rows per call.',
                $e->getMessage(),
            );
        }

        self::assertCount(1, $spy->calls);
    }

    public function test_insert_get_id_refuses_a_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insertGetId() inserts one row: pass a single column => value map, and insert() for a batch.');

        new Query(new SpyMysqlLink())->table('tags')->insertGetId([['name' => 'a']]);
    }

    public function test_insert_get_id_compiles_per_dialect(): void
    {
        $mysql = new PreparingSpyMysqlLink();
        $postgres = new PreparingSpyPostgresLink();

        new Query($mysql)->table('users')->insertGetId(['email' => 'a@example.com']);
        new Query($postgres)->table('users')->insertGetId(['email' => 'a@example.com'], primaryKey: 'user_id');

        self::assertSame('INSERT INTO `users` (`email`) VALUES (?)', $mysql->calls[0]->sql);
        self::assertSame('INSERT INTO "users" ("email") VALUES (?) RETURNING "user_id"', $postgres->calls[0]->sql);
    }

    /**
     * An insert compiles its table and rows only, so any other state on
     * the Query is refused rather than silently ignored.
     *
     * @param callable(Query): mixed $insert
     */
    #[DataProvider('insertsWithIgnoredState')]
    public function test_an_insert_refuses_state_it_would_drop(callable $insert, string $method, string $clauses): void
    {
        $spy = new SpyMysqlLink();

        try {
            $insert(new Query($spy)->table('tags'));
            self::fail("{$method} was expected to throw.");
        } catch (QueryBuilderException $e) {
            self::assertSame(
                "{$method} compiles the table and the inserted rows only, so {$clauses} would be dropped from the "
                . 'statement. Build the insert on a Query carrying only table().',
                $e->getMessage(),
            );
        }

        self::assertCount(0, $spy->calls);
    }

    /**
     * @return iterable<string, array{callable(Query): mixed, string, string}>
     */
    public static function insertsWithIgnoredState(): iterable
    {
        yield 'insert() with a predicate' => [
            static fn (Query $q) => $q->where('id', '=', 1)->insert(['name' => 'a']),
            'insert()',
            'where predicates',
        ];
        yield 'insertOrIgnore() with order and limit' => [
            static fn (Query $q) => $q->orderBy('id')->limit(1)->insertOrIgnore(['name' => 'a']),
            'insertOrIgnore()',
            'orderBy()/orderByRaw(), limit()',
        ];
        yield 'upsert() with a select' => [
            static fn (Query $q) => $q->select('id')->upsert(['name' => 'a'], ['name'], ['name']),
            'upsert()',
            'select()/selectRaw()/selectSub()/selectExists()',
        ];
        yield 'insertUsing() with its own CTE' => [
            static fn (Query $q) => $q->with('x', new Query(new SpyMysqlLink())->table('y'))
                ->insertUsing(['name'], new Query(new SpyMysqlLink())->table('x')->select('name')),
            'insertUsing()',
            'with()/withRecursive()',
        ];
    }

    public function test_insert_using_places_the_select_cte_inside_the_insert_and_returns_the_count(): void
    {
        $spy = new PreparingSpyMysqlLink();
        $source = new Query($spy)
            ->with('recent', new Query($spy)->table('articles')->select('id', 'author_id')->where('created_at', '>', '2026-01-01'))
            ->table('recent')
            ->select('author_id', 'id')
            ->where('author_id', '!=', 3);

        $inserted = new Query($spy)->table('notifications')->insertUsing(['user_id', 'article_id'], $source);

        self::assertSame(
            'INSERT INTO `notifications` (`user_id`, `article_id`) WITH `recent` AS (SELECT `id`, `author_id` FROM '
            . '`articles` WHERE `created_at` > ?) SELECT `author_id`, `id` FROM `recent` WHERE `author_id` != ?',
            $spy->calls[0]->sql,
        );
        self::assertSame(['2026-01-01', 3], $spy->calls[0]->params);
        self::assertSame(0, $inserted);
    }

    public function test_insert_using_carries_the_sources_raw_state(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('archive')->insertUsing(['id'], new Query($spy)->table('articles')->select('id')->whereRaw('id < ?', [5]));

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_insert_using_needs_a_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insertUsing() needs at least one column to insert into.');

        new Query(new SpyMysqlLink())->table('archive')->insertUsing([], new Query(new SpyMysqlLink())->table('articles'));
    }

    public function test_insert_or_ignore_skips_only_unique_conflicts_per_dialect(): void
    {
        $mysql = new PreparingSpyMysqlLink();
        $postgres = new PreparingSpyPostgresLink();
        $rows = [['user_id' => 1, 'article_id' => 2], ['user_id' => 1, 'article_id' => 3]];

        new Query($mysql)->table('favorites')->insertOrIgnore($rows);
        new Query($postgres)->table('favorites')->insertOrIgnore($rows[0]);

        self::assertSame(
            'INSERT INTO `favorites` (`user_id`, `article_id`) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE `user_id` = `user_id`',
            $mysql->calls[0]->sql,
        );
        self::assertSame([1, 2, 1, 3], $mysql->calls[0]->params);
        self::assertSame(
            'INSERT INTO "favorites" ("user_id", "article_id") VALUES (?, ?) ON CONFLICT DO NOTHING',
            $postgres->calls[0]->sql,
        );
    }

    public function test_insert_or_ignore_returns_the_inserted_row_count(): void
    {
        $link = new QueuedRowsMysqlLink([new BufferedSqlResult([], 1, null, null)]);

        self::assertSame(1, new Query($link)->table('favorites')->insertOrIgnore([['user_id' => 1], ['user_id' => 2]]));
    }

    public function test_upsert_compiles_per_dialect(): void
    {
        $mysql = new PreparingSpyMysqlLink();
        $postgres = new PreparingSpyPostgresLink();
        $row = ['article_id' => 7, 'views' => 10, 'updated_at' => '2026-09-13'];

        new Query($mysql)->table('article_stats')->upsert($row, ['article_id'], ['views', 'updated_at']);
        new Query($postgres)->table('article_stats')->upsert([$row, $row], ['article_id'], ['views', 'updated_at']);

        self::assertSame(
            'INSERT INTO `article_stats` (`article_id`, `views`, `updated_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE '
            . '`views` = VALUES(`views`), `updated_at` = VALUES(`updated_at`)',
            $mysql->calls[0]->sql,
        );
        self::assertSame(
            'INSERT INTO "article_stats" ("article_id", "views", "updated_at") VALUES (?, ?, ?), (?, ?, ?) ON CONFLICT '
            . '("article_id") DO UPDATE SET "views" = EXCLUDED."views", "updated_at" = EXCLUDED."updated_at"',
            $postgres->calls[0]->sql,
        );
        self::assertSame([7, 10, '2026-09-13', 7, 10, '2026-09-13'], $postgres->calls[0]->params);
    }

    /**
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    #[DataProvider('invalidUpsertColumns')]
    public function test_upsert_refuses_columns_it_cannot_honor(array $uniqueBy, array $update, string $message): void
    {
        $spy = new SpyMysqlLink();

        try {
            new Query($spy)->table('article_stats')->upsert(['article_id' => 7, 'views' => 10], $uniqueBy, $update);
            self::fail('upsert() was expected to throw.');
        } catch (InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertCount(0, $spy->calls);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, string}>
     */
    public static function invalidUpsertColumns(): iterable
    {
        yield 'no unique columns' => [[], ['views'], 'upsert() needs at least one column in $uniqueBy.'];
        yield 'no update columns' => [['article_id'], [], 'upsert() needs at least one column in $update.'];
        yield 'an unknown unique column' => [
            ['articleId'],
            ['views'],
            'upsert()\'s $uniqueBy names "articleId", which is not one of the inserted columns.',
        ];
        yield 'an unknown update column' => [
            ['article_id'],
            ['view'],
            'upsert()\'s $update names "view", which is not one of the inserted columns.',
        ];
    }

    public function test_increment_and_decrement_assign_arithmetic_then_extra_columns(): void
    {
        $mysql = new PreparingSpyMysqlLink();
        $postgres = new PreparingSpyPostgresLink();

        new Query($mysql)->table('articles')->where('id', '=', 5)->increment('views', 2, ['viewed_at' => '2026-09-13']);
        new Query($postgres)->table('products')->where('id', '=', 5)->decrement('stock');
        new Query($postgres)->table('accounts')->where('id', '=', 6)->increment('balance', 0.5);

        self::assertSame('UPDATE `articles` SET `views` = `views` + ?, `viewed_at` = ? WHERE `id` = ?', $mysql->calls[0]->sql);
        self::assertSame([2, '2026-09-13', 5], $mysql->calls[0]->params);
        self::assertSame('UPDATE "products" SET "stock" = "stock" - ? WHERE "id" = ?', $postgres->calls[0]->sql);
        self::assertSame([1, 5], $postgres->calls[0]->params);
        self::assertSame([0.5, 6], $postgres->calls[1]->params);
    }

    public function test_increment_refuses_assigning_its_own_column_through_extra(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'increment() cannot also assign "views" through $extra: one statement cannot set a column twice.',
        );

        new Query(new SpyMysqlLink())->table('articles')->where('id', '=', 5)->increment('views', 1, ['views' => 0]);
    }

    public function test_decrement_keeps_update_narrowing(): void
    {
        $spy = new SpyMysqlLink();

        try {
            new Query($spy)->table('products')->where('id', '=', 5)->limit(1)->decrement('stock');
            self::fail('decrement() was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertStringStartsWith('decrement() compiles the table and the WHERE clause only, so limit()', $e->getMessage());
        }

        self::assertCount(0, $spy->calls);
    }
}
