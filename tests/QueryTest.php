<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLink;
use PHPUnit\Framework\TestCase;

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
}
