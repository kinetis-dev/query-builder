<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use InvalidArgumentException;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyPostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\QueuedRowsMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\QueuedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Projections, grouping, aggregates, the read terminals, and the binding order across every clause. */
final class ProjectionTest extends TestCase
{
    private static function mysql(): Query
    {
        return new Query(new FakeMysqlLink());
    }

    /**
     * Every clause that can bind is called in the reverse of its SQL
     * position; the bindings must still come out in SQL order.
     */
    public function test_bindings_follow_the_emitted_sql_not_the_call_order(): void
    {
        $compiled = self::mysql()
            ->orderByRaw('FIELD(`status`, ?, ?)', ['pinned', 'open'])
            ->union(self::mysql()->table('archive')->where('year', '=', 2020))
            ->havingRaw('COUNT(*) > ?', [2])
            ->groupByRaw('YEAR(`created_at`) + ?', [0])
            ->where('author_id', '=', 7)
            ->joinOn('users', static fn (Conditions $on) => $on->whereColumn('users.id', '=', 'articles.author_id')->where('users.active', '=', true))
            ->fromSub(self::mysql()->table('articles')->where('tenant_id', '=', 3), 'articles')
            ->selectRaw('COUNT(*) + ? AS total', [1])
            ->with('recent', self::mysql()->table('posts')->where('age', '<', 30))
            ->toSelectSql();

        self::assertSame(
            'WITH `recent` AS (SELECT * FROM `posts` WHERE `age` < ?) (SELECT COUNT(*) + ? AS total FROM (SELECT * '
            . 'FROM `articles` WHERE `tenant_id` = ?) AS `articles` INNER JOIN `users` ON `users`.`id` = '
            . '`articles`.`author_id` AND `users`.`active` = ? WHERE `author_id` = ? GROUP BY YEAR(`created_at`) + ? '
            . 'HAVING COUNT(*) > ?) UNION (SELECT * FROM `archive` WHERE `year` = ?) ORDER BY FIELD(`status`, ?, ?)',
            $compiled->sql,
        );
        self::assertSame([30, 1, 3, true, 7, 0, 2, 2020, 'pinned', 'open'], $compiled->params);
    }

    public function test_a_correlated_select_sub_is_selected_under_its_alias(): void
    {
        $favorited = self::mysql()->table('favorites')
            ->selectRaw('COUNT(*)')
            ->whereColumn('favorites.article_id', '=', 'articles.id')
            ->where('favorites.user_id', '=', 4);

        $compiled = self::mysql()->table('articles')->select('articles.id')->selectSub($favorited, 'favorited')
            ->where('articles.status', '=', 'published')->toSelectSql();

        self::assertSame(
            'SELECT `articles`.`id`, (SELECT COUNT(*) FROM `favorites` WHERE `favorites`.`article_id` = `articles`.`id` '
            . 'AND `favorites`.`user_id` = ?) AS `favorited` FROM `articles` WHERE `articles`.`status` = ?',
            $compiled->sql,
        );
        self::assertSame([4, 'published'], $compiled->params);
    }

    public function test_select_exists_projects_an_integer_flag_under_its_alias_in_binding_order(): void
    {
        $favorited = self::mysql()->table('favorites')
            ->whereColumn('favorites.article_id', '=', 'articles.id')
            ->where('favorites.user_id', '=', 4);

        $compiled = self::mysql()->table('articles')
            ->select('articles.id')
            ->selectRaw('? AS one', [1])
            ->selectExists($favorited, 'favorited')
            ->where('articles.status', '=', 'published')
            ->toSelectSql();

        self::assertSame(
            'SELECT `articles`.`id`, ? AS one, CASE WHEN EXISTS (SELECT * FROM `favorites` WHERE `favorites`.`article_id` = '
            . '`articles`.`id` AND `favorites`.`user_id` = ?) THEN 1 ELSE 0 END AS `favorited` FROM `articles` WHERE '
            . '`articles`.`status` = ?',
            $compiled->sql,
        );
        self::assertSame([1, 4, 'published'], $compiled->params);

        $following = new Query(new FakePostgresLink())->table('follows')
            ->whereColumn('follows.followed_id', '=', 'users.id')
            ->where('follows.follower_id', '=', 9);

        $compiled = new Query(new FakePostgresLink())->table('users')
            ->select('users.id')
            ->selectExists($following, 'following')
            ->toSelectSql();

        self::assertSame(
            'SELECT "users"."id", CASE WHEN EXISTS (SELECT * FROM "follows" WHERE "follows"."followed_id" = "users"."id" '
            . 'AND "follows"."follower_id" = ?) THEN 1 ELSE 0 END AS "following" FROM "users"',
            $compiled->sql,
        );
        self::assertSame([9], $compiled->params);
    }

    public function test_distinct_group_by_and_structured_having(): void
    {
        $compiled = new Query(new FakePostgresLink())->table('articles')
            ->distinct()
            ->select('author_id', 'status')
            ->groupBy('author_id', 'status')
            ->having('status', '=', 'published')
            ->orHaving('status', '=', null)
            ->havingRaw('COUNT(*) >= ?', [3])
            ->toSelectSql();

        self::assertSame(
            'SELECT DISTINCT "author_id", "status" FROM "articles" GROUP BY "author_id", "status" HAVING "status" = ? '
            . 'OR "status" IS NULL AND COUNT(*) >= ?',
            $compiled->sql,
        );
        self::assertSame(['published', 3], $compiled->params);
    }

    public function test_an_empty_having_raw_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('havingRaw() needs a SQL fragment: an empty one compiles to an invalid HAVING clause.');

        self::mysql()->table('articles')->groupBy('author_id')->havingRaw(' ');
    }

    /** MySQL-family syntax has no OFFSET without a LIMIT; PostgreSQL has a bare OFFSET. */
    public function test_a_lone_offset_compiles_per_dialect(): void
    {
        self::assertSame(
            'SELECT * FROM `users` ORDER BY `id` ASC LIMIT 18446744073709551615 OFFSET 40',
            self::mysql()->table('users')->orderBy('id')->offset(40)->toSelectSql()->sql,
        );
        self::assertSame(
            'SELECT * FROM "users" ORDER BY "id" ASC OFFSET 40',
            new Query(new FakePostgresLink())->table('users')->orderBy('id')->offset(40)->toSelectSql()->sql,
        );
    }

    /** A plain count carries no projection, so a projection binding must not reach it either. */
    public function test_a_plain_count_drops_the_projection_and_its_bindings(): void
    {
        $spy = new PreparingSpyMysqlLink();

        new Query($spy)->table('articles')
            ->selectRaw('score * ? AS weighted', [2])
            ->selectSub(new Query($spy)->table('tags')->selectRaw('COUNT(*)')->where('kind', '=', 'topic'), 'tags')
            ->where('status', '=', 'published')
            ->orderByRaw('score * ? DESC', [3])
            ->limit(5)
            ->offset(10)
            ->count();

        self::assertSame('SELECT COUNT(*) AS aggregate FROM `articles` WHERE `status` = ?', $spy->calls[0]->sql);
        self::assertSame(['published'], $spy->calls[0]->params);
    }

    /**
     * @param callable(Query): Query $build
     * @param list<mixed> $params
     */
    #[DataProvider('logicalResults')]
    public function test_a_distinct_grouped_or_combined_count_counts_the_logical_result(callable $build, string $sql, array $params): void
    {
        $spy = new PreparingSpyMysqlLink();
        $build(new Query($spy))->count();

        self::assertSame($sql, $spy->calls[0]->sql);
        self::assertSame($params, $spy->calls[0]->params);
    }

    /**
     * @return iterable<string, array{callable(Query): Query, string, list<mixed>}>
     */
    public static function logicalResults(): iterable
    {
        yield 'distinct' => [
            static fn (Query $q) => $q->table('articles')->distinct()->select('author_id')
                ->where('status', '=', 'published')->orderBy('author_id')->limit(3),
            'SELECT COUNT(*) AS aggregate FROM (SELECT DISTINCT `author_id` FROM `articles` WHERE `status` = ?) AS aggregate_source',
            ['published'],
        ];
        yield 'grouped with HAVING' => [
            static fn (Query $q) => $q->table('comments')->select('article_id')->selectRaw('COUNT(*) + ? AS total', [0])
                ->where('approved', '=', true)->groupBy('article_id')->havingRaw('COUNT(*) >= ?', [3]),
            'SELECT COUNT(*) AS aggregate FROM (SELECT `article_id`, COUNT(*) + ? AS total FROM `comments` WHERE '
            . '`approved` = ? GROUP BY `article_id` HAVING COUNT(*) >= ?) AS aggregate_source',
            [0, true, 3],
        ];
        yield 'set operation, outer order and limit dropped' => [
            static fn (Query $q) => $q->table('articles')->select('id')->where('pinned', '=', true)
                ->union(new Query(new PreparingSpyMysqlLink())->table('drafts')->select('id')->where('owner', '=', 2)->limit(4), all: true)
                ->orderBy('id')->limit(10),
            'SELECT COUNT(*) AS aggregate FROM ((SELECT `id` FROM `articles` WHERE `pinned` = ?) UNION ALL (SELECT `id` '
            . 'FROM `drafts` WHERE `owner` = ? LIMIT 4)) AS aggregate_source',
            [true, 2],
        ];
        yield 'CTE bindings still lead' => [
            static fn (Query $q) => $q->with('recent', new Query(new PreparingSpyMysqlLink())->table('articles')->where('age', '<', 7))
                ->table('recent')->distinct()->select('author_id')->where('score', '>', 1),
            'WITH `recent` AS (SELECT * FROM `articles` WHERE `age` < ?) SELECT COUNT(*) AS aggregate FROM (SELECT '
            . 'DISTINCT `author_id` FROM `recent` WHERE `score` > ?) AS aggregate_source',
            [7, 1],
        ];
    }

    /**
     * @param callable(Query): mixed $aggregate
     */
    #[DataProvider('aggregates')]
    public function test_aggregates_compile_over_the_filtered_rows_and_return_the_scalar(callable $aggregate, string $sql): void
    {
        $link = new PreparingSpyPostgresLink();
        $aggregate(new Query($link)->table('orders')->where('status', '=', 'paid')->orderBy('id')->limit(2));

        self::assertSame($sql, $link->calls[0]->sql);
        self::assertSame(['paid'], $link->calls[0]->params);
    }

    /**
     * @return iterable<string, array{callable(Query): mixed, string}>
     */
    public static function aggregates(): iterable
    {
        yield 'sum()' => [static fn (Query $q) => $q->sum('total'), 'SELECT SUM("total") AS aggregate FROM "orders" WHERE "status" = ?'];
        yield 'min()' => [static fn (Query $q) => $q->min('total'), 'SELECT MIN("total") AS aggregate FROM "orders" WHERE "status" = ?'];
        yield 'max()' => [static fn (Query $q) => $q->max('placed_at'), 'SELECT MAX("placed_at") AS aggregate FROM "orders" WHERE "status" = ?'];
        yield 'avg()' => [static fn (Query $q) => $q->avg('total'), 'SELECT AVG("total") AS aggregate FROM "orders" WHERE "status" = ?'];
    }

    public function test_an_aggregate_returns_the_driver_value_or_null(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([['aggregate' => '12.50']]),
            new QueuedSqlResult([['aggregate' => 3]]),
            new QueuedSqlResult([['aggregate' => null]]),
        ]);

        self::assertSame('12.50', new Query($link)->table('orders')->sum('total'));
        self::assertSame(3, new Query($link)->table('orders')->max('items'));
        self::assertNull(new Query($link)->table('orders')->avg('total'));
    }

    public function test_exists_wraps_the_whole_select_and_reads_the_flag(): void
    {
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('articles')->where('slug', '=', 'hello')->orderBy('id')->limit(1)->exists();

        self::assertSame(
            'SELECT CASE WHEN EXISTS (SELECT * FROM `articles` WHERE `slug` = ? ORDER BY `id` ASC LIMIT 1) THEN 1 ELSE 0 '
            . 'END AS aggregate',
            $spy->calls[0]->sql,
        );

        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([['aggregate' => 1]]),
            new QueuedSqlResult([['aggregate' => '0']]),
            new QueuedSqlResult([['aggregate' => '1']]),
        ]);

        self::assertTrue(new Query($link)->table('articles')->exists());
        self::assertFalse(new Query($link)->table('articles')->exists());
        self::assertTrue(new Query($link)->table('articles')->exists());
    }

    public function test_value_reads_one_column_of_the_first_row_without_changing_the_projection(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([['id' => 3, 'email' => 'a@example.com']]),
            new QueuedSqlResult([]),
        ]);

        self::assertSame('a@example.com', new Query($link)->table('users')->where('id', '=', 3)->value('email'));
        self::assertNull(new Query($link)->table('users')->where('id', '=', 4)->value('email'));
        self::assertSame('SELECT * FROM `users` WHERE `id` = 3 LIMIT 1', $link->calls[0]->sql);
    }

    public function test_pluck_returns_one_column_per_row_in_order(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([['email' => 'b@example.com'], ['email' => 'a@example.com']]),
        ]);

        self::assertSame(
            ['b@example.com', 'a@example.com'],
            new Query($link)->table('users')->select('email')->orderBy('name')->pluck('email'),
        );
    }

    public function test_pluck_refuses_a_column_the_row_does_not_carry(): void
    {
        $link = new QueuedRowsMysqlLink([new QueuedSqlResult([['email' => 'a@example.com']])]);

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            'pluck() could not read "users.email" from the returned row. It reads the column by its name in the '
            . 'result, where a qualified column arrives under its last segment: select the column under the name '
            . 'you pass.',
        );

        new Query($link)->table('users')->select('users.email')->pluck('users.email');
    }

    /**
     * @param callable(Query): mixed $read
     */
    #[DataProvider('readTerminals')]
    public function test_a_read_terminal_leaves_the_callers_builder_unchanged(callable $read): void
    {
        $query = new Query(new QueuedRowsMysqlLink([
            new QueuedSqlResult([['id' => 1, 'aggregate' => 1]]),
            new QueuedSqlResult([['id' => 1, 'aggregate' => 1]]),
        ]))->table('users')->where('active', '=', true)->orderBy('id');

        $before = $query->toSelectSql();
        $read($query);

        self::assertSame($before->sql, $query->toSelectSql()->sql);
        self::assertSame($before->params, $query->toSelectSql()->params);
    }

    /**
     * @return iterable<string, array{callable(Query): mixed}>
     */
    public static function readTerminals(): iterable
    {
        yield 'value()' => [static fn (Query $q) => $q->value('id')];
        yield 'pluck()' => [static fn (Query $q) => $q->pluck('id')];
        yield 'exists()' => [static fn (Query $q) => $q->exists()];
        yield 'count()' => [static fn (Query $q) => $q->count()];
        yield 'sum()' => [static fn (Query $q) => $q->sum('id')];
    }

    public function test_get_hydrates_dtos_only_when_a_class_is_given(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([['id' => 1, 'name' => 'n1', 'label' => 'l1']]),
            new QueuedSqlResult([['id' => 1, 'name' => 'n1', 'label' => 'l1']]),
        ]);

        self::assertSame([['id' => 1, 'name' => 'n1', 'label' => 'l1']], new Query($link)->table('items')->get());

        $item = new Query($link)->table('items')->first(Fixtures\CursorReviewItem::class);
        self::assertInstanceOf(Fixtures\CursorReviewItem::class, $item);
        self::assertSame('l1', $item->label);
    }

    public function test_insert_get_id_still_reads_a_generated_key_through_the_dialect(): void
    {
        $link = new QueuedRowsMysqlLink([new BufferedSqlResult([], 1, null, 42)]);

        self::assertSame(42, new Query($link)->table('users')->insertGetId(['email' => 'a@example.com']));
    }
}
