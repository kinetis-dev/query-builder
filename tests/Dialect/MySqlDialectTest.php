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
        // Strings included: a safe string literal depends on connection
        // charset/SQL-mode state the dialect does not know, so they
        // always bind through the driver instead.
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

    /** The family has no OFFSET without LIMIT, so a lone offset carries the largest BIGINT UNSIGNED. */
    public function test_limit_offset_spells_a_lone_offset_with_an_unbounded_limit(): void
    {
        $dialect = new MySqlDialect();

        self::assertSame('', $dialect->limitOffset(null, null));
        self::assertSame(' LIMIT 5', $dialect->limitOffset(5, null));
        self::assertSame(' LIMIT 5 OFFSET 10', $dialect->limitOffset(5, 10));
        self::assertSame(' LIMIT 18446744073709551615 OFFSET 10', $dialect->limitOffset(null, 10));
    }

    public function test_family_specific_spellings(): void
    {
        $dialect = new MySqlDialect();

        self::assertSame(' LOCK IN SHARE MODE', $dialect->sharedLock());
        self::assertFalse($dialect->admitsLimitedInSubquery());
        self::assertSame(' ON DUPLICATE KEY UPDATE `user_id` = `user_id`', $dialect->insertOrIgnoreClause(['user_id', 'article_id']));
        self::assertSame(
            ' ON DUPLICATE KEY UPDATE `views` = VALUES(`views`), `se``en` = VALUES(`se``en`)',
            $dialect->upsertClause(['article_id'], ['views', 'se`en']),
        );
        self::assertSame('', $dialect->insertGetIdClause('id'));
    }
}
