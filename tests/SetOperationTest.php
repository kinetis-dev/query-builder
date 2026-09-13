<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** union()/intersect()/except() and with()/withRecursive(). */
final class SetOperationTest extends TestCase
{
    private static function ids(string $table): Query
    {
        return new Query(new FakeMysqlLink())->table($table)->select('id');
    }

    /**
     * @param callable(Query, Query): Query $combine
     */
    #[DataProvider('operations')]
    public function test_each_operation_parenthesizes_both_operands(callable $combine, string $keyword): void
    {
        self::assertSame(
            "(SELECT `id` FROM `a`) {$keyword} (SELECT `id` FROM `b`)",
            $combine(self::ids('a'), self::ids('b'))->toSelectSql()->sql,
        );
    }

    /**
     * @return iterable<string, array{callable(Query, Query): Query, string}>
     */
    public static function operations(): iterable
    {
        yield 'union' => [static fn (Query $a, Query $b) => $a->union($b), 'UNION'];
        yield 'union all' => [static fn (Query $a, Query $b) => $a->union($b, all: true), 'UNION ALL'];
        yield 'intersect' => [static fn (Query $a, Query $b) => $a->intersect($b), 'INTERSECT'];
        yield 'intersect all' => [static fn (Query $a, Query $b) => $a->intersect($b, all: true), 'INTERSECT ALL'];
        yield 'except' => [static fn (Query $a, Query $b) => $a->except($b), 'EXCEPT'];
        yield 'except all' => [static fn (Query $a, Query $b) => $a->except($b, all: true), 'EXCEPT ALL'];
    }

    /**
     * SQL gives INTERSECT precedence over UNION and EXCEPT. Grouping each
     * earlier result makes `a ∪ b ∩ c − d` read in call order instead.
     */
    public function test_mixed_operations_apply_in_call_order(): void
    {
        $compiled = self::ids('a')
            ->union(self::ids('b'))
            ->intersect(self::ids('c'), all: true)
            ->except(self::ids('d'))
            ->toSelectSql();

        self::assertSame(
            '(((SELECT `id` FROM `a`) UNION (SELECT `id` FROM `b`)) INTERSECT ALL (SELECT `id` FROM `c`)) '
            . 'EXCEPT (SELECT `id` FROM `d`)',
            $compiled->sql,
        );
    }

    public function test_operand_clauses_stay_inside_and_outer_clauses_apply_to_the_result(): void
    {
        $recent = new Query(new FakeMysqlLink())->table('posts')->select('id')
            ->where('author_id', '=', 1)->orderBy('created_at', 'desc')->limit(5);

        $compiled = new Query(new FakeMysqlLink())->table('posts')->select('id')->where('pinned', '=', true)
            ->union($recent, all: true)
            ->orderBy('id')
            ->limit(10)
            ->offset(20)
            ->toSelectSql();

        self::assertSame(
            '(SELECT `id` FROM `posts` WHERE `pinned` = ?) UNION ALL (SELECT `id` FROM `posts` WHERE `author_id` = ? '
            . 'ORDER BY `created_at` DESC LIMIT 5) ORDER BY `id` ASC LIMIT 10 OFFSET 20',
            $compiled->sql,
        );
        self::assertSame([true, 1], $compiled->params);
    }

    public function test_a_combined_query_can_itself_be_an_operand(): void
    {
        self::assertSame(
            '(SELECT `id` FROM `x`) EXCEPT ((SELECT `id` FROM `a`) UNION (SELECT `id` FROM `b`) LIMIT 3)',
            self::ids('x')->except(self::ids('a')->union(self::ids('b'))->limit(3))->toSelectSql()->sql,
        );
    }

    public function test_first_limits_the_combined_result(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('a')->select('id')->union(new Query($spy)->table('b')->select('id'))->orderBy('id')->first();

        self::assertSame('(SELECT `id` FROM `a`) UNION (SELECT `id` FROM `b`) ORDER BY `id` ASC LIMIT 1', $spy->calls[0]->sql);
    }

    public function test_cursor_pagination_refuses_a_combined_query(): void
    {
        $spy = new SpyMysqlLink();

        try {
            new Query($spy)->table('a')->union(new Query($spy)->table('b'))->cursorPaginate(10, null);
            self::fail('cursorPaginate() was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertSame(
                'cursorPaginate() cannot page a union()/intersect()/except() query: its cursor filter would apply to '
                . 'the first operand only. Select from the combined query with fromSub() and paginate that.',
                $e->getMessage(),
            );
        }

        self::assertCount(0, $spy->calls);
    }

    public function test_a_cte_with_column_names_leads_the_statement_and_its_bindings(): void
    {
        $compiled = new Query(new FakeMysqlLink())
            ->table('popular')
            ->where('article_id', '<', 1000)
            ->with(
                'popular',
                new Query(new FakeMysqlLink())->table('articles')->select('id')->where('score', '>', 50),
                ['article_id'],
            )
            ->toSelectSql();

        self::assertSame(
            'WITH `popular` (`article_id`) AS (SELECT `id` FROM `articles` WHERE `score` > ?) SELECT * FROM `popular` '
            . 'WHERE `article_id` < ?',
            $compiled->sql,
        );
        self::assertSame([50, 1000], $compiled->params);
    }

    public function test_one_recursive_cte_makes_the_with_clause_recursive(): void
    {
        $seed = new Query(new FakePostgresLink())->table('categories')->select('id', 'parent_id')->where('id', '=', 4);
        $step = new Query(new FakePostgresLink())->table('categories')
            ->select('categories.id', 'categories.parent_id')
            ->join('tree', 'tree.parent_id', '=', 'categories.id');

        $compiled = new Query(new FakePostgresLink())
            ->with('roots', new Query(new FakePostgresLink())->table('categories')->select('id')->where('parent_id', '=', null))
            ->withRecursive('tree', $seed->union($step, all: true), ['id', 'parent_id'])
            ->table('tree')
            ->select('id')
            ->toSelectSql();

        self::assertSame(
            'WITH RECURSIVE "roots" AS (SELECT "id" FROM "categories" WHERE "parent_id" IS NULL), "tree" ("id", '
            . '"parent_id") AS ((SELECT "id", "parent_id" FROM "categories" WHERE "id" = ?) UNION ALL (SELECT '
            . '"categories"."id", "categories"."parent_id" FROM "categories" INNER JOIN "tree" ON "tree"."parent_id" = '
            . '"categories"."id")) SELECT "id" FROM "tree"',
            $compiled->sql,
        );
        self::assertSame([4], $compiled->params);
    }

    public function test_an_ordinary_cte_alone_is_not_recursive(): void
    {
        self::assertStringStartsWith(
            'WITH `a` AS',
            new Query(new FakeMysqlLink())->with('a', self::ids('b'))->table('a')->toSelectSql()->sql,
        );
    }
}
