<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Closure;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyPostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyPostgresLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Query::run()'s own dispatch — inline as a literal via query(), or bind
 * via execute() — verified here purely at the "which method, with what
 * final SQL" level, against a spy that records calls instead of talking
 * to a real database. Only charset-independent literals (ints, bools)
 * are ever inlined; strings and everything else bind as real
 * parameters through the driver.
 */
final class InlineLiteralsTest extends TestCase
{
    public function test_an_all_int_where_is_inlined_via_query_not_execute(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 42)->get();

        self::assertCount(1, $spy->calls);
        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `id` = 42', $spy->calls[0]->sql);
    }

    public function test_a_string_where_always_falls_back_to_execute(): void
    {
        // Strings are never inlined: a safe string literal depends on
        // connection charset/SQL-mode state the dialect does not know,
        // and the drivers' own binding is safe by construction.
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('name', '=', "O'Brien")->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `name` = ?', $spy->calls[0]->sql);
        self::assertSame(["O'Brien"], $spy->calls[0]->params);
    }

    /**
     * A null predicate compiles to IS NULL and binds nothing, but a null
     * *value* is still an ordinary bound parameter, and one uninlinable
     * value sends the whole statement down the execute() path.
     */
    public function test_an_inserted_null_value_binds(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->insert(['id' => 1, 'deleted_at' => null]);

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('INSERT INTO `items` (`id`, `deleted_at`) VALUES (?, ?)', $spy->calls[0]->sql);
        self::assertSame([1, null], $spy->calls[0]->params);
    }

    public function test_an_updated_null_value_binds_while_a_null_predicate_does_not(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('deleted_at', '=', null)->update(['note' => null]);

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('UPDATE `items` SET `note` = ? WHERE `deleted_at` IS NULL', $spy->calls[0]->sql);
        self::assertSame([null], $spy->calls[0]->params);
    }

    /** A predicate that binds nothing at all still takes the zero-params query() path. */
    public function test_a_null_predicate_alone_takes_query(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('deleted_at', '=', null)->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `deleted_at` IS NULL', $spy->calls[0]->sql);
    }

    public function test_a_float_value_falls_back_to_execute(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('score', '=', 3.14)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_where_raw_with_a_placeholder_disables_inlining_even_with_only_int_params(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 1)->whereRaw('extra = ?', [2])->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_raw_fragments_without_a_question_mark_keep_an_all_int_query_inlined(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->selectRaw('COUNT(*) AS c')->where('id', '=', 1)->orderByRaw('RAND()')->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT COUNT(*) AS c FROM `items` WHERE `id` = 1 ORDER BY RAND()', $spy->calls[0]->sql);
    }

    /**
     * The "?" here was never a placeholder, yet the number of "?" matches
     * the number of values: substituting by position would write 3 into
     * the string literal. Binding leaves the mismatch to the driver.
     */
    public function test_a_question_mark_inside_raw_sql_text_still_binds(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->whereRaw("note <> 'why?'", [3])->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame("SELECT * FROM `items` WHERE note <> 'why?'", $spy->calls[0]->sql);
        self::assertSame([3], $spy->calls[0]->params);
    }

    /**
     * Each raw entry point, and each way raw text reaches a query from
     * another builder, decides the same way: a fragment without "?"
     * keeps the literals inlined, and one with a "?" binds the whole
     * statement even when every value could have been inlined.
     *
     * @param Closure(Query, string, list<mixed>): Query $attach
     */
    #[DataProvider('rawFragmentPositions')]
    public function test_every_raw_position_disables_inlining_only_for_a_question_mark(Closure $attach): void
    {
        $plain = new SpyMysqlLink();
        $attach(new Query($plain)->table('items')->where('id', '=', 7), 'LENGTH(title) > 0', [])->get();

        self::assertSame('query', $plain->calls[0]->method);
        self::assertStringContainsString('`id` = 7', $plain->calls[0]->sql);
        self::assertStringNotContainsString('?', $plain->calls[0]->sql);

        $marked = new SpyMysqlLink();
        $attach(new Query($marked)->table('items')->where('id', '=', 7), 'LENGTH(title) > ?', [3])->get();

        self::assertSame('execute', $marked->calls[0]->method);
        self::assertEqualsCanonicalizing([3, 7], $marked->calls[0]->params);
    }

    /** @return iterable<string, array{Closure(Query, string, list<mixed>): Query}> */
    public static function rawFragmentPositions(): iterable
    {
        $tags = static fn (): Query => new Query(new FakeMysqlLink())->table('tags');

        yield 'selectRaw()' => [static fn (Query $q, string $raw, array $params) => $q->selectRaw($raw, $params)];
        yield 'whereRaw()' => [static fn (Query $q, string $raw, array $params) => $q->whereRaw($raw, $params)];
        yield 'groupByRaw()' => [static fn (Query $q, string $raw, array $params) => $q->groupByRaw($raw, $params)];
        yield 'havingRaw()' => [static fn (Query $q, string $raw, array $params) => $q->havingRaw($raw, $params)];
        yield 'orderByRaw()' => [static fn (Query $q, string $raw, array $params) => $q->orderByRaw($raw, $params)];
        yield 'a whereGroup() predicate' => [
            static fn (Query $q, string $raw, array $params) => $q->whereGroup(static fn (Conditions $g) => $g->whereRaw($raw, $params)),
        ];
        yield 'a whereExists() subquery' => [
            static fn (Query $q, string $raw, array $params) => $q->whereExists($tags()->whereRaw($raw, $params)),
        ];
        yield 'a selectSub() subquery' => [
            static fn (Query $q, string $raw, array $params) => $q->selectSub($tags()->selectRaw($raw, $params), 'x'),
        ];
        yield 'a selectExists() subquery' => [
            static fn (Query $q, string $raw, array $params) => $q->selectExists($tags()->whereRaw($raw, $params), 'x'),
        ];
        yield 'a CTE' => [
            static fn (Query $q, string $raw, array $params) => $q->with('recent', $tags()->whereRaw($raw, $params)),
        ];
        yield 'a join ON clause' => [
            static fn (Query $q, string $raw, array $params) => $q->joinOn('tags', static fn (Conditions $on) => $on->whereRaw($raw, $params)),
        ];
        yield 'a set operand' => [
            static fn (Query $q, string $raw, array $params) => $q->union($tags()->whereRaw($raw, $params)),
        ];
    }

    public function test_where_in_is_eligible_for_inlining_like_a_plain_where(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->whereIn('id', [1, 2, 3])->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `id` IN (1, 2, 3)', $spy->calls[0]->sql);
    }

    public function test_a_value_containing_a_literal_question_mark_does_not_corrupt_positional_binding(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')
            ->where('name', '=', 'what?')
            ->where('id', '=', 7)
            ->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `name` = ? AND `id` = ?', $spy->calls[0]->sql);
        self::assertSame(['what?', 7], $spy->calls[0]->params);
    }

    public function test_insert_with_a_string_value_binds_every_value(): void
    {
        // One uninlinable value sends the whole statement down the
        // execute() path — never a half-inlined mix.
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->insert(['id' => 1, 'name' => 'alice']);

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('INSERT INTO `items` (`id`, `name`) VALUES (?, ?)', $spy->calls[0]->sql);
        self::assertSame([1, 'alice'], $spy->calls[0]->params);
    }

    public function test_update_with_only_int_values_is_inlined(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 1)->update(['score' => 9]);

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('UPDATE `items` SET `score` = 9 WHERE `id` = 1', $spy->calls[0]->sql);
    }

    public function test_delete_is_inlined_when_the_where_clause_is_all_safe_values(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 1)->delete();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('DELETE FROM `items` WHERE `id` = 1', $spy->calls[0]->sql);
    }

    public function test_no_where_clause_at_all_still_uses_the_original_zero_params_fast_path(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items`', $spy->calls[0]->sql);
    }

    public function test_postgres_bool_where_is_inlined_as_true_false(): void
    {
        $spy = new SpyPostgresLink();
        new Query($spy)->table('items')->where('active', '=', true)->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM "items" WHERE "active" = TRUE', $spy->calls[0]->sql);
    }

    public function test_postgres_string_where_always_falls_back_to_execute(): void
    {
        $spy = new SpyPostgresLink();
        new Query($spy)->table('items')->where('name', '=', "O'Brien")->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM "items" WHERE "name" = ?', $spy->calls[0]->sql);
        self::assertSame(["O'Brien"], $spy->calls[0]->params);
    }

    public function test_postgres_where_raw_disables_inlining(): void
    {
        $spy = new SpyPostgresLink();
        new Query($spy)->table('items')->where('id', '=', 1)->whereRaw('extra = ?', [2])->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_a_driver_preferring_prepared_statements_binds_instead_of_inlining(): void
    {
        // Same query as the first test in this file, same dialect, same
        // int: only the marker differs, and it is enough to choose the
        // other path.
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 42)->get();

        self::assertCount(1, $spy->calls);
        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `id` = ?', $spy->calls[0]->sql);
        self::assertSame([42], $spy->calls[0]->params);
    }

    public function test_such_a_driver_binds_alongside_a_raw_fragment_without_a_question_mark(): void
    {
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('items')->selectRaw('COUNT(*) AS c')->where('id', '=', 42)->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT COUNT(*) AS c FROM `items` WHERE `id` = ?', $spy->calls[0]->sql);
        self::assertSame([42], $spy->calls[0]->params);
    }

    public function test_the_same_holds_on_postgres(): void
    {
        $spy = new PreparingSpyPostgresLink();
        new Query($spy)->table('items')->where('id', '=', 42)->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM "items" WHERE "id" = ?', $spy->calls[0]->sql);
        self::assertSame([42], $spy->calls[0]->params);
    }

    public function test_a_query_with_nothing_to_bind_still_takes_query(): void
    {
        // The marker only governs whether a *parameter* is inlined. A
        // query that never had one has nothing to prepare either way.
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('items')->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items`', $spy->calls[0]->sql);
    }

    public function test_an_update_binds_every_value_for_such_a_driver(): void
    {
        $spy = new PreparingSpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 7)->update(['votes' => 3]);

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('UPDATE `items` SET `votes` = ? WHERE `id` = ?', $spy->calls[0]->sql);
        self::assertSame([3, 7], $spy->calls[0]->params);
    }
}
