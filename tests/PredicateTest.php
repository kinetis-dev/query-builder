<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use InvalidArgumentException;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PredicateTest extends TestCase
{
    private static function mysql(): Query
    {
        return new Query(new FakeMysqlLink());
    }

    private static function postgres(): Query
    {
        return new Query(new FakePostgresLink());
    }

    /** Flat, `a AND b OR c AND d` would bind by SQL precedence instead of by the groups written. */
    public function test_nested_groups_parenthesize_each_level(): void
    {
        $compiled = self::mysql()->table('articles')
            ->where('published', '=', true)
            ->whereGroup(static fn (Conditions $group) => $group
                ->where('author_id', '=', 7)
                ->orWhereGroup(static fn (Conditions $inner) => $inner
                    ->where('featured', '=', true)
                    ->where('score', '>', 10)))
            ->orWhereGroup(static fn (Conditions $group) => $group->where('pinned', '=', true))
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `articles` WHERE `published` = ? AND (`author_id` = ? OR (`featured` = ? AND `score` > ?)) '
            . 'OR (`pinned` = ?)',
            $compiled->sql,
        );
        self::assertSame([true, 7, true, 10, true], $compiled->params);
    }

    public function test_an_empty_group_compiles_to_nothing_at_any_depth(): void
    {
        $compiled = self::mysql()->table('articles')
            ->whereGroup(static fn (Conditions $group) => null)
            ->where('id', '=', 1)
            ->orWhereGroup(static fn (Conditions $group) => $group->whereGroup(static fn (Conditions $inner) => null))
            ->toSelectSql();

        self::assertSame('SELECT * FROM `articles` WHERE `id` = ?', $compiled->sql);
        self::assertSame([1], $compiled->params);
    }

    /**
     * An empty group reads like a filter and compiles to none, so it must
     * not satisfy the predicate update() and delete() require.
     *
     * @param callable(Query): mixed $mutate
     */
    #[DataProvider('mutations')]
    public function test_an_empty_group_does_not_narrow_a_mutation(callable $mutate): void
    {
        $spy = new SpyMysqlLink();
        $query = new Query($spy)->table('articles')->whereGroup(static fn (Conditions $group) => null);

        try {
            $mutate($query);
            self::fail('The mutation was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertStringContainsString('needs a where predicate', $e->getMessage());
        }

        self::assertCount(0, $spy->calls);
    }

    /**
     * @return iterable<string, array{callable(Query): mixed}>
     */
    public static function mutations(): iterable
    {
        yield 'update()' => [static fn (Query $q) => $q->update(['title' => 'x'])];
        yield 'delete()' => [static fn (Query $q) => $q->delete()];
        yield 'increment()' => [static fn (Query $q) => $q->increment('views')];
        yield 'decrement()' => [static fn (Query $q) => $q->decrement('stock')];
    }

    public function test_column_comparisons_quote_both_sides_and_bind_nothing(): void
    {
        $compiled = self::postgres()->table('articles')
            ->whereColumn('articles.updated_at', '>', 'articles.created_at')
            ->orWhereColumn('articles.author_id', '=', 'articles.editor_id')
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM "articles" WHERE "articles"."updated_at" > "articles"."created_at" '
            . 'OR "articles"."author_id" = "articles"."editor_id"',
            $compiled->sql,
        );
        self::assertSame([], $compiled->params);
    }

    public function test_a_column_comparison_operator_is_allow_listed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::mysql()->table('articles')->whereColumn('a', '= b OR 1 = 1 --', 'c');
    }

    public function test_between_variants_bind_both_bounds_in_order(): void
    {
        $compiled = self::mysql()->table('orders')
            ->whereBetween('total', 10, 50)
            ->orWhereBetween('discount', 1, 2)
            ->whereNotBetween('placed_at', '2026-01-01', '2026-02-01')
            ->orWhereNotBetween('items', 3, 4)
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `orders` WHERE `total` BETWEEN ? AND ? OR `discount` BETWEEN ? AND ? '
            . 'AND `placed_at` NOT BETWEEN ? AND ? OR `items` NOT BETWEEN ? AND ?',
            $compiled->sql,
        );
        self::assertSame([10, 50, 1, 2, '2026-01-01', '2026-02-01', 3, 4], $compiled->params);
    }

    public function test_a_null_between_bound_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'whereNotBetween() cannot take null as a bound for "total": a comparison against NULL is never true, '
            . 'so the predicate would match no row.',
        );

        self::mysql()->table('orders')->whereNotBetween('total', 1, null);
    }

    public function test_where_not_in_expands_a_list_and_an_empty_list_is_constant_true(): void
    {
        $compiled = self::mysql()->table('users')
            ->whereNotIn('id', [4, 5])
            ->whereNotIn('role', [])
            ->whereIn('team', [])
            ->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `id` NOT IN (?, ?) AND 1 = 1 AND 1 = 0', $compiled->sql);
        self::assertSame([4, 5], $compiled->params);
    }

    public function test_where_not_in_rejects_a_null_member(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('whereNotIn() cannot take null in the value list for "id"');

        self::mysql()->table('users')->whereNotIn('id', [1, null]);
    }

    public function test_in_and_not_in_subqueries_bind_at_their_sql_position(): void
    {
        $followed = self::postgres()->table('follows')->select('followee_id')->where('follower_id', '=', 3);
        $muted = self::postgres()->table('mutes')->select('user_id')->where('owner_id', '=', 3);

        $compiled = self::postgres()->table('articles')
            ->where('status', '=', 'published')
            ->whereIn('author_id', $followed)
            ->whereNotIn('author_id', $muted, 'OR')
            ->where('id', '>', 100)
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM "articles" WHERE "status" = ? AND "author_id" IN (SELECT "followee_id" FROM "follows" '
            . 'WHERE "follower_id" = ?) OR "author_id" NOT IN (SELECT "user_id" FROM "mutes" WHERE "owner_id" = ?) '
            . 'AND "id" > ?',
            $compiled->sql,
        );
        self::assertSame(['published', 3, 3, 100], $compiled->params);
    }

    /**
     * MySQL 8.4 and MariaDB 11.4 both reject LIMIT inside IN (...), also
     * when it sits in a set operand, so the family's dialect refuses it
     * before execution.
     *
     * @param callable(Query): Query $limit
     */
    #[DataProvider('limitedSubqueries')]
    public function test_the_mysql_family_refuses_a_limited_in_subquery(callable $limit): void
    {
        $sub = $limit(self::mysql()->table('articles')->select('id'));

        try {
            self::mysql()->table('comments')->whereNotIn('article_id', $sub);
            self::fail('whereNotIn() was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertSame(
                'whereNotIn() cannot take a subquery carrying limit() or offset() on MySQL or MariaDB, which reject '
                . 'LIMIT inside an IN subquery. Wrap the limited query with fromSub() and select its column from '
                . 'that instead.',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{callable(Query): Query}>
     */
    public static function limitedSubqueries(): iterable
    {
        yield 'limit()' => [static fn (Query $q) => $q->orderBy('id')->limit(5)];
        yield 'offset() alone' => [static fn (Query $q) => $q->offset(5)];
        yield 'a limited set operand' => [
            static fn (Query $q) => $q->union(self::mysql()->table('drafts')->select('id')->limit(1)),
        ];
    }

    public function test_postgres_admits_a_limited_in_subquery_and_mysql_admits_one_wrapped_in_from_sub(): void
    {
        $postgres = self::postgres()->table('comments')
            ->whereIn('article_id', self::postgres()->table('articles')->select('id')->orderBy('id')->limit(5))
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM "comments" WHERE "article_id" IN (SELECT "id" FROM "articles" ORDER BY "id" ASC LIMIT 5)',
            $postgres->sql,
        );

        $latest = self::mysql()->table('articles')->select('id')->orderBy('id', 'desc')->limit(5);
        $mysql = self::mysql()->table('comments')
            ->whereIn('article_id', self::mysql()->fromSub($latest, 'latest')->select('latest.id'))
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `comments` WHERE `article_id` IN (SELECT `latest`.`id` FROM (SELECT `id` FROM `articles` '
            . 'ORDER BY `id` DESC LIMIT 5) AS `latest`)',
            $mysql->sql,
        );
    }

    public function test_exists_variants_compile_correlated_subqueries(): void
    {
        $favorited = self::mysql()->table('favorites')
            ->whereColumn('favorites.article_id', '=', 'articles.id')
            ->where('favorites.user_id', '=', 9);
        $reported = self::mysql()->table('reports')->whereColumn('reports.article_id', '=', 'articles.id');

        $compiled = self::mysql()->table('articles')
            ->whereExists($favorited)
            ->orWhereNotExists($reported)
            ->whereNotExists($reported)
            ->orWhereExists($favorited)
            ->toSelectSql();

        $favoritedSql = '(SELECT * FROM `favorites` WHERE `favorites`.`article_id` = `articles`.`id` AND `favorites`.`user_id` = ?)';
        $reportedSql = '(SELECT * FROM `reports` WHERE `reports`.`article_id` = `articles`.`id`)';

        self::assertSame(
            "SELECT * FROM `articles` WHERE EXISTS {$favoritedSql} OR NOT EXISTS {$reportedSql} "
            . "AND NOT EXISTS {$reportedSql} OR EXISTS {$favoritedSql}",
            $compiled->sql,
        );
        self::assertSame([9, 9], $compiled->params);
    }

    public function test_a_correlated_predicate_compiles_into_update_and_delete(): void
    {
        $spy = new PreparingSpyMysqlLink();
        $favorited = new Query($spy)->table('favorites')
            ->whereColumn('favorites.article_id', '=', 'articles.id')
            ->where('favorites.user_id', '=', 9);

        new Query($spy)->table('articles')->whereExists($favorited)->update(['featured' => true]);
        new Query($spy)->table('comments')
            ->whereIn('article_id', new Query($spy)->table('articles')->select('id')->where('author_id', '=', 4))
            ->delete();

        self::assertSame(
            'UPDATE `articles` SET `featured` = ? WHERE EXISTS (SELECT * FROM `favorites` WHERE '
            . '`favorites`.`article_id` = `articles`.`id` AND `favorites`.`user_id` = ?)',
            $spy->calls[0]->sql,
        );
        self::assertSame([true, 9], $spy->calls[0]->params);
        self::assertSame(
            'DELETE FROM `comments` WHERE `article_id` IN (SELECT `id` FROM `articles` WHERE `author_id` = ?)',
            $spy->calls[1]->sql,
        );
        self::assertSame([4], $spy->calls[1]->params);
    }

    /** The parent keeps the compiled snapshot, not the mutable Query. */
    public function test_changing_an_attached_query_does_not_change_the_parent(): void
    {
        $followed = self::mysql()->table('follows')->select('followee_id')->where('follower_id', '=', 3);
        $parent = self::mysql()->table('articles')
            ->whereIn('author_id', $followed)
            ->whereGroup(static fn (Conditions $group) => $group->whereExists($followed));

        $before = $parent->toSelectSql();
        $followed->where('muted', '=', false)->select('id');

        self::assertSame($before->sql, $parent->toSelectSql()->sql);
        self::assertSame([3, 3], $parent->toSelectSql()->params);
        self::assertStringNotContainsString('muted', $parent->toSelectSql()->sql);
    }

    /** A clone owns its own predicates in both directions, groups included. */
    public function test_a_clone_does_not_share_predicate_state(): void
    {
        $original = self::mysql()->table('articles')->where('published', '=', true)->groupBy('author_id');
        $copy = clone $original;

        $copy->whereGroup(static fn (Conditions $group) => $group->where('score', '>', 5))->having('author_id', '!=', 1);
        $original->orWhere('pinned', '=', true);

        self::assertSame(
            'SELECT * FROM `articles` WHERE `published` = ? OR `pinned` = ? GROUP BY `author_id`',
            $original->toSelectSql()->sql,
        );
        self::assertSame(
            'SELECT * FROM `articles` WHERE `published` = ? AND (`score` > ?) GROUP BY `author_id` HAVING `author_id` != ?',
            $copy->toSelectSql()->sql,
        );
    }

    /**
     * Raw text anywhere inside an attached query can carry a "?" that is
     * not a placeholder, so it must send the whole statement to execute()
     * even when every bound value is an inlinable int.
     *
     * @param callable(Query, Query): Query $attach
     */
    #[DataProvider('attachments')]
    public function test_raw_sql_inside_an_attached_query_disables_inlining(callable $attach): void
    {
        $spy = new SpyMysqlLink();
        $raw = new Query($spy)->table('favorites')->select('article_id')->whereRaw('user_id = ?', [9]);
        $attach(new Query($spy)->table('articles')->where('id', '>', 1), $raw)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    /**
     * The same attachment without raw text inlines, so the test above
     * fails for the reason it names rather than for the attachment itself.
     *
     * @param callable(Query, Query): Query $attach
     */
    #[DataProvider('attachments')]
    public function test_a_structured_attached_query_still_inlines(callable $attach): void
    {
        $spy = new SpyMysqlLink();
        $structured = new Query($spy)->table('favorites')->select('article_id')->where('user_id', '=', 9);
        $attach(new Query($spy)->table('articles')->where('id', '>', 1), $structured)->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertStringNotContainsString('?', $spy->calls[0]->sql);
    }

    /**
     * @return iterable<string, array{callable(Query, Query): Query}>
     */
    public static function attachments(): iterable
    {
        yield 'whereIn()' => [static fn (Query $parent, Query $sub) => $parent->whereIn('id', $sub)];
        yield 'whereExists() inside a group' => [
            static fn (Query $parent, Query $sub) => $parent->whereGroup(static fn (Conditions $g) => $g->whereExists($sub)),
        ];
        yield 'selectSub()' => [static fn (Query $parent, Query $sub) => $parent->selectSub($sub->limit(1), 'favorite')];
        yield 'fromSub()' => [static fn (Query $parent, Query $sub) => $parent->fromSub($sub, 'f')];
        yield 'joinSub()' => [
            static fn (Query $parent, Query $sub) => $parent->joinSub($sub, 'f', static fn (Conditions $on) => $on->whereColumn('f.article_id', '=', 'articles.id')),
        ];
        yield 'with()' => [static fn (Query $parent, Query $sub) => $parent->with('f', $sub)];
        yield 'union()' => [static fn (Query $parent, Query $sub) => $parent->select('id')->union($sub)];
        yield 'joinOn() raw predicate' => [
            static fn (Query $parent, Query $sub) => $parent->joinOn('users', static fn (Conditions $on) => $on->whereIn('users.id', $sub)),
        ];
    }

    /**
     * @param callable(Query, Query): mixed $attach
     */
    #[DataProvider('everyAttachment')]
    public function test_a_query_for_another_dialect_is_refused(callable $attach, string $method): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            "{$method} was given a Query built on a link to a different kind of database. A subquery compiles "
            . "into its parent's SQL, so build it on a link of the same kind.",
        );

        $attach(self::mysql()->table('articles'), self::postgres()->table('favorites')->select('article_id'));
    }

    /**
     * @return iterable<string, array{callable(Query, Query): mixed, string}>
     */
    public static function everyAttachment(): iterable
    {
        yield 'whereIn()' => [static fn (Query $p, Query $s) => $p->whereIn('id', $s), 'whereIn()'];
        yield 'whereNotIn()' => [static fn (Query $p, Query $s) => $p->whereNotIn('id', $s), 'whereNotIn()'];
        yield 'whereExists()' => [static fn (Query $p, Query $s) => $p->whereExists($s), 'whereExists()'];
        yield 'orWhereNotExists()' => [static fn (Query $p, Query $s) => $p->orWhereNotExists($s), 'orWhereNotExists()'];
        yield 'selectSub()' => [static fn (Query $p, Query $s) => $p->selectSub($s, 'x'), 'selectSub()'];
        yield 'fromSub()' => [static fn (Query $p, Query $s) => $p->fromSub($s, 'x'), 'fromSub()'];
        yield 'joinSub()' => [
            static fn (Query $p, Query $s) => $p->joinSub($s, 'x', static fn (Conditions $on) => $on->whereColumn('a', '=', 'b')),
            'joinSub()',
        ];
        yield 'with()' => [static fn (Query $p, Query $s) => $p->with('x', $s), 'with()'];
        yield 'withRecursive()' => [static fn (Query $p, Query $s) => $p->withRecursive('x', $s), 'withRecursive()'];
        yield 'union()' => [static fn (Query $p, Query $s) => $p->union($s), 'union()'];
        yield 'intersect()' => [static fn (Query $p, Query $s) => $p->intersect($s), 'intersect()'];
        yield 'except()' => [static fn (Query $p, Query $s) => $p->except($s), 'except()'];
        yield 'insertUsing()' => [static fn (Query $p, Query $s) => $p->insertUsing(['article_id'], $s), 'insertUsing()'];
    }

    public function test_a_query_carrying_a_cte_cannot_be_embedded(): void
    {
        $withCte = self::mysql()->with('recent', self::mysql()->table('articles'))->table('recent')->select('id');

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            'whereIn() cannot embed a Query carrying with()/withRecursive(). Register common table expressions on '
            . 'the outermost query and refer to them by name.',
        );

        self::mysql()->table('comments')->whereIn('article_id', $withCte);
    }

    public function test_a_locked_query_cannot_be_embedded(): void
    {
        $locked = new Query(new SpyMysqlTransaction())->table('articles')->select('id')->lockForUpdate();

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            'whereExists() cannot embed a Query carrying lockForUpdate()/lockForShare(). A row lock belongs to the '
            . 'outermost select.',
        );

        new Query(new SpyMysqlTransaction())->table('comments')->whereExists($locked);
    }
}
