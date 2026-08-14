<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Dialect;

use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlConnection;
use Kinetis\QueryBuilder\Tests\Fixtures\FakeMysqlLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MySqlDialectTest extends TestCase
{
    public function test_an_int_is_always_inlinable_regardless_of_charset(): void
    {
        self::assertSame('42', new MySqlDialect()->literalFor(42, new FakeMysqlConnection('gbk')));
        self::assertSame('-7', new MySqlDialect()->literalFor(-7, new FakeMysqlConnection('utf8mb4')));
    }

    public function test_bool_becomes_a_plain_1_or_0(): void
    {
        self::assertSame('1', new MySqlDialect()->literalFor(true, new FakeMysqlConnection()));
        self::assertSame('0', new MySqlDialect()->literalFor(false, new FakeMysqlConnection()));
    }

    public function test_null_and_float_are_never_inlined(): void
    {
        $dialect = new MySqlDialect();
        $link = new FakeMysqlConnection();

        self::assertNull($dialect->literalFor(null, $link));
        self::assertNull($dialect->literalFor(3.14, $link));
    }

    public function test_a_string_is_inlined_as_an_escaped_quoted_literal_on_a_safe_charset(): void
    {
        $literal = new MySqlDialect()->literalFor("O'Brien", new FakeMysqlConnection('utf8mb4'));

        self::assertSame("'O\\'Brien'", $literal);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeCases(): iterable
    {
        yield 'null byte' => ["a\x00b", "'a\\0b'"];
        yield 'backspace' => ["a\x08b", "'a\\bb'"];
        yield 'tab' => ["a\tb", "'a\\tb'"];
        yield 'newline' => ["a\nb", "'a\\nb'"];
        yield 'carriage return' => ["a\rb", "'a\\rb'"];
        yield 'ctrl-z' => ["a\x1ab", "'a\\Zb'"];
        yield 'double quote' => ['a"b', "'a\\\"b'"];
        yield 'single quote' => ["a'b", "'a\\'b'"];
        yield 'backslash' => ['a\\b', "'a\\\\b'"];
        yield 'trailing backslash — the case that breaks a naive quote-only escaper' => ['foo\\', "'foo\\\\'"];
        yield 'plain string, nothing to escape' => ['hello', "'hello'"];
        yield 'empty string' => ['', "''"];
        yield 'a literal question mark' => ['what?', "'what?'"];
    }

    #[DataProvider('escapeCases')]
    public function test_escape_map_matches_the_documented_character_table(string $input, string $expected): void
    {
        self::assertSame($expected, new MySqlDialect()->literalFor($input, new FakeMysqlConnection('utf8mb4')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeCharsets(): iterable
    {
        yield 'utf8mb4' => ['utf8mb4'];
        yield 'utf8mb3' => ['utf8mb3'];
        yield 'utf8' => ['utf8'];
        yield 'ascii' => ['ascii'];
        yield 'latin1' => ['latin1'];
        yield 'binary' => ['binary'];
        yield 'case-insensitive' => ['UTF8MB4'];
    }

    #[DataProvider('safeCharsets')]
    public function test_strings_are_inlinable_on_every_charset_in_the_safe_allow_list(string $charset): void
    {
        self::assertNotNull(new MySqlDialect()->literalFor('x', new FakeMysqlConnection($charset)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeCharsets(): iterable
    {
        // gbk/big5/sjis are the real, historically-exploited charsets
        // where a naive byte-level escaper can be defeated by a
        // multi-byte lead byte swallowing the escape/quote byte that
        // follows it — see MySqlDialect::ASCII_SAFE_CHARSETS's docblock.
        yield 'gbk' => ['gbk'];
        yield 'big5' => ['big5'];
        yield 'sjis' => ['sjis'];
        yield 'an unrecognized charset name' => ['some_future_charset'];
    }

    #[DataProvider('unsafeCharsets')]
    public function test_strings_fall_back_to_a_real_parameter_on_a_charset_outside_the_allow_list(string $charset): void
    {
        self::assertNull(new MySqlDialect()->literalFor("' OR '1'='1", new FakeMysqlConnection($charset)));
    }

    public function test_a_string_is_never_inlined_when_the_link_cannot_report_its_charset(): void
    {
        // FakeMysqlLink implements MysqlLink but not MysqlConnection — the
        // same shape as a live MysqlTransaction, which amphp/mysql gives
        // no getConfig() on at all.
        self::assertNull(new MySqlDialect()->literalFor('x', new FakeMysqlLink()));
    }
}
