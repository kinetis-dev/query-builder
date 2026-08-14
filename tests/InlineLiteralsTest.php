<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlConnection;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyPostgresLink;
use PHPUnit\Framework\TestCase;

/**
 * Query::run()'s own dispatch — inline as a literal via query(), or bind
 * via execute() — verified here purely at the "which method, with what
 * final SQL" level, against a spy that records calls instead of talking
 * to a real database. Whether the *escaping itself* is actually safe is
 * verified separately, against real MySQL/Postgres servers and an
 * established SQL-injection payload corpus — this file is about the
 * dispatch decision being correct, not re-proving that.
 */
final class InlineLiteralsTest extends TestCase
{
    public function test_an_all_int_where_is_inlined_via_query_not_execute(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->where('id', '=', 42)->get();

        self::assertCount(1, $spy->calls);
        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `id` = 42', $spy->calls[0]->sql);
    }

    public function test_a_string_where_on_a_safe_charset_is_also_inlined(): void
    {
        $spy = new SpyMysqlConnection('utf8mb4');
        new Query($spy)->table('items')->where('name', '=', "O'Brien")->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame("SELECT * FROM `items` WHERE `name` = 'O\\'Brien'", $spy->calls[0]->sql);
    }

    public function test_a_string_where_on_an_unsafe_charset_falls_back_to_execute(): void
    {
        $spy = new SpyMysqlConnection('gbk');
        new Query($spy)->table('items')->where('name', '=', 'x')->get();

        self::assertSame('execute', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `name` = ?', $spy->calls[0]->sql);
        self::assertSame(['x'], $spy->calls[0]->params);
    }

    public function test_a_null_value_anywhere_in_the_query_falls_back_to_execute(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->where('id', '=', 1)->where('deleted_at', '=', null)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_a_float_value_falls_back_to_execute(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->where('score', '=', 3.14)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_where_raw_disables_inlining_for_the_whole_query_even_with_only_int_params(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->where('id', '=', 1)->whereRaw('extra = ?', [2])->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_select_raw_disables_inlining_even_for_an_otherwise_all_int_query(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->selectRaw('COUNT(*) as c')->where('id', '=', 1)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_order_by_raw_disables_inlining_even_for_an_otherwise_all_int_query(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->orderByRaw('RAND()')->where('id', '=', 1)->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }

    public function test_where_in_is_eligible_for_inlining_like_a_plain_where(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->whereIn('id', [1, 2, 3])->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM `items` WHERE `id` IN (1, 2, 3)', $spy->calls[0]->sql);
    }

    public function test_a_value_containing_a_literal_question_mark_does_not_corrupt_positional_substitution(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')
            ->where('name', '=', 'what?')
            ->where('id', '=', 7)
            ->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame("SELECT * FROM `items` WHERE `name` = 'what?' AND `id` = 7", $spy->calls[0]->sql);
    }

    public function test_insert_is_inlined_when_every_value_is_safe(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->insert(['id' => 1, 'name' => 'alice']);

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame("INSERT INTO `items` (`id`, `name`) VALUES (1, 'alice')", $spy->calls[0]->sql);
    }

    public function test_update_is_inlined_when_every_value_is_safe(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->where('id', '=', 1)->update(['name' => 'bob']);

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame("UPDATE `items` SET `name` = 'bob' WHERE `id` = 1", $spy->calls[0]->sql);
    }

    public function test_delete_is_inlined_when_the_where_clause_is_all_safe_values(): void
    {
        $spy = new SpyMysqlConnection();
        new Query($spy)->table('items')->where('id', '=', 1)->delete();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('DELETE FROM `items` WHERE `id` = 1', $spy->calls[0]->sql);
    }

    public function test_no_where_clause_at_all_still_uses_the_original_zero_params_fast_path(): void
    {
        $spy = new SpyMysqlConnection();
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

    public function test_postgres_string_where_is_inlined_via_quote_literal(): void
    {
        $spy = new SpyPostgresLink();
        new Query($spy)->table('items')->where('name', '=', "O'Brien")->get();

        self::assertSame('query', $spy->calls[0]->method);
        self::assertSame('SELECT * FROM "items" WHERE "name" = \'O\'\'Brien\'', $spy->calls[0]->sql);
    }

    public function test_postgres_where_raw_disables_inlining(): void
    {
        $spy = new SpyPostgresLink();
        new Query($spy)->table('items')->where('id', '=', 1)->whereRaw('extra = ?', [2])->get();

        self::assertSame('execute', $spy->calls[0]->method);
    }
}
