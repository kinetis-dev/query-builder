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

    public function test_limit_offset_spells_a_bare_offset(): void
    {
        $dialect = new PostgresDialect();

        self::assertSame('', $dialect->limitOffset(null, null));
        self::assertSame(' LIMIT 5', $dialect->limitOffset(5, null));
        self::assertSame(' LIMIT 5 OFFSET 10', $dialect->limitOffset(5, 10));
        self::assertSame(' OFFSET 10', $dialect->limitOffset(null, 10));
    }

    public function test_postgres_specific_spellings(): void
    {
        $dialect = new PostgresDialect();

        self::assertSame(' FOR SHARE', $dialect->sharedLock());
        self::assertTrue($dialect->admitsLimitedInSubquery());
        self::assertSame(' ON CONFLICT DO NOTHING', $dialect->insertOrIgnoreClause(['user_id']));
        self::assertSame(
            ' ON CONFLICT ("user_id", "article_id") DO UPDATE SET "views" = EXCLUDED."views"',
            $dialect->upsertClause(['user_id', 'article_id'], ['views']),
        );
        self::assertSame(' RETURNING "id"', $dialect->insertGetIdClause('id'));
    }
}
