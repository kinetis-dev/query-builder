<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\PreparingSpyPostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyPostgresLink;
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
        // connection charset/SQL-mode state the dialect deliberately
        // knows nothing about, and the drivers' own binding is safe by
        // construction.
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('name', '=', "O'Brien")->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `name` = ?', $spy->calls[0]->sql);
        self::assertSame(["O'Brien"], $spy->calls[0]->params);
    }

    public function test_a_null_value_anywhere_in_the_query_falls_back_to_execute(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 1)->where('deleted_at', '=', null)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_a_float_value_falls_back_to_execute(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('score', '=', 3.14)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_where_raw_disables_inlining_for_the_whole_query_even_with_only_int_params(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->where('id', '=', 1)->whereRaw('extra = ?', [2])->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_select_raw_disables_inlining_even_for_an_otherwise_all_int_query(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->selectRaw('COUNT(*) as c')->where('id', '=', 1)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_order_by_raw_disables_inlining_even_for_an_otherwise_all_int_query(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('items')->orderByRaw('RAND()')->where('id', '=', 1)->get();

        self::assertSame('execute', $spy->calls[0]->method);
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
