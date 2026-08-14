<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Dialect;

use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use PHPUnit\Framework\TestCase;

final class PostgresDialectTest extends TestCase
{
    public function test_an_int_is_always_inlinable(): void
    {
        self::assertSame('42', new PostgresDialect()->literalFor(42));
    }

    public function test_bool_becomes_the_sql_true_false_keyword(): void
    {
        self::assertSame('TRUE', new PostgresDialect()->literalFor(true));
        self::assertSame('FALSE', new PostgresDialect()->literalFor(false));
    }

    public function test_null_float_and_string_are_never_inlined(): void
    {
        // Same policy as MySqlDialect — strings always bind through the
        // driver's real server-side parameters.
        $dialect = new PostgresDialect();

        self::assertNull($dialect->literalFor(null));
        self::assertNull($dialect->literalFor(3.14));
        self::assertNull($dialect->literalFor("O'Brien"));
    }

    public function test_quote_identifier_double_quotes_and_splits_qualified_names(): void
    {
        $dialect = new PostgresDialect();

        self::assertSame('"world"', $dialect->quoteIdentifier('world'));
        self::assertSame('"orders"."total"', $dialect->quoteIdentifier('orders.total'));
        self::assertSame('"we""ird"', $dialect->quoteIdentifier('we"ird'));
    }
}
