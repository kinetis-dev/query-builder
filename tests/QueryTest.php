<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use Kinetis\QueryBuilder\Exception\InvalidPaginationException;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLink;
use Kinetis\QueryBuilder\Tests\Fixtures\QueuedRowsMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\QueuedSqlResult;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueryTest extends TestCase
{
    private function mysql(): Query
    {
        return new Query(new FakeMysqlLink());
    }

    private function postgres(): Query
    {
        return new Query(new FakePostgresLink());
    }

    public function test_dialect_is_auto_detected_from_the_concrete_link(): void
    {
        self::assertSame('SELECT * FROM `users`', $this->mysql()->table('users')->toSelectSql()->sql);
        self::assertSame('SELECT * FROM "users"', $this->postgres()->table('users')->toSelectSql()->sql);
    }

    public function test_an_explicit_dialect_overrides_auto_detection(): void
    {
        $query = new Query(new FakeMysqlLink(), new PostgresDialect());

        self::assertSame('SELECT * FROM "users"', $query->table('users')->toSelectSql()->sql);
    }

    public function test_select_with_specific_columns_quotes_each_one(): void
    {
        $compiled = $this->mysql()->table('users')->select('id', 'email')->toSelectSql();

        self::assertSame('SELECT `id`, `email` FROM `users`', $compiled->sql);
    }

    public function test_a_single_where_binds_its_value(): void
    {
        $compiled = $this->mysql()->table('users')->where('id', '=', 42)->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `id` = ?', $compiled->sql);
        self::assertSame([42], $compiled->params);
    }

    public function test_multiple_wheres_join_with_and_by_default(): void
    {
        $compiled = $this->mysql()->table('users')
            ->where('active', '=', true)
            ->where('age', '>', 18)
            ->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `active` = ? AND `age` > ?', $compiled->sql);
        self::assertSame([true, 18], $compiled->params);
    }

    public function test_or_where_joins_with_or(): void
    {
        $compiled = $this->mysql()->table('users')
            ->where('role', '=', 'admin')
            ->orWhere('role', '=', 'owner')
            ->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `role` = ? OR `role` = ?', $compiled->sql);
        self::assertSame(['admin', 'owner'], $compiled->params);
    }

    public function test_where_in_expands_to_one_placeholder_per_value(): void
    {
        $compiled = $this->mysql()->table('users')->whereIn('id', [1, 2, 3])->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `id` IN (?, ?, ?)', $compiled->sql);
        self::assertSame([1, 2, 3], $compiled->params);
    }

    public function test_where_raw_binds_its_own_params_in_place(): void
    {
        $compiled = $this->mysql()->table('users')->whereRaw('YEAR(created_at) = ?', [2026])->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE YEAR(created_at) = ?', $compiled->sql);
        self::assertSame([2026], $compiled->params);
    }

    /**
     * The one test that actually matters for correctness, not just
     * structure: mixing where()/whereIn()/whereRaw() must produce bindings
     * in the exact order their "?" placeholders appear in the generated
     * SQL — not merely the right values in any order.
     */
    public function test_mixed_where_clauses_produce_bindings_in_the_exact_sql_order(): void
    {
        $compiled = $this->mysql()->table('orders')
            ->where('customer_id', '=', 7)
            ->whereRaw('YEAR(created_at) = ?', [2026])
            ->whereIn('status', ['pending', 'paid'])
            ->where('total', '>', 100)
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `orders` WHERE `customer_id` = ? AND YEAR(created_at) = ? AND `status` IN (?, ?) AND `total` > ?',
            $compiled->sql,
        );
        self::assertSame([7, 2026, 'pending', 'paid', 100], $compiled->params);
    }

    public function test_join_renders_the_on_clause(): void
    {
        $compiled = $this->mysql()->table('orders')
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `orders` INNER JOIN `customers` ON `orders`.`customer_id` = `customers`.`id`',
            $compiled->sql,
        );
    }

    /**
     * A real bug this package's own real-MySQL verification script caught:
     * quoting "orders.total" as one literal backtick-wrapped identifier
     * produces a column genuinely named "orders.total" (which doesn't
     * exist) instead of the qualified reference `orders`.`total` — MySQL
     * rejected it outright with "Unknown column 'orders.total'". Each
     * dotted segment must be quoted separately.
     */
    public function test_a_qualified_column_name_quotes_each_segment_separately(): void
    {
        $mysql = $this->mysql()->table('orders')->select('orders.total', 'users.name')->toSelectSql();
        self::assertSame('SELECT `orders`.`total`, `users`.`name` FROM `orders`', $mysql->sql);

        $postgres = $this->postgres()->table('orders')->select('orders.total', 'users.name')->toSelectSql();
        self::assertSame('SELECT "orders"."total", "users"."name" FROM "orders"', $postgres->sql);
    }

    public function test_select_raw_appends_to_explicit_columns(): void
    {
        $compiled = $this->mysql()->table('orders')
            ->select('day')
            ->selectRaw('COUNT(*) as total')
            ->toSelectSql();

        self::assertSame('SELECT `day`, COUNT(*) as total FROM `orders`', $compiled->sql);
    }

    public function test_select_raw_alone_drops_the_default_wildcard(): void
    {
        $compiled = $this->mysql()->table('orders')->selectRaw('COUNT(*) as total')->toSelectSql();

        self::assertSame('SELECT COUNT(*) as total FROM `orders`', $compiled->sql);
    }

    public function test_left_join_uses_the_left_keyword(): void
    {
        $compiled = $this->mysql()->table('orders')
            ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
            ->toSelectSql();

        self::assertStringContainsString('LEFT JOIN', $compiled->sql);
    }

    public function test_order_by_quotes_the_column_and_uppercases_direction(): void
    {
        $compiled = $this->mysql()->table('users')->orderBy('name', 'desc')->toSelectSql();

        self::assertSame('SELECT * FROM `users` ORDER BY `name` DESC', $compiled->sql);
    }

    public function test_order_by_raw_is_not_quoted(): void
    {
        $compiled = $this->mysql()->table('users')->orderByRaw('RAND()')->toSelectSql();

        self::assertSame('SELECT * FROM `users` ORDER BY RAND()', $compiled->sql);
    }

    public function test_limit_and_offset_are_interpolated_as_plain_integers(): void
    {
        $compiled = $this->mysql()->table('users')->limit(20)->offset(40)->toSelectSql();

        self::assertSame('SELECT * FROM `users` LIMIT 20 OFFSET 40', $compiled->sql);
        self::assertSame([], $compiled->params);
    }

    public function test_count_only_ignores_order_limit_and_offset(): void
    {
        $compiled = $this->mysql()->table('users')
            ->where('active', '=', true)
            ->orderBy('name')
            ->limit(10)
            ->toSelectSql(countOnly: true);

        self::assertSame('SELECT COUNT(*) as aggregate FROM `users` WHERE `active` = ?', $compiled->sql);
        self::assertSame([true], $compiled->params);
    }

    public function test_update_binds_set_values_before_where_bindings(): void
    {
        $compiled = $this->mysql()->table('users')
            ->where('id', '=', 5)
            ->toUpdateSql(['name' => 'Alon', 'email' => 'alon@noy.cc']);

        self::assertSame('UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?', $compiled->sql);
        self::assertSame(['Alon', 'alon@noy.cc', 5], $compiled->params);
    }

    public function test_delete_binds_only_where_values(): void
    {
        $compiled = $this->mysql()->table('users')->where('id', '=', 5)->toDeleteSql();

        self::assertSame('DELETE FROM `users` WHERE `id` = ?', $compiled->sql);
        self::assertSame([5], $compiled->params);
    }

    public function test_postgres_dialect_quotes_identifiers_with_double_quotes(): void
    {
        $compiled = $this->postgres()->table('users')->where('id', '=', 1)->toSelectSql();

        self::assertSame('SELECT * FROM "users" WHERE "id" = ?', $compiled->sql);
    }

    public function test_my_sql_dialect_insert_get_id_query_has_no_returning_clause(): void
    {
        $compiled = (new MySqlDialect())->insertGetIdQuery('users', ['email' => 'alon@noy.cc'], 'id');

        self::assertSame('INSERT INTO `users` (`email`) VALUES (?)', $compiled->sql);
        self::assertSame(['alon@noy.cc'], $compiled->params);
    }

    public function test_postgres_dialect_insert_get_id_query_appends_returning(): void
    {
        $compiled = (new PostgresDialect())->insertGetIdQuery('users', ['email' => 'alon@noy.cc'], 'id');

        self::assertSame('INSERT INTO "users" ("email") VALUES (?) RETURNING "id"', $compiled->sql);
        self::assertSame(['alon@noy.cc'], $compiled->params);
    }

    // --- where()'s operator, orderBy()'s direction, and join()'s type
    // are allow-listed, not interpolated into SQL directly. ---

    public function test_a_where_operator_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('users')->where('id', '= 1 OR 1=1 -- ', 5);
    }

    public function test_an_order_by_direction_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('users')->orderBy('id', 'ASC; DROP TABLE users -- ');
    }

    public function test_a_join_type_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('orders')->join('b', 'a.id', '= 1 UNION SELECT password FROM users WHERE 1', 'b.a_id');
    }

    public function test_a_join_operator_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('orders')->join('customers', 'orders.customer_id', '1=1; DROP TABLE users --', 'customers.id');
    }

    public function test_a_where_boolean_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('users')->where('tenant_id', '=', 7)->where('active', '=', 1, 'OR 1=1 OR');
    }

    public function test_a_where_in_boolean_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('users')->where('tenant_id', '=', 7)->whereIn('id', [1, 2], 'OR 1=1 OR');
    }

    public function test_a_where_raw_boolean_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mysql()->table('users')->where('tenant_id', '=', 7)->whereRaw('active = 1', [], 'OR 1=1 OR');
    }

    public function test_an_allowed_where_operator_still_works_case_insensitively(): void
    {
        $compiled = $this->mysql()->table('users')->where('name', 'like', '%alon%')->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `name` LIKE ?', $compiled->sql);
        self::assertSame(['%alon%'], $compiled->params);
    }

    public function test_an_allowed_join_type_still_works_case_insensitively(): void
    {
        $compiled = $this->mysql()->table('orders')
            ->join('customers', 'orders.customer_id', '=', 'customers.id', 'left')
            ->toSelectSql();

        self::assertSame(
            'SELECT * FROM `orders` LEFT JOIN `customers` ON `orders`.`customer_id` = `customers`.`id`',
            $compiled->sql,
        );
    }

    public function test_an_allowed_where_boolean_still_works_case_insensitively(): void
    {
        $compiled = $this->mysql()->table('users')
            ->where('tenant_id', '=', 7)
            ->where('active', '=', 1, 'or')
            ->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `tenant_id` = ? OR `active` = ?', $compiled->sql);
        self::assertSame([7, 1], $compiled->params);
    }

    public function test_a_postgres_where_boolean_injection_attempt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->postgres()->table('users')->where('tenant_id', '=', 7)->where('active', '=', 1, 'OR 1=1 OR');
    }

    public function test_where_in_with_an_empty_array_compiles_to_a_constant_false_predicate(): void
    {
        $compiled = $this->mysql()->table('users')->whereIn('id', [])->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE 1 = 0', $compiled->sql);
        self::assertSame([], $compiled->params);
    }

    public function test_where_in_with_an_empty_array_mixed_with_another_where_still_binds_correctly(): void
    {
        $compiled = $this->mysql()->table('users')
            ->where('active', '=', true)
            ->whereIn('id', [])
            ->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `active` = ? AND 1 = 0', $compiled->sql);
        self::assertSame([true], $compiled->params);
    }

    public function test_where_in_with_values_is_unaffected(): void
    {
        $compiled = $this->mysql()->table('users')->whereIn('id', [1, 2, 3])->toSelectSql();

        self::assertSame('SELECT * FROM `users` WHERE `id` IN (?, ?, ?)', $compiled->sql);
        self::assertSame([1, 2, 3], $compiled->params);
    }

    public function test_a_qualified_wildcard_leaves_the_star_segment_unquoted(): void
    {
        self::assertSame(
            'SELECT `orders`.* FROM `orders`',
            $this->mysql()->table('orders')->select('orders.*')->toSelectSql()->sql,
        );
        self::assertSame(
            'SELECT "orders".* FROM "orders"',
            $this->postgres()->table('orders')->select('orders.*')->toSelectSql()->sql,
        );
    }

    public function test_a_qualified_wildcard_can_be_combined_with_other_columns(): void
    {
        self::assertSame(
            'SELECT `orders`.*, `users`.`name` FROM `orders`',
            $this->mysql()->table('orders')->select('orders.*', 'users.name')->toSelectSql()->sql,
        );
    }

    public function test_limit_rejects_a_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit() must be 0 or greater, got -1.');

        $this->mysql()->table('users')->limit(-1);
    }

    public function test_limit_of_zero_is_allowed(): void
    {
        self::assertSame(
            'SELECT * FROM `users` LIMIT 0',
            $this->mysql()->table('users')->limit(0)->toSelectSql()->sql,
        );
    }

    public function test_offset_rejects_a_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('offset() must be 0 or greater, got -5.');

        $this->mysql()->table('users')->offset(-5);
    }

    public function test_paginate_rejects_a_non_positive_per_page(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessage('paginate() needs a perPage of at least 1, got 0.');

        $this->mysql()->table('users')->paginate(0);
    }

    /**
     * A 400, not the generic 500 an uncaught InvalidArgumentException
     * would otherwise reach ExceptionHandlerMiddleware as — see
     * Kinetis\Http\Exception\HttpStatusExceptionInterface.
     */
    public function test_invalid_pagination_exception_declares_a_400_status(): void
    {
        try {
            $this->mysql()->table('users')->paginate(0);
            self::fail('paginate() was expected to throw.');
        } catch (InvalidPaginationException $e) {
            self::assertSame(400, $e->httpStatus());
        }
    }

    public function test_paginate_rejects_a_non_positive_page(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessage('paginate() needs a page of at least 1, got 0.');

        $this->mysql()->table('users')->paginate(10, 0);
    }

    public function test_cursor_paginate_rejects_a_non_positive_per_page(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessage('cursorPaginate() needs a perPage of at least 1, got 0.');

        $this->mysql()->table('users')->cursorPaginate(0, null);
    }

    /**
     * cursorPaginate() reads nextCursor off the real cursor column in
     * every row it fetches — that column has to actually be in the
     * SELECT list for that to work at all, regardless of what the
     * caller's own projection asked to see. Verified here purely at the
     * "what SQL did Query actually send" level, against a spy rather
     * than a real database — asserting on returned row data (does the
     * added column get stripped back out again) needs real execution,
     * which this class's own established discipline leaves to
     * real-backend verification rather than a mocked PHPUnit test.
     */
    public function test_cursor_paginate_adds_the_cursor_column_to_a_projection_that_omits_it(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('users')->select('name')->cursorPaginate(20, null, cursorColumn: 'id');

        self::assertCount(1, $spy->calls);
        self::assertSame(
            'SELECT `name`, `id` FROM `users` ORDER BY `id` ASC LIMIT 21',
            $spy->calls[0]->sql,
        );
    }

    public function test_cursor_paginate_does_not_duplicate_the_cursor_column_when_the_projection_already_has_it(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('users')->select('id', 'name')->cursorPaginate(20, null, cursorColumn: 'id');

        self::assertSame(
            'SELECT `id`, `name` FROM `users` ORDER BY `id` ASC LIMIT 21',
            $spy->calls[0]->sql,
        );
    }

    public function test_cursor_paginate_does_not_touch_the_default_wildcard_projection(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('users')->cursorPaginate(20, null, cursorColumn: 'id');

        self::assertSame(
            'SELECT * FROM `users` ORDER BY `id` ASC LIMIT 21',
            $spy->calls[0]->sql,
        );
    }
    /**
     * A qualified cursor column is reported by both MySQL and Postgres
     * under its bare name, which a join can collide with — so it is
     * additionally selected under the caller-supplied alias, appended to
     * whatever projection they already asked for rather than replacing
     * it.
     */
    public function test_cursor_paginate_appends_the_caller_supplied_alias_for_a_qualified_column(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('orders')->select('name')
            ->cursorPaginate(20, null, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');

        self::assertSame(
            'SELECT `name`, `orders`.`id` AS `order_cursor` FROM `orders` ORDER BY `orders`.`id` ASC LIMIT 21',
            $spy->calls[0]->sql,
        );
    }

    /**
     * A cursor alias must never be what turns an untouched wildcard into
     * an explicit projection — compileSelectColumns() drops the default
     * "*" once something explicit exists, and the alias is appended
     * after that rule rather than through it.
     */
    public function test_cursor_paginate_keeps_the_default_wildcard_alongside_the_alias(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('orders')
            ->cursorPaginate(20, null, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');

        self::assertSame(
            'SELECT *, `orders`.`id` AS `order_cursor` FROM `orders` ORDER BY `orders`.`id` ASC LIMIT 21',
            $spy->calls[0]->sql,
        );
    }

    /**
     * Guessing a row key for a qualified column is what silently lost a
     * same-named application column, so it is refused instead — with the
     * message naming the parameter that settles it.
     */
    public function test_cursor_paginate_refuses_a_qualified_column_with_no_alias(): void
    {
        $this->expectException(InvalidPaginationException::class);
        // The complete message, not a substring: a partial assertion
        // leaves the rest of the sentence — including the suggested
        // alias it derives — free to rot unnoticed.
        $this->expectExceptionMessage(
            'cursorPaginate() needs a $cursorAlias for the qualified cursor column "orders.id": both MySQL and '
            . 'Postgres report it under its bare name, which another selected column of that same name would '
            . 'silently overwrite in the returned row. Pass a name nothing else in the projection uses — '
            . "cursorAlias: 'orders_id', say — and the cursor is read from that and stripped back out before "
            . 'the rows are returned.',
        );

        new Query(new SpyMysqlLink())->table('orders')->cursorPaginate(20, null, cursorColumn: 'orders.id');
    }

    /**
     * The cursor comes out of the same result as the delivered rows —
     * one query, never two, which is what makes it impossible for the
     * cursor to name a row the caller was not handed. Asserted on the
     * recorded call count as much as on the value.
     */
    public function test_cursor_paginate_reads_the_cursor_from_the_delivered_row_in_one_query(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([
                ['name' => 'a', 'order_cursor' => 7],
                ['name' => 'b', 'order_cursor' => 8],
            ]),
        ]);

        $page = new Query($link)->table('orders')->select('name')
            ->cursorPaginate(1, null, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');

        self::assertCount(1, $link->calls, 'The cursor must not cost a second round trip.');
        self::assertTrue($page->hasMore);
        self::assertSame('7', $page->nextCursor);
        self::assertSame([['name' => 'a']], $page->data, 'The alias must be stripped from the returned rows.');
    }

    /**
     * A caller's own offset() is part of the query cursorPaginate()
     * paginates, and reading the cursor from the delivered row is what
     * keeps the two consistent: the cursor names the row that was
     * actually returned, whatever offset shifted the window to.
     */
    public function test_cursor_paginate_honours_a_caller_supplied_offset(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([
                ['id' => 2, 'order_cursor' => 2],
                ['id' => 3, 'order_cursor' => 3],
            ]),
        ]);

        $page = new Query($link)->table('orders')->offset(1)
            ->cursorPaginate(1, null, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');

        self::assertStringContainsString('OFFSET 1', $link->calls[0]->sql);
        self::assertSame([['id' => 2]], $page->data);
        self::assertSame('2', $page->nextCursor, 'The cursor must name the row actually delivered.');
    }

    /**
     * An alias created by selectRaw() and ordered by is a legal query
     * this must not break: the projection that defines the alias has to
     * still be there when the ORDER BY referring to it runs.
     */
    public function test_cursor_paginate_keeps_a_projection_alias_its_own_order_by_depends_on(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('orders')->select('id', 'name')->selectRaw('id * 2 AS rank_value')
            ->orderBy('rank_value')
            ->cursorPaginate(1, null, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');

        self::assertCount(1, $spy->calls);
        self::assertStringContainsString('id * 2 AS rank_value', $spy->calls[0]->sql);
        self::assertStringContainsString('`rank_value`', $spy->calls[0]->sql);
    }

    /**
     * An alias is equally available for an *unqualified* column, which
     * is how a caller disambiguates a projection that already carries a
     * different column of that name.
     */
    public function test_cursor_paginate_accepts_an_alias_for_an_unqualified_column_too(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([
                ['id' => 'theirs', 'own_cursor' => 1],
                ['id' => 'theirs', 'own_cursor' => 2],
            ]),
        ]);

        $page = new Query($link)->table('orders')
            ->cursorPaginate(1, null, cursorColumn: 'id', cursorAlias: 'own_cursor');

        self::assertSame('1', $page->nextCursor);
        self::assertSame([['id' => 'theirs']], $page->data);
    }

    /**
     * A collision is destructive rather than confusing — the appended
     * cursor takes the key, and the cleanup that removes the alias
     * removes the caller's own column with it — and nothing after the
     * query can notice, since the key is present either way. The half of
     * it that is visible from the builder is refused before any SQL
     * runs.
     */
    public function test_cursor_paginate_refuses_an_alias_a_listed_column_already_answers_to(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessage(
            'cursorPaginate()\'s $cursorAlias "row_cursor" is already the name of a column this query selects '
            . '("row_cursor"). The cursor is selected under that alias and stripped back out afterwards, so '
            . 'sharing the name would drop the column you asked for. Pick a name nothing else in the projection '
            . 'uses.',
        );

        new Query(new SpyMysqlLink())->table('orders')->select('id', 'row_cursor')
            ->cursorPaginate(20, null, cursorColumn: 'orders.id', cursorAlias: 'row_cursor');
    }

    /**
     * Both engines report a qualified column under its last segment, so
     * select('t.row_cursor') claims the same key as select('row_cursor')
     * and collides just as destructively.
     */
    public function test_cursor_paginate_refuses_an_alias_a_qualified_listed_column_answers_to(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessage('is already the name of a column this query selects ("orders.row_cursor")');

        new Query(new SpyMysqlLink())->table('orders')->select('orders.row_cursor')
            ->cursorPaginate(20, null, cursorColumn: 'orders.id', cursorAlias: 'row_cursor');
    }

    /**
     * A wildcard is not a column the builder can read, so it must not be
     * mistaken for one — rejecting on its presence would refuse every
     * default projection.
     */
    public function test_cursor_paginate_does_not_mistake_a_wildcard_for_a_colliding_column(): void
    {
        $spy = new SpyMysqlLink();
        new Query($spy)->table('orders')->select('*', 'orders.*')
            ->cursorPaginate(20, null, cursorColumn: 'orders.id', cursorAlias: 'row_cursor');

        self::assertStringContainsString('AS `row_cursor`', $spy->calls[0]->sql);
    }

    /**
     * An alias the projection swallowed (a caller who picked a name
     * something else already claims) leaves no key to read the cursor
     * from — a clear error rather than a silently null cursor.
     */
    public function test_cursor_paginate_throws_when_the_alias_is_missing_from_the_returned_row(): void
    {
        $link = new QueuedRowsMysqlLink([
            new QueuedSqlResult([
                ['name' => 'a'],
                ['name' => 'b'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'cursorPaginate() could not read "order_cursor" back off the row it just returned. The cursor '
            . 'column has to reach the result under exactly that name for its value to be readable.',
        );

        new Query($link)->table('orders')
            ->cursorPaginate(1, null, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');
    }
    public function test_insert_rejects_an_empty_data_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insert() needs at least one column');

        $this->mysql()->table('users')->insert([]);
    }

    public function test_insert_get_id_rejects_an_empty_data_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insertGetId() needs at least one column');

        $this->mysql()->table('users')->insertGetId([]);
    }

    public function test_update_rejects_an_empty_data_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('update() needs at least one column');

        $this->mysql()->table('users')->toUpdateSql([]);
    }
}
