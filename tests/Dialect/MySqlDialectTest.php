<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Dialect;

use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use PHPUnit\Framework\TestCase;

final class MySqlDialectTest extends TestCase
{
    public function test_an_int_is_always_inlinable(): void
    {
        self::assertSame('42', new MySqlDialect()->literalFor(42));
        self::assertSame('-7', new MySqlDialect()->literalFor(-7));
    }

    public function test_bool_becomes_a_plain_1_or_0(): void
    {
        self::assertSame('1', new MySqlDialect()->literalFor(true));
        self::assertSame('0', new MySqlDialect()->literalFor(false));
    }

    public function test_null_float_and_string_are_never_inlined(): void
    {
        // Strings deliberately included: a safe string literal depends on
        // connection charset/SQL-mode state the dialect knows nothing
        // about — they always bind through the driver instead.
        $dialect = new MySqlDialect();

        self::assertNull($dialect->literalFor(null));
        self::assertNull($dialect->literalFor(3.14));
        self::assertNull($dialect->literalFor("' OR '1'='1"));
        self::assertNull($dialect->literalFor('hello'));
    }

    public function test_quote_identifier_backticks_and_splits_qualified_names(): void
    {
        $dialect = new MySqlDialect();

        self::assertSame('`world`', $dialect->quoteIdentifier('world'));
        self::assertSame('`orders`.`total`', $dialect->quoteIdentifier('orders.total'));
        self::assertSame('`we``ird`', $dialect->quoteIdentifier('we`ird'));
    }
}
