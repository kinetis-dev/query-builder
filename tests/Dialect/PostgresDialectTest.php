<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Dialect;

use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use Kinetis\QueryBuilder\Tests\Fixtures\FakePostgresLinkWithQuoting;
use PHPUnit\Framework\TestCase;

final class PostgresDialectTest extends TestCase
{
    public function test_an_int_is_always_inlinable(): void
    {
        self::assertSame('42', new PostgresDialect()->literalFor(42, new FakePostgresLinkWithQuoting()));
    }

    public function test_bool_becomes_the_sql_true_false_keyword(): void
    {
        $link = new FakePostgresLinkWithQuoting();

        self::assertSame('TRUE', new PostgresDialect()->literalFor(true, $link));
        self::assertSame('FALSE', new PostgresDialect()->literalFor(false, $link));
    }

    public function test_null_and_float_are_never_inlined(): void
    {
        $dialect = new PostgresDialect();
        $link = new FakePostgresLinkWithQuoting();

        self::assertNull($dialect->literalFor(null, $link));
        self::assertNull($dialect->literalFor(3.14, $link));
    }

    public function test_a_string_delegates_entirely_to_the_links_own_quote_literal(): void
    {
        // Confirms delegation, not correctness of the escaping itself —
        // quoteLiteral() is amphp/postgres's own real, native call, not
        // something this class reimplements (see its docblock); the real
        // implementation's correctness is verified separately, against a
        // live server.
        self::assertSame(
            "'O''Brien'",
            new PostgresDialect()->literalFor("O'Brien", new FakePostgresLinkWithQuoting()),
        );
    }
}
