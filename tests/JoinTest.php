<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use InvalidArgumentException;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JoinTest extends TestCase
{
    public function test_table_and_join_aliases_are_quoted_identifiers(): void
    {
        $build = static fn (Query $q): Query => $q->table('articles', as: 'a')
            ->join('users', 'u.id', '=', 'a.author_id', as: 'u')
            ->leftJoin('images', 'i.article_id', '=', 'a.id', as: 'i')
            ->select('a.title', 'u.username');

        self::assertSame(
            'SELECT `a`.`title`, `u`.`username` FROM `articles` AS `a` INNER JOIN `users` AS `u` ON `u`.`id` = '
            . '`a`.`author_id` LEFT JOIN `images` AS `i` ON `i`.`article_id` = `a`.`id`',
            $build(new Query(new FakeMysqlLink()))->toSelectSql()->sql,
        );
        self::assertSame(
            'SELECT "a"."title", "u"."username" FROM "articles" AS "a" INNER JOIN "users" AS "u" ON "u"."id" = '
            . '"a"."author_id" LEFT JOIN "images" AS "i" ON "i"."article_id" = "a"."id"',
            $build(new Query(new FakePostgresLink()))->toSelectSql()->sql,
        );
    }

    /** An ON clause's bindings precede WHERE's, whichever was called first. */
    public function test_a_compound_join_reuses_the_predicate_vocabulary(): void
    {
        $compiled = new Query(new FakePostgresLink())->table('articles')
            ->where('articles.status', '=', 'published')
            ->joinOn(
                'follows',
                static fn (Conditions $on) => $on
                    ->whereColumn('follows.followee_id', '=', 'articles.author_id')
                    ->where('follows.follower_id', '=', 5)
                    ->whereGroup(static fn (Conditions $g) => $g->where('follows.muted', '=', null)->orWhere('follows.muted', '=', false)),
                'left',
            )
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM "articles" LEFT JOIN "follows" ON "follows"."followee_id" = "articles"."author_id" '
            . 'AND "follows"."follower_id" = ? AND ("follows"."muted" IS NULL OR "follows"."muted" = ?) '
            . 'WHERE "articles"."status" = ?',
            $compiled->sql,
        );
        self::assertSame([5, false, 'published'], $compiled->params);
    }

    public function test_a_subquery_join_binds_its_source_before_its_on_clause(): void
    {
        $totals = new Query(new FakeMysqlLink())->table('comments')
            ->select('article_id')
            ->selectRaw('COUNT(*) AS total')
            ->where('approved', '=', true)
            ->groupBy('article_id');

        $compiled = new Query(new FakeMysqlLink())->table('articles')
            ->joinSub(
                $totals,
                'c',
                static fn (Conditions $on) => $on->whereColumn('c.article_id', '=', 'articles.id')->where('c.total', '>', 2),
                'RIGHT',
            )
            ->where('articles.id', '>', 10)
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `articles` RIGHT JOIN (SELECT `article_id`, COUNT(*) AS total FROM `comments` WHERE '
            . '`approved` = ? GROUP BY `article_id`) AS `c` ON `c`.`article_id` = `articles`.`id` AND `c`.`total` > ? '
            . 'WHERE `articles`.`id` > ?',
            $compiled->sql,
        );
        self::assertSame([true, 2, 10], $compiled->params);
    }

    public function test_a_cross_join_takes_no_on_clause(): void
    {
        self::assertSame(
            'SELECT * FROM `sizes` CROSS JOIN `colors` AS `c` CROSS JOIN `materials`',
            new Query(new FakeMysqlLink())->table('sizes')->crossJoin('colors', as: 'c')->crossJoin('materials')
                ->toSelectSql()->sql,
        );
    }

    public function test_a_join_callback_that_adds_no_predicate_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A INNER JOIN needs at least one ON predicate. Use crossJoin() to join every row with every row.',
        );

        new Query(new FakeMysqlLink())->table('articles')->joinOn('users', static fn (Conditions $on) => null);
    }

    public function test_a_join_sub_type_is_allow_listed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Join type "FULL" is not allowed. Use one of: INNER, LEFT, RIGHT.');

        new Query(new FakeMysqlLink())->table('articles')->joinSub(
            new Query(new FakeMysqlLink())->table('users'),
            'u',
            static fn (Conditions $on) => $on->whereColumn('u.id', '=', 'articles.author_id'),
            'FULL',
        );
    }

    /**
     * An aliased mutation compiles differently across the targets, so a
     * table alias is refused for every write rather than dropped.
     *
     * @param callable(Query): mixed $write
     */
    #[DataProvider('writes')]
    public function test_a_table_alias_is_refused_for_writes(callable $write): void
    {
        $spy = new SpyMysqlLink();

        try {
            $write(new Query($spy)->table('articles', as: 'a'));
            self::fail('The write was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertStringContainsString('a table() alias would be dropped from the statement', $e->getMessage());
        }

        self::assertCount(0, $spy->calls);
    }

    /**
     * @return iterable<string, array{callable(Query): mixed}>
     */
    public static function writes(): iterable
    {
        yield 'update()' => [static fn (Query $q) => $q->where('a.id', '=', 1)->update(['title' => 'x'])];
        yield 'delete()' => [static fn (Query $q) => $q->where('a.id', '=', 1)->delete()];
        yield 'increment()' => [static fn (Query $q) => $q->where('a.id', '=', 1)->increment('views')];
        yield 'insert()' => [static fn (Query $q) => $q->insert(['title' => 'x'])];
    }
}
